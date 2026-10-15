"""Statistics and frozen decision rules of the E1 analysis (PREREGISTRATION-leverage-gt2.md §9, §10).

R per trade = net_bp / (d_bp + c_bp) (fixed-risk sizing at the trade's own theoretical size).
Intervals: cluster bootstrap, clusters = configuration x pair x UTC day of the decision, resampled
jointly for the two arms of a contrast (a cluster key carries its trades of both arms).
Two-sided percentile intervals at 1 − α; p = min(1, 2 · min(P*(Δ ≤ 0), P*(Δ ≥ 0))). Holm over
the declared secondary list. Nothing here selects by PnL: the arms, tests and thresholds are
fixed before the read.
"""

from __future__ import annotations

from datetime import datetime, timezone

import numpy as np

from .engine import STATUS
from .leverage import (
    A_MAX_SCENARIOS,
    APPLIED_RULES,
    CUMULATIVE,
    MMR_MAJORS,
    MMR_MIDCAP_SENSITIVITY,
    PRIMARY_BAND,
    REF_BAND,
    applied_leverage,
    liquidation_hit,
    liquidation_price,
    margin_refused,
    symbol_of_series,
)
from .protocol import (
    ALPHA,
    BOOTSTRAP_RESAMPLES,
    BOOTSTRAP_SEED,
    MAJORS,
    N_MIN,
    ROBUST_MAX_PAIR_SHARE,
    ROBUST_MIN_WEEKS,
    SECONDARY_TESTS,
    SLIPPAGE_GRID_TICKS,
    STRATA_AXES,
)

CHUNK = 200


def _f(value) -> float | None:
    value = float(value)
    return value if np.isfinite(value) else None


def r_multiple(rows: np.ndarray, extra_ticks: int = 0, net_field: str = "net_bp", d_field: str = "d_bp") -> np.ndarray:
    return (rows[net_field] - extra_ticks * rows["tick_bp"]) / (rows[d_field] + rows["c_bp"])


def cluster_codes(rows: np.ndarray, series_names: list[str]) -> np.ndarray:
    """Configuration x pair x UTC day of the decision, as an int64 key."""
    pair_ids = {}
    pair_of_series = np.array([pair_ids.setdefault(symbol_of_series(name), len(pair_ids)) for name in series_names], dtype=np.int64)
    pair = pair_of_series[rows["series"].astype(np.int64)] if rows.size else np.zeros(0, dtype=np.int64)
    day = rows["decision_s"] // 86400
    return (rows["config"].astype(np.int64) * 1_000 + pair) * 100_000 + day


def pair_day_codes(rows: np.ndarray, series_names: list[str]) -> np.ndarray:
    """Pair x day only (diagnostic: trades duplicated across nested configurations share a cluster)."""
    codes = cluster_codes(rows, series_names)
    return codes % (1_000 * 100_000)


def _weights(rng: np.random.Generator, k: int, m: int) -> np.ndarray:
    return rng.multinomial(k, np.full(k, 1.0 / k), size=m).astype(np.float64)


def _interval(draws: np.ndarray, point: float, alpha: float) -> dict:
    draws = draws[np.isfinite(draws)]
    if draws.size == 0 or not np.isfinite(point):
        return {"estimate": _f(point), "ci_low": None, "ci_high": None, "p": None, "resamples": 0}
    lo, hi = np.quantile(draws, (alpha / 2, 1 - alpha / 2))
    p = min(1.0, 2.0 * min(float(np.mean(draws <= 0)), float(np.mean(draws >= 0))))
    return {"estimate": _f(point), "ci_low": _f(lo), "ci_high": _f(hi), "p": p, "resamples": int(draws.size)}


