"""scalp-q3-r2 engine (PREREGISTRATION-scalp-q3-r2.md §3, §4, §6): order outcomes of every second.

For a decision at the end of second `s`, the outcome of ONE order placed there — independent of
whatever the series or the portfolio holds — is computed exactly as `scalp_engine.run_policy`
does for an order it places (entry E1, through rule, 250 ms latency, 30 s TTL, exit Z3 by
`scalp_engine._exit`), plus the protected exit Z3-SL200 (a stop-market 200 bp under the entry,
armed from the fill), the funding paid across settlements and the quantity that traded through
the order's price. The one-position-per-pair replay (`standalone`) and the portfolio
(`q3r2_portfolio`) then read these outcomes; `standalone` on the table reproduces `run_policy`
trade for trade (tested, and checked on every series at run time).

Nothing here reads beyond the decision second to decide whether to trade: the outcome fields
are consequences of the order, used only once the order exists.
"""

from __future__ import annotations

import numpy as np

from .scalp_engine import EXIT_REASONS, PASSIVE_EXITS, Policy, SeriesData, _exit
from .tick import SECOND_US

STOP_BP = 200.0  # §4: one stop level, from the q3x MAE observation (exploratory, declared as such)
CONFIGS = ("C1", "C2", "C3", "C4")
CONFIG_RULES = {"C1": "S7-W900-K1.5", "C2": "Q3-W900-K1.5-T0.1", "C3": "S7L-W900-K1.5", "C4": "Q3L-W900-K1.5-T0.1"}
CONFIG_SIGMA = {"C1": "sigma_900", "C2": "sigma_900", "C3": "sigma_900_live", "C4": "sigma_900_live"}
CONFIG_DEPTH = {"C1": False, "C2": True, "C3": False, "C4": True}
BAND_K = 1.5
DEPTH_T = 0.1
VARIANTS = ("Z3", "SL")  # SL = Z3-SL200
NONE, CROSSED, UNFILLED, FILLED, DEAD = 0, 1, 2, 3, 4
STOP = EXIT_REASONS["stop"]
PASSIVE = EXIT_REASONS["passive"]

OUTCOME_FIELDS = (
    ("status", np.int8), ("price", np.float64), ("fill_ts", np.int64), ("thr_qty", np.float64),
    ("z_exit_ts", np.int64), ("z_exit", np.float64), ("z_reason", np.int8), ("z_fund_bp", np.float64), ("z_busy", np.int32),
    ("s_exit_ts", np.int64), ("s_exit", np.float64), ("s_reason", np.int8), ("s_fund_bp", np.float64), ("s_busy", np.int32),
    ("s_trigger", np.float64), ("s_slip_bp", np.float64), ("fund_missing", np.int8),
)


def config_id(config: str, variant: str) -> str:
    return f"{config}-{variant}"


def stop_trigger(entry: float, tick: float, stop_bp: float = STOP_BP) -> float:
    """Trigger on the tick grid, rounded down (further from the entry)."""
    return float(np.floor(entry * (1.0 - stop_bp / 1e4) / tick + 1e-9) * tick)


class Funding:
    """Settlements of one symbol: `ts_us` (sorted), `rate`, and the window's missing intervals."""

    def __init__(self, ts_us: np.ndarray, rate: np.ndarray, interval_h: np.ndarray, start_s: int, end_s: int, month_max_abs: float) -> None:
        order = np.argsort(ts_us, kind="stable")
        self.ts_us = np.asarray(ts_us, dtype=np.int64)[order]
        self.rate = np.asarray(rate, dtype=np.float64)[order]
        self.interval_h = np.asarray(interval_h, dtype=np.float64)[order]
        self.month_max_abs = float(month_max_abs)
        self.gaps = self.missing_intervals(start_s, end_s)

    def missing_intervals(self, start_s: int, end_s: int, margin_h: float = 8.0, slack_s: float = 60.0) -> list[tuple[int, int]]:
        """§6 completeness: within [start − 8 h, end + 10 h] consecutive settlements must be one declared interval
        apart (± 1 min); the coverage must reach both ends. Returns the intervals (µs) where a settlement is missing."""
        lo = int((start_s - margin_h * 3600) * SECOND_US)
        hi = int((end_s + (margin_h + 2) * 3600) * SECOND_US)
        inside = (self.ts_us >= lo) & (self.ts_us <= hi)
        ts, iv = self.ts_us[inside], self.interval_h[inside]
        gaps: list[tuple[int, int]] = []
        if ts.size == 0:
            return [(lo, hi)]
        if ts[0] - lo > (iv[0] * 3600 + slack_s) * SECOND_US:
            gaps.append((lo, int(ts[0])))
        for a, b, step in zip(ts[:-1], ts[1:], iv[1:]):
            if b - a > (step * 3600 + slack_s) * SECOND_US:
                gaps.append((int(a), int(b)))
        if hi - ts[-1] > (iv[-1] * 3600 + slack_s) * SECOND_US:
            gaps.append((int(ts[-1]), hi))
        return gaps


