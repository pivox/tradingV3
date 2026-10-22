"""Offline tests of the G5 evaluator (`scripts/research_lp/g5_eval.py`, schema `g5-spec/2`, LP-G5 §2.3 (B) xxii) on synthetic
1-minute histories and the repository's own machine spec `specs/g5_gate1_spec.json`: the strict reader
on the real spec (unknown keys, unknown values, capabilities, look-ahead, audits), eligibility at listTime + 30 days, the local-time
generator of T05 and the 8 h filter of T01, the controls at the type's minute of the hour with footprint exclusions (the protocol's
own hour lists), the one classifier P̂(t_arm) at events and controls, the through fill proxy, zero-volume and missing candles, the
costs by role, the funding, the instant unit, C6 (3 × 3 matrix, thresholds, INCONCLUSIVE), the verdict precedence and C1–C7, Holm
and the programme field, the sealing of INCONCLUSIVE / NOT_INFORMATIVE, the economic reading, the descriptive reports and the
command line end to end. The calibration under H0 over 1 000 seeds and the B2 non-regression are in
`test_research_lp_g5_calibration.py`; the Gate 2 (the real `specs/g5_gate2_spec.json`, B xxi) is in `test_research_lp_g5_gate2.py`.

Rewritten on 2026-10-21 for the code (B): the earlier tests read the intermediate format `g5_eval/2`, which the protocol replaces
by `g5-spec/2` (B xviii); each of their concerns is covered here on the new format. No test opens a connection (socket-level
autouse guard) and no market data exists anywhere here."""

from __future__ import annotations

import copy
import json
import math
import socket
import urllib.request
from datetime import date
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import cli, g5_eval, g5_fetch, tape
from scripts.research_lp import events as ev

US = tape.SECOND_US
MINUTE_US = 60 * US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * US
REAL_SPEC = Path(__file__).resolve().parents[1] / "scripts" / "research_lp" / "specs" / "g5_gate1_spec.json"
# LP-G5 Annexe A (informative). A1 (2026-10-21): the draft 0.2.0 was d4189493…af80a; 0.3.0-draft (2ab0073c…62b15a6) carries the amendment A1;
# 0.4.0-draft (2026-10-22, d2a22f71…b97f5e) the freeze controls of the owner decision (a) of that day (Annexe B requests 11, 13, 14, 16, 18);
# 0.4.1-draft (2026-10-22) the calendar pass: CPI 2026-10-14 recorded, sources dated (the content test reports no problem).
REAL_SPEC_SHA256_DRAFT = "1d86d3f538da49010f5e70edb2d55d1200c34ac56e4d30cdec5cc96d4cd35a12"
BASE = json.loads(REAL_SPEC.read_text())
BTC, ETH, SOL = "BTC-USD_UM_XPERP-310404", "ETH-USD_UM_XPERP-310404", "SOL-USD_UM_XPERP-310404"
LISTED = {BTC: "2026-03-30T04:30:00Z", ETH: "2026-04-01T04:00:00Z", SOL: "2026-04-01T04:25:00Z"}


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    """No connection, no name resolution (CTO m4): socket connect / connect_ex / sendto, create_connection, getaddrinfo, urlopen, the
    fetcher's client."""
    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket.socket, "sendto", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(socket, "getaddrinfo", refuse)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)
    monkeypatch.setattr(g5_fetch.UrllibClient, "get", refuse)


# ----------------------------------------------------------------------------- synthetic specs and series


def iso(us: int) -> str:
    return tape.iso_utc(int(us))[:19] + "Z"


def uni(inst: str, listed: str | None = None, tick: float = 0.01) -> dict:
    listed_us = tape.parse_iso_utc(listed or LISTED[inst])
    return {"inst_id": inst, "index_id": inst.split("_")[0], "list_time_utc": iso(listed_us), "eligible_from_utc": iso(listed_us + 30 * DAY_US), "ct_val": 0.0001, "tick_sz": tick}


