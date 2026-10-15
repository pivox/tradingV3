"""Statistics and frozen decision rules of the E1 analysis (PREREGISTRATION-leverage-gt2.md §9, §10).

R per trade = net_bp / (d_bp + c_bp) for EVERY arm, L_eng and L_stop alike: every trade is taken
at its canonical size N = r·E/(d + c) (never above it); L_stop only classifies trades into bands.

Multiplicity (owner decision D1, `protocol.MULTIPLICITY_RULE`): the 31 configurations are nested,
so one trade (series, side, decision second) can appear in several configurations of an arm.
- `distinct_pair_day`: each arm's decision population keeps every distinct trade once; N_min
  counts distinct trades; clusters = pair x UTC day of the decision.
- `copies_config_pair_day`: every copy counts (in N_min too); clusters = configuration x pair x
  UTC day.
The other combination is published as a diagnostic.

Intervals: cluster bootstrap resampled jointly for the two arms of a contrast (a cluster key
carries its trades of both arms). Two-sided percentile intervals at 1 − α;
p = min(1, 2 · min(P*(Δ ≤ 0), P*(Δ ≥ 0))). Holm over the declared secondary list.

Slippage grid (audit §4.8, accepted by the owner): a band whose mean net R changes sign across the
grid {0, 1, 2, 5} extra adverse ticks is NON_INFORMATIVE, and so is every contrast or test that
involves it (the verdict is overridden). The sign of Δ across the grid is published as a
descriptive label (SIGN_STABLE / SIGN_FLIP).

Nothing here selects by PnL: the arms, tests and thresholds are fixed before the read.
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
    R_FRACTION,
    applied_leverage,
    assert_not_levered_up,
    liquidation_hit,
    liquidation_price,
    margin_refused,
    symbol_of_series,
)
from . import protocol as pr
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
    """R at the canonical size r·E/(d + c), for every arm and both definitions."""
    return (rows[net_field] - extra_ticks * rows["tick_bp"]) / (rows[d_field] + rows["c_bp"])


def dedupe(rows: np.ndarray) -> np.ndarray:
    """One row per distinct trade (series, side, decision second), the first in export order.
    Within an arm the outcome of a decision second does not depend on the configuration that
    produced it (same price, same fill, same exit), so the copies are identical."""
    if rows.size == 0:
        return rows
    keys = np.stack([rows["series"].astype(np.int64), rows["side"].astype(np.int64), rows["decision_s"].astype(np.int64)], axis=1)
    _, first = np.unique(keys, axis=0, return_index=True)
    return rows[np.sort(first)]


def decision_population(rows: np.ndarray, rule: str) -> np.ndarray:
    return dedupe(rows) if rule == "distinct_pair_day" else rows


def cluster_codes(rows: np.ndarray, series_names: list[str]) -> np.ndarray:
    """Configuration x pair x UTC day of the decision, as an int64 key."""
    pair_ids = {}
    pair_of_series = np.array([pair_ids.setdefault(symbol_of_series(name), len(pair_ids)) for name in series_names], dtype=np.int64)
    pair = pair_of_series[rows["series"].astype(np.int64)] if rows.size else np.zeros(0, dtype=np.int64)
    day = rows["decision_s"] // 86400
    return (rows["config"].astype(np.int64) * 1_000 + pair) * 100_000 + day


def pair_day_codes(rows: np.ndarray, series_names: list[str]) -> np.ndarray:
    """Pair x UTC day of the decision (every copy of a trade shares one cluster)."""
    codes = cluster_codes(rows, series_names)
    return codes % (1_000 * 100_000)


def decision_clusters(rows: np.ndarray, series_names: list[str], rule: str) -> np.ndarray:
    return pair_day_codes(rows, series_names) if rule == "distinct_pair_day" else cluster_codes(rows, series_names)


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


INDETERMINATE_CELL = 27  # a C2 stratum input is not finite (e.g. `stale_900` in the first 900 s): excluded from S8, counted
N_CELLS = 27


def strata_cells(rows: np.ndarray, terciles: dict[str, list[float] | None]) -> np.ndarray:
    """C2 cell 0-26 (volatility x tick x liquidity terciles frozen by E0a), or INDETERMINATE_CELL
    when an input is not finite (never silently sent to the top tercile)."""
    cells = np.zeros(rows.size, dtype=np.int64)
    bad = np.zeros(rows.size, dtype=bool)
    for axis in STRATA_AXES:
        cut = terciles.get(axis)
        values = rows[axis].astype(np.float64)
        bad |= ~np.isfinite(values)
        index = np.zeros(rows.size, dtype=np.int64) if cut is None else np.searchsorted(np.asarray(cut, dtype=np.float64), np.where(np.isfinite(values), values, 0.0), side="right")
        cells = cells * 3 + np.clip(index, 0, 2)
    return np.where(bad, INDETERMINATE_CELL, cells)


def sign_label(grid: dict[str, float | None]) -> str:
    """NON_INFORMATIVE when the mean changes sign across the slippage grid (audit §4.8)."""
    signs = {float(np.sign(v)) for v in grid.values() if v is not None}
    return "NON_INFORMATIVE" if len(signs) > 1 else "SIGN_STABLE"


def _itt_values(rows: np.ndarray, extra_ticks: int = 0) -> np.ndarray:
    filled = rows["status"] == STATUS["filled"]
    with np.errstate(invalid="ignore"):
        return np.where(filled, np.nan_to_num(r_multiple(rows, extra_ticks), nan=0.0), 0.0)


def values_of(rows: np.ndarray, mode: str, extra_ticks: int = 0) -> np.ndarray:
    return r_multiple(rows, extra_ticks) if mode == "fill" else _itt_values(rows, extra_ticks)


def grid_means(rows: np.ndarray, mode: str = "fill") -> dict[str, float | None]:
    return {str(k): (_f(np.mean(values_of(rows, mode, k))) if rows.size else None) for k in SLIPPAGE_GRID_TICKS}


def _mean_r(rows: np.ndarray) -> float | None:
    return _f(np.mean(r_multiple(rows))) if rows.size else None


# ----------------------------------------------------------------------------- descriptive pieces


def arm_description(rows: np.ndarray, orders: np.ndarray, nominal: np.ndarray, series_names: list[str], configurations: list[str]) -> dict:
    """Metrics and measurement diagnostics of one arm: `rows` the filled trades of the decision
    population, `orders` its admitted orders, `nominal` every filled copy (one per configuration)."""
    n = int(rows.size)
    out = {"orders": int(orders.size), "filled": n, "fill_rate": _f(n / orders.size) if orders.size else None, "filled_nominal": int(nominal.size),
           "duplicate_trades_across_configurations": int(nominal.size - dedupe(nominal).size)}
    if nominal.size:
        sources = dict((identifier, source) for identifier, source, _ in pr.CONFIGURATIONS)
        r_nominal = r_multiple(nominal)
        by_config = {}
        for ci in np.unique(nominal["config"]):
            name = configurations[int(ci)] if int(ci) < len(configurations) else str(int(ci))
            mask = nominal["config"] == ci
            by_config[name] = {"source": sources.get(name), "trades": int(mask.sum()), "share_of_nominal": _f(mask.mean()), "mean_r": _f(r_nominal[mask].mean())}
        by_source: dict[str, int] = {}
        for item in by_config.values():
            by_source[str(item["source"])] = by_source.get(str(item["source"]), 0) + item["trades"]
        out.update({"by_config_nominal": by_config, "by_source_nominal": by_source, "max_config_share_nominal": _f(max(item["share_of_nominal"] for item in by_config.values()))})
    if n == 0:
        return out
    r = r_multiple(rows)
    stop = rows["exit_reason"] == 1
    gross_r = rows["gross_bp"] / (rows["d_bp"] + rows["c_bp"])
    cost_r = rows["fees_bp"] / (rows["d_bp"] + rows["c_bp"])
    slip_r = np.where(stop, rows["stop_slip_bp"] / (rows["d_bp"] + rows["c_bp"]), np.nan)
    frozen_r = (rows["side"] * (rows["frozen_exit"] - rows["entry"]) / rows["entry"] * 1e4 - rows["fees_bp"]) / (rows["d_bp"] + rows["c_bp"])
    grid = grid_means(rows)
    pairs = np.array([symbol_of_series(series_names[int(s)]) for s in rows["series"]])
    weeks = np.array([series_names[int(s)].split(":")[-1] for s in rows["series"]])
    floor = rows["floor"] == 1
    out.update({
        "mean_r": _f(r.mean()), "median_r": _f(np.median(r)), "mean_gross_r": _f(gross_r.mean()), "mean_cost_r": _f(cost_r.mean()),
        "win_rate": _f(np.mean(r > 0)), "worst_r": _f(r.min()), "best_r": _f(r.max()), "share_below_minus_2r": _f(np.mean(r < -2)),
        "slippage_grid_mean_r": grid, "slippage_grid_label": sign_label(grid),
        "floor_split": {"floor": {"filled": int(floor.sum()), "mean_r": _f(r[floor].mean()) if floor.any() else None},
                        "no_floor": {"filled": int((~floor).sum()), "mean_r": _f(r[~floor].mean()) if (~floor).any() else None}},
        "leverage": {key: {"mean": _f(np.mean(rows[key])), "min": _f(np.min(rows[key])), "max": _f(np.max(rows[key]))} for key in ("l_eng", "l_stop")},
        "d_bp_p50": _f(np.median(rows["d_bp"])), "c_bp_p50": _f(np.median(rows["c_bp"])), "cost_share_c_over_d_plus_c_p50": _f(np.median(rows["c_bp"] / (rows["d_bp"] + rows["c_bp"]))),
        "diagnostics": {
            "stop_share": _f(stop.mean()), "same_second_stop_share": _f(np.mean(rows["same_second_stop"] == 1)),
            "stop_slippage_r": {"p50": _f(np.nanmedian(slip_r)) if stop.any() else None, "p90": _f(np.nanquantile(slip_r, 0.9)) if stop.any() else None, "max": _f(np.nanmax(slip_r)) if stop.any() else None},
            "realistic_minus_frozen_r_mean": _f(np.mean(r - frozen_r)), "floor_share": _f(np.mean(floor)),
            "sigma_fallback_share": 0.0, "stop_target_tie_share": None,  # B4: ATR stop (no sigma fallback), no target (no tie)
            "settlement_crossing_share_8h_approx": _f(np.mean(rows["settlements"] > 0)), "hold_s_p50": _f(np.median(rows["hold_s"])),
        },
        "by_pair": {pair: {"trades": int(np.sum(pairs == pair)), "mean_r": _f(r[pairs == pair].mean())} for pair in np.unique(pairs)},
        "by_week": {week: {"trades": int(np.sum(weeks == week)), "mean_r": _f(r[weeks == week].mean())} for week in np.unique(weeks)},
        "max_pair_share": _f(max(np.sum(pairs == pair) for pair in np.unique(pairs)) / n),
    })
    return out


def liquidation_control(rows: np.ndarray, series_names: list[str]) -> dict:
    """Analytic control (no change to the fills). Every trade, in an L_eng or an L_stop arm, is at
    its canonical size N = r·E/(d + c), so its notional / E is L_eng (the sizing guard checks that
    no trade is above it). Per applied-leverage rule and A_max: margin refusals (L_eng > A under
    isolated margin) and liquidation-before-exit counts, MMR 0.01 for the majors and
    {0.005, 0.01, 0.02} for the mid-caps."""
    out = {}
    lev = rows["l_eng"]
    for i in range(rows.size):
        notional_over_equity = R_FRACTION / ((float(rows["d_bp"][i]) + float(rows["c_bp"][i])) / 1e4)
        assert_not_levered_up(float(lev[i]), notional_over_equity, 1.0)
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


def contrast_verdict(result: dict, n_a: int, n_b: int, n_min: int = N_MIN, alpha: float = ALPHA, adjusted_p: float | None = None, slippage_sensitive: bool = False) -> str:
    """Frozen rule: NOT_INFORMATIVE when an arm has fewer than N_min filled trades (counted under
    decision D1) or when a band of the test is slippage-sensitive; otherwise DIFFERENT_LOWER /
    DIFFERENT_HIGHER when the (Holm-adjusted for secondaries) p < α — for the primary,
    equivalently when the 1 − α interval excludes 0 — else NO_DIFFERENCE_DETECTED."""
    if slippage_sensitive or n_a < n_min or n_b < n_min or result.get("estimate") is None or result.get("p") is None:
        return "NOT_INFORMATIVE"
    p = result["p"] if adjusted_p is None else adjusted_p
    if p < alpha:
        return "DIFFERENT_LOWER" if result["estimate"] < 0 else "DIFFERENT_HIGHER"
    return "NO_DIFFERENCE_DETECTED"


# ----------------------------------------------------------------------------- the analysis


def analyse(export: np.ndarray, manifest: dict, census: dict, resamples: int = BOOTSTRAP_RESAMPLES, rule: str | None = None) -> dict:
    rule = pr.multiplicity_rule() if rule is None else rule
    if rule not in pr.MULTIPLICITY_RULES:
        raise ValueError(f"unknown multiplicity rule {rule}")
    distinct = rule == "distinct_pair_day"
    series_names = manifest["series"]
    arm_names = manifest["arms"]  # list of "definition:band"
    configurations = manifest.get("configurations", list(pr.CONFIGURATION_IDS))
    terciles = census["strata_terciles"]
    filled_all = export[export["status"] == STATUS["filled"]]
    admitted_all = export[export["status"] != STATUS["no_exit"]]

    def arm_rows(definition: str, band: str, which: np.ndarray) -> np.ndarray:
        key = f"{definition}:{band}"
        if key not in arm_names:
            return which[:0]
        return which[which["arm"] == arm_names.index(key)]

    def population(definition: str, band: str, mode: str = "fill", dedup: bool = distinct) -> np.ndarray:
        rows = arm_rows(definition, band, filled_all if mode == "fill" else admitted_all)
        return dedupe(rows) if dedup else rows

    def clusters(rows: np.ndarray, kind: str) -> np.ndarray:
        return pair_day_codes(rows, series_names) if kind == "pair_day" else cluster_codes(rows, series_names)

    decision_kind = "pair_day" if distinct else "config_pair_day"
    labels: dict[str, str] = {}

    def band_label(definition: str, band: str, mode: str = "fill") -> str:
        key = f"{definition}:{band}:{mode}"
        if key not in labels:
            rows = population(definition, band, mode)
            labels[key] = sign_label(grid_means(rows, mode)) if rows.size else "SIGN_STABLE"
        return labels[key]

    def n_filled(rows: np.ndarray) -> int:
        return int(np.sum(rows["status"] == STATUS["filled"]))

    def contrast(definition: str, band: str, mode: str = "fill", seed_offset: int = 0, where=None, dedup: bool = distinct, kind: str = decision_kind) -> dict:
        a, b = population(definition, band, mode, dedup), population(definition, REF_BAND, mode, dedup)
        if where is not None:
            a, b = a[where(a)], b[where(b)]
        va, vb = values_of(a, mode), values_of(b, mode)
        result = paired_cluster_bootstrap(clusters(a, kind), va, clusters(b, kind), vb, resamples, BOOTSTRAP_SEED + seed_offset)
        out = {**result, "n_band": n_filled(a), "n_ref": n_filled(b), "rows_band": int(a.size), "rows_ref": int(b.size), "clusters_kind": kind, "deduplicated": dedup}
        if a.size and b.size:
            ga, gb = grid_means(a, mode), grid_means(b, mode)
            delta = {k: (_f(ga[k] - gb[k]) if ga[k] is not None and gb[k] is not None else None) for k in ga}
            la, lb = sign_label(ga), sign_label(gb)
            out.update({"slippage_grid_label_band": la, "slippage_grid_label_ref": lb, "slippage_grid_delta": delta,
                        "slippage_grid_delta_label": "SIGN_FLIP" if sign_label(delta) == "NON_INFORMATIVE" else "SIGN_STABLE",
                        "slippage_sensitive": la == "NON_INFORMATIVE" or lb == "NON_INFORMATIVE"})
        else:
            out["slippage_sensitive"] = False
        return out

    def without_floor(definition: str, band: str) -> dict:
        a = population(definition, band)
        b = population(definition, REF_BAND)
        a, b = a[a["floor"] == 0], b[b["floor"] == 0]
        ma, mb = _mean_r(a), _mean_r(b)
        return {"n_band": int(a.size), "n_ref": int(b.size), "mean_r_band": ma, "mean_r_ref": mb, "delta": _f(ma - mb) if ma is not None and mb is not None else None,
                "note": "descriptive, outside Holm (S9 is the tested version for ]2,3] under L_eng)"}

    arms = {}
    for key in arm_names:
        definition, band = key.split(":")
        rows = population(definition, band)
        arms[key] = arm_description(rows, population(definition, band, "itt"), arm_rows(definition, band, filled_all), series_names, configurations)
        arms[key]["liquidation_control"] = liquidation_control(rows, series_names)
        arms[key]["no_exit"] = int(np.sum((export["arm"] == arm_names.index(key)) & (export["status"] == STATUS["no_exit"])))

    primary = contrast("eng", PRIMARY_BAND)
    primary_doc = {"contrast": "Δ ]2,3] − ]1,2] under L_eng, P2-B4, per fill, pooled over the frozen configurations", "multiplicity_rule": rule, **primary,
                   "verdict": contrast_verdict(primary, primary["n_band"], primary["n_ref"], slippage_sensitive=primary["slippage_sensitive"]),
                   "without_floor_descriptive": without_floor("eng", PRIMARY_BAND)}
    a, b = population("eng", PRIMARY_BAND), population("eng", REF_BAND)
    if a.size and b.size:
        weeks = sorted({series_names[int(s)].split(":")[-1] for s in np.concatenate([a["series"], b["series"]])})
        week_signs = []
        for week in weeks:
            wa = np.array([series_names[int(s)].endswith(":" + week) for s in a["series"]], dtype=bool)
            wb = np.array([series_names[int(s)].endswith(":" + week) for s in b["series"]], dtype=bool)
            if wa.any() and wb.any():
                week_signs.append(float(np.sign(np.mean(r_multiple(a[wa])) - np.mean(r_multiple(b[wb])))))
        same = sum(1 for sign in week_signs if primary["estimate"] is not None and sign == np.sign(primary["estimate"]))
        share = arms.get(f"eng:{PRIMARY_BAND}", {}).get("max_pair_share")
        primary_doc["robustness_descriptive"] = {"weeks_same_sign": same, "weeks": len(week_signs), "weeks_rule_met": same >= ROBUST_MIN_WEEKS,
                                                 "max_pair_share_band": share, "pair_rule_met": (share or 1.0) <= ROBUST_MAX_PAIR_SHARE}
        other = contrast("eng", PRIMARY_BAND, seed_offset=99, dedup=not distinct, kind="config_pair_day" if distinct else "pair_day")
        copies_pair_day = contrast("eng", PRIMARY_BAND, seed_offset=98, dedup=False, kind="pair_day")
        keep = ("estimate", "ci_low", "ci_high", "p", "clusters", "n_band", "n_ref", "deduplicated", "clusters_kind")
        primary_doc["diagnostic_multiplicity"] = {"other_rule": {k: other[k] for k in keep if k in other}, "copies_pair_day": {k: copies_pair_day[k] for k in keep if k in copies_pair_day}}

    secondary: dict[str, dict] = {}
    secondary["S1"] = {**contrast("eng", "3-5", seed_offset=1), "without_floor_descriptive": without_floor("eng", "3-5")}
    secondary["S2"] = {**contrast("eng", "5-10", seed_offset=2), "without_floor_descriptive": without_floor("eng", "5-10")}
    secondary["S3"] = contrast("eng", PRIMARY_BAND, mode="itt", seed_offset=3)
    secondary["S3"]["slippage_sensitive"] = secondary["S3"]["slippage_sensitive"] or band_label("eng", PRIMARY_BAND) == "NON_INFORMATIVE" or band_label("eng", REF_BAND) == "NON_INFORMATIVE"
    for name, band, offset in (("S4", "2-3", 4), ("S5", "3-5", 5), ("S6", "5-10", 6)):
        secondary[name] = {**contrast("stop", band, seed_offset=offset), "without_floor_descriptive": without_floor("stop", band)}
    band_rows = population("eng", PRIMARY_BAND)
    c1_ok = band_rows[np.isfinite(band_rows["c1_net_bp"])]
    c1_values = r_multiple(c1_ok) - c1_ok["c1_net_bp"] / (c1_ok["c1_d_bp"] + c1_ok["c_bp"])
    s7 = one_sample_cluster_bootstrap(clusters(c1_ok, decision_kind), c1_values, resamples, BOOTSTRAP_SEED + 7)
    secondary["S7"] = {**s7, "n_band": int(c1_ok.size), "n_ref": int(c1_ok.size), "c1_indeterminate": int(band_rows.size - c1_ok.size),
                       "c1_hold_s_p50": _f(np.median(c1_ok["c1_hold_s"])) if c1_ok.size else None, "slippage_sensitive": band_label("eng", PRIMARY_BAND) == "NON_INFORMATIVE"}
    a, b = population("eng", PRIMARY_BAND), population("eng", REF_BAND)
    cells_a, cells_b = strata_cells(a, terciles), strata_cells(b, terciles)
    ka, kb = cells_a < INDETERMINATE_CELL, cells_b < INDETERMINATE_CELL
    s8 = stratified_cluster_bootstrap(clusters(a[ka], decision_kind), r_multiple(a[ka]), cells_a[ka], clusters(b[kb], decision_kind), r_multiple(b[kb]), cells_b[kb], N_CELLS, resamples, BOOTSTRAP_SEED + 8)
    secondary["S8"] = {**s8, "n_band": int(ka.sum()), "n_ref": int(kb.sum()), "strata_indeterminate_band": int((~ka).sum()), "strata_indeterminate_ref": int((~kb).sum()),
                       "slippage_sensitive": primary["slippage_sensitive"]}
    secondary["S9"] = contrast("eng", PRIMARY_BAND, seed_offset=9, where=lambda rows: rows["floor"] == 0)

    runnable = {name: (doc["p"] if doc.get("n_band", 0) >= N_MIN and doc.get("n_ref", 0) >= N_MIN and not doc.get("slippage_sensitive") else None) for name, doc in secondary.items()}
    adjusted = holm(runnable)
    for name, label in SECONDARY_TESTS:
        doc = secondary[name]
        doc["label"] = label
        doc["p_holm"] = adjusted[name]
        doc["verdict"] = contrast_verdict(doc, doc.get("n_band", 0), doc.get("n_ref", 0), adjusted_p=adjusted[name], slippage_sensitive=bool(doc.get("slippage_sensitive")))

    cumulative = {}
    for name, bands in CUMULATIVE.items():
        rows = np.concatenate([population("eng", band) for band in bands])
        no_floor = rows[rows["floor"] == 0] if rows.size else rows
        cumulative[name] = {"bands": list(bands), "filled": int(rows.size), "mean_r": _mean_r(rows), "filled_without_floor": int(no_floor.size), "mean_r_without_floor": _mean_r(no_floor),
                            "note": "nested union of disjoint arms, descriptive only"}

    return {"generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), "alpha": ALPHA, "n_min": N_MIN, "resamples": resamples, "seed": BOOTSTRAP_SEED,
            "multiplicity_rule": rule, "primary": primary_doc, "secondary": secondary, "arms": arms, "cumulative_descriptive": cumulative,
            "indeterminate_and_exclusions": manifest.get("counters_total", {})}
