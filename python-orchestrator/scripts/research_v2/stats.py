"""Statistics and gates of the v2 screen (PREREGISTRATION.md, section 6)."""

from __future__ import annotations

import numpy as np

BOOTSTRAP_RESAMPLES = 2_000
BOOTSTRAP_QUANTILE = 0.10
HOUR_MS = 3_600_000

MIN_TRADES = {"4h": (200, 60), "15m": (400, 150)}  # training total, per year
MIN_MEAN_R = {"4h": 0.15, "15m": 0.10}
MIN_PF = {"baseline": 1.25, "adverse": 1.10}
MIN_CONTROL_EDGE = 0.10
MIN_POSITIVE_PAIRS = 6
MAX_DRAWDOWN_QUOTE = 6_000.0
VALIDATION_MIN_TRADES = {"4h": 60, "15m": 150}
VALIDATION_MIN_PF = {"baseline": 1.10, "adverse": 1.00}
VALIDATION_RETENTION = 0.5


def years_of(ms: np.ndarray) -> np.ndarray:
    return ms.astype("datetime64[ms]").astype("datetime64[Y]").astype(int) + 1970


def months_of(ms: np.ndarray) -> np.ndarray:
    return ms.astype("datetime64[ms]").astype("datetime64[M]").astype(str)


def r_values(outcomes: np.ndarray, profile: str) -> np.ndarray:
    return outcomes[f"net_{profile}"] / outcomes[f"risk_{profile}"]


def bootstrap_lower_bound(values: np.ndarray, quantile: float = BOOTSTRAP_QUANTILE, resamples: int = BOOTSTRAP_RESAMPLES, seed: int = 0) -> float | None:
    if values.size < 2:
        return None
    rng = np.random.default_rng(seed)
    samples = rng.choice(values, size=(resamples, values.size), replace=True)
    return float(np.quantile(samples.mean(axis=1), quantile))


def realized_drawdown(net_by_exit: np.ndarray) -> float:
    if net_by_exit.size == 0:
        return 0.0
    equity = np.cumsum(net_by_exit)
    peak = np.maximum.accumulate(np.maximum(equity, 0.0))
    return float(np.max(peak - equity))


def max_exposure(outcomes: np.ndarray) -> float:
    if outcomes.size == 0:
        return 0.0
    times = np.concatenate([outcomes["fill_ms"], outcomes["exit_ms"]])
    deltas = np.concatenate([outcomes["notional"], -outcomes["notional"]])
    kinds = np.concatenate([np.ones(outcomes.size), np.zeros(outcomes.size)])  # exits (0) before fills (1) at equal times
    order = np.lexsort((kinds, times))
    return float(np.max(np.cumsum(deltas[order])))


def _profit_factor(net: np.ndarray) -> float | None:
    gains = float(net[net > 0].sum())
    losses = float(-net[net < 0].sum())
    if losses == 0:
        return None
    return gains / losses


def _group(keys: np.ndarray, net: np.ndarray, r: np.ndarray) -> dict:
    out = {}
    for key in np.unique(keys):
        mask = keys == key
        out[str(key)] = {"trades": int(mask.sum()), "net": float(net[mask].sum()), "mean_r": float(r[mask].mean()), "win_rate": float((net[mask] > 0).mean())}
    return out


