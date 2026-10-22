"""Dynamic exits on identical entries (spec ``specs/dynamic_exits_v1_1.json``).

Question: with the same S1 (5m momentum burst) entries, the same size and the same initial risk,
does re-evaluating the position at every 5-minute close (B: close-based trailing stop; C:
adaptive exit) improve the net result against the fixed reference A (SL 1 R, TP 1.5 R, 60 min)?

Two comparisons, kept apart:

1. Identical entries (exit effect only). Every S1 decision of both sides of a pair is walked in
   time order; a decision is admitted when the pair is free, whatever the side, and a filled plan
   reserves the pair until decision close + 60 minutes (an unfilled one until the TTL end). The
   three methods are then simulated on exactly these entries, with the same size and risk.
2. Realistic portfolio. Each method walks the full list of S1 candidates (not the table above):
   its own exits release the pair and the exposure, so an early exit makes room for new entries.
   Capital and risk constraints are the budget study's (1 % of equity at risk, open notional
   <= 2 x equity, exchange minimums), identical for the three methods.

Reused from research_v2: the entry fill (``fills.simulate``: limit through by one tick, TTL one
bar), the stop fill price (worse of level and open), tick rounding, plan prices, settlement,
stop risk and cost profiles, the S1 signals, the drawdown tracker of ``budgets``.

New here: the exit loop. Decisions are taken at 5m closes and act from the first 1-minute candle
of the next bar (one more bar in the latency sensitivity). Stops and targets are checked on
1-minute candles, stop first when both are touched in one minute. Every target, initial or
moved, is a resting limit order credited only when the market trades through it by one tick
(the older "initial target on a touch" convention is kept as a compatibility sensitivity). A
stop level that would already be crossed at the decision close cannot rest as a stop: it becomes
a market exit at the next effective open (reason ``stop_crossed``), never a fill at the level.

Data: nothing is read by this module unless ``command_run`` is called with the owner's
authorization reference, a frozen spec and inputs whose hashes match the spec; the window is
limited to the authorized exploration period. Unknown funding aborts the run instead of
becoming zero.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path

import numpy as np

from . import budgets as budgets_module
from . import costs, stats
from .bars import MINUTE_MS, Bars, Candles
from .engine import OUTCOME_DTYPE, REFERENCE_NOTIONAL, FUNDING_INTERVAL_MS, _round_to_tick, plan_prices_from_distance
from .fills import PlanSpec, _stop_fill_price, simulate

SPEC_DIR = Path(__file__).parent / "specs"
SPEC_PATH = SPEC_DIR / "dynamic_exits_v1_1.json"
SPEC_HASH_PATH = SPEC_DIR / "dynamic_exits_v1_1.sha256"
DAY_MS = 86_400_000
METHODS = ("A", "B", "C")
TARGET_MODES = ("through", "legacy")
PROFILES = ("baseline", "adverse", "doubled")
# Owner's written authorization of 2026-10-21: Binance USD-M 10 majors, 2023-01-01 -> 2025-01-01
# (1-minute candles, funding, instrument metadata), development only, never a validation.
AUTHORIZED_EXPLORATION_MS = (1_672_531_200_000, 1_735_689_600_000)

# Exit reasons of this module and the settlement reason they map to (costs.settle).
SETTLE_REASON = {
    "stop": "stop",
    "trailing": "trailing",  # a stop that was moved at least once
    "target": "target",
    "time": "time",
    "window_end": "window_end",
    "invalidation": "indicator",  # market exits: taker + stop spread + stop slippage
    "stall": "indicator",
    "stop_crossed": "indicator",
}


class DataError(RuntimeError):
    """A required input is missing or unknown; the run stops instead of assuming a value."""


def _column(profile: str) -> str:
    return "base" if profile == "baseline" else profile


# --- spec ---------------------------------------------------------------------------------


def spec_sha256(path: Path = SPEC_PATH) -> str:
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def load_spec(path: Path = SPEC_PATH, hash_path: Path | None = SPEC_HASH_PATH) -> dict:
    """The versioned spec; refuses a file whose sha256 differs from the recorded one."""
    if hash_path is not None:
        recorded = Path(hash_path).read_text().split()[0]
        actual = spec_sha256(path)
        if recorded != actual:
            raise ValueError(f"dynamic exits spec hash mismatch: recorded {recorded}, actual {actual}")
    return json.loads(Path(path).read_text())


@dataclass(frozen=True)
class MethodParams:
    """The single parameter set of one method (read from the spec)."""

    name: str
    target_r: float | None
    # B
    activation_r: float | None = None
    trail_atr_entry: float | None = None
    # C
    invalidation_u: float | None = None
    invalidation_rho: float | None = None
    giveback_mfe: float | None = None
    giveback_r: float | None = None
    giveback_atr: float | None = None
    stall_bars: int | None = None
    stall_u: float | None = None
    favourable_u: float | None = None
    extension_r: float | None = None
    cap_r: float | None = None


def method_params(spec: dict) -> dict[str, MethodParams]:
    m = spec["methods"]
    c = m["C"]
    return {
        "A": MethodParams("A", m["A"]["target_r"]),
        "B": MethodParams("B", None, activation_r=m["B"]["activation_best_close_r"], trail_atr_entry=m["B"]["trail_atr_entry"]),
        "C": MethodParams(
            "C",
            c["target_r"],
            invalidation_u=c["invalidation"]["u_max"],
            invalidation_rho=c["invalidation"]["atr_ratio_min"],
            giveback_mfe=c["exhaustion"]["giveback_mfe_min"],
            giveback_r=c["exhaustion"]["giveback_r"],
            giveback_atr=c["exhaustion"]["giveback_stop_atr_current"],
            stall_bars=c["exhaustion"]["stall_min_bars"],
            stall_u=c["exhaustion"]["stall_abs_u_max"],
            favourable_u=c["favourable"]["u_min"],
            extension_r=c["favourable"]["target_extension_r"],
            cap_r=c["favourable"]["target_cap_r"],
        ),
    }


def profiles_with_doubled(profiles: dict[str, costs.CostProfile]) -> dict[str, costs.CostProfile]:
    out = dict(profiles)
    if "doubled" not in out:
        out["doubled"] = costs.doubled_spread(profiles["baseline"])
    return out


# --- candidates and the common admission --------------------------------------------------

CANDIDATE_DTYPE = np.dtype(
    [
        ("symbol", "U10"),
        ("side", "i1"),
        ("decision_index", "i8"),
        ("decision_ms", "i8"),
        ("filled", "?"),
        ("fill_index", "i8"),
        ("fill_ms", "i8"),
        ("order_end_ms", "i8"),  # decision close + TTL: an unfilled order is cancelled then
        ("end_index", "i8"),  # exclusive end of the 60-minute horizon (1m candle index)
        ("end_reason", "U12"),  # time | window_end
        ("diag_end_index", "i8"),  # exclusive end of the common 120-minute diagnostic horizon
        ("diag_truncated", "?"),
        ("entry", "f8"),
        ("stop", "f8"),
        ("target_a", "f8"),
        ("r_price", "f8"),  # |entry - stop|
        ("atr_entry", "f8"),
        ("tick", "f8"),
        ("quantity", "f8"),  # at the reference notional
        ("risk_base", "f8"),
        ("risk_adverse", "f8"),
        ("risk_doubled", "f8"),
    ]
)
ENTRY_DTYPE = CANDIDATE_DTYPE


def _funding_intervals(spec: dict) -> int:
    horizon_ms = int(spec["entry"]["horizon_minutes"]) * MINUTE_MS
    return (horizon_ms // 1000 - 1) // (FUNDING_INTERVAL_MS // 1000) + 1


def build_candidates(
    candles: Candles,
    bars: Bars,
    atr: np.ndarray,
    decisions_by_side: dict[int, np.ndarray],
    instrument: costs.Instrument,
    end_ms: int,
    spec: dict,
    profiles: dict[str, costs.CostProfile],
) -> tuple[np.ndarray, dict]:
    """Every S1 decision of the pair (both sides), with its entry fill or its expiry.

    A bar carrying both a long and a short decision is a conflict: both are dropped and counted.
    """
    profiles = profiles_with_doubled(profiles)
    entry_spec = spec["entry"]
    horizon_ms = int(entry_spec["horizon_minutes"]) * MINUTE_MS
    ttl_ms = int(entry_spec["ttl_minutes"]) * MINUTE_MS
    diag_ms = int(spec["diagnostics"]["common_horizon_minutes_after_fill"]) * MINUTE_MS
    stop_atr = float(entry_spec["initial_stop_atr"])
    target_r = float(spec["methods"]["A"]["target_r"])
    tick = instrument.tick_size
    intervals = _funding_intervals(spec)
    long_set = set(int(i) for i in np.asarray(decisions_by_side.get(1, ()), dtype=np.int64))
    short_set = set(int(i) for i in np.asarray(decisions_by_side.get(-1, ()), dtype=np.int64))
    conflicts = long_set & short_set
    merged = sorted([(i, 1) for i in long_set - conflicts] + [(i, -1) for i in short_set - conflicts])
    counters = {"decisions": len(long_set) + len(short_set), "side_conflicts_dropped": 2 * len(conflicts), "atr_invalid": 0, "unsized": 0, "filled": 0, "unfilled": 0}
    rows = []
    for index, side in merged:
        close_ms = int(bars.close_ms[index])
        atr_value = float(atr[index])
        if not (atr_value > 0 and math.isfinite(atr_value)):
            counters["atr_invalid"] += 1
            continue
        entry, stop, target = plan_prices_from_distance(side, float(bars.close[index]), stop_atr * atr_value, target_r, tick, "limit_through")
        quantity = costs.round_quantity(REFERENCE_NOTIONAL, entry, instrument)
        if quantity <= 0:
            counters["unsized"] += 1
            continue
        plan = PlanSpec(candles.symbol, side, int(index), entry, stop, target, None, horizon_ms, ttl_ms, entry_mode="limit_through", through=tick * int(entry_spec["through_ticks"]))
        result = simulate(candles, bars, plan, end_ms)
        risks = [costs.stop_risk_quote(side, entry, stop, quantity, intervals, profiles[name], "maker") for name in PROFILES]
        deadline = min(close_ms + horizon_ms, end_ms)
        end_index = int(np.searchsorted(candles.open_ms, deadline, side="left"))
        if result.filled:
            counters["filled"] += 1
            diag_deadline = int(candles.open_ms[result.fill_index]) + diag_ms
            diag_end = int(np.searchsorted(candles.open_ms, min(diag_deadline, end_ms), side="left"))
            fill_index, fill_ms, truncated = result.fill_index, result.fill_ms, diag_deadline > end_ms
        else:
            counters["unfilled"] += 1
            fill_index = fill_ms = diag_end = -1
            truncated = False
        rows.append(
            (
                candles.symbol,
                side,
                int(index),
                close_ms,
                bool(result.filled),
                fill_index,
                fill_ms,
                close_ms + ttl_ms,
                end_index,
                "time" if close_ms + horizon_ms <= end_ms else "window_end",
                diag_end,
                truncated,
                entry,
                stop,
                target,
                abs(entry - stop),
                atr_value,
                tick,
                quantity,
                *risks,
            )
        )
    table = np.array(rows, dtype=CANDIDATE_DTYPE) if rows else np.zeros(0, dtype=CANDIDATE_DTYPE)
    return table, counters


def common_admission(candidates: np.ndarray, spec: dict) -> np.ndarray:
    """Comparison 1: one plan per pair whatever the side; a filled plan reserves the pair until
    decision close + horizon (every method is flat by then), an unfilled one until the TTL end."""
    horizon_ms = int(spec["entry"]["horizon_minutes"]) * MINUTE_MS
    admitted = np.zeros(candidates.size, dtype=bool)
    busy: dict[str, int] = {}
    for i in np.lexsort((candidates["side"], candidates["decision_ms"], candidates["symbol"])):
        symbol = str(candidates["symbol"][i])
        t = int(candidates["decision_ms"][i])
        if t < busy.get(symbol, -1):
            continue
        admitted[i] = True
        busy[symbol] = t + horizon_ms if candidates["filled"][i] else int(candidates["order_end_ms"][i])
    return admitted


def build_entry_table(candles, bars, atr, decisions_by_side, instrument, end_ms, spec, profiles) -> np.ndarray:
    """The identical-entry table of one pair (comparison 1): admitted and filled candidates."""
    candidates, _ = build_candidates(candles, bars, atr, decisions_by_side, instrument, end_ms, spec, profiles)
    admitted = common_admission(candidates, spec)
    return candidates[admitted & candidates["filled"]]


# --- exit simulation ----------------------------------------------------------------------


@dataclass(frozen=True)
class Decision:
    """A change decided at the close of ``bar`` and applied from candle ``effective_index``."""

    bar: int
    effective_index: int
    kind: str  # stop | target | market_exit
    value: float  # new level (market_exit: NaN)
    reason: str


@dataclass
class ExitResult:
    method: str
    exit_index: int
    exit_ms: int
    exit_price: float
    reason: str
    decisions: list[Decision] = field(default_factory=list)
    stop_path: list[float] = field(default_factory=list)  # stop in force at each monitored candle
    best_close: float = float("nan")


def breakeven_level(side: int, entry: float, profile: costs.CostProfile, tick: float) -> float:
    """Exit price at which a maker entry and a stop-like (taker) exit net zero under the assumed
    costs of ``profile`` (fees, spread, slippage: about 14 bp for the baseline), rounded against
    the position. An assumption about costs, not a guarantee: a gap or a worse slippage than
    assumed still loses money."""
    entry_rate = profile.entry_fee_rate + profile.entry_spread_rate + profile.entry_slippage_rate
    exit_rate = profile.exit_fee_rate + profile.stop_spread_rate + profile.stop_slippage_rate
    if side == 1:
        raw = entry * (1.0 + entry_rate) / (1.0 - exit_rate)
        return _round_to_tick(raw, tick, "up")
    raw = entry * (1.0 - entry_rate) / (1.0 + exit_rate)
    return _round_to_tick(raw, tick, "down")


def _tighter(side: int, current: float, candidate: float) -> float:
    """The stop never loosens: max for a long, min for a short."""
    return max(current, candidate) if side == 1 else min(current, candidate)


def _stop_or_crossed(side: int, bar: int, when: int, stop: float, candidate: float, close: float, tick: float, reason: str) -> Decision | None:
    """A tighter stop when it can rest (at least one tick on the protective side of the decision
    close); otherwise the level is already crossed and the position exits at market next."""
    if _tighter(side, stop, candidate) == stop:
        return None
    if side * (close - candidate) >= tick * (1.0 - 1e-6):
        return Decision(bar, when, "stop", candidate, reason)
    return Decision(bar, when, "market_exit", float("nan"), "stop_crossed")


def simulate_exit(
    candles: Candles,
    bars: Bars,
    atr: np.ndarray,
    row,
    params: MethodParams,
    breakeven_profile: costs.CostProfile,
    latency_bars: int = 0,
    target_mode: str = "through",
) -> ExitResult:
    """Simulate one method on one filled candidate (a ``CANDIDATE_DTYPE`` record).

    ``target_mode``: ``through`` (main) credits every target only on a one-tick trade-through;
    ``legacy`` credits the initial target on a touch (research_v2 fills convention) and a moved
    target on a trade-through.
    """
    if target_mode not in TARGET_MODES:
        raise ValueError(f"unknown target mode {target_mode!r}")
    side = int(row["side"])
    entry = float(row["entry"])
    r_price = float(row["r_price"])
    tick = float(row["tick"])
    atr_entry = float(row["atr_entry"])
    fill_index = int(row["fill_index"])
    end = int(row["end_index"])
    decision_index = int(row["decision_index"])
    stop = float(row["stop"])
    through = tick * (1.0 - 1e-6)  # one tick through, float-safe
    stop_moved = False
    target = None
    target_from = fill_index + 1  # never credited on the fill candle
    target_through = through if target_mode == "through" else 0.0
    if params.target_r is not None:
        target = _round_to_tick(entry + side * params.target_r * r_price, tick, "up" if side == 1 else "down")
    cap = entry + side * params.cap_r * r_price if params.cap_r is not None else None
    breakeven = breakeven_level(side, entry, breakeven_profile, tick)
    pending: list[Decision] = []
    decisions: list[Decision] = []
    stop_path: list[float] = []
    best_close = float("nan")
    closes_seen = 0
    bar_of = _bar_lookup(bars, fill_index, end)

    def effective(bar: int) -> int:
        target_bar = bar + 1 + latency_bars
        return int(bars.first_minute[target_bar]) if target_bar < bars.size else end

    for m in range(fill_index, end):
        # Apply decisions whose effect starts at this candle.
        market_exit: Decision | None = None
        still = []
        for d in pending:
            if d.effective_index > m:
                still.append(d)
                continue
            if d.kind == "stop":
                new = _tighter(side, stop, d.value)
                if new != stop:
                    stop, stop_moved = new, True
            elif d.kind == "target":
                target, target_from, target_through = d.value, d.effective_index, through
            elif d.kind == "market_exit" and market_exit is None:
                market_exit = d
        pending = still
        if market_exit is not None:
            return ExitResult(params.name, m, int(candles.open_ms[m]) + MINUTE_MS, float(candles.open[m]), market_exit.reason, decisions, stop_path, best_close)
        stop_path.append(stop)
        low, high = float(candles.low[m]), float(candles.high[m])
        stop_hit = low <= stop if side == 1 else high >= stop
        target_hit = False
        if target is not None and m >= target_from:
            level = target + side * target_through
            target_hit = high >= level if side == 1 else low <= level
        if stop_hit:  # stop first on ties; fills at the worse of the level and the open
            return ExitResult(params.name, m, int(candles.open_ms[m]) + MINUTE_MS, _stop_fill_price(candles, m, side, stop), "trailing" if stop_moved else "stop", decisions, stop_path, best_close)
        if target_hit:  # a resting limit fills at its price
            return ExitResult(params.name, m, int(candles.open_ms[m]) + MINUTE_MS, float(target), "target", decisions, stop_path, best_close)
        bar = int(bar_of[m - fill_index])
        if m != int(bars.last_minute[bar]) - 1 or bar <= decision_index or params.name == "A":
            continue
        # 5m close: everything below reads only data up to candle m.
        close = float(bars.close[bar])
        closes_seen += 1
        new_best = math.isnan(best_close) or side * (close - best_close) > 0
        if new_best:
            best_close = close
        u = side * (close - entry) / r_price
        mfe = side * (best_close - entry) / r_price
        atr_now = float(atr[bar])
        when = effective(bar)
        new: list[Decision] = []
        if params.name == "B":
            if mfe >= params.activation_r:
                candidate = _round_to_tick(best_close - side * params.trail_atr_entry * atr_entry, tick, "down" if side == 1 else "up")
                d = _stop_or_crossed(side, bar, when, stop, candidate, close, tick, "trail")
                if d is not None:
                    new.append(d)
        else:  # C
            rho = atr_now / atr_entry if atr_entry > 0 else float("nan")
            stall = closes_seen >= params.stall_bars and abs(u) < params.stall_u
            giveback = mfe >= params.giveback_mfe and u <= mfe - params.giveback_r
            if u <= params.invalidation_u and rho >= params.invalidation_rho:
                new.append(Decision(bar, when, "market_exit", float("nan"), "invalidation"))
            elif stall:
                new.append(Decision(bar, when, "market_exit", float("nan"), "stall"))
            elif giveback:
                candidate = _round_to_tick(close - side * params.giveback_atr * atr_now, tick, "down" if side == 1 else "up")
                d = _stop_or_crossed(side, bar, when, stop, candidate, close, tick, "giveback")
                if d is not None:
                    new.append(d)
            elif u >= params.favourable_u and new_best:
                d = _stop_or_crossed(side, bar, when, stop, breakeven, close, tick, "breakeven")
                if d is not None:
                    new.append(d)
                if d is None or d.kind != "market_exit":
                    extended = best_close + side * params.extension_r * r_price
                    current = target if target is not None else extended
                    wanted = max(current, extended) if side == 1 else min(current, extended)
                    wanted = min(wanted, cap) if side == 1 else max(wanted, cap)
                    wanted = _round_to_tick(wanted, tick, "up" if side == 1 else "down")
                    if target is None or wanted != target:
                        new.append(Decision(bar, when, "target", wanted, "extend"))
        decisions.extend(new)
        pending.extend(new)
    last = end - 1
    return ExitResult(params.name, last, int(candles.open_ms[last]) + MINUTE_MS, float(candles.close[last]), str(row["end_reason"]), decisions, stop_path, best_close)


def _bar_lookup(bars: Bars, start: int, end: int) -> np.ndarray:
    minutes = np.arange(start, max(end, start + 1))
    return np.searchsorted(bars.first_minute, minutes, side="right") - 1


# --- settlement ---------------------------------------------------------------------------


@dataclass(frozen=True)
class PairData:
    candles: Candles
    bars: Bars  # 5m
    atr: np.ndarray  # ATR14 on the 5m bars
    funding: object | None = None  # dataset.Funding
    marks: np.ndarray | None = None
    funding_is_zero_by_construction: bool = False  # synthetic random walks only


def funding_paid_checked(pair: PairData, side: int, quantity: float, fill_ms: int, exit_ms: int, tolerance_ms: int = MINUTE_MS) -> float:
    """Funding over (fill, exit]; every 8-hour funding time in that interval must be present in
    the data (within ``tolerance_ms``), otherwise the funding is unknown and the run stops."""
    if pair.funding_is_zero_by_construction:
        return 0.0
    if pair.funding is None or pair.marks is None:
        raise DataError(f"{pair.candles.symbol}: funding data missing")
    times = pair.funding.time_ms
    first = (fill_ms // FUNDING_INTERVAL_MS + 1) * FUNDING_INTERVAL_MS
    for expected in range(int(first), int(exit_ms) + 1, FUNDING_INTERVAL_MS):
        k = int(np.searchsorted(times, expected - tolerance_ms, side="left"))
        if k >= times.size or int(times[k]) > expected + tolerance_ms or not math.isfinite(float(pair.funding.rate[k])):
            raise DataError(f"{pair.candles.symbol}: funding unknown at {expected} (trade {fill_ms}->{exit_ms})")
    return costs.funding_paid(side, quantity, fill_ms, exit_ms, times, pair.funding.rate, pair.marks)


def settle_exit(row, result: ExitResult, profiles: dict[str, costs.CostProfile], spec: dict, funding_paid_quote: float) -> tuple:
    """An ``OUTCOME_DTYPE`` record (net and risk for baseline, adverse and doubled)."""
    if not math.isfinite(funding_paid_quote):
        raise DataError("funding paid is not finite")
    side, entry, stop, quantity = int(row["side"]), float(row["entry"]), float(row["stop"]), float(row["quantity"])
    reason = SETTLE_REASON[result.reason]
    s = {name: costs.settle(side, entry, result.exit_price, quantity, reason, profiles[name], funding_paid_quote, "maker") for name in PROFILES}
    return (
        str(row["symbol"]),
        side,
        int(row["decision_index"]),
        int(row["decision_ms"]),
        int(row["fill_ms"]),
        result.exit_ms,
        entry,
        stop,
        float(row["target_a"]),
        result.exit_price,
        result.reason,
        float(row["atr_entry"]),
        quantity,
        entry * quantity,
        abs(entry - stop) / entry,
        _funding_intervals(spec),
        funding_paid_quote,
        s["baseline"].gross_quote,
        s["baseline"].fees_quote,
        s["baseline"].spread_quote + s["baseline"].slippage_quote,
        s["baseline"].net_quote,
        float(row["risk_base"]),
        s["adverse"].net_quote,
        float(row["risk_adverse"]),
        s["adverse"].fees_quote,
        s["adverse"].spread_quote + s["adverse"].slippage_quote,
        s["doubled"].net_quote,
        float(row["risk_doubled"]),
    )


def run_methods(
    rows: np.ndarray,
    pairs: dict[str, PairData],
    profiles: dict[str, costs.CostProfile],
    spec: dict,
    latency_bars: int = 0,
    target_mode: str = "through",
    methods: tuple[str, ...] = METHODS,
) -> dict[str, np.ndarray]:
    """Outcomes per method on filled ``rows``, aligned with ``rows`` (same order and length)."""
    if rows.size and not bool(np.all(rows["filled"])):
        raise ValueError("run_methods needs filled candidates only")
    params = method_params(spec)
    profiles = profiles_with_doubled(profiles)
    out: dict[str, list] = {name: [] for name in methods}
    for row in rows:
        pair = pairs[str(row["symbol"])]
        for name in methods:
            result = simulate_exit(pair.candles, pair.bars, pair.atr, row, params[name], profiles["baseline"], latency_bars if name != "A" else 0, target_mode)
            paid = funding_paid_checked(pair, int(row["side"]), float(row["quantity"]), int(row["fill_ms"]), result.exit_ms)
            out[name].append(settle_exit(row, result, profiles, spec, paid))
    return {name: (np.array(r, dtype=OUTCOME_DTYPE) if r else np.zeros(0, dtype=OUTCOME_DTYPE)) for name, r in out.items()}


# --- per-pair processing ------------------------------------------------------------------

DIAG_DTYPE = np.dtype([("mfe_a", "f8"), ("mfe_common", "f8"), ("after_target", "f8"), ("tight_hit", "?"), ("tight_then_target", "?")])


def diagnostic_rows(entries: np.ndarray, outcomes_a: np.ndarray, candles: Candles, spec: dict) -> np.ndarray:
    """Per-trade path diagnostics on the common 120-minute horizon after the fill (best prices:
    diagnostic only, never a realisable gain)."""
    tight = float(spec["diagnostics"]["tight_stop_r"])
    out = np.zeros(entries.size, dtype=DIAG_DTYPE)
    out["after_target"] = np.nan
    for i, row in enumerate(entries):
        side, entry, r_price = int(row["side"]), float(row["entry"]), float(row["r_price"])
        start, diag_end = int(row["fill_index"]), int(row["diag_end_index"])
        exit_index = int(np.searchsorted(candles.open_ms, int(outcomes_a["exit_ms"][i]) - MINUTE_MS, side="left"))
        fav = (candles.high[start:diag_end] - entry) / r_price if side == 1 else (entry - candles.low[start:diag_end]) / r_price
        adv = (entry - candles.low[start:diag_end]) / r_price if side == 1 else (candles.high[start:diag_end] - entry) / r_price
        held = max(1, exit_index - start + 1)
        out["mfe_a"][i] = float(np.max(fav[:held])) if fav.size else 0.0
        out["mfe_common"][i] = float(np.max(fav)) if fav.size else 0.0
        target_r = side * (float(row["target_a"]) - entry) / r_price
        if str(outcomes_a["exit_reason"][i]) == "target" and held < fav.size:
            out["after_target"][i] = float(np.max(fav[held:])) - target_r
        horizon_a = max(1, int(row["end_index"]) - start)
        hits = np.flatnonzero(adv[:horizon_a] >= tight)
        if hits.size:
            out["tight_hit"][i] = True
            out["tight_then_target"][i] = bool(np.any(fav[int(hits[0]) + 1 :] >= target_r))
    return out


def process_pair(pair: PairData, instrument: costs.Instrument, decisions_by_side: dict[int, np.ndarray], end_ms: int, profiles: dict[str, costs.CostProfile], spec: dict) -> dict:
    """Candidates, main exits of every filled candidate, the common table and its sensitivities."""
    profiles = profiles_with_doubled(profiles)
    symbol = pair.candles.symbol
    pairs = {symbol: pair}
    candidates, counters = build_candidates(pair.candles, pair.bars, pair.atr, decisions_by_side, instrument, end_ms, spec, profiles)
    filled_idx = np.flatnonzero(candidates["filled"])
    filled = candidates[filled_idx]
    main_filled = run_methods(filled, pairs, profiles, spec)
    main = {}
    for name in METHODS:
        full = np.zeros(candidates.size, dtype=OUTCOME_DTYPE)
        full[filled_idx] = main_filled[name]
        main[name] = full
    admitted = common_admission(candidates, spec)
    common_idx = np.flatnonzero(admitted & candidates["filled"])
    entries = candidates[common_idx]
    common = {name: main[name][common_idx] for name in METHODS}
    latency = run_methods(entries, pairs, profiles, spec, latency_bars=1, methods=("B", "C"))
    latency["A"] = common["A"]
    legacy = run_methods(entries, pairs, profiles, spec, target_mode="legacy", methods=("A", "C"))
    legacy["B"] = common["B"]
    counters["common_admitted_filled"] = int(common_idx.size)
    counters["common_admitted_unfilled"] = int((admitted & ~candidates["filled"]).sum())
    return {
        "candidates": candidates,
        "main": main,
        "common_index": common_idx,
        "entries": entries,
        "common": common,
        "latency": latency,
        "legacy": legacy,
        "diagnostics": diagnostic_rows(entries, common["A"], pair.candles, spec),
        "counters": counters,
    }


def combine(parts: list[dict]) -> dict:
    """Concatenate the per-pair results; sorted by decision time, then symbol, then side."""
    candidates = np.concatenate([p["candidates"] for p in parts]) if parts else np.zeros(0, dtype=CANDIDATE_DTYPE)
    order = np.lexsort((candidates["side"], candidates["symbol"], candidates["decision_ms"]))
    entries = np.concatenate([p["entries"] for p in parts]) if parts else np.zeros(0, dtype=CANDIDATE_DTYPE)
    common_order = np.lexsort((entries["side"], entries["symbol"], entries["decision_ms"]))

    def cat(key: str, name: str, sort: np.ndarray) -> np.ndarray:
        arrays = [p[key][name] for p in parts]
        return (np.concatenate(arrays) if arrays else np.zeros(0, dtype=OUTCOME_DTYPE))[sort]

    counters: dict[str, int] = {}
    for p in parts:
        for key, value in p["counters"].items():
            counters[key] = counters.get(key, 0) + int(value)
    diagnostics = np.concatenate([p["diagnostics"] for p in parts]) if parts else np.zeros(0, dtype=DIAG_DTYPE)
    return {
        "candidates": candidates[order],
        "main": {name: cat("main", name, order) for name in METHODS},
        "entries": entries[common_order],
        "common": {name: cat("common", name, common_order) for name in METHODS},
        "latency": {name: cat("latency", name, common_order) for name in METHODS},
        "legacy": {name: cat("legacy", name, common_order) for name in METHODS},
        "diagnostics": diagnostics[common_order],
        "counters": counters,
    }


# --- statistics ---------------------------------------------------------------------------


def day_block_bootstrap(values: np.ndarray, days: np.ndarray, resamples: int, seed: int) -> np.ndarray:
    """Bootstrap means of ``values`` resampling whole UTC days with replacement."""
    unique, inverse = np.unique(days, return_inverse=True)
    sums = np.bincount(inverse, weights=values, minlength=unique.size)
    counts = np.bincount(inverse, minlength=unique.size).astype(np.float64)
    rng = np.random.default_rng(seed)
    picks = rng.integers(0, unique.size, size=(resamples, unique.size))
    return sums[picks].sum(axis=1) / counts[picks].sum(axis=1)


def holm(p_values: dict[str, float], alpha: float) -> dict[str, dict]:
    """Holm step-down: adjusted p-values and the one-sided level each comparison is tested at."""
    order = sorted(p_values, key=lambda key: p_values[key])
    m = len(order)
    out = {}
    running = 0.0
    stopped = False
    for rank, key in enumerate(order):
        level = alpha / (m - rank)
        adjusted = min(1.0, max(running, (m - rank) * p_values[key]))
        running = adjusted
        rejected = (not stopped) and p_values[key] <= level
        stopped = stopped or not rejected
        out[key] = {"p": p_values[key], "p_holm": adjusted, "level": level, "rejected": rejected}
    return out


def required_trades(sd: float, delta: float, alpha_one_sided: float, power: float, design_effect: float = 1.0) -> int:
    """n = ((z_{1-alpha} + z_{power}) * sd / delta)^2 * design effect (normal approximation,
    NIST/SEMATECH e-Handbook 7.2.2.2, one-sided test of a mean)."""
    from statistics import NormalDist

    z = NormalDist().inv_cdf(1 - alpha_one_sided) + NormalDist().inv_cdf(power)
    return int(math.ceil((z * sd / delta) ** 2 * design_effect))


def _days(outcomes: np.ndarray) -> np.ndarray:
    return outcomes["decision_ms"] // DAY_MS


def _r(outcomes: np.ndarray, profile: str) -> np.ndarray:
    column = _column(profile)
    net, risk = outcomes[f"net_{column}"], outcomes[f"risk_{column}"]
    if not (np.all(np.isfinite(net)) and np.all(np.isfinite(risk)) and np.all(risk > 0)):
        raise DataError(f"non-finite net or non-positive risk in the {profile} profile")
    return net / risk


def method_summary(outcomes: np.ndarray, profile: str, spec: dict) -> dict:
    column = _column(profile)
    boot = spec["statistics"]["bootstrap"]
    level = float(spec["statistics"]["lower_bound_level"])
    large = float(spec["statistics"]["large_loss_r"])
    n = int(outcomes.size)
    if n == 0:
        return {"trades": 0}
    net = outcomes[f"net_{column}"]
    r = _r(outcomes, profile)
    days = _days(outcomes)
    means = day_block_bootstrap(r, days, int(boot["resamples"]), int(boot["seed"]))
    order = np.argsort(outcomes["exit_ms"], kind="stable")
    unique, inverse = np.unique(days, return_inverse=True)
    daily = np.bincount(inverse, weights=r)
    years = stats.years_of(outcomes["decision_ms"])
    by_year = {}
    for year in np.unique(years):
        mask = years == year
        by_year[str(int(year))] = {"trades": int(mask.sum()), "days": int(np.unique(days[mask]).size), "mean_r": float(r[mask].mean()), "total_r": float(r[mask].sum()), "total_quote": float(net[mask].sum())}
    worst = np.argsort(r, kind="stable")[:5]
    return {
        "trades": n,
        "days": int(unique.size),
        "mean_r": float(r.mean()),
        "median_r": float(np.median(r)),
        "sd_r": float(r.std(ddof=1)) if n > 1 else None,
        "total_r": float(r.sum()),
        "mean_quote": float(net.mean()),
        "total_quote": float(net.sum()),
        "win_rate": float((net > 0).mean()),
        "lower_bound_r": float(np.quantile(means, 1 - level)),
        "worst_trade_r": float(r.min()),
        "worst_day_r": float(daily.min()),
        f"trades_below_{large:g}R": int((r < large).sum()),
        "worst_trades": [{"symbol": str(outcomes["symbol"][i]), "side": int(outcomes["side"][i]), "decision_ms": int(outcomes["decision_ms"][i]), "r": float(r[i]), "reason": str(outcomes["exit_reason"][i])} for i in worst],
        "drawdown_r": stats.realized_drawdown(r[order]),
        "drawdown_quote_reference_size": stats.realized_drawdown(net[order]),
        "by_exit": {str(k): int((outcomes["exit_reason"] == k).sum()) for k in np.unique(outcomes["exit_reason"])},
        "by_year": by_year,
    }


def paired_comparisons(outcomes: dict[str, np.ndarray], profile: str, spec: dict) -> dict:
    """B-A and C-A on the same entries, day-block bootstrap, Holm across the two comparisons."""
    column = _column(profile)
    boot = spec["statistics"]["bootstrap"]
    alpha = 1 - float(spec["statistics"]["lower_bound_level"])
    a = outcomes["A"]
    if a.size == 0:
        return {"trades": 0, "days": 0}
    r_a = _r(a, profile)
    days = _days(a)
    years = stats.years_of(a["decision_ms"])
    results, p_values, boots = {}, {}, {}
    for name in ("B", "C"):
        other = outcomes[name]
        same = other.size == a.size and all(np.array_equal(other[k], a[k]) for k in ("decision_ms", "symbol", "side", "entry", f"risk_{column}"))
        if not same:
            raise ValueError(f"{name} and A are not on the same entries with the same risk")
        diff = _r(other, profile) - r_a
        means = day_block_bootstrap(diff, days, int(boot["resamples"]), int(boot["seed"]))
        boots[name] = means
        p_values[f"{name}-A"] = float((np.sum(means <= 0) + 1) / (means.size + 1))
        results[f"{name}-A"] = {
            "mean_diff_r": float(diff.mean()),
            "sd_diff_r": float(diff.std(ddof=1)) if diff.size > 1 else None,
            "total_diff_r": float(diff.sum()),
            "total_diff_quote": float((other[f"net_{column}"] - a[f"net_{column}"]).sum()),
            "share_better": float((diff > 0).mean()),
            "share_worse": float((diff < 0).mean()),
            "by_year_mean_diff_r": {str(int(y)): float(diff[years == y].mean()) for y in np.unique(years)},
        }
    adjusted = holm(p_values, alpha)
    for key, value in adjusted.items():
        means = boots[key[0]]
        results[key].update(value)
        results[key]["lower_bound_holm_r"] = float(np.quantile(means, value["level"]))
        results[key]["lower_bound_unadjusted_r"] = float(np.quantile(means, alpha))
        results[key]["upper_bound_unadjusted_r"] = float(np.quantile(means, 1 - alpha))
    results["trades"] = int(a.size)
    results["days"] = int(np.unique(days).size)
    return results


def diagnostics_summary(diag: np.ndarray, entries: np.ndarray, outcomes_a: np.ndarray, spec: dict) -> dict:
    thresholds = [float(x) for x in spec["diagnostics"]["mfe_thresholds_r"]]
    tight = float(spec["diagnostics"]["tight_stop_r"])
    n = int(diag.size)
    if n == 0:
        return {"trades": 0}
    r_a = _r(outcomes_a, "baseline")
    losing = outcomes_a["net_base"] < 0
    out = {"trades": n, "truncated": int(entries["diag_truncated"].sum())}
    for t in thresholds:
        reached = diag["mfe_a"] >= t
        out[f"mfe_ge_{t:g}R_share"] = float(reached.mean())
        out[f"mfe_ge_{t:g}R_then_losing_share"] = float((reached & losing).sum() / reached.sum()) if reached.any() else None
    giveback = diag["mfe_a"] - r_a
    out["giveback_r_mean"] = float(giveback.mean())
    out["giveback_r_median"] = float(np.median(giveback))
    out["mfe_common_horizon_r_mean"] = float(diag["mfe_common"].mean())
    after = diag["after_target"][np.isfinite(diag["after_target"])]
    out["after_target_trades"] = int(after.size)
    out["after_target_continuation_r_mean"] = float(after.mean()) if after.size else None
    out["after_target_continuation_ge_1R_share"] = float((after >= 1.0).mean()) if after.size else None
    out[f"tight_stop_{tight:g}R_hit_share"] = float(diag["tight_hit"].mean())
    out[f"tight_stop_{tight:g}R_then_target_share"] = float(diag["tight_then_target"].sum() / diag["tight_hit"].sum()) if diag["tight_hit"].any() else None
    out["note"] = "best prices on 1m highs/lows: diagnostic only, never a realisable gain"
    return out


def design_effect(values: np.ndarray, days: np.ndarray, resamples: int, seed: int) -> float | None:
    """Variance of the day-block bootstrap mean over the iid variance of the mean."""
    if values.size < 2:
        return None
    iid = float(values.var(ddof=1)) / values.size
    if not iid > 0:
        return None
    return float(day_block_bootstrap(values, days, resamples, seed).var(ddof=1) / iid)


def power_reestimate(common: dict[str, np.ndarray], spec: dict) -> dict:
    """Declared requirements against the variability and dependence measured on the data."""
    p = spec["data_requirements"]["power"]
    req = spec["data_requirements"]
    boot = spec["statistics"]["bootstrap"]
    alpha, power = float(p["alpha_one_sided"]), float(p["power"])
    out = {
        "declared": {
            "own_mean_no_design_effect": required_trades(p["sd_trade_r"], p["delta_mean_r"], alpha, power),
            "own_mean_design_effect": required_trades(p["sd_trade_r"], p["delta_mean_r"], alpha, power, p["design_effect"]),
            "paired_no_design_effect": required_trades(p["sd_paired_diff_r"], p["delta_diff_r"], alpha, power),
            "paired_design_effect": required_trades(p["sd_paired_diff_r"], p["delta_diff_r"], alpha, power, p["design_effect"]),
            "floor_trades": req["min_trades"],
            "floor_days": req["min_days"],
        }
    }
    a = common["A"]
    if a.size < 2:
        out["measured"] = None
        return out
    days = _days(a)
    n_days = int(np.unique(days).size)
    measured = {"trades": int(a.size), "days": n_days, "trades_per_day": a.size / n_days}
    series = {name: _r(common[name], "baseline") for name in METHODS}
    series["B-A"] = series["B"] - series["A"]
    series["C-A"] = series["C"] - series["A"]
    for key, values in series.items():
        sd = float(values.std(ddof=1))
        deff = design_effect(values, days, int(boot["resamples"]), int(boot["seed"]) + 1)
        delta = float(p["delta_diff_r"] if "-" in key else p["delta_mean_r"])
        needed = required_trades(sd, delta, alpha, power, deff if deff is not None and deff > 1 else 1.0)
        measured[key] = {
            "sd_r": sd,
            "design_effect": deff,
            "required_trades": needed,
            "required_days_at_measured_rate": int(math.ceil(needed / measured["trades_per_day"])),
            "confirmation_minimum_trades": max(int(req["min_trades"]), needed),
        }
    out["measured"] = measured
    return out


# --- portfolio (comparison 2) -------------------------------------------------------------


def portfolio_walk(candidates: np.ndarray, outcomes: np.ndarray, profile: str, spec: dict, budget: float | None, instruments: dict[str, costs.Instrument] | None = None) -> dict:
    """One method on the full candidate list; ``outcomes`` is aligned with ``candidates`` (rows of
    unfilled candidates are ignored). The pair is busy from the decision to the method's own exit
    (or to the TTL end of an unfilled order), whatever the side. With a ``budget``, sizing follows
    the budget study: 1 % of realized equity at risk, open notional (positions and resting entry
    orders) <= 2 x equity, step rounding and exchange minimums. Without one, every admitted trade
    keeps the reference size (exit and admission effect only)."""
    column = _column(profile)
    n = int(candidates.size)
    order = np.lexsort((candidates["side"], candidates["symbol"], candidates["decision_ms"]))
    filled = candidates["filled"]
    release = np.where(filled, outcomes["exit_ms"], candidates["order_end_ms"])
    net_ref = np.where(filled, outcomes[f"net_{column}"], 0.0)
    risk_ref = candidates[f"risk_{column}"]
    if not (np.all(np.isfinite(net_ref)) and np.all(np.isfinite(risk_ref)) and np.all(risk_ref > 0)):
        raise DataError("non-finite net or non-positive risk in the portfolio input")
    start = float(budget) if budget is not None else 0.0
    tracker = budgets_module.DrawdownTracker(start if budget is not None else 1.0)
    open_items: list[tuple[int, str, float, float, float, bool]] = []  # (release, symbol, notional, net, risk, is_position)
    busy: set[str] = set()
    skipped = {"pair_busy": 0, "leverage": 0, "minimum": 0}
    taken_nets, taken_r, taken_ms, taken_keys, exit_order = [], [], [], [], []
    orders_unfilled = 0
    max_open_risk_share = 0.0
    max_concurrent = 0
    r_path: list[tuple[int, float]] = []

    def release_until(t: int) -> None:
        open_items.sort(key=lambda item: item[0])
        while open_items and open_items[0][0] <= t:
            item = open_items.pop(0)
            busy.discard(item[1])
            if item[5]:
                if budget is not None:
                    tracker.settle(item[3])

    for i in order:
        t = int(candidates["decision_ms"][i])
        release_until(t)
        symbol = str(candidates["symbol"][i])
        if symbol in busy:
            skipped["pair_busy"] += 1
            continue
        scale = 1.0
        notional = float(candidates["entry"][i] * candidates["quantity"][i])
        if budget is not None:
            equity = tracker.equity
            instrument = instruments[symbol]
            risk_per_unit = float(risk_ref[i]) / float(candidates["quantity"][i])
            risk_quantity = budgets_module.RISK_FRACTION * equity / risk_per_unit if equity > 0 else 0.0
            entry = float(candidates["entry"][i])
            capacity = budgets_module.LEVERAGE_CAP * equity - sum(item[2] for item in open_items) if equity > 0 else 0.0
            if capacity <= 0:
                skipped["leverage"] += 1
                continue
            capped = capacity / entry < risk_quantity
            quantity = min(risk_quantity, capacity / entry)
            quantity = float(np.floor(quantity / instrument.quantity_step + 1e-9) * instrument.quantity_step)
            if quantity < instrument.min_quantity or quantity * entry < instrument.min_notional:
                skipped["leverage" if capped else "minimum"] += 1
                continue
            scale = quantity / float(candidates["quantity"][i])
            notional = quantity * entry
        busy.add(symbol)
        if not filled[i]:
            orders_unfilled += 1
            open_items.append((int(release[i]), symbol, notional, 0.0, 0.0, False))
            continue
        net = float(net_ref[i]) * scale
        risk = float(risk_ref[i]) * scale
        open_items.append((int(release[i]), symbol, notional, net, risk, True))
        taken_nets.append(net)
        taken_r.append(float(net_ref[i] / risk_ref[i]))
        taken_ms.append(t)
        taken_keys.append((symbol, t, int(candidates["side"][i])))
        r_path.append((int(release[i]), float(net_ref[i] / risk_ref[i])))
        max_concurrent = max(max_concurrent, sum(1 for item in open_items if item[5]))
        if budget is not None and tracker.equity > 0:
            max_open_risk_share = max(max_open_risk_share, sum(item[4] for item in open_items) / tracker.equity)
    release_until(2**62)
    r = np.array(taken_r)
    ms = np.array(taken_ms, dtype=np.int64)
    days = ms // DAY_MS
    years = stats.years_of(ms) if ms.size else np.zeros(0, dtype=int)
    r_by_exit = np.array([v for _, v in sorted(r_path, key=lambda item: item[0])])
    result = {
        "candidates": n,
        "taken": int(r.size),
        "orders_unfilled": orders_unfilled,
        "skipped": skipped,
        "days": int(np.unique(days).size),
        "mean_r": float(r.mean()) if r.size else None,
        "total_r": float(r.sum()) if r.size else None,
        "drawdown_r_exit_order": stats.realized_drawdown(r_by_exit) if r.size else None,
        "by_year": {str(int(y)): {"trades": int((years == y).sum()), "mean_r": float(r[years == y].mean()), "total_r": float(r[years == y].sum())} for y in np.unique(years)},
        "max_concurrent": max_concurrent,
    }
    if budget is not None:
        result.update(
            {
                "budget": float(budget),
                "net": float(np.sum(taken_nets)) if taken_nets else 0.0,
                "roi": float(np.sum(taken_nets)) / float(budget) if taken_nets else 0.0,
                "final_equity": tracker.equity,
                "max_drawdown_quote": tracker.max_drawdown_quote,
                "max_drawdown_share": tracker.max_drawdown_share,
                "drawdown_basis": "realized equity, settlements in exit order, relative to the running peak; unrealized losses not measured",
                "max_open_risk_share": max_open_risk_share,
            }
        )
    else:
        result["net_reference_size"] = float(np.sum(taken_nets)) if taken_nets else 0.0
    result["_keys"] = set(taken_keys)
    return result


def portfolio_section(combined: dict, instruments: dict[str, costs.Instrument], spec: dict) -> dict:
    budget = float(spec["decision"]["portfolio_budget_usdt"])
    candidates = combined["candidates"]
    entries = combined["entries"]
    common_keys = set(zip(entries["symbol"].tolist(), entries["decision_ms"].tolist(), entries["side"].tolist()))
    capital = {profile: {name: portfolio_walk(candidates, combined["main"][name], profile, spec, budget, instruments) for name in METHODS} for profile in ("baseline", "adverse")}
    reference = {name: portfolio_walk(candidates, combined["main"][name], "baseline", spec, None) for name in METHODS}
    admission = {}
    for name in METHODS:
        keys = reference[name]["_keys"]
        admission[name] = {"taken": len(keys), "also_in_common_table": len(keys & common_keys), "not_in_common_table": len(keys - common_keys), "common_entries_not_taken": len(common_keys - keys)}
    for block in (reference, *capital.values()):
        for value in block.values():
            value.pop("_keys", None)
    return {
        "capital": capital,
        "reference_size_no_capital": reference,
        "admission_vs_common_table": admission,
        "note": "realistic portfolio: each method releases the pair and the exposure at its own exit; drawdown = realized, unrealized losses not measured; the 1000 USDT account is a simulation scenario",
    }


# --- verdict ------------------------------------------------------------------------------


def _finite(value) -> bool:
    return isinstance(value, (int, float, np.integer, np.floating)) and not isinstance(value, bool) and math.isfinite(float(value))


def classify_method(report: dict, name: str, spec: dict) -> dict:
    """Improvement, profitability, cost robustness and risk of one method, each failing closed
    on a missing, non-finite or invalid measure."""
    decision = spec["decision"]
    c1 = report.get("comparison_1") or {}
    base, adverse = c1.get("baseline") or {}, c1.get("adverse") or {}
    comp = (base.get("comparisons") or {}).get(f"{name}-A") or {}
    own = (base.get("methods") or {}).get(name) or {}
    adverse_own = (adverse.get("methods") or {}).get(name) or {}
    adverse_comp = (adverse.get("comparisons") or {}).get(f"{name}-A") or {}
    portfolio = (((report.get("comparison_2") or {}).get("capital") or {}).get("baseline") or {}).get(name) or {}
    reasons: list[str] = []

    def check(label: str, value, test) -> bool:
        if not _finite(value):
            reasons.append(f"{label}: missing or non-finite")
            return False
        ok = bool(test(float(value)))
        if not ok:
            reasons.append(f"{label} = {float(value):.4g}")
        return ok

    rejected = comp.get("rejected") is True
    if not rejected:
        reasons.append("Holm test not rejected (or missing)")
    improvement = all([rejected, check("paired Holm lower bound (R)", comp.get("lower_bound_holm_r"), lambda v: v > 0)])
    profitable = all([check("mean net R", own.get("mean_r"), lambda v: v > 0), check("day-block lower bound of the mean net R", own.get("lower_bound_r"), lambda v: v > 0)])
    robust = all([check("adverse mean net R", adverse_own.get("mean_r"), lambda v: v > 0), check("adverse paired mean difference (R)", adverse_comp.get("mean_diff_r"), lambda v: v > 0)])
    portfolio_valid = check("portfolio trades taken", portfolio.get("taken"), lambda v: v > 0)
    risk = all(
        [
            check("drawdown (R)", own.get("drawdown_r"), lambda v: v <= decision["max_drawdown_r"]),
            portfolio_valid,
            check("portfolio realized drawdown share", portfolio.get("max_drawdown_share"), lambda v: v <= decision["max_portfolio_drawdown_share"]),
        ]
    )
    return {"improvement": improvement, "profitable": profitable, "robust_costs": robust, "risk_within_limits": risk, "portfolio_valid": portfolio_valid, "failed": reasons}


def verdict(report: dict, spec: dict) -> dict:
    """Pre-declared decision rule (spec ``decision``) applied to a report."""
    req = spec["data_requirements"]
    comparisons = ((report.get("comparison_1") or {}).get("baseline") or {}).get("comparisons") or {}
    trades, days = comparisons.get("trades"), comparisons.get("days")
    classes = {name: classify_method(report, name, spec) for name in ("B", "C")}
    base = {"evidence_status": spec["evidence_status"], "methods": classes, "trades": trades, "days": days}
    if not (_finite(trades) and _finite(days)) or trades < req["min_trades"] or days < req["min_days"]:
        return {"verdict": "INSUFFICIENT_DATA", **base}
    supported = [n for n, c in classes.items() if c["improvement"] and c["profitable"] and c["robust_costs"] and c["risk_within_limits"]]
    if supported:
        return {"verdict": "IMPROVEMENT_PROFITABLE_SUPPORTED", "which": supported, **base}
    profitable = [n for n, c in classes.items() if c["improvement"] and c["profitable"]]
    if profitable:
        return {"verdict": "IMPROVEMENT_PROFITABLE_NOT_ROBUST", "which": profitable, **base}
    improving = [n for n, c in classes.items() if c["improvement"]]
    if improving:
        return {"verdict": "IMPROVEMENT_BUT_NOT_PROFITABLE", "which": improving, **base}
    return {"verdict": "NO_IMPROVEMENT_DEMONSTRATED", **base}


# --- report -------------------------------------------------------------------------------


def build_report(combined: dict, instruments: dict[str, costs.Instrument], spec: dict) -> dict:
    common = combined["common"]
    report: dict = {"schema": "research-v2-dynamic-exits-report.v2", "spec_version": spec["version"], "counters": combined["counters"]}
    report["comparison_1"] = {profile: {"methods": {name: method_summary(common[name], profile, spec) for name in METHODS}, "comparisons": paired_comparisons(common, profile, spec)} for profile in PROFILES}
    report["comparison_1"]["latency_1_bar"] = {"methods": {name: method_summary(combined["latency"][name], "baseline", spec) for name in METHODS}, "comparisons": paired_comparisons(combined["latency"], "baseline", spec)}
    report["comparison_1"]["target_touch_legacy"] = {"methods": {name: method_summary(combined["legacy"][name], "baseline", spec) for name in METHODS}, "comparisons": paired_comparisons(combined["legacy"], "baseline", spec)}
    report["diagnostics"] = diagnostics_summary(combined["diagnostics"], combined["entries"], common["A"], spec)
    report["comparison_2"] = portfolio_section(combined, instruments, spec) if combined["candidates"].size else {}
    report["power"] = power_reestimate(common, spec)
    report["verdict"] = verdict(report, spec)
    return report


# --- synthetic data -----------------------------------------------------------------------


def synthetic_pair(symbol: str, minutes: int, seed: int, price: float = 100.0, vol: float = 0.0008, tick: float = 0.01) -> tuple[PairData, costs.Instrument]:
    """A random-walk 1-minute series (no market data, no funding) with 5m bars and ATR14."""
    from . import indicators as ind
    from .bars import aggregate

    rng = np.random.default_rng(seed)
    closes = price * np.exp(np.cumsum(rng.normal(0.0, vol, minutes)))
    opens = np.concatenate(([price], closes[:-1]))
    wick = np.abs(rng.normal(0.0, vol / 2, minutes)) * closes
    highs = np.maximum(opens, closes) + wick
    lows = np.minimum(opens, closes) - np.abs(rng.normal(0.0, vol / 2, minutes)) * closes
    rnd = lambda x: np.round(x / tick) * tick  # noqa: E731
    o, h, l, c = rnd(opens), rnd(highs), rnd(lows), rnd(closes)
    h = np.maximum.reduce([h, o, c])
    l = np.minimum.reduce([l, o, c])
    candles = Candles(symbol, np.arange(minutes, dtype=np.int64) * MINUTE_MS, o, h, l, c, np.ones(minutes))
    bars = aggregate(candles, "5m")
    atr = ind.atr(bars.high, bars.low, bars.close, 14)
    instrument = costs.Instrument(symbol, tick, 0.001, 0.001, 5.0, 1e9, 20.0)
    return PairData(candles, bars, atr, funding_is_zero_by_construction=True), instrument


def synthetic_profiles() -> dict[str, costs.CostProfile]:
    """The campaign's cost assumptions (research-cost-assumptions.v1, sha256 83b0216d…)."""
    return profiles_with_doubled(
        {
            "baseline": costs.CostProfile("baseline", 0.0, 0.0002, 0.0, 0.0, 0.0005, 0.0, 0.0003),
            "adverse": costs.CostProfile("adverse", 0.0, 0.001, 0.0, 0.0, 0.001, 0.0, 0.001),
        }
    )