def paired_cluster_bootstrap(codes_a: np.ndarray, values_a: np.ndarray, codes_b: np.ndarray, values_b: np.ndarray,
                             resamples: int = BOOTSTRAP_RESAMPLES, seed: int = BOOTSTRAP_SEED, alpha: float = ALPHA) -> dict:
    """Δ = mean(a) − mean(b), clusters drawn from the union of keys with replacement."""
    if values_a.size == 0 or values_b.size == 0:
        return {"estimate": None, "ci_low": None, "ci_high": None, "p": None, "resamples": 0, "clusters": 0}
    keys, inverse = np.unique(np.concatenate([codes_a, codes_b]), return_inverse=True)
    k = keys.size
    ia, ib = inverse[:codes_a.size], inverse[codes_a.size:]
    sa, na = np.bincount(ia, weights=values_a, minlength=k), np.bincount(ia, minlength=k).astype(np.float64)
    sb, nb = np.bincount(ib, weights=values_b, minlength=k), np.bincount(ib, minlength=k).astype(np.float64)
    point = sa.sum() / na.sum() - sb.sum() / nb.sum()
    rng = np.random.default_rng(seed)
    draws = []
    for start in range(0, resamples, CHUNK):
        w = _weights(rng, k, min(CHUNK, resamples - start))
        with np.errstate(invalid="ignore", divide="ignore"):
            draws.append((w @ sa) / (w @ na) - (w @ sb) / (w @ nb))
    out = _interval(np.concatenate(draws), point, alpha)
    out["clusters"] = int(k)
    return out


def one_sample_cluster_bootstrap(codes: np.ndarray, values: np.ndarray, resamples: int = BOOTSTRAP_RESAMPLES, seed: int = BOOTSTRAP_SEED, alpha: float = ALPHA) -> dict:
    if values.size == 0:
        return {"estimate": None, "ci_low": None, "ci_high": None, "p": None, "resamples": 0, "clusters": 0}
    keys, inverse = np.unique(codes, return_inverse=True)
    k = keys.size
    s, n = np.bincount(inverse, weights=values, minlength=k), np.bincount(inverse, minlength=k).astype(np.float64)
    rng = np.random.default_rng(seed)
    draws = []
    for start in range(0, resamples, CHUNK):
        w = _weights(rng, k, min(CHUNK, resamples - start))
        with np.errstate(invalid="ignore", divide="ignore"):
            draws.append((w @ s) / (w @ n))
    out = _interval(np.concatenate(draws), s.sum() / n.sum(), alpha)
    out["clusters"] = int(k)
    return out


def stratified_cluster_bootstrap(codes_a: np.ndarray, values_a: np.ndarray, cells_a: np.ndarray, codes_b: np.ndarray, values_b: np.ndarray, cells_b: np.ndarray, n_cells: int,
                                 resamples: int = BOOTSTRAP_RESAMPLES, seed: int = BOOTSTRAP_SEED, alpha: float = ALPHA) -> dict:
    """C2: Δ within strata over the common support (cells with trades in both arms), weighted by
    the studied arm's counts; also the share of the studied arm inside the support."""
    if values_a.size == 0 or values_b.size == 0:
        return {"estimate": None, "ci_low": None, "ci_high": None, "p": None, "resamples": 0, "support_share": None}
    keys, inverse = np.unique(np.concatenate([codes_a, codes_b]), return_inverse=True)
    k = keys.size
    ia, ib = inverse[:codes_a.size], inverse[codes_a.size:]

    def table(index, cells, values):
        flat = index * n_cells + cells
        s = np.bincount(flat, weights=values, minlength=k * n_cells).reshape(k, n_cells)
        n = np.bincount(flat, minlength=k * n_cells).reshape(k, n_cells).astype(np.float64)
        return s, n

    sa, na = table(ia, cells_a, values_a)
    sb, nb = table(ib, cells_b, values_b)

    def estimate(SA, NA, SB, NB):
        with np.errstate(invalid="ignore", divide="ignore"):
            support = (NA > 0) & (NB > 0)
            diff = np.where(support, SA / np.where(NA > 0, NA, 1) - SB / np.where(NB > 0, NB, 1), 0.0)
            weight = np.where(support, NA, 0.0)
            return (diff * weight).sum(axis=-1) / weight.sum(axis=-1)

    point = float(estimate(sa.sum(0), na.sum(0), sb.sum(0), nb.sum(0)))
    support = (na.sum(0) > 0) & (nb.sum(0) > 0)
    rng = np.random.default_rng(seed)
    draws = []
    for start in range(0, resamples, CHUNK):
        w = _weights(rng, k, min(CHUNK, resamples - start))
        draws.append(estimate(w @ sa, w @ na, w @ sb, w @ nb))
    out = _interval(np.concatenate(draws), point, alpha)
    out["support_share"] = _f(na.sum(0)[support].sum() / na.sum()) if na.sum() else None
    out["cells_in_support"] = int(support.sum())
    return out