def derived(universe: list[dict] | None = None, window: dict | None = None, mutate=None) -> dict:
    """The real spec with another universe / window / mutation: the audit numbers that depend on them are dropped (T05 calendar,
    break-evens) or recomputed (fetch-plan estimates). The real spec keeps its own numbers (checked by the first tests)."""
    document = copy.deepcopy(BASE)
    if universe is not None:
        document["universe"]["analysed"] = universe
    if window is not None:
        document["window"] = window
    for item in document["events"]:
        item.pop("audit_expected", None)
    document["economic"]["audit_break_even_net_bp"] = {}
    rows = [{"instId": u["inst_id"], "uly": u["index_id"], "listTime": str(tape.parse_iso_utc(u["list_time_utc"]) // 1000)} for u in document["universe"]["analysed"]]
    estimate = g5_fetch.spec_estimate(rows, 30, tape.day_of_date("2026-08-04") * DAY_US, {"candles_1m": 300, "mark_1m": 100, "index_1m": 100, "funding_hist": 400})
    document["fetch_plan"]["pre_freeze"]["requests_estimate"] = estimate["funding_hist"]
    for stream in ("candles_1m", "mark_1m", "index_1m"):
        document["fetch_plan"]["post_freeze"][stream]["requests_estimate"] = estimate[stream]
    document["fetch_plan"]["requests_total_estimate"] = sum(estimate.values())
    if mutate is not None:
        mutate(document)
    return document


def spec_of(**kw) -> g5_eval.Spec:
    return g5_eval.parse_spec(derived(**kw))


SHORT = {"start": "2026-08-01", "end_exclusive": "2026-10-14"}


def pre_a1(document: dict) -> None:
    """The spec without the amendment A1's cost rule (the T01 entry leg at its design value: 13 / 19.5 bp) — the « sans la clé » case."""
    t01 = document["events"][0]
    t01.pop("adverse_selection_entry_leg")
    t01.pop("cost_sensitivity_pre_a1")
    t01["expected_cost_bp"], t01["expected_cost_bp_stress"] = 13.0, 19.5
    document["descriptive"]["reports"].remove("T01_cost_sensitivity_pre_a1")


def lowered_floor(document: dict, instants: int = 20, days: int = 10) -> None:
    document["power_floor"]["min_instants"] = instants
    document["power_floor"]["min_days"] = days


def settlements_8h(spec: g5_eval.Spec) -> np.ndarray:
    first = (spec.pad_start_us // (8 * HOUR_US) + 1) * 8 * HOUR_US
    return np.arange(first, g5_fetch.END_EXCLUSIVE_US, 8 * HOUR_US, dtype=np.int64)


def market(spec: g5_eval.Spec, meta: g5_eval.Instrument, seed: int = 1, sigma_bp: float = 2.0, classes=None, rate_of=None, t01_drift_bp: float = 0.0, t05_drift_bp: float = 0.0,
           d_cov_us: int | None = None, band_bp: float = 25.0, settlements=None, t01_drift_hours=None, t01_drift_days=None) -> g5_eval.Series:
    """A synthetic instrument on the spec's grid: a Gaussian walk of the last price; candles with low / high at ± `band_bp` (the fill
    proxy then fills); settlements every 8 h (or `settlements`) with a class c ∈ {−1, 0, +1} each (`classes`, default random 40/20/40):
    the premium is c × 0.0012 over the 480 minute boundaries ending at s − 5 (so P̂(s − 5) has class c) and the realized rate
    `rate_of(c)` (default ±5 bp outside the zone, ±1 bp inside); planted effects: a drift c × `t01_drift_bp` over (s − 5, s + 15] at the
    settlements (optionally only at `t01_drift_hours` / before `t01_drift_days`), and a continuation s × `t05_drift_bp` over
    (h + 5, h + 60] at the T05 instants."""
    grid = g5_eval.spec_grid(spec)
    n = grid.size
    rng = np.random.default_rng(seed)
    ret = rng.normal(0.0, sigma_bp / 1e4, n)
    ret[0] = 0.0
    st = settlements_8h(spec) if settlements is None else np.asarray(settlements, dtype=np.int64)
    if classes is None:
        classes = rng.choice(np.array([-1, 0, 1]), size=st.size, p=[0.4, 0.2, 0.4])
    classes = np.asarray(classes, dtype=np.int64)
    rate_of = rate_of or (lambda c, i: 5e-4 * c if c else (1e-4 if i % 2 else -1e-4))
    rates = np.array([rate_of(int(c), i) for i, c in enumerate(classes.tolist())], dtype=np.float64)
    premium = np.zeros(n)
    k_s = (st - int(grid[0])) // MINUTE_US
    for k, c, s in zip(k_s.tolist(), classes.tolist(), st.tolist()):
        hi = k - 5
        lo = max(0, hi - 479)
        if hi >= 0:
            premium[lo: min(hi, n - 1) + 1] = c * 0.0012
        hour_ok = t01_drift_hours is None or ((s % DAY_US) // HOUR_US) in t01_drift_hours
        day_ok = t01_drift_days is None or (s // DAY_US) < t01_drift_days
        if c and t01_drift_bp and hour_ok and day_ok and 5 <= k - 4 and k + 15 < n:
            ret[k - 4: k + 16] += c * t01_drift_bp / 1e4 / 20.0
    close = 100.0 * np.exp(np.cumsum(ret))
    if t05_drift_bp:
        t05 = next(t for t in spec.types if t.generator["kind"] == "local_time")
        for h, _, _ in g5_eval.local_time_instants(t05, spec):
            k = (h - int(grid[0])) // MINUTE_US
            if k + 60 < n:
                s = np.sign(close[k + 5] - close[k])
                if s:
                    ret[k + 6: k + 61] += s * t05_drift_bp / 1e4 / 55.0
        close = 100.0 * np.exp(np.cumsum(ret))
    band = band_bp / 1e4
    if d_cov_us is None:
        inside = st[st >= spec.start_us]
        d_cov_us = int(inside[1]) if inside.size > 1 else None
    return g5_eval.make_series(meta, grid, close, close * (1 - band), close * (1 + band), np.ones(n), close * (1 + premium), close.copy(), st, rates, d_cov_us)


def flat(spec: g5_eval.Spec, meta: g5_eval.Instrument, price: float = 100.0, settlements=(), rates=(), d_cov_us: int | None = None) -> g5_eval.Series:
    grid = g5_eval.spec_grid(spec)
    close = np.full(grid.size, price)
    return g5_eval.make_series(meta, grid, close, close.copy(), close.copy(), np.ones(grid.size), close.copy(), close.copy(), settlements, rates, d_cov_us)


def at(spec: g5_eval.Spec, text: str) -> int:
    return tape.parse_iso_utc(text)


def k_of(series: g5_eval.Series, text: str) -> int:
    return int(series.index_of(np.array([tape.parse_iso_utc(text)]))[0])


# ----------------------------------------------------------------------------- the real spec and the strict reader (B xviii)


def test_the_real_spec_is_accepted_and_every_field_is_read():
    spec, sha = g5_eval.load_spec(REAL_SPEC)
    assert sha == REAL_SPEC_SHA256_DRAFT and spec.status == "DRAFT_NOT_FROZEN" and spec.source == "history" and spec.gate == "G5_GATE1_"
    # A1: 27 capabilities + the 7 of the amendment (a1_*, Gate 1 only); owner decision (a) of 2026-10-22: + the 4 freeze controls of Annexe B
    # (gate2_frozen_together, Gate 1 only; the calendar content test, the freeze_pending: marker, the S_eco floor, both gates).
    assert spec.requires == tuple(BASE["requires"]) and set(spec.requires) == set(g5_eval.HISTORY_REQUIRES) and len(spec.requires) == 38
    assert set(g5_eval.A1_CAPABILITIES) <= set(spec.requires) and len(g5_eval.A1_CAPABILITIES) == 7 and spec.version == "0.4.1-draft"
    assert {"gate2_frozen_together", "calendars_content_test_at_freeze", "freeze_pending_marker_refuses_frozen", "economic_s_eco_at_least_d4"} <= set(spec.requires)
    assert spec.notes["frozen_with"] == {"spec": g5_eval.GATE2_SPEC_PATH, "spec_id": "LP-G5-GATE2-2026"} and spec.notes["freeze_pending_markers"] == []
    # CTO M4: the sha256 of the sealed funding receipt is a freeze_fill value; the draft predates the key (absent = null, listed).
    assert spec.freeze_fill["null"] == ["instruments_snapshot_path", "instruments_snapshot_sha256", "calendars_verified_utc", "code_digest_sha256", "decision_ref_p_g5_1",
                                        "funding_seal_receipt_sha256"]
    assert spec.notes["freeze_fill.absent_keys"] == ["funding_seal_receipt_sha256"] and "absent" not in g5_eval.spec_summary(spec, sha, "x")["freeze_fill"]
    assert len(spec.universe) == 25 and [e["inst_id"] for e in spec.excluded] == ["PONS-USD_UM_XPERP-310919", "STRK-USD_UM_XPERP-310919", "CT-USD_UM_XPERP-311003", "QUANT-USD_UM_XPERP-311003"]
    assert spec.instrument(BTC).eligible_from_us == tape.parse_iso_utc("2026-04-29T04:30:00Z") and spec.eligibility_days == 30.0
    assert (spec.start.isoformat(), spec.end_exclusive.isoformat(), spec.end_us) == ("2026-04-28", "2026-10-14", g5_fetch.END_EXCLUSIVE_US)
    t01, t05 = spec.types
    assert (t01.name, t01.label, t01.role, t01.footprint, t01.arm_min, t01.rest_until_min, t01.return_window, t01.key_candles) == (
        "T01_funding_8h", "T01", "maker_maker", (-5, 15), -5, 1, (-5, 15), (-5, 15))
    assert t01.classifier == {"kind": "premium_wavg", "lookback_min": 480, "min_coverage": 0.9, "high": 0.0009, "low": -0.0009, "threshold_origin": BASE["events"][0]["classifier"]["threshold_origin"]}
    assert t01.fill_proxy == {"kind": "through_candles", "open_from_min": -5, "open_to_min": 0} and t01.validation == {
        "rate_high": 0.0004, "rate_low": -0.0004, "sensitivity_min": 0.8, "false_positive_rate_max": 0.2, "min_denominator": 30}
    assert t01.generator["previous_gap_us"] == t01.generator["next_gap_us"] == 8 * HOUR_US and t01.generator["tolerance_us"] == MINUTE_US
    assert t01.controls == {"minute_of_hour": 0, "grid_hours": (0, 8, 16), "within": "funding_coverage"} and t01.c7 == {"event_hour_utc": 16, "require_point_gt_bp": 0.0}
    assert t01.criteria == ("C1", "C2", "C3", "C4", "C5", "C6", "C7") and t01.proxy_missing_share_max == 0.5 and t01.days_per_year == 365
    assert (t05.name, t05.role, t05.footprint, t05.arm_min, t05.return_window, t05.key_candles, t05.sign) == (
        "T05_us_open", "taker_maker", (0, 60), 5, (5, 60), (0, 5, 60), {"kind": "return_sign", "from_min": 0, "to_min": 5})
    assert t05.generator["minute_of_day"] == 9 * 60 + 30 and t05.generator["tz"] == "America/New_York" and t05.generator["exclude_refs"] == ("cpi", "employment_situation")
    assert t05.controls == {"minute_of_hour": 30, "grid_hours": (0, 4, 8, 12, 16, 20), "within": "spec_window"} and t05.criteria == ("C1", "C2", "C3", "C4", "C5") and t05.days_per_year == 252
    assert g5_eval.cost_terms("maker_maker", spec.costs)["total_bp"] == 13.0 and g5_eval.cost_terms("maker_maker", spec.costs)["stress_total_bp"] == 19.5  # design rule
    # A1: the T01 entry leg is measured by the fill conditioning: 9.5 / 14.25 bp, the pre-A1 13 / 19.5 bp kept as the reported sensitivity.
    assert (t01.entry_adverse_selection, t01.expected_cost_bp, t01.expected_cost_bp_stress) == ("measured_by_fill_proxy", 9.5, 14.25)
    assert g5_eval.type_cost_terms(t01, spec.costs)["total_bp"] == 9.5 and t01.cost_sensitivity == {"expected_cost_bp": 13.0, "expected_cost_bp_stress": 19.5}
    assert g5_eval.cost_terms("taker_maker", spec.costs)["total_bp"] == 15.5 and g5_eval.cost_terms("taker_maker", spec.costs)["stress_total_bp"] == pytest.approx(23.25)
    assert spec.statistics == {"alpha": 0.1, "threshold_bp": 10.0, "block_days": 1, "resamples": 10000, "seed": 20261102, "lb_quantile": 0.1, "ub_quantile": 0.9, "sensitivity_block_days": 7}
    assert spec.power == {"min_instants": 100, "min_days": 30} and spec.unit["min_instruments"] == 1 and spec.price["max_missing_share"] == 0.2
    assert spec.controls_common["margin_min"] == 60 and spec.controls_common["components"] == {
        "settlements": (-5, 15), "default_grid": (-5, 15), "type_instants": ("T05_us_open", 0, 60, "T05"), "sessions": True, "macro": True}
    assert [s["name"] for s in spec.sessions] == ["us_cash_open", "us_cash_close", "london_xetra_open", "hong_kong_open", "tokyo_open", "cme_cf_brr_window", "okx_dated_settlement_average"]
    assert [(m["name"], m["time_min"], m["duration_min"], m["dates_ref"]) for m in spec.macro] == [("cpi", 510, 60, "cpi"), ("employment_situation", 510, 60, "employment_situation"),
                                                                                                    ("fomc_statement", 840, 90, "fomc_statement")]
    assert spec.verdict["fetch_error_share_max"] == 0.1 and spec.verdict["c5_trim_share"] == 0.05 and spec.multiplicity["m_max"] == 7 and spec.multiplicity["immediate_if_p_le"] == 0.0142857
    assert spec.family == ("T01_funding_8h", "T05_us_open") and spec.gate2_inscription == {"PASS": "inscribed", "FAIL": "not_inscribed",
                                                                                          "NOT_INFORMATIVE": "inscribed_automatically_without_futility",
                                                                                          "INCONCLUSIVE": "inscribed_automatically_without_futility"}
    assert spec.economic["s_eco"] == 97.25 and spec.economic["max_live"] == 4 and spec.descriptive["reports"] == tuple(g5_eval.DESCRIPTIVE_REPORTS)
    # Calcul d'audit from the spec's own lists (LP-G5 §4.3, §4.4, §5.2, §7.6, Annexe C): the parse refuses any other number.
    assert spec.audit["T05_us_open"] == {"nyse_days_with_eligible_instrument": 116, "raw_instants": 105, "raw_instants_with_ge_3_instruments": 103,
                                         "first_instant_utc": "2026-04-29T13:30:00Z", "halves_by_lower_median_day": [53, 52]}
    assert spec.audit["T01_funding_8h"]["raw_instants_upper_bound"] == 211 and round(spec.audit["T01_funding_8h"]["not_informative_expected_if_f_inst_below"], 3) == 0.474
    assert {k: round(v, 2) for k, v in spec.audit["break_even"].items()} == BASE["economic"]["audit_break_even_net_bp"]
    assert spec.fetch_plan["estimates"] == {"funding_hist": 50, "candles_1m": 13023, "mark_1m": 22606, "index_1m": 22606} and spec.fetch_plan["total"] == 58285
    assert spec.fetch_plan["estimate_coverage_start_us"] == tape.parse_iso_utc("2026-08-04T00:00:00Z") and spec.fetch_plan["estimate_fetched_on"] == "2026-11-02"


def _refused(mutate, match: str):
    document = copy.deepcopy(BASE)
    mutate(document)
    with pytest.raises(g5_eval.SpecError, match=match):
        g5_eval.parse_spec(document)


def test_the_strict_reader_refuses_unknown_keys_values_capabilities_and_inconsistencies():
    t01 = lambda d: d["events"][0]  # noqa: E731
    t05 = lambda d: d["events"][1]  # noqa: E731
    cases = [
        # unknown keys at every level, never ignored
        (lambda d: d.update({"verdict_rule": "lenient"}), "unknown key"),
        (lambda d: d["price"].update({"fallback": "mark"}), "unknown key"),
        (lambda d: t01(d)["classifier"].update({"smoothing": 2}), "unknown key"),
        (lambda d: d["controls_common"].update({"draws_per_day": 3}), "unknown key"),
        (lambda d: d["fetch_plan"]["post_freeze"]["candles_1m"].update({"bar": "5m"}), "unknown key"),
        (lambda d: d["sealing"].update({"printed_extra": []}), "unknown key"),
        (lambda d: t05(d).update({"stop_bp": 50}), "unknown key"),
        (lambda d: d.pop("sealing"), "missing"),
        (lambda d: t01(d)["generator"].pop("interval_filter"), "missing"),
        # schema, status, constants
        (lambda d: d.update({"spec_schema": "g5-spec/1"}), "not implemented"),
        (lambda d: d.update({"status": "FROZEN"}), "draft|null"),
        (lambda d: d.update({"status": "FROZEN", "spec_version": "1.0.0"}), "null"),
        (lambda d: d.update({"status": "APPROVED"}), "not implemented"),
        (lambda d: d.update({"end_exclusive_utc": "2026-10-20T00:00:00Z"}), "implemented rule"),
        (lambda d: d.update({"quarantine_floor_utc": "2026-11-01T00:00:00Z"}), "implemented rule"),
        (lambda d: d.update({"window": {"start": "2026-10-01", "end_exclusive": "2026-10-20"}}), "quarantine"),
        (lambda d: d.update({"spec_id": "LP-G5-GATE2-2026"}), "Gate 1"),
        # requires: the version lock
        (lambda d: d["requires"].append("funding_snipe"), "not implemented"),
        (lambda d: d["requires"].remove("fill_proxy_through_candles"), "without declaring"),
        (lambda d: d["requires"].append("gate2_sim_maker_post_only_touch"), "not applicable"),  # a Gate 2 capability in a history spec
        (lambda d: d["requires"].append("gate2_capture_simulation"), "not implemented"),  # the ad-hoc name of the first (B) xxi draft, retired
        (lambda d: d["requires"].append("price_source_last_only"), "distinct"),
        # implemented values only
        (lambda d: d["price"].update({"source": "mark"}), "implemented rule"),
        (lambda d: d["price"].update({"forward_fill": "previous"}), "implemented rule"),
        (lambda d: d["frozen_candle"].update({"rule": "keep"}), "implemented rule"),
        (lambda d: d["unit"].update({"min_instruments_per_instant": 0}), "outside"),
        (lambda d: d["unit"].update({"report": ["instruments_per_instant_distribution", "median_split"]}), "not implemented"),
        (lambda d: d["statistics"]["bootstrap"].update({"kind": "iid"}), "not implemented"),
        (lambda d: d["statistics"]["bootstrap"].update({"lb_quantile": 0.05}), "alpha"),
        (lambda d: d["statistics"]["bootstrap"].update({"events_and_controls_jointly": False}), "only true"),
        (lambda d: d["power_floor"].update({"extension_on_result": True}), "only false"),
        (lambda d: d["controls_common"].update({"random_draws": 3}), "only 0"),
        (lambda d: d["controls_common"]["exclusions"].append("full moons"), "not an implemented exclusion"),
        (lambda d: d["sessions"][0].update({"tz": "Mars/Olympus"}), "unknown time zone"),
        (lambda d: d["sessions"][0].update({"days": "weekends"}), "not implemented"),
        (lambda d: d["calendars"].update({"tz": "UTC"}), "New York"),
        (lambda d: d["macro_filter"]["releases"][0].update({"dates_ref": "calendars.ppi"}), "names no date list"),
        # costs: the declared totals are checked (CTO B1-vi)
        (lambda d: t01(d).update({"expected_cost_bp": 10.0}), "cost terms"),
        (lambda d: t05(d).update({"expected_cost_bp_stress": 23.0}), "cost terms"),
        (lambda d: d["costs"].update({"g2_table_substitution": "G2 table when available"}), "none"),
        # types
        (lambda d: t01(d).update({"decisive": False}), "only true"),
        (lambda d: t01(d)["generator"].update({"fallback_hours": "0,8,16"}), "not implemented"),
        (lambda d: t01(d)["generator"]["interval_filter"].update({"unknown_neighbour": "keep"}), "not implemented"),
        (lambda d: t01(d)["generator"].update({"require_realized_rate": False}), "only true"),
        (lambda d: t01(d).update({"arm_min": -1, "return_window_min": [-1, 15], "entry": {"kind": "passive_limit", "price": "c(h-1)"}}), "forbidden"),
        (lambda d: t01(d)["entry"].update({"price": "c(h-1)"}), "implemented rule"),
        (lambda d: t01(d)["fill_proxy"].update({"candles_open_to_min": 1}), "rests"),
        (lambda d: t01(d)["fill_proxy"].update({"long_filled_if": "min(low) <= c(t_arm)"}), "implemented rule"),
        (lambda d: t01(d)["classifier"].update({"lookback_min": 240}), "implemented rule"),
        (lambda d: t01(d)["validation"].update({"classifier_class": "+1 if P_hat(t_arm) >= 0.0006, -1 if <= -0.0005, 0 otherwise"}), "thresholds"),
        (lambda d: t01(d)["validation"].update({"population": "every T01 settlement"}), "population"),
        (lambda d: t01(d)["validation"].update({"fail_or_not_computable": "G5_GATE1_FAIL"}), "implemented rule"),
        (lambda d: t01(d).update({"criteria": ["C1", "C2", "C3", "C4", "C6", "C7"]}), "C1-C5"),
        (lambda d: t05(d).update({"c7": {"event_hour_utc": 13, "controls": "same_utc_days", "require_point_gt_bp": 0.0}}), "without C7"),
        (lambda d: t01(d).update({"inconclusive_if": ["funding_coverage_unknown", "C6_failed_or_not_computable", "moon_phase"]}), "not implemented"),
        (lambda d: t01(d).update({"inconclusive_if": ["C6_failed_or_not_computable"]}), "funding_coverage_unknown"),
        (lambda d: t05(d)["controls"].update({"within": "funding_coverage"}), "window_rule"),
        (lambda d: t05(d)["controls"].update({"days": "every day"}), "implemented rule"),
        (lambda d: t05(d)["generator"].update({"days": "fri"}), "not implemented"),
        # calcul d'audit: a number the spec's own lists contradict
        (lambda d: t05(d)["audit_expected"].update({"raw_instants": 123}), "calcul d'audit"),
        (lambda d: t05(d)["audit_expected"].update({"halves_by_lower_median_day": [52, 53]}), "calcul d'audit"),
        (lambda d: t01(d)["audit_expected"].update({"raw_instants_upper_bound_if_funding_fetched_2026_11_02": 213}), "assumed D_cov"),
        (lambda d: t01(d)["audit_expected"].update({"magic": 1}), "unknown key"),
        (lambda d: d["economic"]["audit_break_even_net_bp"].update({"T05_us_open_4_fills_per_event_day": 9.6}), "S_eco"),
        (lambda d: d["fetch_plan"]["post_freeze"]["candles_1m"].update({"requests_estimate": 13000}), "sum|recomputation"),
        (lambda d: d["fetch_plan"]["post_freeze"]["mark_1m"].update({"page": 50}), "documented maximum"),
        (lambda d: d["fetch_plan"].update({"host": "https://www.okx.com"}), "implemented rule"),
        (lambda d: d["fetch_plan"].update({"rate_scale_default": 0.5}), "default"),
        (lambda d: d["fetch_plan"]["pre_freeze"].update({"streams": ["funding_hist", "candles_1m"]}), "before the freeze"),
        (lambda d: d["fetch_plan"]["pre_freeze"].update({"printed": ["jobs_total", "rows"]}), "prints"),
        # verdict, multiplicity, sealing, inscription, economics, descriptive
        (lambda d: d["verdict"].update({"precedence": ["criteria_then_holm", "G5_GATE1_INCONCLUSIVE", "G5_GATE1_NOT_INFORMATIVE"]}), "precedence"),
        (lambda d: d["verdict"]["criteria"].update({"C4": "D point > 0 in each calendar half"}), "implemented rule"),
        (lambda d: d["verdict"]["inconclusive_global"].append("operator_mood"), "not implemented"),
        (lambda d: d["multiplicity"]["local_holm"].update({"family": ["T01_funding_8h"]}), "decisive types"),
        (lambda d: d["multiplicity"]["programme"].update({"immediate_if_p_le": 0.02}), "alpha / m_max"),
        (lambda d: d["multiplicity"]["programme"].update({"field": "pass_programme"}), "implemented rule"),
        (lambda d: d["multiplicity"].update({"forbidden_field_names": []}), "non-empty|forbid"),
        (lambda d: d["sealing"].update({"printed": d["sealing"]["printed"] + ["D"]}), "not implemented"),
        (lambda d: d["sealing"].update({"applies_to_verdicts": ["G5_GATE1_INCONCLUSIVE"]}), "only"),
        (lambda d: d["gate2_inscription"].update({"G5_GATE1_FAIL": "inscribed"}), "not implemented"),
        (lambda d: d["gate2_inscription"].update({"discretion": "owner"}), "not implemented"),
        (lambda d: d["economic"].update({"max_live": 6}), "not feasible"),
        (lambda d: d["economic"].update({"readings": ["PASS", "FAIL", "?"]}), "readings"),
        (lambda d: d["descriptive"]["reports"].append("T05_lunar_cycle"), "not implemented"),
        (lambda d: d["universe"]["analysed"][0].update({"eligible_from_utc": "2026-04-28T04:30:00Z"}), "list_time_utc"),
        (lambda d: d["universe"]["analysed"][0].update({"index_id": "ETH-USD"}), "index"),
        (lambda d: d["universe"]["analysed"].append({"inst_id": "PONS-USD_UM_XPERP-310919", "index_id": "PONS-USD", "list_time_utc": "2026-09-16T08:00:00Z",
                                                     "eligible_from_utc": "2026-10-16T08:00:00Z", "ct_val": 10, "tick_sz": 0.0001}), "excluded_never_eligible"),
        (lambda d: d["universe"].update({"mismatch_with_snapshot": "warn"}), "not implemented"),
        (lambda d: d.update({"gate2": {}}), "unknown key"),
        # every field with an implemented meaning is checked, one mutation each (B xviii)
        (lambda d: d["macro_filter"].update({"evaluate": True}), "only false"),
        (lambda d: d["eligibility"].update({"reference": "instant"}), "not implemented"),
        (lambda d: d["eligibility"].update({"applies_to": ["events"]}), "events and the controls"),
        (lambda d: d["universe"]["excluded_never_eligible"][0].update({"fetched": True}), "only false"),
        (lambda d: d["price"].update({"mixing_sources": "allowed"}), "implemented rule"),
        (lambda d: d["price"].update({"confirm_zero_rows": "keep"}), "implemented rule"),
        (lambda d: d["unit"].update({"instant_weight": "by_instruments"}), "implemented rule"),
        (lambda d: d["costs"]["funding"].update({"cap_stress": "in_net"}), "implemented rule"),
        (lambda d: d["costs"]["funding"].update({"outside_coverage": "skip"}), "implemented rule"),
        (lambda d: d["costs"].update({"margin_opportunity_cost": "subtracted"}), "not implemented"),
        (lambda d: d["sealing"].update({"print_sha256": False}), "only true"),
        (lambda d: d["sealing"].update({"file": "sealed.json"}), "implemented rule"),
        (lambda d: d["fetch_plan"]["pre_freeze"].update({"register_status": "consumed"}), "not implemented"),
        (lambda d: d["fetch_plan"]["pre_freeze"].update({"mode": "values"}), "not implemented"),
        (lambda d: d["fetch_plan"]["post_freeze"]["mark_1m"].update({"start": "list_time_utc"}), "implemented start"),
        (lambda d: d["statistics"]["bootstrap"].update({"p_value": "share of resamples with D* < 0"}), "implemented rule"),
        (lambda d: d["statistics"].update({"difference": "D = median difference"}), "implemented rule"),
        (lambda d: d["controls_common"].update({"selection": "sample"}), "implemented rule"),
        (lambda d: d["controls_common"].update({"funding_at_controls": "ignored"}), "implemented rule"),
        (lambda d: t01(d)["fill_proxy"].update({"applies_to": ["events"]}), "events and controls"),
        (lambda d: t01(d)["c7"].update({"controls": "all_days"}), "not implemented"),
        (lambda d: t01(d)["funding_coverage"].update({"unknown": "assume 2026-08-01"}), "implemented rule"),
        (lambda d: t05(d)["sign"].update({"zero": "keep"}), "not implemented"),
        (lambda d: t05(d)["sign"].update({"price": "mark"}), "not implemented"),
        (lambda d: t05(d)["exit"].update({"price": "c(h+55)"}), "implemented rule"),
        (lambda d: d["verdict"].update({"fail": "G5_GATE1_FAIL(type) whenever C1 fails"}), "implemented rule"),
        (lambda d: d["multiplicity"]["local_holm"].update({"p_for_inconclusive_or_not_informative": 0.5}), "only 1.0"),
        (lambda d: d["economic"].update({"admission_order_within_instant": "random"}), "implemented rule"),
        (lambda d: d["economic"].update({"measure": "D"}), "implemented rule"),
        (lambda d: d["freeze_fill"].update({"signature": "x"}), "unknown key"),
        (lambda d: d["events"][0].update({"inconclusive_if": ["funding_coverage_unknown", "C6_failed_or_not_computable", "proxy_missing_instrument_day_share_gt_0_50",
                                                               "classifier_index_source_unresolved_or_unavailable"]}), "capture"),
    ]
    for mutate, match in cases:
        _refused(mutate, match)


def test_no_information_after_the_decision_is_refused_at_parse_time():
    """(B) xvii, CTO B2: a classifier evaluated after t_arm, a sign window ending after arm_min, are refused (look-ahead)."""
    _refused(lambda d: d["events"][0]["classifier"].update({"evaluated_at": "h-1"}), "information after the decision")
    _refused(lambda d: d["events"][0]["classifier"].update({"evaluated_at": "settlement"}), "information after the decision")
    _refused(lambda d: d["events"][1]["sign"].update({"to_min": 6}), "look-ahead")
    _refused(lambda d: d["events"][1].update({"arm_min": 4, "return_window_min": [4, 60], "entry": {"kind": "taker", "price": "c(h+4)", "half_spread_bp": "costs.taker_entry_half_spread_bp"}}),
             "look-ahead")
    _refused(lambda d: d["events"][0]["classifier"].update({"applies_to": ["events"]}), "events and the controls")
    # The only post-decision quantities are the fill, the exit and the return (and the realized rate as a cost): the classifier and the
    # sign end at t_arm by construction.
    spec, _ = g5_eval.load_spec(REAL_SPEC)
    t01, t05 = spec.types
    assert t01.lookback_min == -484 and max(t05.sign["from_min"], t05.sign["to_min"]) <= t05.arm_min


def verified_sessions(d: dict) -> None:
    """At the freeze the sessions marked verify_at_freeze are verified (P-G5-5) and written false; the lists pass the content test (Annexe B
    request 13, 2026-10-22: the CPI of October 2026, 2026-10-14 in Annexe C, outside the window, is listed)."""
    for session in d["sessions"]:
        if "verify_at_freeze" in session:
            session["verify_at_freeze"] = False
    if "2026-10-14" not in d["calendars"]["cpi"]:
        d["calendars"]["cpi"].append("2026-10-14")


def test_a_frozen_spec_needs_its_freeze_fill_and_the_c5_trim_share_is_read_from_its_text():
    def frozen(d):
        verified_sessions(d)
        d.update({"status": "FROZEN", "spec_version": "1.0.0"})
        d["freeze_fill"].update({"instruments_snapshot_path": "snapshot.json", "instruments_snapshot_sha256": "a" * 64, "calendars_verified_utc": "2026-11-02T10:00:00Z",
                                 "code_digest_sha256": "b" * 64, "decision_ref_p_g5_1": "owner, 2026-11-02: scale confirmed", "funding_seal_receipt_sha256": "d" * 64})

    document = copy.deepcopy(BASE)
    frozen(document)
    spec = g5_eval.parse_spec(document)
    assert spec.frozen and spec.freeze_fill["null"] == [] and spec.freeze_fill["funding_seal_receipt_sha256"] == "d" * 64
    # CTO M4: a FROZEN Gate 1 names its sealed funding receipt (absent or null refused; a lower-case sha256).
    _refused(lambda d: (frozen(d), d["freeze_fill"].pop("funding_seal_receipt_sha256")), "funding_seal_receipt_sha256")
    _refused(lambda d: (frozen(d), d["freeze_fill"].update({"funding_seal_receipt_sha256": None})), "funding_seal_receipt_sha256")
    _refused(lambda d: (frozen(d), d["freeze_fill"].update({"funding_seal_receipt_sha256": "D" * 64})), "sha256")
    _refused(lambda d: (frozen(d), d["freeze_fill"].update({"code_digest_sha256": "not-a-sha"})), "sha256")
    _refused(lambda d: (frozen(d), d["freeze_fill"].update({"calendars_verified_utc": "yesterday"})), "ISO")
    _refused(lambda d: (frozen(d), d["sessions"][5].update({"verify_at_freeze": True})), "verify_at_freeze")  # the BRR window left unverified
    # Owner decision (a) of 2026-10-22 (Annexe B 13, 14, 16): the content test, the freeze_pending: marker, the S_eco floor.
    _refused(lambda d: (frozen(d), d["calendars"]["cpi"].remove("2026-10-14")), "content test.*cpi: no date in 2026-10")
    _refused(lambda d: (frozen(d), d["calendars"]["fomc_statement"].remove("2026-07-29")), "content test.*fomc_statement: no date in the 56-day span")
    _refused(lambda d: (frozen(d), d["calendars"]["nyse_half_days"].append("2026-07-04")), "content test.*not a NYSE trading day")
    _refused(lambda d: (frozen(d), d["sessions"][1].pop("on_nyse_half_days"), d["calendars"]["nyse_half_days"].append("2026-11-27")), "content test.*no session carries")
    _refused(lambda d: (frozen(d), d["universe"].update({"rule": d["universe"]["rule"] + " ; freeze_pending: to write"})), "freeze_pending: in a FROZEN spec")
    _refused(lambda d: d["economic"].update({"s_eco_usd_per_year": 97.0}), "97.25")
    _refused(lambda d: d.update({"frozen_with": {**d["frozen_with"], "spec": "elsewhere.json"}}), "frozen_with.spec")
    _refused(lambda d: d.update({"frozen_with": {**d["frozen_with"], "rule": "read when convenient"}}), "frozen_with.rule")
    _refused(lambda d: d.pop("frozen_with"), "missing")
    document = copy.deepcopy(BASE)
    document["verdict"]["criteria"]["C5"] = document["verdict"]["criteria"]["C5"].replace("0.05", "0.10")
    assert g5_eval.parse_spec(document).verdict["c5_trim_share"] == 0.10


# ----------------------------------------------------------------------------- generators, eligibility, local time (B ii, vii, x, xii)


def test_t05_instants_are_09_30_new_york_on_frozen_nyse_days_without_cpi_and_nfp():
    spec, _ = g5_eval.load_spec(REAL_SPEC)
    t05 = spec.type("T05_us_open")
    rows = g5_eval.local_time_instants(t05, spec, apply_exclusions=False)
    instants = {d.isoformat(): (iso(m), e) for m, d, e in rows}
    assert instants["2026-04-29"] == ("2026-04-29T13:30:00Z", False) and instants["2026-06-10"] == ("2026-06-10T13:30:00Z", True)  # CPI: generated, excluded
    assert "2026-06-19" not in instants and "2026-07-03" not in instants and "2026-05-02" not in instants  # Juneteenth, Independence Day observed, a Saturday
    assert instants["2026-07-02"][1] is True and instants["2026-04-28"] == ("2026-04-28T13:30:00Z", False)  # Employment Situation on a Thursday
    assert all(iso(m).endswith("13:30:00Z") for m, _, _ in rows)  # the whole Gate 1 window is EDT
    kept = g5_eval.local_time_instants(t05, spec)
    assert len(kept) == len(rows) - sum(1 for _, _, e in rows if e)
    # DST from local time (zoneinfo), checked against events.us_dst_active: 14:30 UTC in EST.
    ny = t05.generator["tz_info"]
    assert iso(g5_eval.local_to_utc_us(date(2026, 11, 2), 570, ny)) == "2026-11-02T14:30:00Z" and iso(g5_eval.local_to_utc_us(date(2027, 3, 12), 570, ny)) == "2027-03-12T14:30:00Z"
    assert iso(g5_eval.local_to_utc_us(date(2027, 3, 15), 570, ny)) == "2027-03-15T13:30:00Z"
    london = next(s for s in spec.sessions if s["name"] == "london_xetra_open")["tz_info"]
    assert iso(g5_eval.local_to_utc_us(date(2026, 8, 5), 480, london)) == "2026-08-05T07:00:00Z" and iso(g5_eval.local_to_utc_us(date(2026, 11, 2), 480, london)) == "2026-11-02T08:00:00Z"


def test_new_york_conversions_are_checked_against_events_us_dst_active(monkeypatch):
    spec, _ = g5_eval.load_spec(REAL_SPEC)
    monkeypatch.setattr(ev, "us_dst_active", lambda day: False)
    with pytest.raises(g5_eval.SpecError, match="us_dst_active"):
        g5_eval.local_to_utc_us(date(2026, 8, 5), 570, spec.type("T05_us_open").generator["tz_info"])


def test_t01_instants_keep_8h_settlements_with_both_neighbours_and_a_realized_rate():
    spec = spec_of(universe=[uni(BTC)], window=SHORT)
    meta = spec.instrument(BTC)
    base = tape.parse_iso_utc("2026-09-01T00:00:00Z")
    st = [base + h * HOUR_US for h in (0, 8, 16, 24, 28, 32, 40, 48, 56)] + [base + 56 * HOUR_US + 30 * US]  # a 4 h pair (24 → 28 → 32) and a misaligned one
    rates = [1e-4] * len(st)
    rates[7] = math.nan  # 48 h: realized rate missing
    series = flat(spec, meta, settlements=st, rates=rates, d_cov_us=base)
    kept, counts, other = g5_eval.settlement_instants(series, spec.type("T01_funding_8h"), spec)
    hours = [(t - base) // HOUR_US for t in kept.tolist()]
    assert hours == [8, 16, 40] and counts["unknown_neighbour"] == 1 and counts["realized_rate_missing"] == 1 and counts["misaligned"] == 1
    assert counts["interval_other"] == 4 and sorted((t - base) // HOUR_US for t in other.tolist()) == [24, 28, 32, 56]  # the T02 stratum (descriptive)
    # CTO M1: the h+ gap is known after the decision; the instants removed by that criterion alone are counted (24 h: next at 4 h;
    # 56 h: next 30 s later), the protocol deciding at the freeze between filtering on h- only and declaring the exception.
    assert counts["removed_by_next_neighbour_only"] == 2 and "next_unknown_at_decision" not in counts  # history: h+ = the next settlement served
    # The settlement of 2026-10-13 16:00 has no next one before END_EXCLUSIVE: excluded (LP-G5 §4.2), counted with the h+ criterion.
    last = flat(spec, meta, settlements=settlements_8h(spec), rates=np.full(settlements_8h(spec).size, 1e-4), d_cov_us=base)
    kept, counts, _ = g5_eval.settlement_instants(last, spec.type("T01_funding_8h"), spec)
    assert iso(int(kept[-1])) == "2026-10-13T08:00:00Z" and counts["removed_by_next_neighbour_only"] == 1


def test_eligibility_at_list_time_plus_30_days_applies_to_the_footprint_of_events_and_controls():
    """(B) vii: an instrument-instant exists only if its footprint starts at listTime + 30 days or later."""
    listed = "2026-08-20T13:32:00Z"  # eligible from 2026-09-19 13:32 (a Saturday): the first T05 event is 2026-09-21
    spec = spec_of(universe=[uni(BTC, listed)], window=SHORT)
    meta = spec.instrument(BTC)
    assert iso(meta.eligible_from_us) == "2026-09-19T13:32:00Z"
    t05, t01 = spec.type("T05_us_open"), spec.type("T01_funding_8h")
    series = flat(spec, meta, settlements=settlements_8h(spec), rates=np.full(settlements_8h(spec).size, 1e-4), d_cov_us=spec.start_us)
    common = g5_eval.common_instants(spec)
    raw, counts = g5_eval.restrict(series, common["local_time"]["T05_us_open"], t05, spec)
    assert iso(int(raw[0])) == "2026-09-21T13:30:00Z" and counts["excluded_ineligible"] == int((common["local_time"]["T05_us_open"] < raw[0]).sum())
    # T01: the footprint starts at h − 5 — 2026-09-19 16:00 starts 15:55 >= 13:32 (kept), 08:00 starts 07:55 (excluded).
    kept, _, _ = g5_eval.settlement_instants(series, t01, spec)
    raw01, counts01 = g5_eval.restrict(series, kept, t01, spec)
    assert iso(int(raw01[0])) == "2026-09-19T16:00:00Z" and counts01["excluded_ineligible"] > 0
    controls, diag = g5_eval.control_instants(series, t01, spec, raw01, g5_eval.exclusion_intervals(series, t01, spec, common))
    assert controls.size and int(controls.min()) - 5 * MINUTE_US >= meta.eligible_from_us
    # A control of the first event day before the eligibility is not admitted: 2026-09-19 03:00..12:00 are before 13:32.
    first_day = [iso(int(c)) for c in controls.tolist() if iso(int(c)).startswith("2026-09-19")]
    assert first_day == ["2026-09-19T18:00:00Z", "2026-09-19T19:00:00Z", "2026-09-19T20:00:00Z", "2026-09-19T21:00:00Z", "2026-09-19T22:00:00Z"]
    assert diag["restriction"]["excluded_ineligible"] >= 1


# ----------------------------------------------------------------------------- controls at the minute of the hour, footprint exclusions (B xi, xii)


def _control_hours(spec, series, tspec, day: str) -> list[str]:
    common = g5_eval.common_instants(spec)
    d = tape.day_of_date(day)
    raw = np.array([d * DAY_US + (13 * 60 + 30) * MINUTE_US], dtype=np.int64) if tspec.generator["kind"] == "local_time" else np.array([d * DAY_US + 16 * HOUR_US], dtype=np.int64)
    controls, _ = g5_eval.control_instants(series, tspec, spec, raw, g5_eval.exclusion_intervals(series, tspec, spec, common))
    return [iso(int(c))[11:16] for c in controls.tolist()]


def test_controls_are_every_admissible_hour_at_the_type_minute_with_the_protocol_hour_lists():
    """LP-G5 §7.1 (calcul d'audit, summer time): T01 an ordinary NYSE day 03, 04, 05, 10, 11, 12, 18, 22 h; 12 h out on CPI / NFP days,
    18 h out on FOMC days, 13 hours on a Saturday; T05 09:30, 17:30, 21:30 UTC (two on FOMC days). Footprints, not instants."""
    spec = spec_of(universe=[uni(BTC)], window=SHORT)
    meta = spec.instrument(BTC)
    series = flat(spec, meta, settlements=settlements_8h(spec), rates=np.full(settlements_8h(spec).size, 1e-4), d_cov_us=spec.start_us)
    t01, t05 = spec.type("T01_funding_8h"), spec.type("T05_us_open")
    assert _control_hours(spec, series, t01, "2026-08-05") == ["03:00", "04:00", "05:00", "10:00", "11:00", "12:00", "18:00", "22:00"]
    assert _control_hours(spec, series, t01, "2026-08-12") == ["03:00", "04:00", "05:00", "10:00", "11:00", "18:00", "22:00"]  # CPI
    assert _control_hours(spec, series, t01, "2026-09-04") == ["03:00", "04:00", "05:00", "10:00", "11:00", "18:00", "22:00"]  # Employment Situation (a Friday)
    assert _control_hours(spec, series, t01, "2026-09-16") == ["03:00", "04:00", "05:00", "10:00", "11:00", "12:00", "22:00"]  # FOMC
    assert len(_control_hours(spec, series, t01, "2026-08-08")) == 13  # a Saturday
    assert _control_hours(spec, series, t05, "2026-08-05") == ["09:30", "17:30", "21:30"]
    assert _control_hours(spec, series, t05, "2026-09-16") == ["09:30", "21:30"]  # FOMC
    # The settlements of the instrument, all intervals: an escalated 04:00 settlement removes 03, 04 and 05 h for this instrument only.
    extra = np.sort(np.concatenate([settlements_8h(spec), [tape.parse_iso_utc("2026-08-05T04:00:00Z")]]))
    escalated = flat(spec, meta, settlements=extra, rates=np.full(extra.size, 1e-4), d_cov_us=spec.start_us)
    assert _control_hours(spec, escalated, t01, "2026-08-05") == ["10:00", "11:00", "12:00", "18:00", "22:00"]
    # Footprint rule at the margin: admissible iff end <= start − margin or start >= end + margin.
    a = np.array([0, 0, 0], dtype=np.int64)
    b = np.array([100, 101, 50], dtype=np.int64)
    assert g5_eval.violates(np.array([160]), np.array([170]), a, b, 60).tolist() == [False, True, False]
    assert g5_eval.violates(np.array([-200]), np.array([-60]), a, b, 60).tolist() == [False, False, False]
    assert g5_eval.violates(np.array([-200]), np.array([-59]), a, b, 60).tolist() == [True, True, True]


# ----------------------------------------------------------------------------- the one classifier at t_arm (B iv, xvii)


def test_one_classifier_p_hat_at_t_arm_for_events_and_controls_and_nothing_after_the_decision():
    spec = spec_of(universe=[uni(BTC)], window=SHORT)
    meta = spec.instrument(BTC)
    t01 = spec.type("T01_funding_8h")
    h = tape.parse_iso_utc("2026-09-02T16:00:00Z")
    c = tape.parse_iso_utc("2026-09-03T04:00:00Z")  # an admissible control hour; its look-back does not overlap the event's
    series = flat(spec, meta, settlements=settlements_8h(spec), rates=np.full(settlements_8h(spec).size, 5e-4), d_cov_us=spec.start_us)
    series.low[:], series.high[:] = 99.0, 101.0  # the resting orders fill: this test is about the classifier
    k_h, k_c = int(series.index_of([h])[0]), int(series.index_of([c])[0])
    premium = np.zeros(series.size)
    premium[k_h - 5 - 479: k_h - 5 + 1] = 0.0012  # the 480 boundaries ending at h − 5
    premium[k_h - 4: k_h + 30] = -0.05  # after the decision: never read
    premium[k_c - 5 - 479: k_c - 5 + 1] = -0.0010
    series.mark[:] = series.index * (1.0 + premium)
    series.cache.clear()
    value = series.p_hat(np.array([k_h - 5, k_c - 5]), t01.classifier)
    assert value[0] == pytest.approx(0.0012, rel=1e-9) and value[1] == pytest.approx(-0.0010, rel=1e-9)
    e = g5_eval.measure(series, [h], t01, spec, "event")
    k = g5_eval.measure(series, [c], t01, spec, "control")
    assert e["sign"].tolist() == [1] and e["p_hat"][0] == pytest.approx(0.0012, rel=1e-9) and k["sign"].tolist() == [-1]
    # Changing everything after t_arm changes neither P̂ nor the class (no look-ahead); the same function serves events and controls.
    series.mark[k_h - 4:] = series.index[k_h - 4:] * 1.5
    series.cache.clear()
    assert g5_eval.measure(series, [h], t01, spec, "event")["p_hat"][0] == pytest.approx(0.0012, rel=1e-9)
    # Inside the zone: excluded and counted, at events as at controls.
    series.mark[k_c - 5 - 479: k_c - 5 + 1] = series.index[k_c - 5 - 479: k_c - 5 + 1] * (1 + 0.0008)
    series.cache.clear()
    assert g5_eval.measure(series, [c], t01, spec, "control")["counts"]["classifier_inside_zone"] == 1
    # Weights 1..480 renormalised over the present boundaries; fewer than 90 % present: not computable.
    rng = np.random.default_rng(5)
    pi = rng.normal(0, 1e-3, 480)
    gaps = rng.choice(480, size=40, replace=False)
    window = series.index[k_c - 5 - 479: k_c - 5 + 1] * (1 + pi)
    window[gaps] = np.nan
    series.mark[k_c - 5 - 479: k_c - 5 + 1] = window
    series.cache.clear()
    w = np.arange(1, 481, dtype=np.float64)
    ok = np.ones(480, dtype=bool)
    ok[gaps] = False
    expected = math.fsum((w[ok] * pi[ok]).tolist()) / math.fsum(w[ok].tolist())
    assert series.p_hat(np.array([k_c - 5]), t01.classifier)[0] == pytest.approx(expected, rel=1e-9, abs=1e-15)
    series.mark[k_c - 5 - 479: k_c - 5 - 479 + 50] = np.nan  # 90 missing of 480: 81 % present
    series.cache.clear()
    assert math.isnan(series.p_hat(np.array([k_c - 5]), t01.classifier)[0]) and g5_eval.measure(series, [c], t01, spec, "control")["counts"]["classifier_not_computable"] == 1


# ----------------------------------------------------------------------------- fill proxy, key candles, missing data (B vi, viii, i)


def test_the_through_fill_proxy_zero_volume_key_candles_and_missing_prices():
    spec = spec_of(universe=[uni(BTC, tick=0.1)], window=SHORT)
    meta = spec.instrument(BTC)
    t01 = spec.type("T01_funding_8h")
    h = tape.parse_iso_utc("2026-09-02T16:00:00Z")
    series = flat(spec, meta, settlements=settlements_8h(spec), rates=np.full(settlements_8h(spec).size, 5e-4), d_cov_us=spec.start_us)
    k = int(series.index_of([h])[0])
    series.mark[k - 5 - 479: k - 5 + 1] = series.index[k - 5 - 479: k - 5 + 1] * 1.0012  # long: rests at the bid c(h − 5) = 100
    series.cache.clear()

    def run(low=None, high=None, vol=None, close=None):
        s = g5_eval.make_series(meta, series.grid_us, series.close.copy() if close is None else close, series.low.copy() if low is None else low,
                                series.high.copy() if high is None else high, series.vol.copy() if vol is None else vol, series.mark, series.index, series.settlements_us,
                                series.settlement_rates, series.d_cov_start_us)
        return g5_eval.measure(s, [h], t01, spec, "event")

    low = series.low.copy()
    assert run(low)["counts"]["unfilled"] == 1  # never below 100 − 1 tick
    low[k - 4] = 99.9  # the candle opening at h − 5 (ending h − 4) touches exactly c − 1 tick: not strictly below
    assert run(low)["counts"]["unfilled"] == 1
    low[k + 1] = 99.89  # the candle opening at h (ending h + 1): inside the resting window
    assert run(low)["counts"]["kept"] == 1
    low2 = series.low.copy()
    low2[k + 2] = 90.0  # opening at h + 1: after the order's end
    low2[k - 5] = 90.0  # opening at h − 6: before the decision
    assert run(low2)["counts"]["unfilled"] == 1
    low3 = low.copy()
    low3[k - 2] = np.nan
    assert run(low3)["counts"]["fill_window_missing"] == 1
    vol = series.vol.copy()
    vol[k + 15] = 0.0  # the exit candle is frozen: excluded
    assert run(low, vol=vol)["counts"]["excluded_vol_zero"] == 1
    vol = series.vol.copy()
    vol[k + 3] = 0.0  # not a key candle
    assert run(low, vol=vol)["counts"]["kept"] == 1
    close = series.close.copy()
    close[k + 15] = np.nan
    assert run(low, close=close)["counts"]["missing_key_price"] == 1
    # A control is held to the same proxy: the same arrays give the same outcome at a control instant.
    c = tape.parse_iso_utc("2026-09-02T11:00:00Z")
    kc = int(series.index_of([c])[0])
    series.mark[kc - 5 - 479: kc - 5 + 1] = series.index[kc - 5 - 479: kc - 5 + 1] * 1.0012
    series.cache.clear()
    s = g5_eval.make_series(meta, series.grid_us, series.close, series.low, series.high, series.vol, series.mark, series.index, series.settlements_us, series.settlement_rates,
                            series.d_cov_start_us)
    assert g5_eval.measure(s, [c], t01, spec, "control")["counts"]["unfilled"] == 1
    s.low[kc + 1] = 99.8
    assert g5_eval.measure(s, [c], t01, spec, "control")["counts"]["kept"] == 1


def test_an_instrument_missing_more_than_20_percent_of_its_event_footprints_leaves_the_type():
    document = derived(universe=[uni(BTC), uni(ETH)], window=SHORT, mutate=lowered_floor)
    spec = g5_eval.parse_spec(document)
    good = market(spec, spec.instrument(BTC), seed=1)
    bad = market(spec, spec.instrument(ETH), seed=2)
    t05 = spec.type("T05_us_open")
    for h, _, _ in g5_eval.local_time_instants(t05, spec):
        k = int(bad.index_of([h])[0])
        bad.close[k + 10: k + 30] = np.nan  # 20 of 61 footprint minutes
    run = g5_eval.evaluate_type({BTC: good, ETH: bad}, t05, spec, g5_eval.common_instants(spec))
    assert run["exclusions"]["instruments_excluded_missing_share"] == [ETH] and list(run["series"]) == [BTC] and ETH not in run["controls"]
    # T01: an instrument left out for missing prices still belongs to the raw population of C6 (its P̂ is computable).
    bad01 = market(spec, spec.instrument(ETH), seed=3)
    for s_ in bad01.settlements_us.tolist():
        k = int(bad01.index_of([s_])[0])
        bad01.close[max(0, k - 5): k + 1] = np.nan
    doc = g5_eval.run_study({BTC: good, ETH: bad01}, spec, {"d_cov_global_us": good.d_cov_start_us})
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["exclusions"]["instruments_excluded_missing_share"] == [ETH] and t01["validation"]["population"] == t01["exclusions"]["raw_instrument_instants"]
    # Every instrument out: no statistic, NOT_INFORMATIVE (sealed), never an error.
    doc = g5_eval.run_study({ETH: bad01}, spec, {"d_cov_global_us": good.d_cov_start_us})
    assert doc["types"]["T01_funding_8h"]["verdict"] in ("NOT_INFORMATIVE", "INCONCLUSIVE") and doc["types"]["T01_funding_8h"]["n_instants"] == 0


# ----------------------------------------------------------------------------- costs, funding, unit (B xiii, xiv, ix)


def test_costs_by_role_funding_signs_and_funding_outside_coverage():
    spec = spec_of(universe=[uni(BTC)], window=SHORT)
    meta = spec.instrument(BTC)
    t01, t05 = spec.type("T01_funding_8h"), spec.type("T05_us_open")
    h = tape.parse_iso_utc("2026-09-02T16:00:00Z")
    st = settlements_8h(spec)
    rates = np.full(st.size, 5e-4)
    series = flat(spec, meta, settlements=st, rates=rates, d_cov_us=tape.parse_iso_utc("2026-09-01T00:00:00Z"))
    k = int(series.index_of([h])[0])
    series.mark[k - 484: k - 4] = series.index[k - 484: k - 4] * 1.0012
    series.low[k - 4] = 99.0
    series.close[k + 15:] = 100.0 * math.exp(30 / 1e4)  # +30 bp at the exit
    series.cache.clear()
    e = g5_eval.measure(series, [h], t01, spec, "event")
    # The long pays R_h = 5 bp (the settlement h is in (h − 5, h + 15]); maker / maker costs 9.5 bp since A1 (13 bp before: the entry leg's
    # adverse selection is measured by the fill conditioning; adapted by A1).
    assert e["sign"].tolist() == [1] and e["r_bp"][0] == pytest.approx(30.0) and e["funding_bp"][0] == pytest.approx(-5.0) and e["net_bp"][0] == pytest.approx(30 - 5 - 9.5)
    assert e["net_stress_bp"][0] == pytest.approx(30 - 5 - 14.25) and e["rate_at_h"][0] == pytest.approx(5e-4)
    # T05: taker / maker 15.5 bp; a settlement crossed inside (h + 5, h + 60] is paid; outside D_cov the funding is assumed zero and counted.
    h5 = tape.parse_iso_utc("2026-09-02T13:30:00Z")
    k5 = int(series.index_of([h5])[0])
    t05_series = flat(spec, meta, settlements=np.sort(np.concatenate([st, [h5 + 30 * MINUTE_US]])), rates=np.concatenate([rates, [5e-4]]), d_cov_us=spec.start_us)
    t05_series.close[k5 + 5:] = 100.0 * math.exp(10 / 1e4)  # up in the first five minutes: long
    t05_series.close[k5 + 60:] = 100.0 * math.exp(40 / 1e4)
    m = g5_eval.measure(t05_series, [h5], t05, spec, "event")
    assert m["sign"].tolist() == [1] and m["r_bp"][0] == pytest.approx(30.0) and m["funding_bp"][0] == pytest.approx(-5.0) and m["net_bp"][0] == pytest.approx(30 - 5 - 15.5)
    t05_series.d_cov_start_us = tape.parse_iso_utc("2026-09-10T00:00:00Z")
    m = g5_eval.measure(t05_series, [h5], t05, spec, "event")
    assert m["funding_bp"][0] == 0.0 and m["counts"]["funding_unknown"] == 1 and m["funding_outside_coverage"] == 1
    # A crossed settlement whose rate is unknown inside D_cov: excluded and counted.
    t05_series.d_cov_start_us = spec.start_us
    t05_series.settlement_rates[np.searchsorted(t05_series.settlements_us, h5 + 30 * MINUTE_US)] = np.nan
    assert g5_eval.measure(t05_series, [h5], t05, spec, "event")["counts"]["funding_rate_missing"] == 1
    # Control funding is computed: a control crossing a settlement is counted as an anomaly (none by construction of the controls).
    c = g5_eval.measure(series, [tape.parse_iso_utc("2026-09-02T11:00:00Z")], t05, spec, "control")
    assert c["funding_nonzero"] == 0


def test_the_t01_instant_that_opens_d_cov_pays_its_known_settlement():
    """LP-G5 §11.1 (defect fixed 2026-10-21, pre-freeze pass): the T01 instant h = D_cov has a funding window (h − 5 ; h + 15] that opens
    before D_cov, but the only settlement it crosses is h itself, which is covered (rate known). Before the fix the whole window was
    `outside` (open < D_cov) and its funding assumed zero (`funding_unknown` = 1); now a settlement is assumed zero only when it is really
    outside the coverage, i.e. when the window may cross a settlement before D_cov (it opens before the served predecessor of D_cov)."""
    spec = spec_of(universe=[uni(BTC)], window=SHORT)
    meta = spec.instrument(BTC)
    t01 = spec.type("T01_funding_8h")
    h = tape.parse_iso_utc("2026-09-02T16:00:00Z")
    st = settlements_8h(spec)
    series = flat(spec, meta, settlements=st, rates=np.full(st.size, 5e-4), d_cov_us=h)  # D_cov = h: the instant that opens the coverage
    k = int(series.index_of([h])[0])
    series.mark[k - 484: k - 4] = series.index[k - 484: k - 4] * 1.0012
    series.low[k - 4] = 99.0
    series.close[k + 15:] = 100.0 * math.exp(30 / 1e4)
    series.cache.clear()
    e = g5_eval.measure(series, [h], t01, spec, "event")
    assert e["sign"].tolist() == [1] and e["funding_bp"][0] == pytest.approx(-5.0) and e["counts"]["funding_unknown"] == 0 and e["funding_outside_coverage"] == 0
    assert e["net_bp"][0] == pytest.approx(30 - 5 - 9.5)
    # A window that opens before the served predecessor of D_cov may cross a settlement outside the coverage: still assumed zero, counted.
    h_before = h - 8 * HOUR_US
    series.mark[k - 484 - 480: k - 4 - 480] = series.index[k - 484 - 480: k - 4 - 480] * 1.0012
    series.low[k - 4 - 480] = 99.0
    series.cache.clear()
    b = g5_eval.measure(series, [h_before], t01, spec, "event")
    assert b["funding_bp"][0] == 0.0 and b["counts"]["funding_unknown"] == 1 and b["funding_outside_coverage"] == 1


def test_t01_reads_one_d_cov_the_receipt_window_on_top_of_the_instrument_coverage():
    """LP-G5 §4.4: « T01 entier (événements et contrôles) se restreint à D_cov », the single window of the sealed receipt (registry);
    an instrument whose own history starts earlier is cut at it, one whose history starts later keeps its own start."""
    spec = spec_of(universe=[uni(BTC)], window=SHORT)
    meta = spec.instrument(BTC)
    st = settlements_8h(spec)
    own = tape.parse_iso_utc("2026-08-10T08:00:00Z")
    series = flat(spec, meta, settlements=st, rates=np.full(st.size, 1e-4), d_cov_us=own)
    common = g5_eval.common_instants(spec)
    t01 = spec.type("T01_funding_8h")
    run = g5_eval.evaluate_type({BTC: series}, t01, spec, common)
    assert iso(int(run["raw"][BTC].min())) == "2026-08-10T08:00:00Z"  # no receipt window: the instrument's own coverage
    common["d_cov_global_us"] = tape.parse_iso_utc("2026-08-20T00:00:00Z")
    run = g5_eval.evaluate_type({BTC: series}, t01, spec, common)
    assert iso(int(run["raw"][BTC].min())) == "2026-08-20T00:00:00Z" and run["exclusions"]["restriction"]["outside_funding_coverage"] > 0
    assert int(run["controls"][BTC].min()) >= common["d_cov_global_us"]
    common["d_cov_global_us"] = tape.parse_iso_utc("2026-08-01T00:00:00Z")  # earlier than the instrument's own: its own start holds
    assert iso(int(g5_eval.evaluate_type({BTC: series}, t01, spec, common)["raw"][BTC].min())) == "2026-08-10T08:00:00Z"


def test_the_unit_is_the_instant_with_equal_weights_and_n_unique_instants():
    parts = [{"inst": "A", "instants_us": np.array([10, 20], dtype=np.int64), "net_bp": np.array([1.0, 4.0])},
             {"inst": "B", "instants_us": np.array([10], dtype=np.int64), "net_bp": np.array([3.0])}]
    ts, values, counts = g5_eval.instant_means(parts, "net_bp")
    assert ts.tolist() == [10, 20] and values.tolist() == [2.0, 4.0] and counts.tolist() == [2, 1]
    ts, values, counts = g5_eval.instant_means(parts, "net_bp", min_instruments=2)
    assert ts.tolist() == [10] and values.tolist() == [2.0]


# ----------------------------------------------------------------------------- the study: C6, verdicts, Holm, sealing, economics (B v, xv, xvi, xx)

D_COV = tape.parse_iso_utc("2026-08-04T08:00:00Z")


def study(seed: int = 1, t01: float = 0.0, t05: float = 0.0, window: dict | None = None, mutate=None, instruments=(BTC, ETH, SOL), context: dict | None = None, **kw):
    def change(document):
        document["statistics"]["bootstrap"]["resamples"] = 2000  # test cost; the frozen spec keeps 10 000
        if mutate is not None:
            mutate(document)

    spec = g5_eval.parse_spec(derived(universe=[uni(i) for i in instruments], window=window, mutate=change))
    series = {i: market(spec, spec.instrument(i), seed=seed + 7 * j, t01_drift_bp=t01, t05_drift_bp=t05, d_cov_us=D_COV, **kw) for j, i in enumerate(instruments)}
    ctx = {"d_cov_global_us": D_COV}
    ctx.update(context or {})
    return spec, series, g5_eval.run_study(series, spec, ctx)


@pytest.fixture(scope="module")
def planted():
    """+40 bp planted at every T01 settlement (drift of the class over (h − 5, h + 15]) and +40 bp of T05 continuation."""
    return study(seed=3, t01=40.0, t05=40.0)


@pytest.fixture(scope="module")
def null():
    return study(seed=11)


def test_a_planted_effect_passes_c1_to_c7_and_holm_with_the_programme_field(planted):
    spec, series, doc = planted
    t01, t05 = doc["types"]["T01_funding_8h"], doc["types"]["T05_us_open"]
    for r in (t01, t05):
        assert r["verdict_label"] == "G5_GATE1_" + "PASS" and r["holm_local_confirmed"] and r["gate2_inscription"] == "inscribed" and "sealed" not in r
        assert all(c["pass"] for c in r["criteria"].values()) and r["pass_programme_immediate"] and r["statistic"]["p_one_sided"] <= 0.0142857
    assert list(t01["criteria"]) == ["C1", "C2", "C3", "C4", "C5", "C6", "C7"] and list(t05["criteria"]) == ["C1", "C2", "C3", "C4", "C5"]
    # D ≈ drift − funding paid (5 bp) at T01, ≈ the continuation at T05; the costs cancel in D, not in C2 / C3.
    assert 25 < t01["statistic"]["D"] < 45 and 30 < t05["statistic"]["D"] < 50
    assert t01["criteria"]["C2"]["event_net_mean"] == pytest.approx(t01["statistic"]["event_mean"]) and t01["criteria"]["C3"]["event_net_stress_mean"] == pytest.approx(
        t01["statistic"]["event_mean"] - 4.75, abs=1e-6)  # A1: 14.25 − 9.5 (19.5 − 13 = 6.5 before A1; adapted)
    assert t01["criteria"]["C4"]["halves"]["first_half"]["days"] >= t01["criteria"]["C4"]["halves"]["second_half"]["days"]
    assert t01["criteria"]["C7"]["event_hour_utc"] == 16 and t01["criteria"]["C7"]["n"] > 0
    assert t01["validation"]["pass"] and t01["validation"]["sensitivity"] == 1.0 and t01["validation"]["false_positive_rate"] == 0.0
    assert t01["validation"]["denominator_rate_inside_zone"] >= 30 and sum(t01["validation"]["matrix_classifier_class|rate_class"].values()) == t01["validation"]["population"]
    assert doc["summary"]["holm_local_confirmed"] == ["T01_funding_8h", "T05_us_open"] and doc["holm"]["m"] == 2
    assert t01["n_instants"] == t01["exclusions"]["raw_instants"] * t01["exclusions"]["f_inst"] and t01["exclusions"]["raw_instants"] == 211  # the audit count, on the data
    assert t05["n_instants"] == 105 and t05["n_days"] == 105 and t05["exclusions"]["raw_instants"] == 105
    text = json.dumps(g5_eval._clean(doc))
    assert '"pass_programme"' not in text and "pass_programme_immediate" in text
    with pytest.raises(RuntimeError, match="forbidden field"):
        g5_eval.check_forbidden({"x": [{"pass_programme": True}]}, spec)


def test_without_an_effect_the_types_fail_with_the_annotation_of_the_spec(null):
    spec, _, doc = null
    for name, r in doc["types"].items():
        assert r["verdict"] == "FAIL" and r["gate2_inscription"] == "not_inscribed" and not r["holm_local_confirmed"] and r["reading"] == "FAIL"
        assert "C1 fails" in r["verdict_reasons"]
        expected = spec.verdict["annotation_lt"] if r["statistic"]["ub90"] < 10 else spec.verdict["annotation_ge"]
        assert r["fail_annotation"] == expected and r["closure_scope"] == BASE["verdict"]["closure_scope"]  # FAIL closes the tested form only
    assert doc["types"]["T01_funding_8h"]["fail_annotation"] == "effet >= +10 pb exclu sous la forme testee"  # D ≈ −5 bp (funding paid)


def test_c7_c4_and_c2_are_criteria_of_their_own():
    _, _, doc = study(seed=5, t01=40.0, t01_drift_hours=(0, 8))  # the effect at the composite photos only (LP-G5 §1.4)
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["verdict"] == "FAIL" and "C7 fails" in t01["verdict_reasons"] and t01["criteria"]["C1"]["pass"] and t01["criteria"]["C7"]["point"] < 5
    cut = tape.day_of_date("2026-09-08")
    _, _, doc = study(seed=6, t01=60.0, t01_drift_days=cut)  # the effect in the first half of the event days only
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["verdict"] == "FAIL" and "C4 fails" in t01["verdict_reasons"] and t01["criteria"]["C4"]["halves"]["second_half"]["point"] < 0
    # Adapted by A1: at the pre-A1 total (the spec without adverse_selection_entry_leg, 13 / 19.5 bp) D ≈ 16 − 5 bp of funding ≥ 10 but the
    # absolute net ≈ 16 − 5 − 13 < 0: C2 (and C3) fail; under A1 (9.5 / 14.25 bp) the net ≈ +1.5 bp holds C2 and C3 alone fails.
    _, _, doc = study(seed=7, t01=16.0, mutate=pre_a1)
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["costs_bp"]["total_bp"] == 13.0 and t01["criteria"]["C1"]["pass"] and not t01["criteria"]["C2"]["pass"] and not t01["criteria"]["C3"]["pass"]
    assert t01["verdict"] == "FAIL" and t01["verdict_reasons"][:2] == ["C2 fails", "C3 fails"]
    _, _, doc = study(seed=7, t01=16.0)
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["costs_bp"]["total_bp"] == 9.5 and t01["criteria"]["C1"]["pass"] and t01["criteria"]["C2"]["pass"] and not t01["criteria"]["C3"]["pass"]
    assert t01["verdict"] == "FAIL" and t01["verdict_reasons"][0] == "C3 fails"


def test_inconclusive_c6_and_d_cov_unknown_are_sealed_and_inscribed_automatically():
    # Rates of the opposite class: P̂ never says who pays — sensitivity 0 ⇒ INCONCLUSIVE (C6), whatever D is.
    spec, _, doc = study(seed=4, t01=40.0, rate_of=lambda c, i: -5e-4 * c if c else 1e-4)
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["verdict_label"] == "G5_GATE1_INCONCLUSIVE" and t01["verdict_reasons"][0].startswith("C6_failed_or_not_computable")
    assert t01["gate2_inscription"] == "inscribed_automatically_without_futility" and t01["validation"]["sensitivity"] == 0.0 and t01["validation"]["pass"] is False
    assert "statistic" not in t01 and "criteria" not in t01 and "economic" not in t01 and "descriptive" not in t01 and t01["holm"]["p_used"] == 1.0
    # Denominators below 30 (no rate inside the zone): not computable ⇒ INCONCLUSIVE.
    _, _, doc = study(seed=4, t01=40.0, classes=np.where(np.arange(settlements_8h(spec).size) % 2, 1, -1))
    assert doc["types"]["T01_funding_8h"]["verdict"] == "INCONCLUSIVE" and doc["types"]["T01_funding_8h"]["validation"]["computable"] is False
    # D_cov unknown ⇒ T01 INCONCLUSIVE; T05 unaffected.
    _, _, doc = study(seed=4, t01=40.0, t05=40.0, context={"d_cov_global_us": None})
    assert doc["types"]["T01_funding_8h"]["verdict_reasons"][0].startswith("funding_coverage_unknown") and doc["types"]["T05_us_open"]["verdict"] == "PASS"
    # A global device defect (fetch errors > 10 %, end_reason missing) ⇒ every type INCONCLUSIVE.
    _, _, doc = study(seed=4, t01=40.0, t05=40.0, context={"device": ["fetch_job_error_share_gt: 12 of 100 jobs in error > 0.1 (§4.5)"]})
    assert all(r["verdict"] == "INCONCLUSIVE" and "sealed" in r for r in doc["types"].values()) and doc["summary"]["global_device_defects"]


def test_the_proxy_missing_rule_and_the_power_floor():
    def no_mark(series_by_inst):
        for s in series_by_inst.values():
            s.mark[: s.index_of([tape.parse_iso_utc("2026-09-25T00:00:00Z")])[0]] = np.nan
            s.cache.clear()

    spec = g5_eval.parse_spec(derived(universe=[uni(BTC), uni(ETH)], mutate=lambda d: d["statistics"]["bootstrap"].update({"resamples": 500})))
    series = {i: market(spec, spec.instrument(i), seed=9 + j, t01_drift_bp=40.0, d_cov_us=D_COV) for j, i in enumerate((BTC, ETH))}
    no_mark(series)
    doc = g5_eval.run_study(series, spec, {"d_cov_global_us": D_COV})
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["verdict"] == "INCONCLUSIVE" and any(r.startswith("proxy_missing_instrument_day_share_gt_0_50") for r in t01["verdict_reasons"])
    assert t01["exclusions"]["proxy_missing"]["share"] > 0.5
    # Below the floor: NOT_INFORMATIVE, sealed, inscribed automatically; never an extension.
    _, _, doc = study(seed=4, t05=40.0, window={"start": "2026-08-01", "end_exclusive": "2026-10-14"})
    t05 = doc["types"]["T05_us_open"]
    assert t05["verdict_label"] == "G5_GATE1_NOT_INFORMATIVE" and t05["verdict_reasons"][0].startswith("n_instants=") and "sealed" in t05
    assert t05["gate2_inscription"] == "inscribed_automatically_without_futility"


def test_holm_needs_both_or_the_smallest_at_alpha_over_two(monkeypatch):
    """(B) xvi: PASS needs every criterion and the local Holm (K = 2, α 0.10; a sealed type counts p = 1)."""
    real = g5_eval.criteria_for

    def fake(spec, tspec, inst, validation):
        criteria, diff = real(spec, tspec, inst, validation)
        criteria = {k: {**v, "pass": True} for k, v in criteria.items()}
        return criteria, {**diff, "p_one_sided": 0.07 if tspec.label == "T05" else 0.02}

    monkeypatch.setattr(g5_eval, "criteria_for", fake)
    _, _, doc = study(seed=8, t01=40.0, t05=40.0, window=SHORT, mutate=lowered_floor)
    t01, t05 = doc["types"]["T01_funding_8h"], doc["types"]["T05_us_open"]
    assert t01["verdict"] == "PASS" and t05["verdict"] == "PASS" and t01["holm"]["threshold"] == 0.05 and t05["holm"]["threshold"] == 0.10  # 0.02 <= 0.05, then 0.07 <= 0.10
    assert not t01["pass_programme_immediate"] and not t05["pass_programme_immediate"]  # 0.02 > 0.10 / 7

    def fake_one(spec, tspec, inst, validation):
        criteria, diff = real(spec, tspec, inst, validation)
        criteria = {k: {**v, "pass": True} for k, v in criteria.items()}
        return criteria, {**diff, "p_one_sided": 0.07}

    monkeypatch.setattr(g5_eval, "criteria_for", fake_one)
    _, _, doc = study(seed=8, t01=40.0, t05=40.0, window=SHORT, mutate=lowered_floor, rate_of=lambda c, i: -5e-4 * c if c else 1e-4)
    t05 = doc["types"]["T05_us_open"]
    assert doc["types"]["T01_funding_8h"]["verdict"] == "INCONCLUSIVE" and t05["verdict"] == "FAIL" and t05["verdict_reasons"] == ["non confirmé par Holm"]


def test_sealed_types_print_only_the_sealing_list_and_the_sha256_of_their_sealed_file():
    spec, _, doc = study(seed=4, t01=40.0, t05=40.0, window={"start": "2026-08-01", "end_exclusive": "2026-10-14"}, rate_of=lambda c, i: -5e-4 * c if c else 1e-4)
    payloads = doc["_sealed_payloads"]
    assert set(payloads) == {"T01_funding_8h", "T05_us_open"}
    for name, r in doc["types"].items():
        assert set(r) == {"type", "label", "decisive", "role", "costs_bp", "days_per_year", "window_rule", "gate2_inscription", "verdict", "verdict_label", "verdict_reasons",
                          "n_instants", "n_days", "exclusions", "fill_rate", "validation", "holm", "holm_local_confirmed", "pass_programme_immediate", "fail_annotation", "closure_scope",
                          "reading", "sealed"}
        assert r["sealed"]["sha256"] == g5_fetch.sha256_text(payloads[name]) and r["sealed"]["file"] == f"sealed/{name}.json"
        sealed = json.loads(payloads[name])
        assert set(spec.sealing["sealed"]) <= set(sealed) and sealed["D"] is not None and sealed["statistic"]["D"] == sealed["D"]
        public = json.dumps(g5_eval._clean({k: v for k, v in doc.items() if k != "_sealed_payloads"}))
        assert repr(round(sealed["D"], 6))[:8] not in public and "lb90" not in json.dumps(r) and "p_one_sided" not in json.dumps(r)
    assert doc["summary"]["sealed_sha256"] == {n: r["sealed"]["sha256"] for n, r in doc["types"].items()}
    assert all("p" not in t for t in doc["holm"]["tests"].values())


def test_the_economic_reading_admits_at_most_max_live_fills_per_instant_and_reads_pursued(planted):
    spec, series, doc = planted
    for name, r in doc["types"].items():
        e = r["economic"]
        tspec = spec.type(name)
        assert e["fills"] <= 3 * r["n_instants"] and e["max_live"] == 4 and e["days_per_year"] == tspec.days_per_year
        assert e["usd_per_year_point"] == pytest.approx(e["fills_per_day"] * 100.0 * e["net_bp_mean"] / 1e4 * tspec.days_per_year)
        pursued = e["usd_per_year_point"] >= 97.25 and (e["usd_per_year_ub90"] >= 2 * 97.25 or e["usd_per_year_lb90"] > 0)
        assert e["pursued"] == pursued and r["reading"] == ("PASS, poursuivi" if pursued else spec.economic["readings"][1])
        assert e["pos_mode"]["overlapping_positions_same_instrument"] == 0 and e["pos_mode"]["net_mode"] == e["pos_mode"]["long_short_mode"]
        minutes = tspec.return_window[1] - tspec.return_window[0]
        assert e["margin_opportunity_cost"]["rule"] == "negligible_reported" and e["margin_opportunity_cost"]["minutes_per_position"] == minutes
        assert e["margin_opportunity_cost"]["usd_per_position_at_5pct"] == pytest.approx(100.0 * minutes / (365 * 1440) * 0.05)
    assert doc["types"]["T01_funding_8h"]["economic"]["calendar_days"] == 71  # calendar days of D_cov (2026-08-04 → 10-13)
    assert doc["types"]["T05_us_open"]["economic"]["calendar_days"] == 116  # NYSE days with an eligible instrument, macro days included
    sub = [{"inst": i, "instants_us": np.array([0, 0, 0, 0, 0]) * 0 + DAY_US * 20000, "net_bp": np.array([1.0])} for i in ("E", "D", "C", "B", "A")]
    for p in sub:
        p["instants_us"] = np.array([DAY_US * 20000])
    e = g5_eval.economic_reading(sub, spec.type("T05_us_open"), spec, 10, "FAIL")
    assert e["fills"] == 4 and e["fills_uncapped"] == 5 and e["reading"] == "FAIL"


def test_every_descriptive_report_of_the_spec_is_produced_without_any_p_value(planted):
    spec, _, doc = planted
    names = set(spec.descriptive["reports"])
    produced = set(doc["common_descriptive"])
    for r in doc["types"].values():
        produced |= set(r["descriptive"])
    assert names <= produced
    assert set(doc["common_descriptive"]) == set(g5_eval.COMMON_REPORTS)
    t01, t05 = doc["types"]["T01_funding_8h"]["descriptive"], doc["types"]["T05_us_open"]["descriptive"]
    assert all(k.startswith("T01_") or k in g5_eval.PER_TYPE_REPORTS or k in ("instruments_per_instant",) + g5_eval.UNIT_REPORTS for k in t01)
    assert t05["T05_ten_am_et_release_days"]["status"] == "UNAVAILABLE" and set(t05["T05_horizons_15_30"]) == {"exit_h+20", "exit_h+35"}
    assert t01["T01_dead_zone_thresholds_0_0006_minus_0_0005"]["thresholds"] == [0.0006, -0.0005] and t01["T01_without_fill_proxy"]["n"] >= doc["types"]["T01_funding_8h"]["n_instants"]
    v = t01["T01_v_shape_minus15_0_plus15"]["events"]  # the drift is planted over (h − 5, h + 15]: a quarter before h, three quarters after
    assert t01["T01_classified_by_realized_rate"]["point"] > 20 and 5 < v["signed_return_bp_h_minus_15_to_h"] < 15 and v["signed_return_bp_h_to_h_plus_15"] > 20
    assert set(t01["T01_by_photo_hour_00_08_16"]) == {"00:00", "08:00", "16:00"} and t01["block_days_7"]["lb90"] is not None and t01["portfolio_reading"]["net_mode"]["fills"] > 0
    # Annexe B request 18 (2026-10-22): T01 filtered on the previous settlement only (the next-settlement criterion, known after the decision at
    # the Gate 1, off): the last settlement before END_EXCLUSIVE, whose successor is never served, joins it on every instrument.
    prev = t01["T01_previous_settlement_filter_only"]
    assert prev["rule"] == g5_eval.PREVIOUS_ONLY_RULE and prev["raw_instrument_instants_added_vs_primary"] >= 3 and prev["n"] >= doc["types"]["T01_funding_8h"]["n_instants"]
    assert prev["point"] is not None and "p_one_sided" not in prev
    assert t05["T05_macro_days_separately"]["n"] == 11 and t05["regime_rv24_above_median"]["stress"]["n"] + t05["regime_rv24_above_median"]["calm"]["n"] > 0
    assert "p_one_sided" not in json.dumps(g5_eval._clean(t01)) and "p_one_sided" not in json.dumps(g5_eval._clean(doc["common_descriptive"]))
    assert doc["common_descriptive"]["minute_zero_T07"]["by_minute_of_hour"]["00"]["n"] > 0
    for r in doc["types"].values():  # unit.report
        assert r["descriptive"]["instruments_per_instant_distribution"]["events"]["distribution"] and r["descriptive"]["stratum_min_instruments_3"]["n"] > 0
        assert r["exclusions"]["excluded_vol_zero_share_by_instrument"] == {i: 0.0 for i in (BTC, ETH, SOL)}  # frozen_candle.report


def test_day_block_bootstrap_moves_events_and_controls_of_a_day_together_and_holm_steps_down():
    ev_v, ev_d = np.array([1.0, 2.0, 3.0, 4.0]), np.array([0, 0, 1, 2])
    ct_v, ct_d = np.array([0.0, 1.0, 1.0]), np.array([0, 1, 2])
    a = g5_eval.day_block_bootstrap(ev_v, ev_d, ct_v, ct_d, resamples=500, seed=7)
    b = g5_eval.day_block_bootstrap(ev_v, ev_d, ct_v, ct_d, resamples=500, seed=7)
    assert a == b and a["point"] == pytest.approx(2.5 - 2 / 3) and a["blocks"] == 3 and 0 <= a["p_one_sided"] <= 1 and a["lb90"] <= a["point"] <= a["ub90"]
    # Fixed blocks (C5): the same draws when an event day disappears.
    c = g5_eval.day_block_bootstrap(ev_v[:3], ev_d[:3], ct_v, ct_d, resamples=500, seed=7, blocks=np.array([0, 1, 2]))
    assert c["blocks"] == 3
    assert g5_eval.day_block_bootstrap(np.array([1.0]), np.array([0]), np.array([0.0]), np.array([0]))["lb90"] is None  # one block: no statistic
    h = g5_eval.holm({"a": 0.01, "b": 0.04, "c": None}, 0.10)
    assert h["confirmed"] == ["a", "b"] and h["tests"]["c"]["p"] == 1.0 and h["tests"]["a"]["threshold"] == pytest.approx(0.10 / 3)
    assert g5_eval.holm({"a": 0.04, "b": 0.07}, 0.10)["confirmed"] == ["a", "b"] and g5_eval.holm({"a": 0.06, "b": 0.07}, 0.10)["confirmed"] == []
    assert g5_eval.holm({"a": 0.06, "b": 1.0}, 0.10)["confirmed"] == []


# ----------------------------------------------------------------------------- the command line, end to end (B xviii, xx)


def write_history(root: Path, spec: g5_eval.Spec, series_by_inst: dict, funding_from_us: int, decision_ref: str, drop_state: str | None = None) -> str:
    """The synthetic series in the `g5_fetch` layouts (candles opening at g_{k−1}, closing at g_k), finalized day files, the manifest
    states of the jobs, the funding history in the sealed store from `funding_from_us` (the server's horizon), archived as the fetcher
    does (`g5_fetch.archive_sealed_store`, CTO m3), and its receipt. Returns the receipt's sha256 (freeze_fill, CTO M4)."""
    store = tape.TapeStore(root, "values", source={"rest": "synthetic", "decision_ref": decision_ref, "dataset_id": spec.dataset_id}, kind=g5_fetch.MANIFEST_KIND)
    sealed = tape.TapeStore(root / g5_fetch.SEALED_DIR_NAME, "values", source={"rest": "synthetic", "decision_ref": decision_ref}, kind=g5_fetch.MANIFEST_KIND)
    fetched = tape.parse_iso_utc("2026-11-03T00:00:00Z")
    states, sealed_states = {}, {}

    def save(base: Path, layout: str, key: str, rows: np.ndarray) -> None:
        days = rows["ts_us"] // DAY_US
        for d in np.unique(days).tolist():
            tape.save_rows(base / layout / key / f"{key}-{tape.date_of_day(int(d))}.npz", rows[days == d])

    for inst, s in series_by_inst.items():
        k = np.arange(1, s.size)
        opens = s.grid_us[:-1]
        candles = np.zeros(k.size, dtype=tape.CANDLES_1M_DTYPE)
        for col, values in (("ts_us", opens), ("open", s.close[k - 1]), ("high", s.high[k]), ("low", s.low[k]), ("close", s.close[k]), ("vol", s.vol[k]), ("vol_ccy", s.vol[k]),
                            ("vol_ccy_quote", s.vol[k] * 100)):
            candles[col] = values
        candles["fetched_us"], candles["confirm"] = fetched, 1
        candles["confirm"][100] = 0  # one unconfirmed candle: dropped and counted
        late = candles[-1:].copy()
        late["ts_us"] = g5_fetch.END_EXCLUSIVE_US + 3 * MINUTE_US  # a row at or after END_EXCLUSIVE in the last day's file: dropped
        save(root, "candles_1m", inst, candles)
        tape.save_rows(root / "candles_1m" / inst / f"{inst}-2026-10-13.npz", np.concatenate([candles[candles["ts_us"] // DAY_US == tape.day_of_date("2026-10-13")], late]))
        for layout, key, values in (("mark_1m", inst, s.mark), ("index_1m", s.meta.index_id, s.index)):
            px = np.zeros(k.size, dtype=tape.PX_CANDLES_1M_DTYPE)
            px["ts_us"], px["fetched_us"], px["open"], px["high"], px["low"], px["close"], px["confirm"] = opens, fetched, values[k - 1], values[k], values[k], values[k], 1
            save(root, layout, key, px)
        keep = s.settlements_us >= funding_from_us
        fund = np.zeros(int(keep.sum()), dtype=tape.FUNDING_HIST_DTYPE)
        fund["ts_us"], fund["fetched_us"], fund["funding_rate"], fund["realized_rate"], fund["method"], fund["formula_type"] = s.settlements_us[keep], fetched, s.settlement_rates[keep] * 1.1, s.settlement_rates[keep], 1, 1
        save(root / g5_fetch.SEALED_DIR_NAME, "funding_hist", inst, fund)
        for stream in ("candles_1m", "mark_1m"):
            states[f"{inst}:{stream}"] = {"complete": True, "end_reason": "reached_start", "errors": []}
        states[f"{s.meta.index_id}:index_1m"] = {"complete": True, "end_reason": "reached_start", "errors": []}
        ts = np.sort(s.settlements_us[keep])
        sealed_states[f"{inst}:funding_hist"] = {"complete": True, "end_reason": "server_empty_before_start", "errors": [], "rows": int(ts.size), "coverage_start_us": int(ts[0]),
                                                 "d_cov_start_us": int(ts[1]), "truncated": True}
    if drop_state:
        states[drop_state]["end_reason"] = None
    store.manifest["g5_streams"] = states
    store.save_manifest()
    sealed.manifest["g5_streams"] = sealed_states
    sealed.save_manifest()
    archived = g5_fetch.archive_sealed_store(root)
    assert not (root / g5_fetch.SEALED_DIR_NAME).exists()
    coverage = max(v["d_cov_start_us"] for v in sealed_states.values())
    receipt = {"kind": g5_fetch.RECEIPT_KIND, "end_exclusive": g5_fetch.END_EXCLUSIVE, "jobs_total": len(sealed_states), "jobs_complete": len(sealed_states), "jobs_error": 0,
               "coverage_start_us": coverage, "coverage_start_utc": tape.iso_utc(coverage), **archived}
    (root / g5_fetch.SEALED_RECEIPT_NAME).write_text(json.dumps(receipt, indent=1, sort_keys=True))
    return tape.sha256_of(root / g5_fetch.SEALED_RECEIPT_NAME)


def snapshot_file(path: Path, spec_doc: dict, tick: str = "0.01") -> Path:
    rows = [{"instId": u["inst_id"], "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": u["index_id"], "ctVal": str(u["ct_val"]), "tickSz": tick,
             "listTime": str(tape.parse_iso_utc(u["list_time_utc"]) // 1000)} for u in spec_doc["universe"]["analysed"]]
    rows += [{"instId": e["inst_id"], "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": e["inst_id"].split("_")[0], "ctVal": "1", "tickSz": "0.0001",
              "listTime": str(tape.parse_iso_utc(e["list_time_utc"]) // 1000)} for e in spec_doc["universe"]["excluded_never_eligible"]]
    path.write_text(json.dumps({"code": "0", "msg": "", "data": rows}))
    return path


def test_the_command_line_end_to_end_on_a_synthetic_tape(tmp_path: Path, capsys):
    funding_from = tape.parse_iso_utc("2026-08-04T00:00:00Z")  # the server's three-month horizon: D_cov starts at 08:00
    decision = "test only: owner's written confirmation of the scale (P-G5-1)"

    def frozen(document):
        document["statistics"]["bootstrap"]["resamples"] = 1000
        lowered_floor(document, instants=40, days=20)
        verified_sessions(document)
        document.update({"status": "FROZEN", "spec_version": "1.0.0-test"})

    document = derived(universe=[uni(BTC), uni(ETH)], window=SHORT, mutate=frozen)
    snapshot = snapshot_file(tmp_path / "snapshot.json", document)
    document["freeze_fill"].update({"instruments_snapshot_path": str(snapshot), "instruments_snapshot_sha256": tape.sha256_of(snapshot), "calendars_verified_utc": "2026-11-02T10:00:00Z",
                                    "code_digest_sha256": cli.code_digest(), "decision_ref_p_g5_1": decision, "funding_seal_receipt_sha256": "0" * 64})
    spec = g5_eval.parse_spec(document)
    series = {i: market(spec, spec.instrument(i), seed=21 + j, t01_drift_bp=40.0, d_cov_us=D_COV) for j, i in enumerate((BTC, ETH))}

    def gate2_of(path: Path) -> Path:
        """Annexe B request 11 (gate2_frozen_together): the FROZEN Gate 2 frozen with the Gate 1 file `path` (its sha256 in freeze_fill)."""
        from tests.test_research_lp_g5_gate2 import frozen_gate2_for

        gate2 = path.with_name(path.stem + ".gate2.json")
        gate2.write_text(json.dumps(frozen_gate2_for(json.loads(path.read_text()), g5_fetch.sha256_bytes(path.read_bytes())), indent=1))
        return gate2

    def spec_file(name: str, receipt_sha: str) -> tuple[Path, str]:
        """The frozen spec naming the sealed receipt of one tape (freeze_fill.funding_seal_receipt_sha256, CTO M4), and its Gate 2."""
        doc = copy.deepcopy(document)
        doc["freeze_fill"]["funding_seal_receipt_sha256"] = receipt_sha
        path = tmp_path / name
        path.write_text(json.dumps(doc, indent=1, ensure_ascii=False))
        gate2_of(path)
        return path, g5_fetch.sha256_bytes(path.read_bytes())

    root = tmp_path / "history"
    receipt_sha = write_history(root, spec, series, funding_from, decision)
    document["freeze_fill"]["funding_seal_receipt_sha256"] = receipt_sha
    spec_path, sha = spec_file("spec.json", receipt_sha)
    out_json, out_md = tmp_path / "run" / "g5.json", tmp_path / "run" / "g5.md"
    gate2_path = spec_path.with_name("spec.gate2.json")
    args = ["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--expect-spec-sha256", sha, "--gate2-spec", str(gate2_path), "--out-json", str(out_json),
            "--out-md", str(out_md)]
    # Annexe B request 11 (gate2_frozen_together): before any market value, the Gate 2 frozen with this Gate 1 is read; a DRAFT one, another
    # Gate 1 sha256, a freeze_pending: marker or the repository's draft (the default frozen_with.spec) refuse the run, nothing read.
    good_gate2 = gate2_path.read_text()
    for change, match in ((lambda d: d.update({"status": "DRAFT_NOT_FROZEN"}), "not FROZEN"), (lambda d: d["freeze_fill"].update({"gate1_spec_sha256": "9" * 64}), "is not the sha256"),
                          (lambda d: d["quality"].update({"status": "freeze_pending: to write"}), "freeze_pending:")):
        bad = json.loads(good_gate2)
        change(bad)
        gate2_path.write_text(json.dumps(bad))
        with pytest.raises(SystemExit, match=f"gate2_frozen_together.*{match}"):
            cli.main(args)
    gate2_path.write_text(good_gate2)
    without = [a for a in args if a not in ("--gate2-spec", str(gate2_path))]
    with pytest.raises(SystemExit, match="gate2_frozen_together.*g5_gate2_spec.json.*not FROZEN"):
        cli.main(without)
    assert cli.main(args) == 0
    printed = json.loads(capsys.readouterr().out)
    assert printed["gate2_spec_sha256"] == tape.sha256_of(gate2_path)
    result = json.loads(out_json.read_text(), parse_constant=lambda c: pytest.fail(f"non-finite number {c} in the JSON report"))
    assert printed["spec_sha256"] == sha == result["spec"]["sha256"] and result["code_digest"] == cli.code_digest() and result["evaluator"] == g5_eval.EVALUATOR_VERSION
    assert result["gate2_spec"] == {"path": str(gate2_path), "sha256": tape.sha256_of(gate2_path), "spec_id": "LP-G5-GATE2-2026", "status": "FROZEN", "gate1_spec_sha256": sha}
    t01, t05 = result["types"]["T01_funding_8h"], result["types"]["T05_us_open"]
    assert t01["verdict_label"] == "G5_GATE1_PASS" and t05["verdict_label"] == "G5_GATE1_FAIL" and result["d_cov_global"] == iso(D_COV)
    assert result["funding_seal"]["receipt_sha256"] == tape.sha256_of(root / g5_fetch.SEALED_RECEIPT_NAME) == receipt_sha and result["fetch_quality"]["error_share"] == 0.0
    assert result["funding_seal"]["sealed_archive_sha256"] == tape.sha256_of(root / g5_fetch.SEALED_ARCHIVE_NAME) and result["spec"]["freeze_fill"]["funding_seal_receipt_sha256"] == receipt_sha
    cov = result["coverage"][BTC]
    assert cov["unconfirmed_dropped"]["candles_1m"] == 1 and cov["rows_at_or_after_end_dropped"] == 1 and cov["d_cov_start"] == iso(D_COV)
    assert '"pass_programme"' not in out_json.read_text() and printed["gate2_inscription"] == {"T01_funding_8h": "inscribed", "T05_us_open": "not_inscribed"}
    notes = result["spec"]["notes"]  # every descriptive text of the spec is copied into the report
    for key, value in (("note", BASE["note"]), ("universe.selection_bias_declared", BASE["universe"]["selection_bias_declared"]), ("sessions_note", BASE["sessions_note"]),
                       ("controls_common.rule", BASE["controls_common"]["rule"]), ("macro_filter.ten_am_et_releases", BASE["macro_filter"]["ten_am_et_releases"]),
                       ("verdict.closure_scope", BASE["verdict"]["closure_scope"]), ("fetch_plan.max_requests", BASE["fetch_plan"]["max_requests"]),
                       ("freeze_fill.rule", BASE["freeze_fill"]["rule"]), ("calendars.read_by", BASE["calendars"]["read_by"])):
        assert notes[key] == value
    assert notes["sessions_to_verify_at_freeze"] == [] and notes["classifier.threshold_origin"]["T01_funding_8h"] == BASE["events"][0]["classifier"]["threshold_origin"]  # verified at the freeze
    assert g5_eval.load_spec(REAL_SPEC)[0].notes["sessions_to_verify_at_freeze"] == ["cme_cf_brr_window"]  # the draft still lists the BRR window
    protocol = REAL_SPEC.parents[4] / BASE["protocol"]
    assert result["spec"]["protocol_sha256"] == tape.sha256_of(protocol) and result["summary"]["gate2_inscription_discretion"] == "none"
    text = out_md.read_text()
    assert text.startswith("# G5 — `LP-G5-GATE1-2026` (history)") and BASE["descriptive"]["rule"] in text.split("## Verdicts")[0] and "GATES` §9.7" in text
    # A sealed type: drop T01's sensitivity (rates of the opposite class) → INCONCLUSIVE, its statistics in sealed/<type>.json only.
    series_c6 = {i: market(spec, spec.instrument(i), seed=21 + j, t01_drift_bp=40.0, d_cov_us=D_COV, rate_of=lambda c, n: -5e-4 * c if c else 1e-4) for j, i in enumerate((BTC, ETH))}
    root2 = tmp_path / "history-c6"
    spec_path2, sha2 = spec_file("spec-c6.json", write_history(root2, spec, series_c6, funding_from, decision))
    out2 = tmp_path / "run2" / "g5.json"
    assert cli.main(["g5-eval", "--tape", str(root2), "--spec", str(spec_path2), "--expect-spec-sha256", sha2, "--gate2-spec", str(gate2_of(spec_path2)), "--out-json", str(out2),
                     "--out-md", str(tmp_path / "run2" / "g5.md")]) == 0
    printed = json.loads(capsys.readouterr().out)
    r2 = json.loads(out2.read_text())["types"]["T01_funding_8h"]
    sealed_path = tmp_path / "run2" / "sealed" / "T01_funding_8h.json"
    assert r2["verdict"] == "INCONCLUSIVE" and sealed_path.exists() and tape.sha256_of(sealed_path) == r2["sealed"]["sha256"] == printed["sealed_sha256"]["T01_funding_8h"]
    assert "statistic" not in r2 and json.loads(sealed_path.read_text())["D"] is not None and "scellé" in (tmp_path / "run2" / "g5.md").read_text()
    # Global device defects decided before any market value is read: exit 2, every type INCONCLUSIVE, nothing read.
    for bad_args, reason in ((["--expect-spec-sha256", "0" * 64], "spec_sha256_mismatch"),):
        a = list(args)
        a[a.index("--expect-spec-sha256") + 1] = bad_args[1]
        assert cli.main(a) == 2
        refused = json.loads(capsys.readouterr().out)
        assert refused["market_data_read"] is False and refused["device"][0].startswith(reason) and set(refused["verdicts"].values()) == {"G5_GATE1_INCONCLUSIVE"}
    for change, reason in ((lambda d: d.update({"status": "DRAFT_NOT_FROZEN", "spec_version": "0.9-draft"}), "status_not_frozen"),
                           (lambda d: d["freeze_fill"].update({"code_digest_sha256": "c" * 64}), "code_digest_mismatch")):
        other = copy.deepcopy(document)
        change(other)
        p = tmp_path / f"{reason}.json"
        p.write_text(json.dumps(other))
        assert cli.main(["g5-eval", "--tape", str(root), "--spec", str(p), "--expect-spec-sha256", g5_fetch.sha256_bytes(p.read_bytes()), "--out-json", str(tmp_path / f"{reason}-out.json")]) == 2
        refused = json.loads(capsys.readouterr().out)
        assert refused["market_data_read"] is False and any(x.startswith(reason) for x in refused["device"])
    # The snapshot, the universe and the sealed store are checked before reading (refuse_run).
    tampered = copy.deepcopy(document)
    bad_snapshot = snapshot_file(tmp_path / "snapshot-tick.json", document, tick="0.1")
    tampered["freeze_fill"].update({"instruments_snapshot_path": str(bad_snapshot), "instruments_snapshot_sha256": tape.sha256_of(bad_snapshot)})
    p = tmp_path / "tick.json"
    p.write_text(json.dumps(tampered))
    with pytest.raises(SystemExit, match="tickSz"):
        cli.main(["g5-eval", "--tape", str(root), "--spec", str(p), "--expect-spec-sha256", g5_fetch.sha256_bytes(p.read_bytes()), "--gate2-spec", str(gate2_of(p)),
                  "--out-json", str(tmp_path / "x.json")])
    with pytest.raises(SystemExit, match="snapshot"):
        cli.main(args[:-4] + ["--instruments-file", str(bad_snapshot), "--out-json", str(tmp_path / "x.json")])
    # CTO M4: the sealed receipt is the one frozen in freeze_fill (another tape's receipt, or a wrong --expect-funding-seal-sha256: refuse_run).
    with pytest.raises(SystemExit, match="freeze_fill.funding_seal_receipt_sha256"):
        cli.main(["g5-eval", "--tape", str(root2), "--spec", str(spec_path), "--expect-spec-sha256", sha, "--gate2-spec", str(gate2_path), "--out-json", str(tmp_path / "x.json")])
    with pytest.raises(SystemExit, match="expect-funding-seal-sha256"):
        cli.main(args + ["--expect-funding-seal-sha256", "f" * 64])
    # CTO m3: the sealed store is one archive whose sha256, manifest and files are those of the receipt; an open tree is refused.
    archive = root / g5_fetch.SEALED_ARCHIVE_NAME
    original = archive.read_bytes()
    archive.write_bytes(original + b"\0" * 1024)
    with pytest.raises(SystemExit, match="differs from its receipt"):
        cli.main(args)
    archive.write_bytes(original)
    (root / g5_fetch.SEALED_DIR_NAME).mkdir()
    with pytest.raises(SystemExit, match="open sealed store"):
        cli.main(args)
    (root / g5_fetch.SEALED_DIR_NAME).rmdir()
    receipt_text = (root / g5_fetch.SEALED_RECEIPT_NAME).read_text()
    (root / g5_fetch.SEALED_RECEIPT_NAME).unlink()
    with pytest.raises(SystemExit, match="no sealed funding receipt"):  # never a silent INCONCLUSIVE of T01 (CTO M4)
        cli.main(args)
    (root / g5_fetch.SEALED_RECEIPT_NAME).write_text(receipt_text)
    # A job without end_reason: global INCONCLUSIVE (stream_end_reason_missing), both types sealed.
    root3 = tmp_path / "history-missing"
    spec_path3, sha3 = spec_file("spec-missing.json", write_history(root3, spec, series, funding_from, decision, drop_state=f"{ETH}:mark_1m"))
    out3 = tmp_path / "run3" / "g5.json"
    assert cli.main(["g5-eval", "--tape", str(root3), "--spec", str(spec_path3), "--expect-spec-sha256", sha3, "--gate2-spec", str(gate2_of(spec_path3)), "--out-json", str(out3)]) == 0
    r3 = json.loads(out3.read_text())
    assert all(r["verdict"] == "INCONCLUSIVE" and "sealed" in r for r in r3["types"].values()) and any("stream_end_reason_missing" in x for x in r3["summary"]["global_device_defects"])
    with pytest.raises(SystemExit):  # the command line exposes no threshold, sign, window or seed
        cli.build_parser().parse_args(args + ["--threshold-bp", "5"])
    capsys.readouterr()


def test_no_test_can_open_a_socket():
    with pytest.raises(AssertionError):
        socket.create_connection(("127.0.0.1", 9))
    with pytest.raises(AssertionError):
        urllib.request.urlopen("https://eea.okx.com/api/v5/public/time")
