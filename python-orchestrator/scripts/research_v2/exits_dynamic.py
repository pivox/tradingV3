"""Dynamic exits on identical entries (spec ``specs/dynamic_exits_v1.json``).

Question: with the same S1 (5m momentum burst) entries, the same size and the same initial risk,
does re-evaluating the position at every 5-minute close (B: close-based trailing stop; C:
adaptive exit) improve the net result against the fixed reference A (SL 1 R, TP 1.5 R, 60 min)?

What is reused from research_v2:

- the entry fill (``fills.simulate`` on plan A: limit through by one tick, TTL one bar), the stop
  fill price (``fills._stop_fill_price``: worse of level and open), the tick rounding
  (``engine._round_to_tick``), the plan prices (``engine.plan_prices_from_distance``);
- settlement, stop risk and cost profiles (``costs``), the portfolio re-sizing (``budgets``), the
  per-profile summary and drawdown (``stats``), the S1 signals (``scalping``).

What is new here is only the exit loop: decisions are taken at 5m closes and take effect from
the first 1-minute candle of the next bar (``latency_bars`` more bars in the sensitivity run);
stops and targets are checked on 1-minute candles, stop first on ties; a moved target is a new
limit order filled only by a one-tick trade-through from its effective candle on; a market exit
fills at the effective candle's open and pays taker + stop spread + stop slippage.

Admission is method-independent (a filled plan reserves the pair until decision close + 60 min),
so a single entry table serves the three methods.

Nothing in this module reads market data by itself; ``command_run`` refuses to start without an
explicit owner authorization reference.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
from dataclasses import dataclass, field
from pathlib import Path

import numpy as np

from . import budgets as budgets_module
from . import costs, stats
from .bars import MINUTE_MS, Bars, Candles
from .engine import OUTCOME_DTYPE, REFERENCE_NOTIONAL, FUNDING_INTERVAL_MS, _round_to_tick, plan_prices_from_distance
from .fills import PlanSpec, _stop_fill_price, simulate

SPEC_PATH = Path(__file__).parent / "specs" / "dynamic_exits_v1.json"
SPEC_HASH_PATH = Path(__file__).parent / "specs" / "dynamic_exits_v1.sha256"
DAY_MS = 86_400_000
METHODS = ("A", "B", "C")

# Exit reasons of this module and the settlement reason they map to (costs.settle).
SETTLE_REASON = {
    "stop": "stop",
    "trailing": "trailing",  # a stop that was moved at least once
    "target": "target",
    "time": "time",
    "window_end": "window_end",
    "invalidation": "indicator",  # market exit: taker + stop spread + stop slippage
    "stall": "indicator",
}


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


# --- entry table --------------------------------------------------------------------------

ENTRY_DTYPE = np.dtype(
    [
        ("symbol", "U10"),
        ("side", "i1"),
        ("decision_index", "i8"),
        ("decision_ms", "i8"),
        ("fill_index", "i8"),
        ("fill_ms", "i8"),
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
        ("quantity", "f8"),
    ]
)


def build_entry_table(
    candles: Candles,
    bars: Bars,
    atr: np.ndarray,
    decisions: np.ndarray,
    side: int,
    instrument: costs.Instrument,
    end_ms: int,
    spec: dict,
) -> np.ndarray:
    """One row per filled S1 entry; the admission does not depend on any exit method.

    ``decisions`` are the S1 decision bar indices (``engine.decision_indices`` on the scalping
    features), already restricted to the window. Fills come from ``fills.simulate`` on plan A.
    """
    entry_spec = spec["entry"]
    horizon_ms = int(entry_spec["horizon_minutes"]) * MINUTE_MS
    ttl_ms = int(entry_spec["ttl_minutes"]) * MINUTE_MS
    diag_ms = int(spec["diagnostics"]["common_horizon_minutes_after_fill"]) * MINUTE_MS
    stop_atr = float(entry_spec["initial_stop_atr"])
    target_r = float(spec["methods"]["A"]["target_r"])
    tick = instrument.tick_size
    rows = []
    busy_until = -1
    for index in np.asarray(decisions, dtype=np.int64):
        close_ms = int(bars.close_ms[index])
        if close_ms < busy_until:
            continue
        atr_value = float(atr[index])
        if not (atr_value > 0):
            continue
        entry, stop, target = plan_prices_from_distance(side, float(bars.close[index]), stop_atr * atr_value, target_r, tick, "limit_through")
        quantity = costs.round_quantity(REFERENCE_NOTIONAL, entry, instrument)
        if quantity <= 0:
            continue
        plan = PlanSpec(candles.symbol, side, int(index), entry, stop, target, None, horizon_ms, ttl_ms, entry_mode="limit_through", through=tick * int(entry_spec["through_ticks"]))
        result = simulate(candles, bars, plan, end_ms)
        if not result.filled:
            busy_until = close_ms + ttl_ms
            continue
        busy_until = close_ms + horizon_ms  # method-independent reservation
        deadline = min(close_ms + horizon_ms, end_ms)
        end_index = int(np.searchsorted(candles.open_ms, deadline, side="left"))
        diag_deadline = int(candles.open_ms[result.fill_index]) + diag_ms
        diag_end = int(np.searchsorted(candles.open_ms, min(diag_deadline, end_ms), side="left"))
        rows.append(
            (
                candles.symbol,
                side,
                int(index),
                close_ms,
                result.fill_index,
                result.fill_ms,
                end_index,
                "time" if close_ms + horizon_ms <= end_ms else "window_end",
                diag_end,
                diag_deadline > end_ms,
                entry,
                stop,
                target,
                abs(entry - stop),
                atr_value,
                tick,
                quantity,
            )
        )
    return np.array(rows, dtype=ENTRY_DTYPE) if rows else np.zeros(0, dtype=ENTRY_DTYPE)


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
    """Exit price at which a maker entry and a stop-like (taker) exit net exactly zero, rounded
    against the position (long: up), so that a fill at this level covers the round trip."""
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


def simulate_exit(
    candles: Candles,
    bars: Bars,
    atr: np.ndarray,
    row,
    params: MethodParams,
    breakeven_profile: costs.CostProfile,
    latency_bars: int = 0,
) -> ExitResult:
    """Simulate one method on one entry row (an ``ENTRY_DTYPE`` record)."""
    side = int(row["side"])
    entry = float(row["entry"])
    r_price = float(row["r_price"])
    tick = float(row["tick"])
    atr_entry = float(row["atr_entry"])
    fill_index = int(row["fill_index"])
    end = int(row["end_index"])
    decision_index = int(row["decision_index"])
    stop = float(row["stop"])
    stop_moved = False
    target = None
    target_from = fill_index + 1  # initial target: from the candle after the fill, on a touch
    target_through = 0.0
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
                target, target_from, target_through = d.value, d.effective_index, tick * (1.0 - 1e-6)  # one tick through, float-safe
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
        if stop_hit:  # stop first on ties
            return ExitResult(params.name, m, int(candles.open_ms[m]) + MINUTE_MS, _stop_fill_price(candles, m, side, stop), "trailing" if stop_moved else "stop", decisions, stop_path, best_close)
        if target_hit:
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
                if _tighter(side, stop, candidate) != stop:
                    new.append(Decision(bar, when, "stop", candidate, "trail"))
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
                if _tighter(side, stop, candidate) != stop:
                    new.append(Decision(bar, when, "stop", candidate, "giveback"))
            elif u >= params.favourable_u and new_best:
                if _tighter(side, stop, breakeven) != stop:
                    new.append(Decision(bar, when, "stop", breakeven, "breakeven"))
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


def _funding_intervals(spec: dict) -> int:
    horizon_ms = int(spec["entry"]["horizon_minutes"]) * MINUTE_MS
    return (horizon_ms // 1000 - 1) // (FUNDING_INTERVAL_MS // 1000) + 1


def settle_exit(row, result: ExitResult, profiles: dict[str, costs.CostProfile], spec: dict, funding_paid_quote: float = 0.0) -> tuple:
    """An ``OUTCOME_DTYPE`` record (net and risk for baseline, adverse and doubled)."""
    side, entry, stop, quantity = int(row["side"]), float(row["entry"]), float(row["stop"]), float(row["quantity"])
    reason = SETTLE_REASON[result.reason]
    intervals = _funding_intervals(spec)
    s = {name: costs.settle(side, entry, result.exit_price, quantity, reason, profiles[name], funding_paid_quote, "maker") for name in ("baseline", "adverse", "doubled")}
    risk = {name: costs.stop_risk_quote(side, entry, stop, quantity, intervals, profiles[name], "maker") for name in ("baseline", "adverse", "doubled")}
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
        intervals,
        funding_paid_quote,
        s["baseline"].gross_quote,
        s["baseline"].fees_quote,
        s["baseline"].spread_quote + s["baseline"].slippage_quote,
        s["baseline"].net_quote,
        risk["baseline"],
        s["adverse"].net_quote,
        risk["adverse"],
        s["adverse"].fees_quote,
        s["adverse"].spread_quote + s["adverse"].slippage_quote,
        s["doubled"].net_quote,
        risk["doubled"],
    )


def profiles_with_doubled(profiles: dict[str, costs.CostProfile]) -> dict[str, costs.CostProfile]:
    out = dict(profiles)
    out["doubled"] = costs.doubled_spread(profiles["baseline"])
    return out


@dataclass(frozen=True)
class PairData:
    candles: Candles
    bars: Bars  # 5m
    atr: np.ndarray  # ATR14 on the 5m bars
    funding: object | None = None  # dataset.Funding
    marks: np.ndarray | None = None


def run_methods(entries: np.ndarray, pairs: dict[str, PairData], profiles: dict[str, costs.CostProfile], spec: dict, latency_bars: int = 0) -> dict[str, np.ndarray]:
    """Outcomes per method on the same entry table, sorted like the table (by decision time)."""
    params = method_params(spec)
    profiles = profiles_with_doubled(profiles) if "doubled" not in profiles else profiles
    out: dict[str, list] = {name: [] for name in METHODS}
    for row in entries:
        pair = pairs[str(row["symbol"])]
        for name in METHODS:
            result = simulate_exit(pair.candles, pair.bars, pair.atr, row, params[name], profiles["baseline"], latency_bars if name != "A" else 0)
            paid = 0.0
            if pair.funding is not None and pair.marks is not None:
                paid = costs.funding_paid(int(row["side"]), float(row["quantity"]), int(row["fill_ms"]), result.exit_ms, pair.funding.time_ms, pair.funding.rate, pair.marks)
            out[name].append(settle_exit(row, result, profiles, spec, paid))
    return {name: (np.array(rows, dtype=OUTCOME_DTYPE) if rows else np.zeros(0, dtype=OUTCOME_DTYPE)) for name, rows in out.items()}


# --- diagnostics --------------------------------------------------------------------------


def diagnostics(entries: np.ndarray, outcomes_a: np.ndarray, pairs: dict[str, PairData], spec: dict, profile: str = "baseline") -> dict:
    """Path diagnostics on the common 120-minute horizon after the fill (best prices: diagnostic
    only, never a realisable gain)."""
    column = "base" if profile == "baseline" else profile
    thresholds = [float(x) for x in spec["diagnostics"]["mfe_thresholds_r"]]
    tight = float(spec["diagnostics"]["tight_stop_r"])
    n = int(entries.size)
    mfe_a = np.zeros(n)
    mfe_common = np.zeros(n)
    after_tp = []
    tight_hit = np.zeros(n, dtype=bool)
    tight_then_tp = np.zeros(n, dtype=bool)
    for i, row in enumerate(entries):
        pair = pairs[str(row["symbol"])]
        c = pair.candles
        side, entry, r_price = int(row["side"]), float(row["entry"]), float(row["r_price"])
        start, diag_end = int(row["fill_index"]), int(row["diag_end_index"])
        exit_index = int(np.searchsorted(c.open_ms, int(outcomes_a["exit_ms"][i]) - MINUTE_MS, side="left"))
        fav = (c.high[start:diag_end] - entry) / r_price if side == 1 else (entry - c.low[start:diag_end]) / r_price
        adv = (entry - c.low[start:diag_end]) / r_price if side == 1 else (c.high[start:diag_end] - entry) / r_price
        held = max(1, exit_index - start + 1)
        mfe_a[i] = float(np.max(fav[:held])) if fav.size else 0.0
        mfe_common[i] = float(np.max(fav)) if fav.size else 0.0
        if str(outcomes_a["exit_reason"][i]) == "target" and held < fav.size:
            after_tp.append(float(np.max(fav[held:])) - float(row["target_a"] - entry) * side / r_price)
        horizon_a = max(1, int(row["end_index"]) - start)  # A's own 60-minute horizon
        hits = np.flatnonzero(adv[:horizon_a] >= tight)
        if hits.size:
            tight_hit[i] = True
            first = int(hits[0])
            target_r = side * (float(row["target_a"]) - entry) / r_price
            tight_then_tp[i] = bool(np.any(fav[first + 1 :] >= target_r))
    net_a = outcomes_a[f"net_{column}"]
    r_a = net_a / outcomes_a[f"risk_{column}"] if n else np.zeros(0)
    losing = net_a < 0
    out = {"trades": n, "truncated": int(entries["diag_truncated"].sum()) if n else 0}
    for t in thresholds:
        reached = mfe_a >= t
        out[f"mfe_ge_{t:g}R_share"] = float(reached.mean()) if n else None
        out[f"mfe_ge_{t:g}R_then_losing_share"] = float((reached & losing).sum() / reached.sum()) if reached.any() else None
    giveback = mfe_a - r_a
    out["giveback_r_mean"] = float(giveback.mean()) if n else None
    out["giveback_r_median"] = float(np.median(giveback)) if n else None
    out["mfe_common_horizon_r_mean"] = float(mfe_common.mean()) if n else None
    out["after_target_continuation_r_mean"] = float(np.mean(after_tp)) if after_tp else None
    out["after_target_trades"] = len(after_tp)
    out[f"tight_stop_{tight:g}R_hit_share"] = float(tight_hit.mean()) if n else None
    out[f"tight_stop_{tight:g}R_then_target_share"] = float(tight_then_tp.sum() / tight_hit.sum()) if tight_hit.any() else None
    out["note"] = "best prices on 1m highs/lows: diagnostic only, never a realisable gain"
    return out


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
    """n = ((z_{1-alpha} + z_{power}) * sd / delta)^2 * design effect (normal approximation)."""
    from statistics import NormalDist

    z = NormalDist().inv_cdf(1 - alpha_one_sided) + NormalDist().inv_cdf(power)
    return int(math.ceil((z * sd / delta) ** 2 * design_effect))


def _days(outcomes: np.ndarray) -> np.ndarray:
    return outcomes["decision_ms"] // DAY_MS


def method_summary(outcomes: np.ndarray, profile: str, spec: dict) -> dict:
    column = "base" if profile == "baseline" else profile
    boot = spec["statistics"]["bootstrap"]
    level = float(spec["statistics"]["lower_bound_level"])
    n = int(outcomes.size)
    if n == 0:
        return {"trades": 0}
    net = outcomes[f"net_{column}"]
    r = net / outcomes[f"risk_{column}"]
    days = _days(outcomes)
    means = day_block_bootstrap(r, days, int(boot["resamples"]), int(boot["seed"]))
    order = np.argsort(outcomes["exit_ms"], kind="stable")
    daily = np.bincount(np.unique(days, return_inverse=True)[1], weights=r)
    return {
        "trades": n,
        "days": int(np.unique(days).size),
        "mean_r": float(r.mean()),
        "total_r": float(r.sum()),
        "mean_quote": float(net.mean()),
        "total_quote": float(net.sum()),
        "lower_bound_r": float(np.quantile(means, 1 - level)),
        "worst_trade_r": float(r.min()),
        "worst_day_r": float(daily.min()),
        "drawdown_r": stats.realized_drawdown(r[order]),
        "drawdown_quote": stats.realized_drawdown(net[order]),
        "by_exit": {str(k): int((outcomes["exit_reason"] == k).sum()) for k in np.unique(outcomes["exit_reason"])},
    }


def paired_comparisons(outcomes: dict[str, np.ndarray], profile: str, spec: dict) -> dict:
    """B-A and C-A on the same entries, day-block bootstrap, Holm across the two comparisons."""
    column = "base" if profile == "baseline" else profile
    boot = spec["statistics"]["bootstrap"]
    alpha = 1 - float(spec["statistics"]["lower_bound_level"])
    a = outcomes["A"]
    if a.size == 0:
        return {"trades": 0}
    r_a = a[f"net_{column}"] / a[f"risk_{column}"]
    days = _days(a)
    results, p_values, boots = {}, {}, {}
    for name in ("B", "C"):
        other = outcomes[name]
        if not (np.array_equal(other["decision_ms"], a["decision_ms"]) and np.array_equal(other["symbol"], a["symbol"]) and np.array_equal(other[f"risk_{column}"], a[f"risk_{column}"])):
            raise ValueError(f"{name} and A are not on the same entries with the same risk")
        diff = other[f"net_{column}"] / other[f"risk_{column}"] - r_a
        means = day_block_bootstrap(diff, days, int(boot["resamples"]), int(boot["seed"]))
        boots[name] = means
        p_values[f"{name}-A"] = float((np.sum(means <= 0) + 1) / (means.size + 1))
        results[f"{name}-A"] = {"mean_diff_r": float(diff.mean()), "total_diff_r": float(diff.sum()), "total_diff_quote": float((other[f"net_{column}"] - a[f"net_{column}"]).sum()), "share_better": float((diff > 0).mean()), "share_worse": float((diff < 0).mean())}
    adjusted = holm(p_values, alpha)
    for key, value in adjusted.items():
        means = boots[key[0]]
        results[key].update(value)
        results[key]["lower_bound_holm_r"] = float(np.quantile(means, value["level"]))
        results[key]["lower_bound_unadjusted_r"] = float(np.quantile(means, alpha))
    results["trades"] = int(a.size)
    results["days"] = int(np.unique(days).size)
    return results


def portfolio(outcomes: dict[str, np.ndarray], instruments: dict[str, costs.Instrument], spec: dict) -> dict:
    """budgets.budget_study with the same capital and exposure limits for the three methods."""
    budget = float(spec["decision"]["portfolio_budget_usdt"])
    return {name: budgets_module.budget_study(rows[np.argsort(rows["decision_ms"], kind="stable")], instruments, "base", (budget,))[f"{budget:g}"] for name, rows in outcomes.items() if rows.size}


def verdict(report: dict, spec: dict) -> dict:
    """Pre-declared decision rule (spec ``decision``) applied to a report."""
    req = spec["data_requirements"]
    base = report["baseline"]
    trades, days = base["comparisons"].get("trades", 0), base["comparisons"].get("days", 0)
    if trades < req["min_trades"] or days < req["min_days"]:
        return {"verdict": "INSUFFICIENT_DATA", "trades": trades, "days": days}
    passing, improving = [], []
    for name in ("B", "C"):
        comp = base["comparisons"][f"{name}-A"]
        if not (comp["rejected"] and comp["lower_bound_holm_r"] > 0):
            continue
        improving.append(name)
        own = base["methods"][name]
        adverse = report["adverse"]
        ok = (
            own["mean_r"] > 0
            and own["lower_bound_r"] > 0
            and adverse["methods"][name]["mean_r"] > 0
            and adverse["comparisons"][f"{name}-A"]["mean_diff_r"] > 0
            and own["drawdown_r"] <= spec["decision"]["max_drawdown_r"]
            and (report.get("portfolio", {}).get(name, {}).get("max_drawdown_share") or 0.0) <= spec["decision"]["max_portfolio_drawdown_share"]
        )
        if ok:
            passing.append(name)
    if passing:
        return {"verdict": "IMPROVEMENT_PROFITABLE_SUPPORTED", "methods": passing}
    if improving:
        return {"verdict": "IMPROVEMENT_BUT_LOSS_MAKING", "methods": improving}
    return {"verdict": "NO_IMPROVEMENT"}


def build_report(entries: np.ndarray, pairs: dict[str, PairData], profiles: dict[str, costs.CostProfile], instruments: dict[str, costs.Instrument], spec: dict) -> dict:
    outcomes = run_methods(entries, pairs, profiles, spec)
    latency = run_methods(entries, pairs, profiles, spec, latency_bars=1)
    report: dict = {"schema": "research-v2-dynamic-exits-report.v1", "spec_sha256": spec_sha256(), "entries": int(entries.size)}
    for profile in ("baseline", "adverse", "doubled"):
        report[profile] = {"methods": {name: method_summary(rows, profile, spec) for name, rows in outcomes.items()}, "comparisons": paired_comparisons(outcomes, profile, spec)}
    report["latency_1_bar"] = {"methods": {name: method_summary(rows, "baseline", spec) for name, rows in latency.items()}, "comparisons": paired_comparisons(latency, "baseline", spec)}
    report["diagnostics"] = diagnostics(entries, outcomes["A"], pairs, spec) if entries.size else {}
    report["portfolio"] = portfolio(outcomes, instruments, spec)
    report["verdict"] = verdict(report, spec)
    return report


# --- synthetic data and CLI ---------------------------------------------------------------


def synthetic_pair(symbol: str, minutes: int, seed: int, price: float = 100.0, vol: float = 0.0008, tick: float = 0.01) -> tuple[PairData, costs.Instrument]:
    """A random-walk 1-minute series (no market data) with 5m bars and ATR14."""
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
    return PairData(candles, bars, atr), instrument


def synthetic_profiles() -> dict[str, costs.CostProfile]:
    """The campaign's cost assumptions (research-cost-assumptions.v1, sha256 83b0216d…)."""
    return {
        "baseline": costs.CostProfile("baseline", 0.0, 0.0002, 0.0, 0.0, 0.0005, 0.0, 0.0003),
        "adverse": costs.CostProfile("adverse", 0.0, 0.001, 0.0, 0.0, 0.001, 0.0, 0.001),
    }


