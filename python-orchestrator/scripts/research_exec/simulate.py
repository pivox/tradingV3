"""Resting limit orders on the captured tape: placement, fills, mark-outs.

Every `grid_s` seconds of the exchange clock, a buy and a sell order are placed at the touch
(best bid / best ask) and, when the spread allows it, one tick inside the touch. An order
becomes live `latency_ms` after its decision; if the quote has moved so that it would cross,
it is cancelled (limit-only policy). A live order rests until `ttl_s` after the decision.

Fill rules (the study's pre-registration fixes which one gates the verdict):

- `through` (pessimistic): a buy at p is filled by the first trade printed strictly below p,
  a sell by the first trade strictly above p, whatever the queue position was;
- `touch` (optimistic, front of the queue): a buy at p is filled by the first trade at or
  below p whose taker sold, a sell by the first trade at or above p whose taker bought.

Mark-outs are measured against the last quote at or before fill time + horizon:
`m_h = side × (mid(τ+h) − p) / p` in basis points, plus `m_0` against the quote just before
the filling trade (the half-spread captured). The round trip `rt_h` exits at the opposite
touch at τ+h and pays the maker and the taker fee. For every valid decision, the
unconditional mid drift `d_h` at the same horizons is kept as the no-fill baseline.
"""

from __future__ import annotations

from dataclasses import asdict, dataclass

import numpy as np

from .capture import Instrument

HORIZONS_S = (1, 10, 60, 300)
STATUS = {"stale": 0, "no_inside": 1, "crossed": 2, "unfilled": 3, "filled": 4}
PLACEMENTS = {"touch": 0, "inside": 1}
RULES = ("through", "touch")

ORDER_DTYPE = np.dtype(
    [("decision_ts", "<i8"), ("side", "<i1"), ("placement", "<i1"), ("price", "<f8"), ("bid", "<f8"), ("ask", "<f8"),
     ("half_spread_bp", "<f8"), ("status", "<i1"), ("fill_ts", "<i8"), ("fill_delay_us", "<i8"), ("m_0", "<f8")]
    + [(f"m_{h}s", "<f8") for h in HORIZONS_S]
    + [(f"rt_{h}s", "<f8") for h in HORIZONS_S]
    + [(f"d_{h}s", "<f8") for h in HORIZONS_S]
    + [("stale_mask", "<i1")]
)


@dataclass(frozen=True)
class Parameters:
    grid_s: float = 5.0
    latency_ms: float = 250.0
    ttl_s: float = 45.0
    stale_s: float = 10.0
    maker_bp: float = 2.0
    taker_bp: float = 5.0
    rule: str = "through"

    def to_dict(self) -> dict:
        return asdict(self)