def synthetic_campaign(pairs_count: int, minutes: int, decisions: int, seed: int, spec: dict) -> tuple[dict, dict]:
    profiles = synthetic_profiles()
    parts, instruments = [], {}
    for k in range(pairs_count):
        symbol = f"SYN{k}USDT"
        pair, instrument = synthetic_pair(symbol, minutes, seed + k)
        instruments[symbol] = instrument
        rng = np.random.default_rng(seed + 1000 + k)
        valid = np.flatnonzero(~np.isnan(pair.atr))[:-13]
        by_side = {}
        for side in (1, -1):
            by_side[side] = np.sort(rng.choice(valid, size=min(decisions, valid.size), replace=False))
        parts.append(process_pair(pair, instrument, by_side, int(pair.candles.open_ms[-1]) + MINUTE_MS, profiles, spec))
    return combine(parts), instruments


# --- real data ----------------------------------------------------------------------------


def _sha256_file(path: Path) -> str:
    return hashlib.sha256(Path(path).read_bytes()).hexdigest()


def load_real_pair(cache_root: Path, symbol: str, window_ms: tuple[int, int], instrument: costs.Instrument) -> tuple[PairData, dict[int, np.ndarray], dict]:
    """Candles and funding of the authorized window only, the S1 decisions of both sides."""
    from . import dataset, scalping
    from .engine import Window, configuration_signals, decision_indices
    from .features import compute_features

    start, end = window_ms
    candles_all, taker_all = dataset.load_cached_candles(cache_root, symbol)
    keep = (candles_all.open_ms >= start) & (candles_all.open_ms < end)
    lo, hi = int(np.argmax(keep)), int(keep.size - np.argmax(keep[::-1]))
    if not keep.any() or not keep[lo:hi].all():
        raise DataError(f"{symbol}: the authorized window is not contiguous in the cache")
    candles = Candles(symbol, *(np.array(a[lo:hi]) for a in (candles_all.open_ms, candles_all.open, candles_all.high, candles_all.low, candles_all.close, candles_all.volume)))
    taker = np.array(taker_all[lo:hi])
    del candles_all, taker_all
    expected = (end - start) // MINUTE_MS
    if candles.size != expected or int(np.max(np.diff(candles.open_ms))) != MINUTE_MS:
        raise DataError(f"{symbol}: {candles.size} candles in the window, {expected} expected (missing minutes)")
    funding_all = dataset.load_cached_funding(cache_root, symbol)
    fmask = (funding_all.time_ms >= start) & (funding_all.time_ms < end)
    funding = dataset.Funding(symbol, funding_all.time_ms[fmask], funding_all.rate[fmask])
    fifteen = compute_features(candles, "15m", funding)
    five = scalping.compute_scalping_features(candles, taker, "5m", funding, fifteen)
    window = Window(start, end)
    by_side = {}
    for side, name in ((1, "long"), (-1, "short")):
        configuration = scalping.parse_scalp_configuration(f"S1|5m|{name}|S1.T1.5")
        by_side[side] = decision_indices(five, configuration_signals(configuration, five), window)
    pair = PairData(candles, five.bars, five["atr14"], funding, dataset.funding_marks(candles, funding))
    info = {"candles": int(candles.size), "first_open_ms": int(candles.open_ms[0]), "last_open_ms": int(candles.open_ms[-1]), "funding_events": int(funding.time_ms.size), "s1_long_decisions": int(by_side[1].size), "s1_short_decisions": int(by_side[-1].size)}
    return pair, by_side, info