def command_synthetic(args: argparse.Namespace) -> int:
    spec = load_spec()
    pairs, instruments, tables = {}, {}, []
    for k in range(args.pairs):
        symbol = f"SYN{k}USDT"
        pair, instrument = synthetic_pair(symbol, args.minutes, args.seed + k)
        pairs[symbol], instruments[symbol] = pair, instrument
        rng = np.random.default_rng(args.seed + 1000 + k)
        valid = np.flatnonzero(~np.isnan(pair.atr))
        decisions = np.sort(rng.choice(valid[:-13], size=min(args.decisions, valid.size - 13), replace=False))
        for side in (1, -1):
            tables.append(build_entry_table(pair.candles, pair.bars, pair.atr, decisions, side, instrument, int(pair.candles.open_ms[-1]) + MINUTE_MS, spec))
    entries = np.concatenate(tables)
    entries = entries[np.lexsort((entries["side"], entries["symbol"], entries["decision_ms"]))]
    report = build_report(entries, pairs, synthetic_profiles(), instruments, spec)
    report["data"] = "SYNTHETIC random walk (no market data)"
    text = json.dumps(report, indent=1, sort_keys=True, default=float)
    if args.out:
        Path(args.out).write_text(text + "\n")
    print(json.dumps({"entries": report["entries"], "verdict": report["verdict"]}))
    return 0


def command_run(args: argparse.Namespace) -> int:
    """Real-data run: refused without an explicit owner authorization reference."""
    if not args.owner_authorization.strip():
        print("refused: the exploration needs the owner's written authorization (--owner-authorization)", file=sys.stderr)
        return 2
    raise SystemExit("not wired yet: the real-data driver is written after the owner's go, against the frozen spec")


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
    run = sub.add_parser("run", help="real data (needs the owner's authorization)")
    run.add_argument("--owner-authorization", default="")
    run.set_defaults(func=command_run)
    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
