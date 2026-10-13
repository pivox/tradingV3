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

ENTRIES = ("E1", "E2", "E3")  # E3 (r9): at the touch with the queue fill rule
QUEUE_ORDER_NOTIONAL = 250.0  # own size of an E3 order, in USDT (r9 §3)
EXITS = ("X1", "X2", "X3", "X4", "Y1", "Y2", "Y3", "Y4", "Z1", "Z2", "Z3", "Z4", "B1", "B2", "B3", "B4")
# Exit parameters: time exits (seconds), stop/target (sigma window, stop multiple, target multiple,
# maximum hold), passive exits (post after, TTL, fallback at).
TIME_EXITS = {"X1": 60, "X2": 300, "Y1": 900, "Y2": 3600, "Z1": 3600}
BRACKET_EXITS = {"X3": (60, 1.0, 1.5, 300), "Y3": (300, 1.5, 3.0, 3600), "Z4": (300, 2.0, 4.0, 7200)}
PASSIVE_EXITS = {"X4": (60, 30, 90), "Y4": (900, 60, 960), "Z2": (1800, 300, 3600), "Z3": (3600, 300, 3900)}
# r10 (PREREGISTRATION-r10.md §3), the 1.2.0 geometries on the platform's setups: stop at 1.5 × ATR14(5m) of the
# decision second (`atr5` feature, floor two ticks, a print at or beyond it, taker at the next touch); target as an
# R multiple of the stop distance, maker (resting limit, through rule) or taker (a print at or beyond, taker at the
# touch), None for the time-only exit; 2 h maximum hold and a taker exit no later than 23:59:00 UTC of the fill's day.
SETUP_EXITS = {"B1": (1.8, "taker"), "B2": (1.8, "maker"), "B3": (3.0, "maker"), "B4": (None, None)}
SETUP_STOP_ATR = 1.5
SETUP_MAX_HOLD_S = 7200
SETUP_DAY_CLOSE_S = 86400 - 60
SETUP_TTL_US = 45_000_000  # scalping@1.1.0 order_policy.ttl_seconds
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
        # Contiguous copies: a field view of a structured array is strided, and searchsorted
        # copies the whole array on every call (0.17 s per call on 12 M prints).
        self.qts, self.bid, self.ask = (np.ascontiguousarray(quotes[name]) for name in ("ts", "bid", "ask"))
        self.tts, self.tpx = np.ascontiguousarray(trades["ts"]), np.ascontiguousarray(trades["price"])
        self.tsz, self.tbuy = np.ascontiguousarray(trades["size"]), np.ascontiguousarray(trades["taker_buy"])  # E3 queue rule
        self.instrument = instrument
        self.start_s = int(start_s)
        self.n = bars["mid"].size
        self.ticks = instrument.tick_array(np.where(np.isfinite(bars["bid"]), bars["bid"], 1.0))
        self._index_cache: dict[tuple[int, int], tuple[np.ndarray, np.ndarray, np.ndarray]] = {}

    def indices(self, latency_us: int, ttl_us: int) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
        """Per decision second: quote index at activation, first print index after activation,
        print index bound at the TTL (searchsorted once for the whole series)."""
        key = (latency_us, ttl_us)
        if key not in self._index_cache:
            end_us = (self.start_s + np.arange(self.n, dtype=np.int64) + 1) * SECOND_US
            quote_at_activation = np.searchsorted(self.qts, end_us + latency_us, side="right") - 1
            first_after_activation = np.searchsorted(self.tts, end_us + latency_us, side="right")
            bound_at_ttl = np.searchsorted(self.tts, end_us + ttl_us, side="right")
            self._index_cache[key] = (quote_at_activation, first_after_activation, bound_at_ttl)
        return self._index_cache[key]


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
    bars = data.bars
    counters = Counters()
    rows: list[tuple] = []
    busy_until = -1
    tol_scale = 1e-6
    ttl_s = policy.ttl_us // SECOND_US
    quote_at_activation, first_after_activation, bound_at_ttl = data.indices(policy.latency_us, policy.ttl_us)
    mids, ages, bids, asks, ticks = bars["mid"], bars["quote_age"], bars["bid"], bars["ask"], data.ticks
    inside = policy.entry == "E2"
    queue = policy.entry == "E3"
    displayed = (bars.get("bid_size") if side > 0 else bars.get("ask_size")) if queue else None
    opposite_taker = 0 if side > 0 else 1  # a resting bid is hit by taker sells, a resting ask by taker buys
    atr5 = data.features.get("atr5") if policy.exit in SETUP_EXITS else None
    for s in decisions.tolist():
        if s < busy_until or s >= data.n:
            continue
        if atr5 is not None and not atr5[s] == atr5[s]:
            continue  # no 5-minute ATR yet (warm-up, or a random control second before the first close)
        counters.decisions += 1
        mid = mids[s]
        if not mid == mid or ages[s] > policy.stale_s:  # NaN check without a numpy call
            continue
        b, a = float(bids[s]), float(asks[s])
        tick = float(ticks[s])
        tol = tick * tol_scale
        if inside and (a - b) >= 2 * tick - tol:
            price = b + tick if side > 0 else a - tick
        else:
            price = b if side > 0 else a
        price = round(price / tick) * tick
        iq = int(quote_at_activation[s])
        if iq < 0:
            continue
        counters.orders += 1
        if (side > 0 and price >= data.ask[iq] - tol) or (side < 0 and price <= data.bid[iq] + tol):
            counters.crossed += 1
            busy_until = s + 1 + policy.cancel_block_s
            continue
        j0 = int(first_after_activation[s])
        j1 = int(bound_at_ttl[s])
        window = data.tpx[j0:j1]
        hit = (window < price - tol) if side > 0 else (window > price + tol)
        if queue and displayed is not None and window.size:
            ahead = float(displayed[s])
            if ahead == ahead:  # a finite displayed quantity: the queue rule applies alongside the through rule
                at_price = (np.abs(window - price) <= tol) & (data.tbuy[j0:j1] == opposite_taker)
                taken = np.cumsum(np.where(at_price, data.tsz[j0:j1], 0.0))
                hit = hit | (taken >= ahead + QUEUE_ORDER_NOTIONAL / price)
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


