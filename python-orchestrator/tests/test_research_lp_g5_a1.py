"""Offline tests of the amendment A1 of LP-G5 (2026-10-21, written before any freeze and any reading): the entry-leg adverse
selection of T01 measured by the conditioning on the fill (9.5 / 14.25 bp with `adverse_selection_entry_leg: measured_by_fill_proxy`,
13 / 19.5 bp without it, the pre-A1 total reported as a sensitivity), the strict reading of the new keys and of `descriptive.a1`, the
identity of the rule at the Gate 2 (trap 7), the footprints crossing END_EXCLUSIVE or starting before the eligibility (excluded and
counted), the A / B / C table, the filled / unfilled analysis, the taker variant of T01, the chronological stability and the diagnostic
classification on synthetic tapes built to fall in each of the four cases (and in « none of the four »).

Synthetic only: no market data, no network (socket-level autouse guard). The helpers of the G5 evaluator's tests are reused."""

from __future__ import annotations

import copy
import json
import math
import socket
import urllib.request
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import g5_eval, g5_fetch, tape
from tests.test_research_lp_g5_eval import (BASE, BTC, D_COV, ETH, SHORT, SOL, derived, flat, lowered_floor, market, pre_a1, settlements_8h, spec_of, study,
                                            uni)

US = tape.SECOND_US
MINUTE_US = 60 * US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * US
END = g5_fetch.END_EXCLUSIVE_US
GATE2_SPEC = Path(__file__).resolve().parents[1] / "scripts" / "research_lp" / "specs" / "g5_gate2_spec.json"
GATE2_BASE = json.loads(GATE2_SPEC.read_text())


@pytest.fixture(autouse=True)
def _no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - the assertion is the point
        raise AssertionError("network access attempted")

    monkeypatch.setattr(socket.socket, "connect", refuse)
    monkeypatch.setattr(socket.socket, "connect_ex", refuse)
    monkeypatch.setattr(socket.socket, "sendto", refuse)
    monkeypatch.setattr(socket, "create_connection", refuse)
    monkeypatch.setattr(socket, "getaddrinfo", refuse)
    monkeypatch.setattr(urllib.request, "urlopen", refuse)
    monkeypatch.setattr(g5_fetch.UrllibClient, "get", refuse)


def fewer_resamples(document: dict) -> None:
    document["statistics"]["bootstrap"]["resamples"] = 1000  # test cost; the frozen spec keeps 10 000


def _refused(mutate, match: str, base: dict | None = None):
    document = copy.deepcopy(base if base is not None else BASE)
    mutate(document)
    with pytest.raises(g5_eval.SpecError, match=match):
        g5_eval.parse_spec(document)


def classification(result: dict, payloads: dict | None = None) -> dict:
    """The A1 classification of a type, read in the sealed payload when the type is sealed."""
    if "sealed" in result:
        return json.loads(payloads[result["type"]])["type_descriptives"]["diagnostic_classification_a1"]
    return result["descriptive"]["diagnostic_classification_a1"]


def adverse_market(spec: g5_eval.Spec, meta: g5_eval.Instrument, seed: int, drift_bp: float = 60.0) -> g5_eval.Series:
    """(c) « badly executed »: every other classified T01 settlement, the resting order is not filled (no trade through c(h − 5) − 1
    tick) and the price runs away by `drift_bp` in the class's direction over (h − 5, h + 15]; at the others the order is filled and the
    price does not move. The fill selects the instants without the move: the adverse selection, planted. Controls: always filled, flat."""
    s = market(spec, meta, seed=seed, sigma_bp=0.2, d_cov_us=D_COV)
    grid, n = s.grid_us, s.grid_us.size
    ret = np.diff(np.log(s.close), prepend=np.log(s.close[0]))
    ret[0] = 0.0
    k_s = (s.settlements_us - int(grid[0])) // MINUTE_US
    classes = g5_eval.classify(s.p_hat(k_s - 5, {"lookback_min": 480, "min_coverage": 0.9}), 0.0009, -0.0009)
    runaway = []
    for i, (k, c) in enumerate(zip(k_s.tolist(), classes.tolist())):
        if c and i % 2 and 5 <= k - 4 and k + 15 < n:
            ret[k - 4: k + 16] += c * drift_bp / 1e4 / 20.0
            runaway.append((k, c))
    close = 100.0 * np.exp(np.cumsum(ret))
    low, high = close * (1 - 25e-4), close * (1 + 25e-4)
    for k, c in runaway:  # the candles opening at h − 5 … h end at k − 4 … k + 1
        if c > 0:
            low[k - 4: k + 2] = close[k - 4: k + 2]
        else:
            high[k - 4: k + 2] = close[k - 4: k + 2]
    premium_factor = s.mark / s.index
    return g5_eval.make_series(meta, grid, close, low, high, np.ones(n), close * premium_factor, close.copy(), s.settlements_us, s.settlement_rates, D_COV)