def code_identity() -> dict:
    package = Path(__file__).parent
    files = {path.name: hashlib.sha256(path.read_bytes()).hexdigest() for path in sorted(package.glob("*.py"))}
    combined = hashlib.sha256("".join(f"{k}:{v}\n" for k, v in files.items()).encode()).hexdigest()
    return {"files": files, "package_sha256": combined}


def command_run(args: argparse.Namespace) -> int:
    """Exploration on the authorized data: frozen spec, matching input hashes, authorized window."""
    if not args.owner_authorization.strip():
        print("refused: the exploration needs the owner's written authorization (--owner-authorization)", file=sys.stderr)
        return 2
    spec = load_spec()
    if not str(spec.get("status", "")).startswith("FROZEN"):
        print(f"refused: spec status {spec.get('status')!r} is not frozen", file=sys.stderr)
        return 2
    data = spec["data"]
    window = (int(data["exploration_window_ms"][0]), int(data["exploration_window_ms"][1]))
    if window[0] < AUTHORIZED_EXPLORATION_MS[0] or window[1] > AUTHORIZED_EXPLORATION_MS[1]:
        print(f"refused: window {window} outside the authorized exploration {AUTHORIZED_EXPLORATION_MS}", file=sys.stderr)
        return 2
    cost_path, instrument_path, cache_root = Path(args.cost_path), Path(args.instrument_path), Path(args.cache_root)
    for label, path, wanted in (("cost assumptions", cost_path, spec["costs"]["sha256"]), ("instrument assumptions", instrument_path, data["instrument_assumptions_sha256"])):
        actual = _sha256_file(path)
        if actual != wanted:
            print(f"refused: {label} sha256 {actual} differs from the spec's {wanted}", file=sys.stderr)
            return 2
    profiles = profiles_with_doubled(costs.load_cost_profiles(cost_path))
    instruments_all = costs.load_instruments(instrument_path)
    symbols = tuple(args.symbols.split(",")) if args.symbols else tuple(data["symbols"])
    if not set(symbols) <= set(data["symbols"]):
        print("refused: symbols outside the authorized list", file=sys.stderr)
        return 2
    out_dir = Path(args.out_dir)
    out_dir.mkdir(parents=True, exist_ok=False)
    started = time.time()
    parts, infos = [], {}
    for symbol in symbols:
        t0 = time.time()
        pair, by_side, info = load_real_pair(cache_root, symbol, window, instruments_all[symbol])
        parts.append(process_pair(pair, instruments_all[symbol], by_side, window[1], profiles, spec))
        info["seconds"] = round(time.time() - t0, 1)
        infos[symbol] = info
        print(json.dumps({"pair": symbol, **{k: v for k, v in info.items() if k != "first_open_ms"}}), flush=True)
        del pair
    combined = combine(parts)
    instruments = {s: instruments_all[s] for s in symbols}
    report = build_report(combined, instruments, spec)
    report["run"] = {
        "started_utc": datetime.fromtimestamp(started, timezone.utc).isoformat(),
        "seconds": round(time.time() - started, 1),
        "owner_authorization": args.owner_authorization,
        "spec_sha256": spec_sha256(),
        "code": code_identity(),
        "cache_root": str(cache_root),
        "cost_assumptions_sha256": spec["costs"]["sha256"],
        "instrument_assumptions_sha256": data["instrument_assumptions_sha256"],
        "window_ms": list(window),
        "pairs": infos,
    }
    (out_dir / "report.json").write_text(json.dumps(report, indent=1, sort_keys=True, default=float) + "\n")
    arrays = {"candidates": combined["candidates"], "entries": combined["entries"], "diagnostics": combined["diagnostics"]}
    for key in ("main", "common", "latency", "legacy"):
        for name in METHODS:
            arrays[f"{key}_{name}"] = combined[key][name]
    np.savez_compressed(out_dir / "outcomes.npz", **arrays)
    print(json.dumps({"verdict": report["verdict"]["verdict"], "out": str(out_dir)}))
    return 0


