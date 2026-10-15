"""Month-block bootstrap and the Gate 1 criteria G1-G7 (§7, §8).

`research_swing2.stats.month_block_ci` returns a fixed 90 % interval (quantiles 5-95); the protocol needs 95 %, so
the bootstrap here takes the level as a parameter. Blocks are the calendar months (UTC) of the decision hour; one
draw resamples the months present with replacement; each interval uses its own generator seeded with SEED, so a
result does not depend on the order of the calls.
"""

from __future__ import annotations

import math
from collections.abc import Sequence

import numpy as np

from . import protocol as pr

GATES = ("G1", "G2", "G3", "G4", "G5", "G6", "G7")


def _draw_counts(blocks: int, draws: int, seed: int) -> np.ndarray:
    rng = np.random.default_rng(seed)
    picks = rng.integers(0, blocks, size=(draws, blocks))
    counts = np.zeros((draws, blocks))
    np.add.at(counts, (np.repeat(np.arange(draws), blocks), picks.ravel()), 1.0)
    return counts


def _quantiles(values: np.ndarray, level: float) -> list[float] | None:
    values = values[np.isfinite(values)]
    if values.size == 0:
        return None
    tail = (1.0 - level) / 2.0
    return [float(np.quantile(values, tail)), float(np.quantile(values, 1.0 - tail))]


def block_ci(months: Sequence[str], values: Sequence[float], level: float = pr.CI_LEVEL, draws: int = pr.BOOTSTRAP_DRAWS,
             seed: int = pr.SEED) -> list[float] | None:
    """Interval of the mean of `values` (one per observation, its month in `months`)."""
    months = np.asarray(months)
    values = np.asarray(values, dtype=np.float64)
    keep = np.isfinite(values)
    months, values = months[keep], values[keep]
    labels, index = np.unique(months, return_inverse=True)
    if labels.size < 2:
        return None
    sums = np.bincount(index, values, labels.size)
    sizes = np.bincount(index, None, labels.size).astype(np.float64)
    counts = _draw_counts(labels.size, draws, seed)
    with np.errstate(invalid="ignore", divide="ignore"):
        means = (counts @ sums) / (counts @ sizes)
    return _quantiles(means, level)


def block_ci_difference(months_a: Sequence[str], a: Sequence[float], months_b: Sequence[str], b: Sequence[float],
                        level: float = pr.CI_LEVEL, draws: int = pr.BOOTSTRAP_DRAWS, seed: int = pr.SEED) -> list[float] | None:
    """Interval of mean(a) - mean(b), both samples resampled by the same draw of months (a control is attached to
    the month of its event)."""
    months_a, a = np.asarray(months_a), np.asarray(a, dtype=np.float64)
    months_b, b = np.asarray(months_b), np.asarray(b, dtype=np.float64)
    ka, kb = np.isfinite(a), np.isfinite(b)
    months_a, a, months_b, b = months_a[ka], a[ka], months_b[kb], b[kb]
    labels = np.unique(np.concatenate((months_a, months_b)))
    if labels.size < 2:
        return None
    ia, ib = np.searchsorted(labels, months_a), np.searchsorted(labels, months_b)
    sa, na = np.bincount(ia, a, labels.size), np.bincount(ia, None, labels.size).astype(np.float64)
    sb, nb = np.bincount(ib, b, labels.size), np.bincount(ib, None, labels.size).astype(np.float64)
    counts = _draw_counts(labels.size, draws, seed)
    with np.errstate(invalid="ignore", divide="ignore"):
        diff = (counts @ sa) / (counts @ na) - (counts @ sb) / (counts @ nb)
    return _quantiles(diff, level)


def _mean(values) -> float | None:
    values = [v for v in values if v is not None and math.isfinite(v)]
    return sum(values) / len(values) if values else None