@pytest.fixture(scope="module")
def planted():
    return study(seed=3, t01=40.0, t05=40.0, mutate=fewer_resamples)


@pytest.fixture(scope="module")
def adverse():
    def change(document):
        fewer_resamples(document)
        lowered_floor(document)

    spec = g5_eval.parse_spec(derived(universe=[uni(i) for i in (BTC, ETH, SOL)], mutate=change))
    series = {i: adverse_market(spec, spec.instrument(i), seed=30 + 7 * j) for j, i in enumerate((BTC, ETH, SOL))}
    return spec, series, g5_eval.run_study(series, spec, {"d_cov_global_us": D_COV})


# ----------------------------------------------------------------------------- 1. the double count of T01 (costs, strict reading)


def test_a1_the_t01_cost_is_9_5_bp_with_the_key_and_13_without():
    spec, _ = g5_eval.load_spec(Path(__file__).resolve().parents[1] / "scripts" / "research_lp" / "specs" / "g5_gate1_spec.json")
    t01, t05 = spec.types
    a1 = g5_eval.type_cost_terms(t01, spec.costs)
    assert (a1["total_bp"], a1["stress_total_bp"], a1["fees_bp"], a1["adverse_selection_bp"], a1["slippage_bp"], a1["taker_entry_half_spread_bp"]) == (9.5, 14.25, 4.0, 3.5, 2.0, 0.0)
    assert a1["maker_legs"] == 2 and a1["maker_legs_with_assumed_adverse_selection"] == 1 and a1["adverse_selection_entry_leg"] == "measured_by_fill_proxy"
    design = g5_eval.cost_terms("maker_maker", spec.costs)
    assert (design["total_bp"], design["stress_total_bp"], design["adverse_selection_bp"]) == (13.0, 19.5, 7.0)
    assert g5_eval.type_cost_terms(t01, spec.costs, conditioned_on_fill=False)["total_bp"] == 13.0  # no conditioning: nothing measures the entry leg
    assert t01.cost_sensitivity == {"expected_cost_bp": 13.0, "expected_cost_bp_stress": 19.5}
    # « sans la clé »: the same spec without adverse_selection_entry_leg (and its sensitivity) is the pre-A1 rule, 13 / 19.5 bp.
    old = g5_eval.parse_spec(derived(mutate=pre_a1)).type("T01_funding_8h")
    assert (old.entry_adverse_selection, old.expected_cost_bp, old.expected_cost_bp_stress, old.cost_sensitivity) == ("assumed_design_value", 13.0, 19.5, None)
    assert g5_eval.type_cost_terms(old, spec.costs)["total_bp"] == 13.0
    # T05 unchanged: taker 5 + maker 2, the exit maker leg's adverse selection 3.5, the entry half spread 3, slippage 2 — each term once.
    t5 = g5_eval.type_cost_terms(t05, spec.costs)
    assert (t5["total_bp"], t5["stress_total_bp"], t5["fees_bp"], t5["adverse_selection_bp"], t5["taker_entry_half_spread_bp"], t5["slippage_bp"]) == (
        15.5, pytest.approx(23.25), 7.0, 3.5, 3.0, 2.0)
    assert t5["maker_legs_with_assumed_adverse_selection"] == 1 and t5["adverse_selection_entry_leg"] == "not_applicable_taker_entry" and t05.cost_sensitivity is None
    with pytest.raises(g5_eval.SpecError, match="taker entry"):
        g5_eval.cost_terms("taker_maker", spec.costs, "measured_by_fill_proxy")


