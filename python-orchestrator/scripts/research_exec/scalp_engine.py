"""Entry and exit policies of the tick-data scalping screen, replayed on the prints.

One decision at a time per series: a decision at the end of second `s` places a resting limit
order (touch or one tick inside), live `latency_us` later, cancelled if it would cross the
quote live at activation, filled under the pessimistic through rule (a print strictly beyond
the order price), expiring at the TTL. A cancelled order blocks new decisions for
`cancel_block_s`, an unfilled one until its TTL, a position until its exit.

Exits: X1 taker at the opposite touch 60 s after the fill, X2 the same at 300 s, X3 stop at
entry − 1 σ_60 (floor two ticks, prints at or beyond it, taker at the next touch) with a
passive target at entry + 1.5 σ_60 (through rule) and a 300 s maximum hold, X4 a passive exit
posted at the opposite touch 60 s after the fill with a 30 s TTL then a taker fallback at 90 s.
Market exits carry the latency. Fees: maker on the entry, maker or taker on the exit.
"""

from __future__ import annotations

from dataclasses import dataclass

import numpy as np

from .capture import Instrument
from .tick import SECOND_US

ENTRIES = ("E1", "E2")
EXITS = ("X1", "X2", "X3", "X4")
EXIT_REASONS = {"time": 0, "stop": 1, "target": 2, "passive": 3, "fallback": 4}

TRADE_DTYPE = np.dtype(
    [("series", "<i2"), ("side", "<i1"), ("decision_s", "<i8"), ("fill_ts", "<i8"), ("entry", "<f8"), ("exit_ts", "<i8"),
     ("exit", "<f8"), ("exit_reason", "<i1"), ("gross_bp", "<f8"), ("fees_bp", "<f8"), ("net_bp", "<f8"), ("hold_s", "<f8")]
)


@dataclass(frozen=True)
class Policy:
    entry: str
    exit: str
    latency_us: int = 250_000
    ttl_us: int = 30_000_000
    cancel_block_s: int = 5
    stale_s: float = 10.0
    maker_bp: float = 2.0
    taker_bp: float = 5.0

    def __post_init__(self) -> None:
        if self.entry not in ENTRIES or self.exit not in EXITS:
            raise ValueError(f"unknown policy {self.entry}/{self.exit}")


@dataclass
class Counters:
    decisions: int = 0
    orders: int = 0
    crossed: int = 0
    unfilled: int = 0
    filled: int = 0


class SeriesData:
    """Arrays of one series shared by every configuration replayed on it."""

    def __init__(self, series_id: int, bars: dict[str, np.ndarray], features: dict[str, np.ndarray], quotes: np.ndarray, trades: np.ndarray, instrument: Instrument, start_s: int) -> None:
        self.series_id = series_id
        self.bars = bars
        self.features = features
        self.qts, self.bid, self.ask = quotes["ts"], quotes["bid"], quotes["ask"]
        self.tts, self.tpx = trades["ts"], trades["price"]
        self.instrument = instrument
        self.start_s = int(start_s)
        self.n = bars["mid"].size


def _quote_at(data: SeriesData, moment: int) -> int:
    return int(np.searchsorted(data.qts, moment, side="right")) - 1


def _touch(data: SeriesData, moment: int, want_bid: bool) -> float | None:
    """Best bid (or ask) live at `moment`, None before the first quote."""
    iq = _quote_at(data, moment)
    if iq < 0:
        return None
    return float(data.bid[iq]) if want_bid else float(data.ask[iq])