def decision_grid(quotes: np.ndarray, trades: np.ndarray, params: Parameters) -> np.ndarray:
    """Epoch-aligned decision instants that leave room for the TTL and the longest horizon."""
    grid = int(round(params.grid_s * 1e6))
    start = int(max(quotes["ts"][0], trades["ts"][0]))
    end = int(min(quotes["ts"][-1], trades["ts"][-1])) - int(round(params.ttl_s * 1e6)) - max(HORIZONS_S) * 1_000_000
    first = -(-start // grid) * grid
    if end <= first:
        return np.zeros(0, dtype=np.int64)
    return np.arange(first, end, grid, dtype=np.int64)


def _quote_index(qts: np.ndarray, moments: np.ndarray, side: str = "right") -> np.ndarray:
    return np.searchsorted(qts, moments, side=side) - 1


def simulate_symbol(quotes: np.ndarray, trades: np.ndarray, instrument: Instrument, params: Parameters) -> np.ndarray:
    """Simulate every decision × side × placement for one symbol and return the order rows."""
    if params.rule not in RULES:
        raise ValueError(f"unknown fill rule {params.rule}")
    decisions = decision_grid(quotes, trades, params)
    pieces = [
        _simulate_combination(quotes, trades, instrument, params, decisions, side, placement)
        for side in (1, -1)
        for placement in PLACEMENTS.values()
    ]
    return np.concatenate(pieces) if pieces else np.zeros(0, dtype=ORDER_DTYPE)


def _simulate_combination(quotes, trades, instrument, params, decisions, side, placement) -> np.ndarray:
    qts, bid, ask = quotes["ts"], quotes["bid"], quotes["ask"]
    mid = (bid + ask) / 2.0
    tts, tpx, tbuy = trades["ts"], trades["price"], trades["taker_buy"]
    stale_us = int(round(params.stale_s * 1e6))
    latency_us = int(round(params.latency_ms * 1e3))
    ttl_us = int(round(params.ttl_s * 1e6))
    rows = np.zeros(decisions.size, dtype=ORDER_DTYPE)
    rows["decision_ts"] = decisions
    rows["side"] = side
    rows["placement"] = placement
    rows["fill_ts"] = -1
    rows["fill_delay_us"] = -1
    for name in ORDER_DTYPE.names:
        if name.startswith(("m_", "rt_", "d_")):
            rows[name] = np.nan
    if decisions.size == 0:
        return rows
    # Quote seen at the decision.
    iq = _quote_index(qts, decisions)
    seen = iq >= 0
    iq_safe = np.where(seen, iq, 0)
    fresh = seen & (decisions - qts[iq_safe] <= stale_us)
    b, a = bid[iq_safe], ask[iq_safe]
    rows["bid"], rows["ask"] = b, a
    rows["half_spread_bp"] = (a - b) / (a + b) * 1e4
    ticks = instrument.tick_array(b)
    tolerance = ticks * 1e-6
    # Unconditional drift at the decision for fresh quotes (long perspective).
    for h in HORIZONS_S:
        ih = _quote_index(qts, decisions + h * 1_000_000)
        ok = fresh & (ih >= 0)
        ih_safe = np.where(ih >= 0, ih, 0)
        rows[f"d_{h}s"] = np.where(ok, (mid[ih_safe] - mid[iq_safe]) / mid[iq_safe] * 1e4, np.nan)
    # Order price.
    if placement == PLACEMENTS["touch"]:
        price = np.where(side > 0, b, a)
        eligible = fresh
    else:
        price = np.where(side > 0, b + ticks, a - ticks)
        eligible = fresh & ((a - b) >= 2 * ticks - tolerance)
    price = np.round(price / ticks) * ticks
    rows["price"] = price
    rows["status"] = np.where(fresh, np.where(eligible, STATUS["unfilled"], STATUS["no_inside"]), STATUS["stale"])
    # Activation: cancel orders that would cross the quote live at that moment.
    activation = decisions + latency_us
    ia = _quote_index(qts, activation)
    ia_safe = np.where(ia >= 0, ia, 0)
    if side > 0:
        crossing = price >= ask[ia_safe] - tolerance
    else:
        crossing = price <= bid[ia_safe] + tolerance
    crossing &= eligible & (ia >= 0)
    rows["status"][crossing] = STATUS["crossed"]
    resting = eligible & ~crossing
    # Fill search, order by order, on the trades strictly after activation and up to the TTL.
    j0 = np.searchsorted(tts, activation, side="right")
    j1 = np.searchsorted(tts, decisions + ttl_us, side="right")
    fill_ts = np.full(decisions.size, -1, dtype=np.int64)
    for k in np.flatnonzero(resting):
        lo, hi = int(j0[k]), int(j1[k])
        if hi <= lo:
            continue
        window = tpx[lo:hi]
        p, tol = price[k], tolerance[k]
        if params.rule == "through":
            hit = window < p - tol if side > 0 else window > p + tol
        else:
            takers = tbuy[lo:hi]
            hit = (window <= p + tol) & (takers == 0) if side > 0 else (window >= p - tol) & (takers == 1)
        if hit.any():
            fill_ts[k] = tts[lo + int(np.argmax(hit))]
    filled = fill_ts >= 0
    rows["status"][filled] = STATUS["filled"]
    rows["fill_ts"] = fill_ts
    rows["fill_delay_us"] = np.where(filled, fill_ts - decisions, -1)
    if not filled.any():
        return rows
    # Mark-outs for the fills.
    f = np.flatnonzero(filled)
    p = price[f]
    tau = fill_ts[f]
    before = _quote_index(qts, tau, side="left")
    before_safe = np.where(before >= 0, before, 0)
    m0 = np.where(before >= 0, side * (mid[before_safe] - p) / p * 1e4, np.nan)
    rows["m_0"][f] = m0
    stale_mask = np.zeros(f.size, dtype=np.int8)
    for bit, h in enumerate(HORIZONS_S):
        moment = tau + h * 1_000_000
        ih = _quote_index(qts, moment)
        ih_safe = np.where(ih >= 0, ih, 0)
        rows[f"m_{h}s"][f] = side * (mid[ih_safe] - p) / p * 1e4
        exit_price = bid[ih_safe] if side > 0 else ask[ih_safe]
        rows[f"rt_{h}s"][f] = side * (exit_price - p) / p * 1e4 - params.maker_bp - params.taker_bp
        stale_mask |= ((moment - qts[ih_safe]) > stale_us).astype(np.int8) << bit
    rows["stale_mask"][f] = stale_mask
    return rows


def simulate_extract(quotes: dict[str, np.ndarray], trades: dict[str, np.ndarray], instruments: dict[str, Instrument], params: Parameters) -> dict[str, np.ndarray]:
    return {symbol: simulate_symbol(quotes[symbol], trades[symbol], instruments[symbol], params) for symbol in sorted(quotes)}
