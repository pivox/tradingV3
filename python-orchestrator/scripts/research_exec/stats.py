"""Summaries, block bootstrap and the pre-registered verdict of the execution study."""

from __future__ import annotations

import numpy as np

from .simulate import HORIZONS_S, PLACEMENTS, STATUS

SESSIONS = {"asia": (0, 8), "europe": (8, 13), "us": (13, 21), "late": (21, 24)}
BLOCK_S = 600
RESAMPLES = 2000
SEED = 20261012
PRIMARY_HORIZON_S = 60
SECONDARY_HORIZONS_S = (10, 300)
MINIMUM_FILL_RATE = 0.10


def block_bootstrap_lower_bound(values: np.ndarray, moments_us: np.ndarray, quantile: float = 0.10, resamples: int = RESAMPLES, block_s: int = BLOCK_S, seed: int = SEED) -> float | None:
    """Lower quantile of the resampled mean, resampling whole time blocks (dependence-aware)."""
    values = np.asarray(values, dtype=np.float64)
    keep = np.isfinite(values)
    values, moments_us = values[keep], np.asarray(moments_us)[keep]
    if values.size == 0:
        return None
    blocks = moments_us // (block_s * 1_000_000)
    unique, inverse = np.unique(blocks, return_inverse=True)
    sums = np.bincount(inverse, weights=values, minlength=unique.size)
    counts = np.bincount(inverse, minlength=unique.size).astype(np.float64)
    if unique.size == 1:
        return float(values.mean())
    rng = np.random.default_rng(seed)
    draws = rng.integers(0, unique.size, size=(resamples, unique.size))
    means = np.empty(resamples)
    for r in range(resamples):
        picked = np.bincount(draws[r], minlength=unique.size).astype(np.float64)
        means[r] = float(picked @ sums) / float(picked @ counts)
    return float(np.quantile(means, quantile))


def _f(value) -> float | None:
    """Finite float or None (NaN never reaches the JSON documents)."""
    value = float(value)
    return value if np.isfinite(value) else None


def _hour_utc(ts_us: np.ndarray) -> np.ndarray:
    return (ts_us // 1_000_000 % 86_400) // 3600


def group_summary(rows: np.ndarray, maker_bp: float) -> dict:
    """Fill statistics and mark-outs of one group of order rows (one or several sides)."""
    status = rows["status"]
    eligible = int(np.sum(status >= STATUS["crossed"]))
    filled_rows = rows[status == STATUS["filled"]]
    summary = {
        "decisions": int(rows.size),
        "stale": int(np.sum(status == STATUS["stale"])),
        "no_inside": int(np.sum(status == STATUS["no_inside"])),
        "crossed": int(np.sum(status == STATUS["crossed"])),
        "eligible": eligible,
        "filled": int(filled_rows.size),
        "fill_rate": float(filled_rows.size / eligible) if eligible else None,
        "fill_delay_s_p50": float(np.median(filled_rows["fill_delay_us"]) / 1e6) if filled_rows.size else None,
        "half_spread_bp_mean": _f(np.nanmean(rows["half_spread_bp"][status >= STATUS["no_inside"]])) if eligible else None,
        "m_0_mean": _f(np.nanmean(filled_rows["m_0"])) if filled_rows.size else None,
        "horizons": {},
    }
    for h in HORIZONS_S:
        marks = filled_rows[f"m_{h}s"]
        drift = rows[f"d_{h}s"][status >= STATUS["no_inside"]]
        item = {
            "mean_bp": _f(np.nanmean(marks)) if filled_rows.size else None,
            "median_bp": _f(np.nanmedian(marks)) if filled_rows.size else None,
            "net_mean_bp": _f(np.nanmean(marks) - maker_bp) if filled_rows.size else None,
            "net_lb90_bp": None,
            "round_trip_mean_bp": _f(np.nanmean(filled_rows[f"rt_{h}s"])) if filled_rows.size else None,
            "drift_mean_bp": _f(np.nanmean(drift)) if drift.size and np.isfinite(drift).any() else None,
            "stale_share": float(np.mean((filled_rows["stale_mask"] >> HORIZONS_S.index(h)) & 1)) if filled_rows.size else None,
        }
        if filled_rows.size:
            lb = block_bootstrap_lower_bound(marks, filled_rows["fill_ts"])
            item["net_lb90_bp"] = None if lb is None else float(lb - maker_bp)
        summary["horizons"][str(h)] = item
    return summary


def summarize_symbol(rows: np.ndarray, maker_bp: float) -> dict:
    """Per placement: pooled sides, each side, and sessions at the primary horizon."""
    result: dict = {}
    for placement_name, placement in PLACEMENTS.items():
        subset = rows[rows["placement"] == placement]
        entry = {
            "pooled": group_summary(subset, maker_bp),
            "buy": group_summary(subset[subset["side"] > 0], maker_bp),
            "sell": group_summary(subset[subset["side"] < 0], maker_bp),
            "sessions": {},
        }
        hours = _hour_utc(subset["decision_ts"])
        for session, (start, stop) in SESSIONS.items():
            mask = (hours >= start) & (hours < stop)
            if mask.any():
                entry["sessions"][session] = group_summary(subset[mask], maker_bp)
        result[placement_name] = entry
    return result


def verdict(symbol_summaries: dict[str, dict], rule: str, placement: str = "touch") -> dict:
    """Pre-registered rule: with the gating rule and placement, every symbol must show a
    positive mean net mark-out at the primary horizon with a positive block-bootstrap 90 %
    lower bound, a fill rate of at least 10 %, and the same at one secondary horizon."""
    checks = {}
    for symbol, summary in sorted(symbol_summaries.items()):
        pooled = summary[placement]["pooled"]
        primary = pooled["horizons"][str(PRIMARY_HORIZON_S)]
        secondary_ok = any(
            (pooled["horizons"][str(h)]["net_mean_bp"] or 0) > 0 and (pooled["horizons"][str(h)]["net_lb90_bp"] or 0) > 0
            for h in SECONDARY_HORIZONS_S
        )
        checks[symbol] = {
            "fill_rate_ok": (pooled["fill_rate"] or 0) >= MINIMUM_FILL_RATE,
            "primary_net_mean_bp": primary["net_mean_bp"],
            "primary_net_lb90_bp": primary["net_lb90_bp"],
            "primary_ok": (primary["net_mean_bp"] or 0) > 0 and (primary["net_lb90_bp"] or 0) > 0,
            "secondary_ok": secondary_ok,
        }
        checks[symbol]["passes"] = checks[symbol]["fill_rate_ok"] and checks[symbol]["primary_ok"] and secondary_ok
    return {
        "rule": rule,
        "placement": placement,
        "primary_horizon_s": PRIMARY_HORIZON_S,
        "secondary_horizons_s": list(SECONDARY_HORIZONS_S),
        "minimum_fill_rate": MINIMUM_FILL_RATE,
        "symbols": checks,
        "maker_edge": bool(checks) and all(item["passes"] for item in checks.values()),
    }