def test_a1_the_measured_net_subtracts_9_5_bp_when_filled_and_13_bp_without_the_fill_conditioning():
    spec = spec_of(universe=[uni(BTC)], window=SHORT)
    meta = spec.instrument(BTC)
    t01 = spec.type("T01_funding_8h")
    h = tape.parse_iso_utc("2026-09-02T16:00:00Z")
    st = settlements_8h(spec)
    series = flat(spec, meta, settlements=st, rates=np.full(st.size, 5e-4), d_cov_us=tape.parse_iso_utc("2026-09-01T00:00:00Z"))
    k = int(series.index_of([h])[0])
    series.mark[k - 484: k - 4] = series.index[k - 484: k - 4] * 1.0012
    series.low[k - 4] = 99.0  # filled through c(h − 5) − 1 tick
    series.close[k + 15:] = 100.0 * math.exp(30 / 1e4)
    series.cache.clear()
    filled = g5_eval.measure(series, [h], t01, spec, "event")
    unconditional = g5_eval.measure(series, [h], t01, spec, "event", {"fill": False, "fill_status": True})
    assert filled["conditioned_on_fill"] and filled["net_bp"][0] == pytest.approx(30 - 5 - 9.5) and filled["net_stress_bp"][0] == pytest.approx(30 - 5 - 14.25)
    assert not unconditional["conditioned_on_fill"] and unconditional["net_bp"][0] == pytest.approx(30 - 5 - 13) and unconditional["fill_flag"].tolist() == [1]
    assert filled["r_bp"][0] == unconditional["r_bp"][0] == pytest.approx(30.0)  # A and B: the same gross return, no cost term
    series.low[k - 4] = 100.0  # no trade through: unfilled, still in the A population with its flag
    series.cache.clear()
    assert g5_eval.measure(series, [h], t01, spec, "event")["counts"]["unfilled"] == 1
    assert g5_eval.measure(series, [h], t01, spec, "event", {"fill": False, "fill_status": True})["fill_flag"].tolist() == [0]


def test_a1_the_parser_refuses_an_inconsistent_cost_or_an_incomplete_amendment():
    t01 = lambda d: d["events"][0]  # noqa: E731
    t05 = lambda d: d["events"][1]  # noqa: E731
    cases = [
        (lambda d: t01(d).update({"expected_cost_bp": 13.0, "expected_cost_bp_stress": 19.5}), "cost terms"),  # the key with the pre-A1 totals
        (lambda d: (t01(d).pop("adverse_selection_entry_leg"), t01(d).pop("cost_sensitivity_pre_a1"),
                    d["descriptive"]["reports"].remove("T01_cost_sensitivity_pre_a1")), "cost terms"),  # 9.5 without the key
        (lambda d: t01(d).update({"adverse_selection_entry_leg": "half_measured"}), "not implemented"),
        (lambda d: t05(d).update({"adverse_selection_entry_leg": "measured_by_fill_proxy"}), "measured_by_fill_proxy needs a resting"),
        (lambda d: t01(d).pop("cost_sensitivity_pre_a1"), "needs cost_sensitivity_pre_a1"),
        (lambda d: t01(d)["cost_sensitivity_pre_a1"].update({"expected_cost_bp": 12.0}), "design-value totals"),
        (lambda d: t01(d)["cost_sensitivity_pre_a1"].update({"expected_cost_bp_stress": 18.0}), "design-value totals"),
        (lambda d: t01(d)["cost_sensitivity_pre_a1"].update({"use": "C2 at 13 bp"}), "implemented rule"),
        (lambda d: t01(d)["cost_sensitivity_pre_a1"].update({"adverse_selection_entry_leg": "measured_by_fill_proxy"}), "implemented rule"),
        (lambda d: t01(d)["cost_sensitivity_pre_a1"].update({"extra": 1}), "unknown key"),
        (lambda d: t01(d).update({"adverse_selection_entry_leg": "assumed_design_value", "expected_cost_bp": 13.0, "expected_cost_bp_stress": 19.5}), "only beside"),
        (lambda d: t05(d).update({"cost_sensitivity_pre_a1": copy.deepcopy(BASE["events"][0]["cost_sensitivity_pre_a1"])}), "only beside"),
        # the A1 reports are mandatory, and only for a type with the feature
        (lambda d: d["descriptive"]["reports"].remove("abc_signal_execution_net"), "mandatory"),
        (lambda d: d["descriptive"]["reports"].remove("T01_filled_vs_unfilled_signal"), "mandatory"),
        (lambda d: d["descriptive"]["reports"].remove("diagnostic_classification_a1"), "mandatory"),
        (lambda d: d["descriptive"]["reports"].append("T05_filled_vs_unfilled_signal"), "not implemented"),
        (lambda d: d["descriptive"].pop("a1"), "descriptive.a1"),
        (lambda d: d["descriptive"]["a1"]["classification_rules"].update({"a_no_edge": "LB90(A_event - A_control) <= 2"}), "implemented rule"),
        (lambda d: d["descriptive"]["a1"]["classification_rules"].pop("none_of_the_four"), "missing"),
        (lambda d: d["descriptive"]["a1"].update({"classification_precedence": ["a_no_edge", "d_insufficiently_documented", "c_badly_executed",
                                                                                "b_too_weak_for_costs", "none_of_the_four"]}), "precedence"),
        (lambda d: d["descriptive"]["a1"]["definitions"].update({"A_signal": "the filled instants only"}), "implemented rule"),
        (lambda d: d["descriptive"]["a1"].update({"status": "decisive"}), "implemented rule"),
        (lambda d: d["requires"].remove("a1_entry_adverse_selection_measured_by_fill_proxy"), "without declaring"),
        (lambda d: d["requires"].remove("a1_footprint_boundaries_counted"), "without declaring"),
    ]
    for mutate, match in cases:
        _refused(mutate, match)