def evaluate(observations: Sequence[dict], level: float = pr.CI_LEVEL, draws: int = pr.BOOTSTRAP_DRAWS) -> dict:
    """Gate 1 on one sample at one horizon. Each observation: `month`, `half`, `base`, `y`, `y_raw`, `c1` (list of
    control y_raw, possibly empty) and `c2` (the shared-shock control's y_raw or None). Only observations with a
    defined `y` and `y_raw` enter.

    Verdict: G1 fails -> INSUFFICIENT_EVIDENCE; else C2 match rate < 80 % -> STATISTICAL_BLOCKER; else PASS when
    G2-G7 all pass, FAIL otherwise. G4 is the owner's amendment of 2026-10-15 (S2 > 0 as a point estimate; its
    interval is published, not decisive) unless `G4_RULE` says otherwise."""
    obs = [o for o in observations if o["y"] is not None and math.isfinite(o["y"]) and o["y_raw"] is not None and math.isfinite(o["y_raw"])]
    n = len(obs)
    months = [o["month"] for o in obs]
    y = [o["y"] for o in obs]
    y_raw = [o["y_raw"] for o in obs]
    bases = sorted({o["base"] for o in obs})
    mean_y = _mean(y)
    out: dict = {"events": n, "bases": len(bases), "months": len(set(months)), "mean_y": mean_y, "mean_y_raw": _mean(y_raw),
                 "ci_level": level, "draws": draws}
    out["ci_y"] = block_ci(months, y, level, draws) if n else None
    # S1 (C1): per event, y_raw minus the mean of its C1 controls with a defined outcome.
    d1 = [(o["month"], o["y_raw"] - _mean(o["c1"])) for o in obs if _mean(o["c1"]) is not None]
    out["s1"] = _mean([d for _, d in d1])
    out["s1_events"] = len(d1)
    out["ci_s1"] = block_ci([m for m, _ in d1], [d for _, d in d1], level, draws) if d1 else None
    # S2 (C2): mean y_raw of the events minus the mean y_raw of the matched shared-shock controls.
    c2 = [(o["month"], o["c2"]) for o in obs if o["c2"] is not None and math.isfinite(o["c2"])]
    out["c2_matched"] = len(c2)
    out["c2_match_rate"] = len(c2) / n if n else None
    out["s2"] = (out["mean_y_raw"] - _mean([v for _, v in c2])) if c2 and n else None
    out["ci_s2"] = block_ci_difference(months, y_raw, [m for m, _ in c2], [v for _, v in c2], level, draws) if c2 else None
    # Half-years (7 periods) and robustness.
    halves = {}
    for h in range(7):
        values = [o["y"] for o in obs if o["half"] == h]
        halves[h] = {"events": len(values), "mean_y": _mean(values)}
    out["half_years"] = halves
    qualifying = [h for h, v in halves.items() if v["events"] >= pr.G6_MIN_EVENTS]
    positive = [h for h in qualifying if halves[h]["mean_y"] is not None and halves[h]["mean_y"] > 0]
    trim = math.ceil(pr.G7_TRIM * n)
    trimmed = sorted(y)[: n - trim] if n else []
    contribution: dict[str, float] = {}
    for o in obs:
        contribution[o["base"]] = contribution.get(o["base"], 0.0) + o["y"]
    top_base = min(contribution, key=lambda b: (-contribution[b], b)) if contribution else None
    without_top = [o["y"] for o in obs if o["base"] != top_base]
    out["g7"] = {"trimmed_events": trim, "mean_without_top5pct": _mean(trimmed), "top_base": top_base,
                 "mean_without_top_base": _mean(without_top)}
    ci_y, ci_s1, ci_s2 = out["ci_y"], out["ci_s1"], out["ci_s2"]
    g4_point = out["s2"] is not None and out["s2"] > 0
    g4_ci = g4_point and ci_s2 is not None and ci_s2[0] > 0
    gates = {
        "G1": n >= pr.G1_MIN_EVENTS and len(bases) >= pr.G1_MIN_BASES and out["months"] >= pr.G1_MIN_MONTHS,
        "G2": mean_y is not None and mean_y > 0 and ci_y is not None and ci_y[0] > 0,
        "G3": mean_y is not None and mean_y >= pr.G3_FLOOR,
        "G4": g4_point if pr.G4_RULE == "point_estimate" else g4_ci,
        "G5": out["s1"] is not None and out["s1"] > 0 and ci_s1 is not None and ci_s1[0] > 0,
        "G6": len(positive) >= pr.G6_MIN_PERIODS,
        "G7": (out["g7"]["mean_without_top5pct"] or 0) > 0 and (out["g7"]["mean_without_top_base"] or 0) > 0,
    }
    if pr.G4_RULE not in pr.G4_RULES:
        raise ValueError("G4 rule not set")
    out["gates"] = {k: bool(v) for k, v in gates.items()}
    out["g4_rule"] = pr.G4_RULE
    out["g4_ci_lower_bound_positive"] = bool(g4_ci)  # published, not decisive under the amendment
    out["g6"] = {"qualifying_periods": len(qualifying), "positive_periods": len(positive)}
    blocker = out["c2_match_rate"] is None or out["c2_match_rate"] < pr.C2_MIN_MATCH
    failed = [k for k in GATES[1:] if not out["gates"][k]]
    if not out["gates"]["G1"]:
        verdict = "INSUFFICIENT_EVIDENCE"
    elif blocker:
        verdict = "STATISTICAL_BLOCKER"
    elif not failed:
        verdict = "PASS"
    else:
        verdict = "FAIL"
    out["statistical_blocker"] = bool(blocker)
    out["failed_gates"] = failed
    out["verdict"] = verdict
    out["fail_due_to_G4_alone"] = verdict == "FAIL" and failed == ["G4"]
    return out