def holm(pvalues: dict[str, float | None]) -> dict[str, float]:
    """Holm step-down adjusted p-values; a test that could not run counts as p = 1."""
    items = sorted((1.0 if p is None else float(p), name) for name, p in pvalues.items())
    m = len(items)
    out, running = {}, 0.0
    for k, (p, name) in enumerate(items):
        running = max(running, min(1.0, (m - k) * p))
        out[name] = running
    return out


def contrast_verdict(result: dict, n_a: int, n_b: int, n_min: int = N_MIN, alpha: float = ALPHA, adjusted_p: float | None = None) -> str:
    """Frozen rule: NOT_INFORMATIVE when an arm has fewer than N_min filled trades; otherwise
    DIFFERENT_LOWER / DIFFERENT_HIGHER when the (Holm-adjusted for secondaries) p < α — for the
    primary, equivalently when the 1 − α interval excludes 0 — else NO_DIFFERENCE_DETECTED."""
    if n_a < n_min or n_b < n_min or result.get("estimate") is None or result.get("p") is None:
        return "NOT_INFORMATIVE"
    p = result["p"] if adjusted_p is None else adjusted_p
    if p < alpha:
        return "DIFFERENT_LOWER" if result["estimate"] < 0 else "DIFFERENT_HIGHER"
    return "NO_DIFFERENCE_DETECTED"


def strata_cells(rows: np.ndarray, terciles: dict[str, list[float] | None]) -> np.ndarray:
    cells = np.zeros(rows.size, dtype=np.int64)
    for axis in STRATA_AXES:
        cut = terciles.get(axis)
        index = np.zeros(rows.size, dtype=np.int64) if cut is None else np.searchsorted(np.asarray(cut), rows[axis], side="right")
        cells = cells * 3 + np.clip(index, 0, 2)
    return cells


# ----------------------------------------------------------------------------- descriptive pieces