def funding_bp(funding: Funding | None, tts: np.ndarray, tpx: np.ndarray, fill_ts: np.ndarray, exit_ts: np.ndarray, entry: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Funding paid by a long across the settlements `fill < T ≤ exit`, in bp of the entry notional:
    Σ rate × P_T / entry × 1e4 (P_T the last print at or before T). A trade overlapping a missing
    interval is charged the month's largest absolute rate once (adverse, §6) and flagged."""
    n = fill_ts.size
    if funding is None:
        return np.full(n, np.nan), np.ones(n, dtype=np.int8)
    if funding.ts_us.size:
        ip = np.searchsorted(tts, funding.ts_us, side="right") - 1
        price = np.where(ip >= 0, tpx[np.maximum(ip, 0)], np.nan)
        price = np.where(np.isfinite(price), price, tpx[0] if tpx.size else np.nan)
        cum = np.concatenate([[0.0], np.cumsum(funding.rate * price)])
        i0 = np.searchsorted(funding.ts_us, fill_ts, side="right")
        i1 = np.searchsorted(funding.ts_us, exit_ts, side="right")
        out = (cum[i1] - cum[i0]) / entry * 1e4
    else:
        out = np.zeros(n)
    missing = np.zeros(n, dtype=np.int8)
    for a, b in funding.gaps:
        hit = (fill_ts < b) & (exit_ts > a)
        out = np.where(hit, out + funding.month_max_abs * 1e4, out)
        missing |= hit.astype(np.int8)
    return out, missing


def _bid_at(data: SeriesData, moment: int) -> float | None:
    iq = int(np.searchsorted(data.qts, moment, side="right")) - 1
    return float(data.bid[iq]) if iq >= 0 else None


def stop_exit(data: SeriesData, policy: Policy, entry: float, jf: int, fill_ts: int, tick: float, tol: float, deadline_us: int, stop_bp: float = STOP_BP) -> tuple[int, float, float, float] | None:
    """First print after the fill print at or below the trigger, strictly before `deadline_us`;
    executed at market `latency` later at min(trigger print, prints within the latency, live bid).
    Returns (exit moment, exit price, trigger, slippage bp below the trigger) or None."""
    trigger = stop_trigger(entry, tick, stop_bp)
    start_us = data.start_s * SECOND_US
    lows = data.bars["low"]
    tts, tpx = data.tts, data.tpx
    dl_idx = int(np.searchsorted(tts, deadline_us, side="left"))  # prints strictly before the deadline
    fsec = int((fill_ts - start_us) // SECOND_US)
    next_idx = int(np.searchsorted(tts, start_us + (fsec + 1) * SECOND_US, side="left"))
    hi = min(next_idx, dl_idx)
    k = -1
    if hi > jf + 1:
        hit = tpx[jf + 1:hi] <= trigger + tol
        if hit.any():
            k = jf + 1 + int(np.argmax(hit))
    if k < 0 and dl_idx > next_idx:
        last_sec = min(int((deadline_us - 1 - start_us) // SECOND_US), data.n - 1)
        if last_sec >= fsec + 1:
            with np.errstate(invalid="ignore"):
                candidates = np.flatnonzero(lows[fsec + 1:last_sec + 1] <= trigger + tol)
            for c in candidates.tolist():
                sec = fsec + 1 + c
                lo = int(np.searchsorted(tts, start_us + sec * SECOND_US, side="left"))
                up = min(int(np.searchsorted(tts, start_us + (sec + 1) * SECOND_US, side="left")), dl_idx)
                hit = tpx[lo:up] <= trigger + tol
                if hit.any():
                    k = lo + int(np.argmax(hit))
                    break
    if k < 0:
        return None
    moment = int(tts[k]) + policy.latency_us
    k_end = int(np.searchsorted(tts, moment, side="right"))
    price = float(tpx[k:k_end].min())
    bid = _bid_at(data, moment)
    if bid is not None:
        price = min(price, bid)
    return moment, price, trigger, (trigger - price) / trigger * 1e4


def compute_outcomes(data: SeriesData, policy: Policy, seconds: np.ndarray | None = None, funding: Funding | None = None, stop_bp: float = STOP_BP) -> dict[str, np.ndarray]:
    """Outcome columns for the decision seconds `seconds` (default: every second of the series), long side, E1/Z3 policy."""
    if policy.entry != "E1" or policy.exit != "Z3":
        raise ValueError("scalp-q3-r2 replays E1 / Z3 only")
    seconds = np.arange(data.n, dtype=np.int64) if seconds is None else np.asarray(seconds, dtype=np.int64)
    m = seconds.size
    cols = {name: np.zeros(m, dtype=dtype) for name, dtype in OUTCOME_FIELDS}
    for name in ("price", "z_exit", "s_exit", "s_trigger", "s_slip_bp", "z_fund_bp", "s_fund_bp"):
        cols[name][:] = np.nan
    bars = data.bars
    mids, ages, bids, ticks = bars["mid"], bars["quote_age"], bars["bid"], data.ticks
    quote_at_activation, first_after_activation, bound_at_ttl = data.indices(policy.latency_us, policy.ttl_us)
    tts, tpx, tsz, qask = data.tts, data.tpx, data.tsz, data.ask
    start_us = data.start_s * SECOND_US
    fallback_us = PASSIVE_EXITS["Z3"][2] * SECOND_US
    status, price_c, fill_c, thr_c = cols["status"], cols["price"], cols["fill_ts"], cols["thr_qty"]
    for row, s in enumerate(seconds.tolist()):
        mid = mids[s]
        if not mid == mid or ages[s] > policy.stale_s:
            continue
        b = float(bids[s])
        tick = float(ticks[s])
        tol = tick * 1e-6
        price = round(b / tick) * tick
        iq = int(quote_at_activation[s])
        if iq < 0:
            continue
        price_c[row] = price
        if price >= qask[iq] - tol:
            status[row] = CROSSED
            continue
        j0 = int(first_after_activation[s])
        j1 = int(bound_at_ttl[s])
        window = tpx[j0:j1]
        hit = window < price - tol
        if window.size == 0 or not hit.any():
            status[row] = UNFILLED
            continue
        jf = j0 + int(np.argmax(hit))
        fill_ts = int(tts[jf])
        fill_c[row] = fill_ts
        thr_c[row] = float(tsz[j0:j1][hit].sum())
        exit_ts, exit_price, reason, _ = _exit(data, policy, 1, s, price, jf, fill_ts, tick, tol)
        if exit_price is None:
            status[row] = DEAD
            continue
        status[row] = FILLED
        cols["z_exit_ts"][row], cols["z_exit"][row], cols["z_reason"][row] = exit_ts, exit_price, reason
        cols["z_busy"][row] = int((exit_ts - start_us) // SECOND_US) + 1
        deadline = exit_ts if reason == PASSIVE else fill_ts + fallback_us
        stop = stop_exit(data, policy, price, jf, fill_ts, tick, tol, deadline, stop_bp)
        if stop is None:
            s_exit_ts, s_exit, s_reason = exit_ts, exit_price, reason
        else:
            s_exit_ts, s_exit, cols["s_trigger"][row], cols["s_slip_bp"][row] = stop
            s_reason = STOP
        cols["s_exit_ts"][row], cols["s_exit"][row], cols["s_reason"][row] = s_exit_ts, s_exit, s_reason
        cols["s_busy"][row] = int((s_exit_ts - start_us) // SECOND_US) + 1
    filled = status == FILLED
    if filled.any():
        for v in ("z", "s"):
            fb, miss = funding_bp(funding, tts, tpx, fill_c[filled], cols[f"{v}_exit_ts"][filled], price_c[filled])
            cols[f"{v}_fund_bp"][filled] = fb
            cols["fund_missing"][filled] |= miss
    return cols


def fees_bp(reason: np.ndarray, maker_bp: float, taker_bp: float) -> np.ndarray:
    """Entry maker + exit maker (passive) or taker (fallback, stop): the engine's fee rule."""
    return maker_bp + np.where(reason == PASSIVE, maker_bp, taker_bp)


def standalone(cols: dict[str, np.ndarray], signal: np.ndarray, variant: str, n: int, policy: Policy, count_decisions: bool = False) -> tuple[np.ndarray, dict]:
    """One order at a time per series (the `run_policy` busy rule) over the sorted decision
    seconds `signal`, whose outcome rows are `cols[...][s]` (a full-length table). Returns the
    seconds of the orders that filled and the counters."""
    status = cols["status"]
    busy_col = cols["z_busy" if variant == "Z3" else "s_busy"]
    ttl_s = policy.ttl_us // SECOND_US
    counters = {"decisions": 0, "orders": 0, "crossed": 0, "unfilled": 0, "filled": 0}
    filled: list[int] = []
    if not count_decisions:
        signal = signal[status[signal] != NONE]
    idx, size, busy = 0, signal.size, -1
    while idx < size:
        s = int(signal[idx])
        if s >= n:
            break
        counters["decisions"] += 1
        st = int(status[s])
        if st == NONE:
            idx += 1
            continue
        counters["orders"] += 1
        if st == CROSSED:
            counters["crossed"] += 1
            busy = s + 1 + policy.cancel_block_s
        elif st == UNFILLED:
            counters["unfilled"] += 1
            busy = s + 1 + ttl_s
        elif st == DEAD:
            counters["filled"] += 1
            busy = n
        else:
            counters["filled"] += 1
            filled.append(s)
            busy = int(busy_col[s])
        idx = int(np.searchsorted(signal, busy, side="left"))
    return np.asarray(filled, dtype=np.int64), counters


def trade_rows(cols: dict[str, np.ndarray], filled: np.ndarray, variant: str, series_id: int, start_s: int, policy: Policy) -> dict[str, np.ndarray]:
    """Trade columns of the filled orders: the `scalp_engine.TRADE_DTYPE` fields plus funding and stop data."""
    p = "z" if variant == "Z3" else "s"
    entry = cols["price"][filled]
    exit_ = cols[f"{p}_exit"][filled]
    reason = cols[f"{p}_reason"][filled]
    gross = (exit_ - entry) / entry * 1e4
    exit_fee = np.where(reason == PASSIVE, policy.maker_bp, policy.taker_bp)
    fees = policy.maker_bp + exit_fee
    fill_ts = cols["fill_ts"][filled]
    exit_ts = cols[f"{p}_exit_ts"][filled]
    return {
        "series": np.full(filled.size, series_id, dtype=np.int16), "s": filled, "decision_s": start_s + filled, "fill_ts": fill_ts,
        "entry": entry, "exit_ts": exit_ts, "exit": exit_, "exit_reason": reason, "gross_bp": gross, "fees_bp": fees, "net_bp": gross - fees,
        "hold_s": (exit_ts - fill_ts) / 1e6, "fund_bp": cols[f"{p}_fund_bp"][filled], "slip_bp": cols["s_slip_bp"][filled] if p == "s" else np.full(filled.size, np.nan),
        "thr_qty": cols["thr_qty"][filled],
    }


def path_marks(data: SeriesData, fill_ts: int, exit_ts: int, step_s: int = 60) -> np.ndarray:
    """Live bid (proxy) at every minute after the fill and before the exit (mark-to-market path)."""
    count = int((exit_ts - fill_ts - 1) // (step_s * SECOND_US))
    if count <= 0:
        return np.zeros(0)
    marks = fill_ts + np.arange(1, count + 1, dtype=np.int64) * step_s * SECOND_US
    iq = np.searchsorted(data.qts, marks, side="right") - 1
    return np.where(iq >= 0, data.bid[np.maximum(iq, 0)], np.nan)


def excursions(data: SeriesData, fill_ts: int, exit_ts: int, entry: float) -> tuple[float, float]:
    """MAE / MFE in bp of the entry from the per-second print lows / highs strictly after the fill second, up to the
    exit second (second-level approximation, §11)."""
    start_us = data.start_s * SECOND_US
    a = int((fill_ts - start_us) // SECOND_US) + 1
    b = min(int((exit_ts - start_us) // SECOND_US), data.n - 1)
    if b < a:
        return 0.0, 0.0
    with np.errstate(invalid="ignore"):
        low = np.nanmin(data.bars["low"][a:b + 1]) if np.isfinite(data.bars["low"][a:b + 1]).any() else entry
        high = np.nanmax(data.bars["high"][a:b + 1]) if np.isfinite(data.bars["high"][a:b + 1]).any() else entry
    return float((min(low, entry) / entry - 1) * 1e4), float((max(high, entry) / entry - 1) * 1e4)


def passive_through_qty(data: SeriesData, policy: Policy, fill_ts: int, exit_ts: int, reason: int) -> float:
    """Diagnostic (§4): volume that traded through the passive exit price between its posting and its fill/TTL."""
    if reason != PASSIVE:
        return np.nan
    post_after, ttl_s, _ = PASSIVE_EXITS["Z3"]
    post = fill_ts + post_after * SECOND_US + policy.latency_us
    iq = int(np.searchsorted(data.qts, post, side="right")) - 1
    if iq < 0:
        return np.nan
    ask = float(data.ask[iq])
    k0 = int(np.searchsorted(data.tts, post, side="right"))
    k1 = int(np.searchsorted(data.tts, post + ttl_s * SECOND_US, side="right"))
    window = data.tpx[k0:k1]
    return float(data.tsz[k0:k1][window > ask + ask * 1e-12].sum())