def summarize(outcomes: np.ndarray, profile: str) -> dict:
    """Per-profile statistics of a configuration's outcomes (sorted by decision time)."""
    n = int(outcomes.size)
    if n == 0:
        return {"trades": 0}
    net = outcomes[f"net_{profile}"]
    r = r_values(outcomes, profile)
    exit_order = np.argsort(outcomes["exit_ms"], kind="stable")
    years = years_of(outcomes["exit_ms"])
    months = months_of(outcomes["exit_ms"])
    by_month = _group(months, net, r)
    best_month = max(by_month, key=lambda key: by_month[key]["net"])
    by_pair = _group(outcomes["symbol"], net, r)
    by_year = _group(years, net, r)
    by_year_decision = _group(years_of(outcomes["decision_ms"]), net, r)
    sides = outcomes["side"]
    by_side = {name: _group(np.full(int((sides == value).sum()), name), net[sides == value], r[sides == value]).get(name) for name, value in (("long", 1), ("short", -1)) if (sides == value).any()}
    reasons = outcomes["exit_reason"]
    by_exit = {str(reason): {"trades": int((reasons == reason).sum()), "net": float(net[reasons == reason].sum()), "mean_r": float(r[reasons == reason].mean())} for reason in np.unique(reasons)}
    holding_hours = (outcomes["exit_ms"] - outcomes["fill_ms"]) / HOUR_MS
    return {
        "trades": n,
        "wins": int((net > 0).sum()),
        "win_rate": float((net > 0).mean()),
        "net": float(net.sum()),
        "gross": float(outcomes["gross"].sum()),
        "fees": float(outcomes[f"fees_{profile}"].sum()) if f"fees_{profile}" in outcomes.dtype.names else None,
        "friction": float(outcomes[f"friction_{profile}"].sum()) if f"friction_{profile}" in outcomes.dtype.names else None,
        "funding_paid": float(outcomes["funding_paid"].sum()),
        "mean_r": float(r.mean()),
        "std_r": float(r.std(ddof=1)) if n > 1 else None,
        "se_r": float(r.std(ddof=1) / np.sqrt(n)) if n > 1 else None,
        "lower_bound_r": bootstrap_lower_bound(r),
        "profit_factor": _profit_factor(net),
        "realized_drawdown": realized_drawdown(net[exit_order]),
        "max_exposure": max_exposure(outcomes),
        "mean_holding_hours": float(holding_hours.mean()),
        "by_year": by_year,
        "by_year_decision": by_year_decision,
        "by_pair": by_pair,
        "pairs_with_trades": len(by_pair),
        "pairs_positive": int(sum(1 for value in by_pair.values() if value["net"] > 0)),
        "by_month": {key: round(value["net"], 2) for key, value in by_month.items()},
        "positive_months": int(sum(1 for value in by_month.values() if value["net"] > 0)),
        "best_month": best_month,
        "net_without_best_month": float(net.sum() - by_month[best_month]["net"]),
        "by_side": by_side,
        "by_exit": by_exit,
    }


def training_gates(base: dict, adverse: dict, cadence: str, years: tuple[int, ...] = (2023, 2024), control: dict | None = None) -> list[str]:
    """Reasons for failing the training gates G1–G7; empty when the configuration passes."""
    reasons: list[str] = []
    minimum_total, minimum_year = MIN_TRADES[cadence]
    for name, summary in (("baseline", base), ("adverse", adverse)):
        if summary.get("trades", 0) < minimum_total:
            reasons.append(f"G1:{name}:trades<{minimum_total}")
            continue
        for year in years:
            year_row = summary["by_year_decision"].get(str(year))
            if year_row is None or year_row["trades"] < minimum_year:
                reasons.append(f"G1:{name}:trades_{year}<{minimum_year}")
            if year_row is None or year_row["net"] <= 0:
                reasons.append(f"G2:{name}:net_{year}<=0")
        if name == "baseline":
            if summary["mean_r"] < MIN_MEAN_R[cadence]:
                reasons.append(f"G3:baseline:mean_r<{MIN_MEAN_R[cadence]}")
            if summary["lower_bound_r"] is None or summary["lower_bound_r"] <= 0:
                reasons.append("G3:baseline:lower_bound<=0")
        elif summary["mean_r"] <= 0:
            reasons.append("G3:adverse:mean_r<=0")
        pf = summary["profit_factor"]
        if pf is None or pf < MIN_PF[name]:
            reasons.append(f"G4:{name}:pf<{MIN_PF[name]}")
        if summary["net_without_best_month"] <= 0:
            reasons.append(f"G6:{name}:net_without_best_month<=0")
        if summary["realized_drawdown"] > MAX_DRAWDOWN_QUOTE:
            reasons.append(f"G7:{name}:drawdown>{MAX_DRAWDOWN_QUOTE:g}")
    if base.get("trades", 0) >= minimum_total and base["pairs_positive"] < MIN_POSITIVE_PAIRS:
        reasons.append(f"G6:baseline:pairs_positive<{MIN_POSITIVE_PAIRS}")
    if control is not None and base.get("trades", 0) >= minimum_total:
        if control.get("p95") is None:
            reasons.append("G5:control_missing")
        else:
            if base["mean_r"] <= control["p95"]:
                reasons.append("G5:mean_r<=control_p95")
            if base["mean_r"] - control["median"] < MIN_CONTROL_EDGE:
                reasons.append(f"G5:edge<{MIN_CONTROL_EDGE}")
    return reasons


SCALP_MIN_TRADES = {"5m": (1_000, 300), "1m": (2_000, 600)}
SCALP_MIN_MEAN_R = 0.05
SCALP_MIN_PF = {"baseline": 1.20, "adverse": 1.00}
SCALP_MIN_CONTROL_EDGE = 0.05
SCALP_VALIDATION_MIN_TRADES = {"5m": 300, "1m": 600}