def arm_description(rows: np.ndarray, orders: np.ndarray, series_names: list[str], definition: str) -> dict:
    """Metrics and measurement diagnostics of one arm (filled trades `rows`, admitted orders `orders`)."""
    n = int(rows.size)
    out = {"orders": int(orders.size), "filled": n, "fill_rate": _f(n / orders.size) if orders.size else None}
    if n == 0:
        return out
    r = r_multiple(rows)
    stop = rows["exit_reason"] == 1
    gross_r = rows["gross_bp"] / (rows["d_bp"] + rows["c_bp"])
    cost_r = rows["fees_bp"] / (rows["d_bp"] + rows["c_bp"])
    slip_r = np.where(stop, rows["stop_slip_bp"] / (rows["d_bp"] + rows["c_bp"]), np.nan)
    frozen_r = (rows["side"] * (rows["frozen_exit"] - rows["entry"]) / rows["entry"] * 1e4 - rows["fees_bp"]) / (rows["d_bp"] + rows["c_bp"])
    grid = {str(k): _f(np.mean(r_multiple(rows, k))) for k in SLIPPAGE_GRID_TICKS}
    signs = {np.sign(v) for v in grid.values() if v is not None}
    pairs = np.array([symbol_of_series(series_names[int(s)]) for s in rows["series"]])
    weeks = np.array([series_names[int(s)].split(":")[-1] for s in rows["series"]])
    keys = np.stack([rows["series"].astype(np.int64), rows["side"].astype(np.int64), rows["fill_ts"], rows["exit_ts"]], axis=1)
    duplicates = n - np.unique(keys, axis=0).shape[0]
    out.update({
        "mean_r": _f(r.mean()), "median_r": _f(np.median(r)), "mean_gross_r": _f(gross_r.mean()), "mean_cost_r": _f(cost_r.mean()),
        "win_rate": _f(np.mean(r > 0)), "worst_r": _f(r.min()), "best_r": _f(r.max()), "share_below_minus_2r": _f(np.mean(r < -2)),
        "slippage_grid_mean_r": grid, "slippage_grid_label": "NON_INFORMATIVE" if len(signs) > 1 else "SIGN_STABLE",
        "leverage": {"mean": _f(np.mean(rows["l_eng" if definition == "eng" else "l_stop"])), "min": _f(np.min(rows["l_eng" if definition == "eng" else "l_stop"]))},
        "d_bp_p50": _f(np.median(rows["d_bp"])), "c_bp_p50": _f(np.median(rows["c_bp"])), "cost_share_c_over_d_plus_c_p50": _f(np.median(rows["c_bp"] / (rows["d_bp"] + rows["c_bp"]))),
        "diagnostics": {
            "stop_share": _f(stop.mean()), "same_second_stop_share": _f(np.mean(rows["same_second_stop"] == 1)),
            "stop_slippage_r": {"p50": _f(np.nanmedian(slip_r)) if stop.any() else None, "p90": _f(np.nanquantile(slip_r, 0.9)) if stop.any() else None, "max": _f(np.nanmax(slip_r)) if stop.any() else None},
            "realistic_minus_frozen_r_mean": _f(np.mean(r - frozen_r)), "floor_share": _f(np.mean(rows["floor"] == 1)),
            "sigma_fallback_share": 0.0, "stop_target_tie_share": None,  # B4: ATR stop (no sigma fallback), no target (no tie)
            "settlement_crossing_share": _f(np.mean(rows["settlements"] > 0)), "hold_s_p50": _f(np.median(rows["hold_s"])),
            "duplicate_trades_across_configurations": int(duplicates),
        },
        "by_pair": {pair: {"trades": int(np.sum(pairs == pair)), "mean_r": _f(r[pairs == pair].mean())} for pair in np.unique(pairs)},
        "by_week": {week: {"trades": int(np.sum(weeks == week)), "mean_r": _f(r[weeks == week].mean())} for week in np.unique(weeks)},
        "max_pair_share": _f(max(np.sum(pairs == pair) for pair in np.unique(pairs)) / n),
    })
    return out


def liquidation_control(rows: np.ndarray, series_names: list[str], definition: str) -> dict:
    """Analytic control (no change to the fills): per applied-leverage rule and A_max, margin
    refusals (L > A under isolated margin) and liquidation-before-exit counts, MMR 0.01 for the
    majors and {0.005, 0.01, 0.02} for the mid-caps."""
    out = {}
    lev = rows["l_eng" if definition == "eng" else "l_stop"]
    pairs = [symbol_of_series(series_names[int(s)]) for s in rows["series"]]
    for rule in APPLIED_RULES:
        for a_max in A_MAX_SCENARIOS:
            for mmr_mid in MMR_MIDCAP_SENSITIVITY:
                refused = liquidated = 0
                for i in range(rows.size):
                    applied = applied_leverage(float(lev[i]), rule, a_max)
                    if margin_refused(float(lev[i]), applied):
                        refused += 1
                        continue
                    mmr = MMR_MAJORS if pairs[i] in MAJORS else mmr_mid
                    if liquidation_hit(float(rows["worst"][i]), liquidation_price(float(rows["entry"][i]), int(rows["side"][i]), applied, mmr), int(rows["side"][i])):
                        liquidated += 1
                out[f"{rule}|A{a_max}|mmr_mid{mmr_mid}"] = {"trades": int(rows.size), "margin_refused": refused, "liquidated_before_exit": liquidated}
    return out


