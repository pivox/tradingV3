"""E0a — pre-fill census of the P2-B4 signals by leverage band (PREREGISTRATION-leverage-gt2.md §7).

Every signal second of every frozen configuration, on every pair-window series, classified from
its decision-time inputs only (`engine.decision_inputs`): no order is simulated, no fill, no
exit, no print after the decision second is read, and no pair blocking is applied (an upper
envelope of what E1 can admit). Counts per configuration x pair x window x stream, under L_eng
and L_stop, by band, with the indeterminable reasons, the floor strata, and the pooled terciles
of the C2 stratum inputs (volatility, tick size, liquidity), which E1 then uses unchanged.
"""

from __future__ import annotations

import numpy as np

from ..research_exec.scalp_engine import SETUP_TTL_US, Policy, SeriesData
from ..research_exec.screen import parse_configuration
from ..research_exec.tick import signal_mask
from .engine import OK, decision_inputs
from .leverage import ARM_BANDS, BAND_NAMES, DEFINITIONS, INDETERMINATE_REASONS, REF_BAND, STUDIED_BANDS, assert_admissible, band_index
from .protocol import F_MIN, N_MIN, STRATA_AXES

POLICY = Policy("E1", "B4", ttl_us=SETUP_TTL_US)


def streams_of(identifier: str, features: dict[str, np.ndarray]) -> dict[int, np.ndarray]:
    """Decision seconds per stream of one configuration (a "both" configuration has two)."""
    rule, side, _entry, _exit = parse_configuration(identifier)
    return {stream: np.flatnonzero(signal_mask(rule, features, stream)) for stream in ((1, -1) if side == 0 else (side,))}


def classify(data: SeriesData, side: int, seconds: np.ndarray) -> dict:
    """Counts of one stream: reasons, and per definition the band totals and their floor share."""
    x = decision_inputs(data, side, seconds, POLICY.stale_s)
    status = x["status"]
    ok = status == OK
    out = {"signals": int(status.size), "reasons": {reason: int(np.sum(status == k + 1)) for k, reason in enumerate(INDETERMINATE_REASONS)}}
    for definition in DEFINITIONS:
        idx = band_index(x["l_eng" if definition == "eng" else "l_stop"])
        out[definition] = {name: {"total": int(np.sum(ok & (idx == k))), "floor": int(np.sum(ok & (idx == k) & (x["floor"] == 1)))} for k, name in enumerate(BAND_NAMES)}
    out["strata"] = {axis: x[axis][ok] for axis in STRATA_AXES}
    return out


def census_series(data: SeriesData, name: str, configurations: tuple[str, ...]) -> dict:
    assert_admissible(name)
    parts = {}
    for identifier in configurations:
        streams = streams_of(identifier, data.features)
        parts[identifier] = {str(side): classify(data, side, seconds) for side, seconds in streams.items()}
    return parts


def _empty_bands() -> dict:
    return {name: {"total": 0, "floor": 0} for name in BAND_NAMES}


def aggregate(series_parts: list[tuple[str, dict]]) -> dict:
    """Pool the per-series parts: totals per definition and band, per configuration, per pair,
    per window; indeterminable reasons; and the C2 terciles over the pooled determinable signals."""
    pooled = {definition: _empty_bands() for definition in DEFINITIONS}
    reasons = dict.fromkeys(INDETERMINATE_REASONS, 0)
    by_config: dict[str, dict] = {}
    by_pair: dict[str, dict] = {}
    by_window: dict[str, dict] = {}
    rows = []
    samples = {axis: [] for axis in STRATA_AXES}
    signals = 0
    for name, parts in series_parts:
        _venue, pair, window = name.split(":")
        for identifier, streams in parts.items():
            for side, item in streams.items():
                signals += item["signals"]
                for reason, count in item["reasons"].items():
                    reasons[reason] += count
                for axis in STRATA_AXES:
                    samples[axis].append(np.asarray(item["strata"][axis], dtype=np.float64))
                row = {"config": identifier, "pair": pair, "window": window, "side": int(side), "signals": item["signals"], "reasons": item["reasons"]}
                for definition in DEFINITIONS:
                    row[definition] = {band: dict(item[definition][band]) for band in BAND_NAMES}
                    for target in (pooled[definition], by_config.setdefault(identifier, {d: _empty_bands() for d in DEFINITIONS})[definition],
                                   by_pair.setdefault(pair, {d: _empty_bands() for d in DEFINITIONS})[definition], by_window.setdefault(window, {d: _empty_bands() for d in DEFINITIONS})[definition]):
                        for band in BAND_NAMES:
                            target[band]["total"] += item[definition][band]["total"]
                            target[band]["floor"] += item[definition][band]["floor"]
                rows.append(row)
    terciles = {}
    for axis in STRATA_AXES:
        values = np.concatenate(samples[axis]) if samples[axis] else np.zeros(0)
        values = values[np.isfinite(values)]
        terciles[axis] = [float(q) for q in np.quantile(values, (1 / 3, 2 / 3))] if values.size else None
    return {"signals": signals, "indeterminate": reasons, "pooled": pooled, "by_config": by_config, "by_pair": by_pair, "by_window": by_window, "rows": rows,
            "strata_terciles": terciles, "strata_population": "pooled determinable census signals (all configurations, both definitions share them)"}


def trigger(pooled: dict, n_min: int = N_MIN, f_min: float = F_MIN) -> dict:
    """E0a → E1 rule: a band is triggered iff census x f_min ≥ N_min. Per definition, E1 runs the
    reference arm and the triggered studied bands, and nothing at all if the reference ]1,2] or
    every studied band fails (no contrast is possible)."""
    out = {}
    for definition in DEFINITIONS:
        bands = {band: {"census": int(pooled[definition][band]["total"]), "expected_fills_at_f_min": pooled[definition][band]["total"] * f_min,
                        "triggered": pooled[definition][band]["total"] * f_min >= n_min} for band in ARM_BANDS}
        studied = [band for band in STUDIED_BANDS if bands[band]["triggered"]]
        run = [REF_BAND] + studied if bands[REF_BAND]["triggered"] and studied else []
        out[definition] = {"bands": bands, "e1_arms": run}
    return {"n_min": n_min, "f_min": f_min, "definitions": out}
