"""Summaries, the r1/r2 gates restated for swing v2, the matched-control surplus and selection."""

from __future__ import annotations

import math
import statistics
from collections import Counter, defaultdict
from collections.abc import Mapping, Sequence
from datetime import datetime, timezone

from .engine import REFERENCE_EQUITY, RISK_FRACTION, Trade

PROFILES = ("baseline", "adverse")
SCENARIOS = ("baseline", "adverse", "baseline_conservative", "adverse_conservative")  # OKX campaign
MIN_TRADES = {"training": 100, "validation": 50, "holdout": 50, "okx": 200}
MIN_PAIRS = 5
MIN_PF = {"training": {"baseline": 1.2, "adverse": 1.0}, "validation": {"baseline": 1.2, "adverse": 1.0},
          "holdout": {"baseline": 1.1, "adverse": 1.0}, "okx": {"baseline": 1.1, "adverse": 1.0}}
MAX_DRAWDOWN_R = 20.0
MIN_YEAR_TRADES = 30
MAX_SELECTED_PER_SIDE = 3
SELECTION_P = 0.10
EXIT_CLASSES = ("stop", "target", "time", "trailing", "delisting")


def year_of(ms: int) -> str:
    return str(datetime.fromtimestamp(ms / 1000, tz=timezone.utc).year)


def month_of(ms: int) -> str:
    return datetime.fromtimestamp(ms / 1000, tz=timezone.utc).strftime("%Y-%m")


def summarize(trades: Sequence[Trade], profile: str, equity: float = REFERENCE_EQUITY, conservative: bool = False) -> dict:
    """Trades in exit order; PnL attributed to the exit year; drawdown on realized equity, in R of 1 % of equity.

    ``conservative``: trades touched by a delisting, an interruption or the censoring are booked at no better than
    the loss of their conservative planned risk, and R is measured against the conservative planned risk."""
    ordered = sorted(trades, key=lambda t: (t.exit_ms, t.symbol))
    count = len(ordered)
    nets = [t.net_conservative(profile) if conservative else t.net[profile] for t in ordered]
    rs = [t.r_conservative(profile) if conservative else t.r(profile) for t in ordered]
    gains = sum(n for n in nets if n > 0)
    losses = -sum(n for n in nets if n <= 0)
    equity_curve = peak = drawdown = 0.0
    for net in nets:
        equity_curve += net
        peak = max(peak, equity_curve)
        drawdown = max(drawdown, peak - equity_curve)
    by_year: dict = defaultdict(lambda: {"trades": 0, "net": 0.0, "net_r": 0.0})
    by_pair: dict = defaultdict(lambda: {"trades": 0, "net": 0.0, "net_r": 0.0})
    by_month: dict = defaultdict(lambda: {"trades": 0, "net": 0.0, "net_r": 0.0})
    for trade, net, r in zip(ordered, nets, rs):
        for bucket in (by_year[year_of(trade.exit_ms)], by_pair[trade.symbol], by_month[month_of(trade.exit_ms)]):
            bucket["trades"] += 1
            bucket["net"] += net
            bucket["net_r"] += r
    by_decision_year: dict = defaultdict(lambda: {"trades": 0, "net": 0.0, "net_r": 0.0})
    by_month_r: dict = defaultdict(list)
    for trade, net, r in zip(ordered, nets, rs):
        bucket = by_decision_year[year_of(trade.decision_ms)]
        bucket["trades"] += 1
        bucket["net"] += net
        bucket["net_r"] += r
        by_month_r[month_of(trade.decision_ms)].append(r)
    holds = [(t.exit_ms - t.fill_ms) / 3_600_000 for t in ordered]
    reasons = Counter(t.reason for t in ordered)
    return {
        "trades": count,
        "pairs_with_trades": len(by_pair),
        "pairs_positive": sum(1 for v in by_pair.values() if v["net"] > 0),
        "wins": sum(1 for n in nets if n > 0),
        "net_quote": sum(nets),
        "mean_net_r": sum(rs) / count if count else None,
        "median_net_r": statistics.median(rs) if count else None,
        "profit_factor": gains / losses if losses > 0 else (math.inf if gains > 0 else None),
        "drawdown_quote": drawdown,
        "drawdown_r": drawdown / (RISK_FRACTION * equity),
        "funding_quote": sum(t.funding[profile] for t in ordered),
        "exit_classes": {name: reasons.get(name, 0) for name in EXIT_CLASSES},
        "ambiguous": sum(1 for t in ordered if t.ambiguous),
        "mean_hold_hours": sum(holds) / count if count else None,
        "by_year": {k: dict(v) for k, v in sorted(by_year.items())},
        "by_pair": {k: dict(v) for k, v in sorted(by_pair.items())},
        "by_month": {k: dict(v) for k, v in sorted(by_month.items())},
        "by_decision_year": {k: dict(v) for k, v in sorted(by_decision_year.items())},
        "events": {e: sum(1 for t in ordered if getattr(t, "event", None) == e) for e in ("delisting", "interruption", "censored")},
        "funding_imputed": sum(getattr(t, "funding_imputed", 0) for t in ordered),
        "month_block_ci90": month_block_ci(by_month_r),
    }