def test_a1_the_gate2_keeps_the_gate1_entry_leg_rule_for_its_gate1_construction():
    """Trap 7: the Gate 2's descriptive Gate 1 construction uses the same entry-leg rule; a Gate 2 draft that differs is refused."""
    spec = g5_eval.parse_spec(copy.deepcopy(GATE2_BASE))
    assert spec.type("T01_funding_8h").expected_cost_bp == 9.5 and spec.type("T01_funding_8h").entry_adverse_selection == "measured_by_fill_proxy"
    assert spec.status == "DRAFT_NOT_FROZEN" and spec.version == "0.1.7-draft"  # adapted 2026-10-21: D10 (0.1.4), then D7 (i) and D8 (0.1.5) bumped the draft (0.1.3 = A1); 0.1.6: owner decisions of 2026-10-22; 0.1.7: calendars and choices (1)-(7) written in the body
    other = copy.deepcopy(GATE2_BASE)
    other["events"][0].pop("adverse_selection_entry_leg")
    with pytest.raises(g5_eval.SpecError, match="adverse_selection_entry_leg"):
        g5_eval.parse_spec(other)
    taker = copy.deepcopy(GATE2_BASE)
    taker["events"][1]["adverse_selection_entry_leg"] = "measured_by_fill_proxy"
    with pytest.raises(g5_eval.SpecError, match="measured_by_fill_proxy needs a resting"):
        g5_eval.parse_spec(taker)


# ----------------------------------------------------------------------------- 7. the boundaries (END_EXCLUSIVE, eligibility)