def run_policy(data: SeriesData, side: int, decisions: np.ndarray, policy: Policy) -> tuple[np.ndarray, Counters]:
    """Replay the policy on the given decision seconds (bar indices, sorted)."""
    bars, f = data.bars, data.features
    counters = Counters()
    rows: list[tuple] = []
    busy_until = -1
    tol_scale = 1e-6
    ttl_s = policy.ttl_us // SECOND_US
    for s in decisions.tolist():
        if s < busy_until or s >= data.n:
            continue
        counters.decisions += 1
        mid = bars["mid"][s]
        if not np.isfinite(mid) or bars["quote_age"][s] > policy.stale_s:
            continue
        b, a = float(bars["bid"][s]), float(bars["ask"][s])
        tick = data.instrument.tick(b)
        tol = tick * tol_scale
        if policy.entry == "E2" and (a - b) >= 2 * tick - tol:
            price = b + tick if side > 0 else a - tick
        else:
            price = b if side > 0 else a
        price = round(price / tick) * tick
        t_dec = (data.start_s + s + 1) * SECOND_US
        t_act = t_dec + policy.latency_us
        iq = _quote_at(data, t_act)
        if iq < 0:
            continue
        counters.orders += 1
        if (side > 0 and price >= data.ask[iq] - tol) or (side < 0 and price <= data.bid[iq] + tol):
            counters.crossed += 1
            busy_until = s + 1 + policy.cancel_block_s
            continue
        j0 = int(np.searchsorted(data.tts, t_act, side="right"))
        j1 = int(np.searchsorted(data.tts, t_dec + policy.ttl_us, side="right"))
        window = data.tpx[j0:j1]
        hit = (window < price - tol) if side > 0 else (window > price + tol)
        if window.size == 0 or not hit.any():
            counters.unfilled += 1
            busy_until = s + 1 + ttl_s
            continue
        jf = j0 + int(np.argmax(hit))
        fill_ts = int(data.tts[jf])
        counters.filled += 1
        exit_ts, exit_price, reason, exit_fee = _exit(data, policy, side, s, price, jf, fill_ts, tick, tol)
        if exit_price is None:
            busy_until = data.n
            continue
        gross = side * (exit_price - price) / price * 1e4
        fees = policy.maker_bp + exit_fee
        rows.append((data.series_id, side, data.start_s + s, fill_ts, price, exit_ts, exit_price, reason, gross, fees, gross - fees, (exit_ts - fill_ts) / 1e6))
        busy_until = int((exit_ts - data.start_s * SECOND_US) // SECOND_US) + 1
    trades = np.array(rows, dtype=TRADE_DTYPE) if rows else np.zeros(0, dtype=TRADE_DTYPE)
    return trades, counters


def _market_exit(data: SeriesData, policy: Policy, side: int, moment: int, reason: int) -> tuple[int, float | None, int, float]:
    exit_moment = moment + policy.latency_us
    price = _touch(data, exit_moment, want_bid=side > 0)  # a long sells at the bid, a short buys at the ask
    return exit_moment, price, reason, policy.taker_bp


def _exit(data: SeriesData, policy: Policy, side: int, s: int, entry: float, jf: int, fill_ts: int, tick: float, tol: float) -> tuple[int, float | None, int, float]:
    if policy.exit == "X1":
        return _market_exit(data, policy, side, fill_ts + 60 * SECOND_US, EXIT_REASONS["time"])
    if policy.exit == "X2":
        return _market_exit(data, policy, side, fill_ts + 300 * SECOND_US, EXIT_REASONS["time"])
    if policy.exit == "X3":
        sigma = float(data.features["sigma_60"][s])
        if not np.isfinite(sigma):
            sigma = 2 * tick / entry
        distance = max(sigma * entry, 2 * tick)
        stop = entry - side * distance
        target = entry + side * 1.5 * distance
        target = (np.ceil(target / tick) if side > 0 else np.floor(target / tick)) * tick
        deadline = fill_ts + 300 * SECOND_US
        k0 = jf + 1
        k1 = int(np.searchsorted(data.tts, deadline, side="right"))
        window = data.tpx[k0:k1]
        stop_hit = (window <= stop + tol) if side > 0 else (window >= stop - tol)
        target_hit = (window > target + tol) if side > 0 else (window < target - tol)
        ks = int(np.argmax(stop_hit)) if stop_hit.any() else -1
        kt = int(np.argmax(target_hit)) if target_hit.any() else -1
        if ks >= 0 and (kt < 0 or data.tts[k0 + ks] // SECOND_US <= data.tts[k0 + kt] // SECOND_US):
            return _market_exit(data, policy, side, int(data.tts[k0 + ks]), EXIT_REASONS["stop"])
        if kt >= 0:
            return int(data.tts[k0 + kt]), float(target), EXIT_REASONS["target"], policy.maker_bp
        return _market_exit(data, policy, side, deadline, EXIT_REASONS["time"])
    # X4: passive exit posted at the opposite touch after 60 s, 30 s TTL, taker fallback at 90 s.
    post = fill_ts + 60 * SECOND_US + policy.latency_us
    price = _touch(data, post, want_bid=side < 0)  # a long posts its sell at the ask, a short its buy at the bid
    if price is None:
        return _market_exit(data, policy, side, fill_ts + 90 * SECOND_US, EXIT_REASONS["fallback"])
    k0 = int(np.searchsorted(data.tts, post, side="right"))
    k1 = int(np.searchsorted(data.tts, fill_ts + 90 * SECOND_US, side="right"))
    window = data.tpx[k0:k1]
    hit = (window > price + tol) if side > 0 else (window < price - tol)
    if window.size and hit.any():
        return int(data.tts[k0 + int(np.argmax(hit))]), float(price), EXIT_REASONS["passive"], policy.maker_bp
    return _market_exit(data, policy, side, fill_ts + 90 * SECOND_US, EXIT_REASONS["fallback"])