def month_block_ci(by_month: Mapping[str, list], draws: int = 2000, seed: int = 20261014) -> list | None:
    """90 % interval of the mean net R by a bootstrap over calendar months of decision (correlated pairs: a month is
    one block)."""
    import random

    blocks = [v for v in by_month.values() if v]
    if len(blocks) < 2:
        return None
    rng = random.Random(seed)
    means = []
    for _ in range(draws):
        sample = [blocks[rng.randrange(len(blocks))] for _ in blocks]
        values = [x for block in sample for x in block]
        means.append(sum(values) / len(values))
    means.sort()
    return [means[int(0.05 * draws)], means[int(0.95 * draws) - 1]]


def gate_reasons(summary: Mapping, phase: str, profile: str, years: Sequence[str]) -> list[str]:
    """The r1/r2 protocol gates; drawdown in R (owner, 2026-10-14) and the year rule of the phase.

    Training: every year at least 30 trades and net > 0 (r1/r2). Validation and holdout: a year is
    required positive only when it has at least 30 trades (owner, 2026-10-14); thinner years are reported.
    """
    reasons = []
    if summary["trades"] < MIN_TRADES[phase]:
        reasons.append("insufficient_trades")
    if summary["pairs_with_trades"] < MIN_PAIRS:
        reasons.append("insufficient_pairs")
    if summary["mean_net_r"] is None or summary["mean_net_r"] <= 0:
        reasons.append("nonpositive_mean_net_r")
    if phase == "okx" and summary.get("net_quote", 0.0) <= 0:
        reasons.append("nonpositive_net_pnl")
    pf = summary["profit_factor"]
    if pf is None:
        reasons.append("undefined_profit_factor")
    elif pf < MIN_PF[phase][profile]:
        reasons.append("profit_factor_below_threshold")
    if summary["drawdown_r"] > MAX_DRAWDOWN_R:
        reasons.append("drawdown_exceeded")
    for year in years:
        annual = summary["by_year"].get(year, {"trades": 0, "net": 0.0})
        if phase == "training":
            if annual["trades"] < MIN_YEAR_TRADES:
                reasons.append(f"insufficient_year_trades:{year}")
            if annual["net"] <= 0:
                reasons.append(f"nonpositive_year:{year}")
        elif annual["trades"] >= MIN_YEAR_TRADES and annual["net"] <= 0:
            reasons.append(f"nonpositive_year:{year}")
    return reasons


def passes(document: Mapping, phase: str, years: Sequence[str]) -> bool:
    return all(not gate_reasons(document[p], phase, p, years) for p in PROFILES)


def control_position(value: float | None, controls: Sequence[float | None]) -> dict:
    """Surplus over the control median, the share of controls below, and the empirical p (ties count against)."""
    values = [c if c is not None else -math.inf for c in controls]
    finite = [c for c in values if math.isfinite(c)]
    median = statistics.median(finite) if finite else None
    if value is None or median is None:
        return {"control_median": median, "control_p90": None, "surplus": None, "percentile": None, "p_empirical": None}
    above = sum(1 for c in values if c >= value)
    p90 = sorted(finite)[min(len(finite) - 1, math.ceil(0.9 * len(finite)) - 1)]
    return {"control_median": median, "control_p90": p90, "surplus": value - median,
            "percentile": sum(1 for c in values if c < value) / len(values), "p_empirical": (1 + above) / (1 + len(values))}


def benjamini_hochberg(pvalues: Mapping[str, float | None]) -> dict[str, float | None]:
    """BH q-values (step-up, monotone), for information only."""
    items = sorted(((p, k) for k, p in pvalues.items() if p is not None))
    m = len(items)
    q: dict[str, float | None] = {k: None for k in pvalues}
    running = 1.0
    for rank in range(m, 0, -1):
        p, key = items[rank - 1]
        running = min(running, p * m / rank)
        q[key] = running
    return q


def selectable(row: Mapping) -> bool:
    """Training selection: every gate on both profiles, positive surplus on both, p ≤ 0.10 on the baseline."""
    if not row["passes_gates"]:
        return False
    for profile in PROFILES:
        position = row["control"][profile]
        if position["surplus"] is None or position["surplus"] <= 0:
            return False
    return row["control"]["baseline"]["p_empirical"] <= SELECTION_P


def select(rows: Sequence[Mapping]) -> list[str]:
    """At most three per side, by decreasing baseline surplus (ties: configuration id)."""
    chosen = []
    for side in ("long", "short"):
        eligible = [r for r in rows if r["side"] == side and selectable(r)]
        eligible.sort(key=lambda r: (-r["control"]["baseline"]["surplus"], r["id"]))
        chosen.extend(r["id"] for r in eligible[:MAX_SELECTED_PER_SIDE])
    return chosen