def test_a1_footprints_crossing_end_exclusive_or_starting_before_the_eligibility_are_excluded_and_counted():
    listed = "2026-08-20T10:07:00Z"  # eligible from 2026-09-19T10:07:00Z, inside the window
    spec = spec_of(universe=[uni(BTC, listed=listed)], window=SHORT)
    assert spec.end_us == END  # the Gate 1 window ends at g5_fetch.END_EXCLUSIVE
    meta = spec.instrument(BTC)
    t01, t05 = spec.type("T01_funding_8h"), spec.type("T05_us_open")
    st = settlements_8h(spec)
    series = flat(spec, meta, settlements=st, rates=np.full(st.size, 1e-4), d_cov_us=spec.start_us)
    # T01 footprint [h − 5 ; h + 15]: ending exactly at END reads the candle closing at END (opened at END − 60 s): kept; one minute later: out.
    kept, counts = g5_eval.restrict(series, np.array([END - 15 * MINUTE_US, END - 14 * MINUTE_US, END - MINUTE_US]), t01, spec)
    assert kept.tolist() == [END - 15 * MINUTE_US] and counts["crosses_end_exclusive"] == 2 and counts["kept"] == 1
    kept, counts = g5_eval.restrict(series, np.array([END - 60 * MINUTE_US, END - 59 * MINUTE_US]), t05, spec)  # T05 footprint [h ; h + 60]
    assert kept.tolist() == [END - 60 * MINUTE_US] and counts["crosses_end_exclusive"] == 1 and counts["outside_window"] == 0
    # Eligibility on the footprint start: T01 starts at h − 5, T05 at h.
    e = meta.eligible_from_us
    kept, counts = g5_eval.restrict(series, np.array([e + 5 * MINUTE_US, e + 4 * MINUTE_US]), t01, spec)
    assert kept.tolist() == [e + 5 * MINUTE_US] and counts["excluded_ineligible"] == 1
    kept, counts = g5_eval.restrict(series, np.array([e, e - MINUTE_US]), t05, spec)
    assert kept.tolist() == [e] and counts["excluded_ineligible"] == 1
    # The controls go through the same restriction, counted: the 10:07 eligibility removes 00:30 … 09:30 of that day, the 23:30 control
    # of the last day crosses END (with an empty exclusion union; the real union already removes it by the 00:00 grid).
    first = (e // DAY_US) * DAY_US + 13 * HOUR_US + 30 * MINUTE_US
    last = END - DAY_US + 13 * HOUR_US + 30 * MINUTE_US
    controls, diag = g5_eval.control_instants(series, t05, spec, np.array([first, last]), {})
    assert diag["restriction"]["excluded_ineligible"] == 10 and diag["restriction"]["crosses_end_exclusive"] == 1
    assert int(controls.min()) == (e // DAY_US) * DAY_US + 10 * HOUR_US + 30 * MINUTE_US and int(controls.max()) == END - 90 * MINUTE_US


def test_a1_boundary_counts_are_reported_for_the_events_and_the_controls_of_a_study(planted):
    _, _, doc = planted
    for r in doc["types"].values():
        ev, ct = r["exclusions"]["restriction"], r["exclusions"]["controls"]["restriction"]
        assert ev["crosses_end_exclusive"] == 0 and ct["crosses_end_exclusive"] == 0  # no generator of the spec reaches END by construction
        assert "excluded_ineligible" in ev and "excluded_ineligible" in ct and ev["excluded_ineligible"] > 0  # SOL / ETH become eligible on 2026-05-01


# ----------------------------------------------------------------------------- 2–5. the A / B / C table and its companions


def _no_p_value(value) -> bool:
    return "p_one_sided" not in json.dumps(g5_eval._clean(value))


def test_a1_the_abc_table_is_produced_for_every_type_with_events_controls_and_difference(planted):
    spec, _, doc = planted
    for name, r in doc["types"].items():
        abc = r["descriptive"]["abc_signal_execution_net"]
        assert abc["status"] == g5_eval.A1_STATUS and _no_p_value(abc)
        for row in ("A_signal", "B_execution", "C_net"):
            for side in ("events", "controls"):
                assert set(abc[row][side]) >= {"n_instants", "n_instrument_instants", "n_days", "fill_rate", "mean", "median", "lb90", "ub90"}
                assert abc[row][side]["lb90"] <= abc[row][side]["mean"] <= abc[row][side]["ub90"]
            assert set(abc[row]["events_minus_controls"]) >= {"point", "lb90", "ub90", "median_difference"}
        # C is the primary measure: its event mean, its D and its interval are the decisive ones (same bootstrap draws).
        st = r["statistic"]
        assert abc["C_net"]["events"]["mean"] == pytest.approx(st["event_mean"]) and abc["C_net"]["events_minus_controls"]["point"] == pytest.approx(st["D"])
        assert abc["C_net"]["events_minus_controls"]["lb90"] == pytest.approx(st["lb90"]) and abc["C_net"]["events_minus_controls"]["ub90"] == pytest.approx(st["ub90"])
        assert abc["C_net"]["events"]["mean_at_stress"] == pytest.approx(r["criteria"]["C3"]["event_net_stress_mean"])
        assert abc["C_net"]["events"]["n_instants"] == r["n_instants"] and abc["A_signal"]["events"]["fill_rate"] is None
        assert abc["C_net"]["portfolio"]["fills"] > 0 and abc["costs_bp"]["total_bp"] == r["costs_bp"]["total_bp"]
        # C = B + funding − costs, at every instant: here the costs are the type's (9.5 / 15.5 bp), the funding is −5 bp at T01 (0 at the one
        # instant that opens D_cov: its window opens before D_cov, funding assumed 0 and counted, §5.4), 0 at T05.
        funding = -5.0 if name.startswith("T01") else 0.0
        unknown = r["exclusions"]["event"]["funding_unknown"] if name.startswith("T01") else 0
        assert unknown <= 3 and abc["C_net"]["events"]["mean"] == pytest.approx(abc["B_execution"]["events"]["mean"] + funding - r["costs_bp"]["total_bp"],
                                                                                  abs=5.0 * (1 if unknown else 0) / r["n_instants"] + 1e-6)
        assert abc["A_signal"]["events"]["n_instrument_instants"] >= abc["B_execution"]["events"]["n_instrument_instants"]
    t05 = doc["types"]["T05_us_open"]["descriptive"]["abc_signal_execution_net"]
    assert t05["B_execution"]["events"]["fill_rate"] == 1.0 and t05["A_signal"]["events"]["mean"] == pytest.approx(t05["B_execution"]["events"]["mean"])  # taker: A = B
    t01 = doc["types"]["T01_funding_8h"]
    assert t01["descriptive"]["abc_signal_execution_net"]["B_execution"]["events"]["fill_rate"] == t01["fill_rate"]["events"]["rate"]
    text = g5_eval.render_markdown(g5_eval._clean({**{k: v for k, v in doc.items() if k != "_sealed_payloads"}, "generated_at": "test",
                                                   "spec": {"spec_id": "LP-G5-GATE1-2026", "source": "history", "descriptive_rule": "r", "sha256": "s", "status": "DRAFT_NOT_FROZEN",
                                                            "window": ["a", "b"]}}))
    assert text.count("### Amendement A1") == 2 and "| A (signal) | événements |" in text and "| C (net) | événements − contrôles |" in text
    assert "Classification diagnostique (rapportée, jamais un verdict)" in text


def test_a1_the_filled_unfilled_gap_is_the_signature_of_the_adverse_selection(adverse):
    _, _, doc = adverse
    r = doc["types"]["T01_funding_8h"]
    f = r["descriptive"]["T01_filled_vs_unfilled_signal"]
    ev, ct = f["events"], f["controls"]
    assert ev["A_filled"]["mean"] == pytest.approx(0.0, abs=1.0) and ev["A_unfilled"]["mean"] == pytest.approx(60.0, abs=1.5)
    assert ev["gap_filled_minus_unfilled"]["ub90"] < 0 and ev["fill_rate"] == pytest.approx(0.5, abs=0.05) and ev["fill_window_missing"] == 0
    assert ct["fill_rate"] == 1.0 and ct["A_unfilled"]["n_instants"] == 0 and ct["gap_filled_minus_unfilled"]["point"] is None
    assert f["fill_rate_events_minus_controls"] == pytest.approx(ev["fill_rate"] - 1.0) and _no_p_value(f)
    assert ev["fill_rate"] == pytest.approx(r["fill_rate"]["events"]["rate"])  # the same proxy as the primary measure
    abc = r["descriptive"]["abc_signal_execution_net"]
    assert abc["A_signal"]["events"]["mean"] == pytest.approx(30.0, abs=1.5) and abc["B_execution"]["events"]["mean"] == pytest.approx(0.0, abs=1.0)


def test_a1_the_taker_variant_of_t01_enters_at_c_t_arm_without_fill_conditioning(adverse, planted):
    _, _, doc = adverse
    r = doc["types"]["T01_funding_8h"]
    taker = r["descriptive"]["T01_taker_variant_without_fill_conditioning"]
    abc = r["descriptive"]["abc_signal_execution_net"]
    assert taker["costs_bp"]["role"] == "taker_maker" and taker["costs_bp"]["total_bp"] == 15.5 and taker["costs_bp"]["stress_total_bp"] == pytest.approx(23.25)
    # Every classified instant (the A population), the same funding (−5 bp here), the taker costs: ≈ 30 − 5 − 15.5.
    assert taker["events"]["n_instrument_instants"] == abc["A_signal"]["events"]["n_instrument_instants"]
    tolerance = 5.0 / taker["events"]["n_instants"] + 1e-6  # the instant opening D_cov pays no funding (assumed 0, counted, §5.4)
    assert taker["event_net_mean"] == pytest.approx(abc["A_signal"]["events"]["mean"] - 5.0 - 15.5, abs=tolerance) and taker["event_net_mean"] > 0
    assert taker["event_net_stress_mean"] == pytest.approx(taker["event_net_mean"] - 7.75, abs=1e-6) and _no_p_value(taker)
    # With every order filled (planted), the taker variant differs from C by the cost difference only (15.5 − 9.5 bp), D unchanged.
    _, _, doc = planted
    t01 = doc["types"]["T01_funding_8h"]
    taker = t01["descriptive"]["T01_taker_variant_without_fill_conditioning"]
    assert taker["event_net_mean"] == pytest.approx(t01["statistic"]["event_mean"] - 6.0, abs=1e-6) and taker["D"]["point"] == pytest.approx(t01["statistic"]["D"], abs=1e-6)
    assert doc["types"]["T05_us_open"]["descriptive"].get("T01_taker_variant_without_fill_conditioning") is None  # T05 is taker already


def test_a1_the_pre_a1_total_is_a_reported_sensitivity(planted):
    _, _, doc = planted
    t01 = doc["types"]["T01_funding_8h"]
    sens = t01["descriptive"]["T01_cost_sensitivity_pre_a1"]
    assert sens["rule"] == g5_eval.COST_SENSITIVITY_USE and (sens["a1"]["total_bp"], sens["pre_a1"]["total_bp"]) == (9.5, 13.0)
    assert sens["a1"]["event_net_mean"] == pytest.approx(t01["criteria"]["C2"]["event_net_mean"]) and sens["pre_a1"]["event_net_mean"] == pytest.approx(
        t01["criteria"]["C2"]["event_net_mean"] - 3.5)
    assert sens["pre_a1"]["event_net_stress_mean"] == pytest.approx(t01["criteria"]["C3"]["event_net_stress_mean"] - 5.25)
    assert sens["pre_a1"]["event_net_mean_positive"] is True and sens["D"].startswith("unchanged")  # values, never a criterion
    assert set(t01["criteria"]) == {"C1", "C2", "C3", "C4", "C5", "C6", "C7"} and t01["criteria"]["C2"]["event_net_mean"] == pytest.approx(sens["a1"]["event_net_mean"])


def test_a1_chronological_stability_by_month_and_by_half_of_the_window(planted):
    _, _, doc = planted
    for name, cut in (("T01_funding_8h", "2026-09-08"), ("T05_us_open", "2026-07-21")):  # D_cov 08-04 → 10-14 (71 days); 04-28 → 10-14 (169 days)
        r = doc["types"][name]
        ch = r["descriptive"]["chronological_stability_month_and_window_halves"]
        assert sum(m["C_net"]["n"] for m in ch["by_month"].values()) == r["n_instants"]
        assert sum(m["C_net"]["n_control"] for m in ch["by_month"].values()) == r["statistic"]["n_control_instants"]
        assert all(m["C_net"]["point"] > 20 and m["A_signal"]["point"] > 20 for m in ch["by_month"].values() if m["C_net"]["n"] >= 5)
        halves = ch["window_halves"]
        assert halves["cut_day"] == cut and halves["first_half"]["C_net"]["n"] + halves["second_half"]["C_net"]["n"] == r["n_instants"]
    assert list(doc["types"]["T05_us_open"]["descriptive"]["chronological_stability_month_and_window_halves"]["by_month"]) == [
        "2026-04", "2026-05", "2026-06", "2026-07", "2026-08", "2026-09", "2026-10"]
    # An effect in the first half of the window only: the halves say so (descriptive; C4 cuts at the median event day instead).
    _, _, doc = study(seed=6, t01=60.0, t01_drift_days=tape.day_of_date("2026-09-08"), mutate=fewer_resamples)
    halves = doc["types"]["T01_funding_8h"]["descriptive"]["chronological_stability_month_and_window_halves"]["window_halves"]
    assert halves["first_half"]["A_signal"]["point"] > 50 and abs(halves["second_half"]["A_signal"]["point"]) < 5


# ----------------------------------------------------------------------------- 6. the diagnostic classification (reported, never a verdict)


def test_a1_classification_a_no_edge_on_a_null_tape():
    _, _, doc = study(seed=11, mutate=fewer_resamples)
    for r in doc["types"].values():
        c = classification(r)
        assert r["verdict"] == "FAIL" and c["class"] == "a_no_edge" and c["label"] == "(a) signal sans avantage"
        assert c["inputs"]["A_gap_lb90"] <= 0 < c["inputs"]["A_gap_ub90"] < 10 and c["rule"] == g5_eval.DIAGNOSTIC_RULES["a_no_edge"]


def test_a1_classification_b_too_weak_for_the_costs():
    # A signal of +12 bp (T01) and +10 bp (T05): the gap of A is established, the net is not (12 − 5 − 9.5 < 0 ; 10 − 15.5 < 0).
    _, _, doc = study(seed=13, t01=12.0, t05=10.0, mutate=fewer_resamples)
    for r in doc["types"].values():
        c = classification(r)
        assert c["class"] == "b_too_weak_for_costs" and c["inputs"]["A_gap_lb90"] > 0 and c["inputs"]["C_event_mean"] <= 0
        assert r["verdict"] == "FAIL"  # the classification never changes the verdict
    assert classification(doc["types"]["T01_funding_8h"])["conditions"]["fill_gap_ub90_lt_0_at_events"] is False  # every order filled: no adverse selection


def test_a1_classification_c_badly_executed(adverse):
    _, _, doc = adverse
    r = doc["types"]["T01_funding_8h"]
    c = classification(r)
    assert c["class"] == "c_badly_executed" and c["label"] == "(c) signal exploitable mal exécuté" and r["verdict"] == "FAIL"
    assert all(c["conditions"][k] for k in ("A_gap_lb90_gt_0", "fill_gap_ub90_lt_0_at_events", "B_event_below_A_event", "C_event_le_0", "taker_variant_event_net_gt_0"))
    assert c["inputs"]["A_event_mean"] > 25 and abs(c["inputs"]["B_event_mean"]) < 1 and c["inputs"]["taker_variant_event_net_mean"] > 0
    assert doc["types"]["T05_us_open"]["descriptive"]["diagnostic_classification_a1"]["class"] != "c_badly_executed"  # no fill proxy: never (c)


def test_a1_classification_d_insufficiently_documented_below_the_floor_and_with_a_wide_interval():
    # Below the floor: NOT_INFORMATIVE, sealed; the classification is in the sealed payload.
    _, _, doc = study(seed=4, t05=40.0, window=SHORT, mutate=fewer_resamples)
    r = doc["types"]["T05_us_open"]
    c = classification(r, doc["_sealed_payloads"])
    assert r["verdict"] == "NOT_INFORMATIVE" and "sealed" in r and c["class"] == "d_insufficiently_documented"
    assert c["conditions"]["n_below_power_floor"] and c["conditions"]["verdict_not_informative_or_inconclusive"]
    assert "diagnostic_classification_a1" not in json.dumps(g5_eval._clean(r))  # nothing of it printed for a sealed type
    # A noisy tape (σ 25 bp per minute): the interval of the A gap holds both 0 and +10 bp at T05 — not documented, whatever the verdict.
    _, _, doc = study(seed=22, sigma_bp=25.0, mutate=fewer_resamples)
    r = doc["types"]["T05_us_open"]
    c = classification(r)
    assert r["verdict"] == "FAIL" and c["class"] == "d_insufficiently_documented" and c["conditions"]["A_gap_interval_holds_0_and_threshold"]
    assert c["inputs"]["A_gap_lb90"] <= 0 and c["inputs"]["A_gap_ub90"] >= 10


def test_a1_classification_none_of_the_four_when_signal_and_net_are_positive(planted):
    _, _, doc = planted
    for r in doc["types"].values():
        c = classification(r)
        assert r["verdict"] == "PASS" and c["class"] == "none_of_the_four" and c["inputs"]["C_event_mean"] > 0
        assert c["precedence"] == list(g5_eval.DIAGNOSTIC_PRECEDENCE) and "never a verdict" in c["note"]