def command_synthetic(args: argparse.Namespace) -> int:
    spec = load_spec()
    combined, instruments = synthetic_campaign(args.pairs, args.minutes, args.decisions, args.seed, spec)
    report = build_report(combined, instruments, spec)
    report["data"] = "SYNTHETIC random walk (no market data)"
    if args.out:
        Path(args.out).write_text(json.dumps(report, indent=1, sort_keys=True, default=float) + "\n")
    print(json.dumps({"entries": int(combined["entries"].size), "verdict": report["verdict"]["verdict"]}))
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="research_v2.exits_dynamic")
    sub = parser.add_subparsers(dest="command", required=True)
    syn = sub.add_parser("synthetic", help="end-to-end run on synthetic random walks")
    syn.add_argument("--pairs", type=int, default=3)
    syn.add_argument("--minutes", type=int, default=20 * 1440)
    syn.add_argument("--decisions", type=int, default=400)
    syn.add_argument("--seed", type=int, default=7)
    syn.add_argument("--out", default="")
    syn.set_defaults(func=command_synthetic)
    run = sub.add_parser("run", help="authorized exploration data (frozen spec, hashes checked)")
    run.add_argument("--owner-authorization", default="")
    run.add_argument("--cache-root", default="")
    run.add_argument("--cost-path", default="")
    run.add_argument("--instrument-path", default="")
    run.add_argument("--out-dir", default="")
    run.add_argument("--symbols", default="")
    run.set_defaults(func=command_run)
    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