def scalping_training_gates(base: dict, adverse: dict, doubled: dict, cadence: str, years: tuple[int, ...] = (2023, 2024), control: dict | None = None) -> list[str]:
    """Training gates of the scalping pre-registration (section 4)."""
    reasons: list[str] = []
    minimum_total, minimum_year = SCALP_MIN_TRADES[cadence]
    for name, summary in (("baseline", base), ("adverse", adverse)):
        if summary.get("trades", 0) < minimum_total:
            reasons.append(f"G1:{name}:trades<{minimum_total}")
            continue
        for year in years:
            year_row = summary["by_year_decision"].get(str(year))
            if year_row is None or year_row["trades"] < minimum_year:
                reasons.append(f"G1:{name}:trades_{year}<{minimum_year}")
            if year_row is None or year_row["net"] <= 0:
                reasons.append(f"G2:{name}:net_{year}<=0")
        if name == "baseline":
            if summary["mean_r"] < SCALP_MIN_MEAN_R:
                reasons.append(f"G3:baseline:mean_r<{SCALP_MIN_MEAN_R}")
            if summary["lower_bound_r"] is None or summary["lower_bound_r"] <= 0:
                reasons.append("G3:baseline:lower_bound<=0")
        elif summary["mean_r"] <= 0:
            reasons.append("G3:adverse:mean_r<=0")
        pf = summary["profit_factor"]
        if pf is None or pf < SCALP_MIN_PF[name]:
            reasons.append(f"G4:{name}:pf<{SCALP_MIN_PF[name]}")
        if summary["net_without_best_month"] <= 0:
            reasons.append(f"G6:{name}:net_without_best_month<=0")
        if summary["realized_drawdown"] > MAX_DRAWDOWN_QUOTE:
            reasons.append(f"G7:{name}:drawdown>{MAX_DRAWDOWN_QUOTE:g}")
    if base.get("trades", 0) >= minimum_total:
        if base["pairs_positive"] < MIN_POSITIVE_PAIRS:
            reasons.append(f"G6:baseline:pairs_positive<{MIN_POSITIVE_PAIRS}")
        if doubled.get("trades", 0) == 0 or doubled["net"] <= 0:
            reasons.append("G8:doubled_spread:net<=0")
        if control is not None:
            if control.get("p95") is None:
                reasons.append("G5:control_missing")
            else:
                if base["mean_r"] <= control["p95"]:
                    reasons.append("G5:mean_r<=control_p95")
                if base["mean_r"] - control["median"] < SCALP_MIN_CONTROL_EDGE:
                    reasons.append(f"G5:edge<{SCALP_MIN_CONTROL_EDGE}")
    return reasons


def scalping_validation_gates(base: dict, adverse: dict, cadence: str, training_mean_r_base: float) -> list[str]:
    reasons: list[str] = []
    for name, summary in (("baseline", base), ("adverse", adverse)):
        if summary.get("trades", 0) < SCALP_VALIDATION_MIN_TRADES[cadence]:
            reasons.append(f"V:{name}:trades<{SCALP_VALIDATION_MIN_TRADES[cadence]}")
            continue
        if summary["net"] <= 0:
            reasons.append(f"V:{name}:net<=0")
        pf = summary["profit_factor"]
        if pf is None or pf < VALIDATION_MIN_PF[name]:
            reasons.append(f"V:{name}:pf<{VALIDATION_MIN_PF[name]}")
        if summary["mean_r"] <= 0:
            reasons.append(f"V:{name}:mean_r<=0")
    if base.get("trades", 0) >= SCALP_VALIDATION_MIN_TRADES[cadence] and base["mean_r"] < VALIDATION_RETENTION * training_mean_r_base:
        reasons.append("V:baseline:mean_r<50%_of_training")
    return reasons


def validation_gates(base: dict, adverse: dict, cadence: str, training_mean_r_base: float) -> list[str]:
    reasons: list[str] = []
    for name, summary in (("baseline", base), ("adverse", adverse)):
        if summary.get("trades", 0) < VALIDATION_MIN_TRADES[cadence]:
            reasons.append(f"V:{name}:trades<{VALIDATION_MIN_TRADES[cadence]}")
            continue
        if summary["net"] <= 0:
            reasons.append(f"V:{name}:net<=0")
        pf = summary["profit_factor"]
        if pf is None or pf < VALIDATION_MIN_PF[name]:
            reasons.append(f"V:{name}:pf<{VALIDATION_MIN_PF[name]}")
        if summary["mean_r"] <= 0:
            reasons.append(f"V:{name}:mean_r<=0")
    if base.get("trades", 0) >= VALIDATION_MIN_TRADES[cadence] and base["mean_r"] < VALIDATION_RETENTION * training_mean_r_base:
        reasons.append("V:baseline:mean_r<50%_of_training")
    return reasons