def _setup_exit(data: SeriesData, policy: Policy, side: int, s: int, entry: float, jf: int, fill_ts: int, tick: float, tol: float) -> tuple[int, float | None, int, float]:
    """r10 B exits: ATR stop, R-multiple target (maker through rule or taker at the touch), 2 h
    hold, UTC day close; the stop wins over the target within the same second."""
    target_r, role = SETUP_EXITS[policy.exit]
    distance = max(SETUP_STOP_ATR * float(data.features["atr5"][s]), 2 * tick)
    stop = entry - side * distance
    day_close = (fill_ts // (86400 * SECOND_US)) * 86400 * SECOND_US + SETUP_DAY_CLOSE_S * SECOND_US
    deadline = min(fill_ts + SETUP_MAX_HOLD_S * SECOND_US, max(day_close, fill_ts))
    k0 = jf + 1
    k1 = int(np.searchsorted(data.tts, deadline, side="right"))
    window = data.tpx[k0:k1]
    stop_hit = (window <= stop + tol) if side > 0 else (window >= stop - tol)
    ks = int(np.argmax(stop_hit)) if stop_hit.any() else -1
    kt, target = -1, None
    if target_r is not None:
        target = entry + side * target_r * distance
        target = (np.ceil(target / tick) if side > 0 else np.floor(target / tick)) * tick
        if role == "maker":
            target_hit = (window > target + tol) if side > 0 else (window < target - tol)
        else:
            target_hit = (window >= target - tol) if side > 0 else (window <= target + tol)
        kt = int(np.argmax(target_hit)) if target_hit.any() else -1
    if ks >= 0 and (kt < 0 or data.tts[k0 + ks] // SECOND_US <= data.tts[k0 + kt] // SECOND_US):
        return _market_exit(data, policy, side, int(data.tts[k0 + ks]), EXIT_REASONS["stop"])
    if kt >= 0:
        if role == "maker":
            return int(data.tts[k0 + kt]), float(target), EXIT_REASONS["target"], policy.maker_bp
        return _market_exit(data, policy, side, int(data.tts[k0 + kt]), EXIT_REASONS["target"])
    return _market_exit(data, policy, side, deadline, EXIT_REASONS["time"])


def _exit(data: SeriesData, policy: Policy, side: int, s: int, entry: float, jf: int, fill_ts: int, tick: float, tol: float) -> tuple[int, float | None, int, float]:
    if policy.exit in TIME_EXITS:
        return _market_exit(data, policy, side, fill_ts + TIME_EXITS[policy.exit] * SECOND_US, EXIT_REASONS["time"])
    if policy.exit in SETUP_EXITS:
        return _setup_exit(data, policy, side, s, entry, jf, fill_ts, tick, tol)
    if policy.exit in BRACKET_EXITS:
        window_s, stop_k, target_k, max_hold = BRACKET_EXITS[policy.exit]
        sigma = float(data.features[f"sigma_{window_s}"][s])
        if not np.isfinite(sigma):
            sigma = 2 * tick / entry
        distance = max(stop_k * sigma * entry, 2 * tick)
        stop = entry - side * distance
        target = entry + side * (target_k / stop_k) * distance
        target = (np.ceil(target / tick) if side > 0 else np.floor(target / tick)) * tick
        deadline = fill_ts + max_hold * SECOND_US
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
    post_after, ttl_s, fallback_at = PASSIVE_EXITS[policy.exit]
    post = fill_ts + post_after * SECOND_US + policy.latency_us
    price = _touch(data, post, want_bid=side < 0)  # a long posts its sell at the ask, a short its buy at the bid
    if price is None:
        return _market_exit(data, policy, side, fill_ts + fallback_at * SECOND_US, EXIT_REASONS["fallback"])
    k0 = int(np.searchsorted(data.tts, post, side="right"))
    k1 = int(np.searchsorted(data.tts, post + ttl_s * SECOND_US, side="right"))
    window = data.tpx[k0:k1]
    hit = (window > price + tol) if side > 0 else (window < price - tol)
    if window.size and hit.any():
        return int(data.tts[k0 + int(np.argmax(hit))]), float(price), EXIT_REASONS["passive"], policy.maker_bp
    return _market_exit(data, policy, side, fill_ts + fallback_at * SECOND_US, EXIT_REASONS["fallback"])