# ----------------------------------------------------------------------------- the analysis


def analyse(export: np.ndarray, manifest: dict, census: dict, resamples: int = BOOTSTRAP_RESAMPLES) -> dict:
    series_names = manifest["series"]
    arm_names = manifest["arms"]  # list of "definition:band"
    terciles = census["strata_terciles"]
    filled_all = export[export["status"] == STATUS["filled"]]
    admitted_all = export[export["status"] != STATUS["no_exit"]]

    def arm_rows(definition: str, band: str, which: np.ndarray) -> np.ndarray:
        key = f"{definition}:{band}"
        if key not in arm_names:
            return which[:0]
        return which[which["arm"] == arm_names.index(key)]

    arms = {}
    for key in arm_names:
        definition, band = key.split(":")
        arms[key] = arm_description(arm_rows(definition, band, filled_all), arm_rows(definition, band, admitted_all), series_names, definition)
        arms[key]["liquidation_control"] = liquidation_control(arm_rows(definition, band, filled_all), series_names, definition)
        arms[key]["no_exit"] = int(np.sum((export["arm"] == arm_names.index(key)) & (export["status"] == STATUS["no_exit"])))

    def contrast(definition: str, band: str, mode: str = "fill", seed_offset: int = 0, where=None) -> tuple[dict, int, int]:
        source = filled_all if mode == "fill" else admitted_all
        a, b = arm_rows(definition, band, source), arm_rows(definition, REF_BAND, source)
        if where is not None:
            a, b = a[where(a)], b[where(b)]
        va = r_multiple(a) if mode == "fill" else np.where(a["status"] == STATUS["filled"], r_multiple(a), 0.0)
        vb = r_multiple(b) if mode == "fill" else np.where(b["status"] == STATUS["filled"], r_multiple(b), 0.0)
        na = int(np.sum(a["status"] == STATUS["filled"]))
        nb = int(np.sum(b["status"] == STATUS["filled"]))
        result = paired_cluster_bootstrap(cluster_codes(a, series_names), va, cluster_codes(b, series_names), vb, resamples, BOOTSTRAP_SEED + seed_offset)
        return result, na, nb

    primary, na, nb = contrast("eng", PRIMARY_BAND)
    primary_doc = {"contrast": "Δ ]2,3] − ]1,2] under L_eng, P2-B4, per fill, pooled over the frozen configurations", "n_band": na, "n_ref": nb, **primary,
                   "verdict": contrast_verdict(primary, na, nb)}
    if na and nb:
        a, b = arm_rows("eng", PRIMARY_BAND, filled_all), arm_rows("eng", REF_BAND, filled_all)
        primary_doc["slippage_grid_delta"] = {str(k): _f(np.mean(r_multiple(a, k)) - np.mean(r_multiple(b, k))) for k in SLIPPAGE_GRID_TICKS}
        weeks = sorted({series_names[int(s)].split(":")[-1] for s in np.concatenate([a["series"], b["series"]])})
        week_signs = []
        for week in weeks:
            wa = np.array([series_names[int(s)].endswith(":" + week) for s in a["series"]], dtype=bool)
            wb = np.array([series_names[int(s)].endswith(":" + week) for s in b["series"]], dtype=bool)
            if wa.any() and wb.any():
                week_signs.append(float(np.sign(np.mean(r_multiple(a[wa])) - np.mean(r_multiple(b[wb])))))
        same = sum(1 for sign in week_signs if primary["estimate"] is not None and sign == np.sign(primary["estimate"]))
        primary_doc["robustness_descriptive"] = {"weeks_same_sign": same, "weeks": len(week_signs), "weeks_rule_met": same >= ROBUST_MIN_WEEKS,
                                                 "max_pair_share_band": arms.get(f"eng:{PRIMARY_BAND}", {}).get("max_pair_share"),
                                                 "pair_rule_met": (arms.get(f"eng:{PRIMARY_BAND}", {}).get("max_pair_share") or 1.0) <= ROBUST_MAX_PAIR_SHARE}
        diag = paired_cluster_bootstrap(pair_day_codes(a, series_names), r_multiple(a), pair_day_codes(b, series_names), r_multiple(b), resamples, BOOTSTRAP_SEED + 99)
        primary_doc["diagnostic_pair_day_clusters"] = {k: diag[k] for k in ("estimate", "ci_low", "ci_high", "p", "clusters")}

    secondary: dict[str, dict] = {}
    s1, n1a, n1b = contrast("eng", "3-5", seed_offset=1)
    secondary["S1"] = {**s1, "n_band": n1a, "n_ref": n1b}
    s2, n2a, n2b = contrast("eng", "5-10", seed_offset=2)
    secondary["S2"] = {**s2, "n_band": n2a, "n_ref": n2b}
    s3, n3a, n3b = contrast("eng", PRIMARY_BAND, mode="itt", seed_offset=3)
    secondary["S3"] = {**s3, "n_band": n3a, "n_ref": n3b}
    for name, band, offset in (("S4", "2-3", 4), ("S5", "3-5", 5), ("S6", "5-10", 6)):
        result, xa, xb = contrast("stop", band, seed_offset=offset)
        secondary[name] = {**result, "n_band": xa, "n_ref": xb}
    band_rows = arm_rows("eng", PRIMARY_BAND, filled_all)
    c1_ok = band_rows[np.isfinite(band_rows["c1_net_bp"])]
    c1_values = r_multiple(c1_ok) - c1_ok["c1_net_bp"] / (c1_ok["c1_d_bp"] + c1_ok["c_bp"])
    s7 = one_sample_cluster_bootstrap(cluster_codes(c1_ok, series_names), c1_values, resamples, BOOTSTRAP_SEED + 7)
    secondary["S7"] = {**s7, "n_band": int(c1_ok.size), "n_ref": int(c1_ok.size), "c1_indeterminate": int(band_rows.size - c1_ok.size),
                       "c1_hold_s_p50": _f(np.median(c1_ok["c1_hold_s"])) if c1_ok.size else None}
    a, b = arm_rows("eng", PRIMARY_BAND, filled_all), arm_rows("eng", REF_BAND, filled_all)
    s8 = stratified_cluster_bootstrap(cluster_codes(a, series_names), r_multiple(a), strata_cells(a, terciles), cluster_codes(b, series_names), r_multiple(b), strata_cells(b, terciles), 27, resamples, BOOTSTRAP_SEED + 8)
    secondary["S8"] = {**s8, "n_band": int(a.size), "n_ref": int(b.size)}
    s9, n9a, n9b = contrast("eng", PRIMARY_BAND, seed_offset=9, where=lambda rows: rows["floor"] == 0)
    secondary["S9"] = {**s9, "n_band": n9a, "n_ref": n9b}

    runnable = {name: (doc["p"] if doc.get("n_band", 0) >= N_MIN and doc.get("n_ref", 0) >= N_MIN else None) for name, doc in secondary.items()}
    adjusted = holm(runnable)
    for name, label in SECONDARY_TESTS:
        doc = secondary[name]
        doc["label"] = label
        doc["p_holm"] = adjusted[name]
        doc["verdict"] = contrast_verdict(doc, doc.get("n_band", 0), doc.get("n_ref", 0), adjusted_p=adjusted[name])

    cumulative = {}
    for name, bands in CUMULATIVE.items():
        rows = np.concatenate([arm_rows("eng", band, filled_all) for band in bands])
        cumulative[name] = {"bands": list(bands), "filled": int(rows.size), "mean_r": _f(np.mean(r_multiple(rows))) if rows.size else None, "note": "nested union of disjoint arms, descriptive only"}

    return {"generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "alpha": ALPHA, "n_min": N_MIN, "resamples": resamples, "seed": BOOTSTRAP_SEED,
            "primary": primary_doc, "secondary": secondary, "arms": arms, "cumulative_descriptive": cumulative,
            "indeterminate_and_exclusions": manifest.get("counters_total", {})}
