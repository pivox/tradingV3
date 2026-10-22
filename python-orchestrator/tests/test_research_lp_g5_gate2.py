"""Offline tests of the G5 Gate 2 (LP-G5 §2.3 (B) xxi, §5.5, §8, §10, Annexe B) on the repository's own draft spec
`specs/g5_gate2_spec.json` and on synthetic capture tapes: the strict reader (`g5_gate2.parse_capture_spec`) accepts the draft at
`DRAFT_NOT_FROZEN` and refuses `FROZEN` while an `owner_decision_pending` (or a null) remains; the identity with the Gate 1 spec; the
calcul d'audit (150 days, 449 instants, NYSE days, the control hours of the three DST regimes); the owner decisions and their
options; the simulation (post-only at the touch, taker at the opposite touch with slippage, passive exit and taker remainder,
funding grace, stress add-on, band count, stale and crossed books); the quarantine floor, the key minute without print, the
unusable instrument-days and the data-gap extension; the inscription read from the Gate 1 result; the command line end to end.

Adapted on 2026-10-21 for CTO review-b: the synthetic `funding-rate` messages follow docs-v5 (a settled message carries in
settFundingRate the rate of the previous settlement, its fundingTime is the upcoming one; a processing message at each settlement),
so a settled rate attributed to its own fundingTime (B1) fails here; C6 is computed at the Gate 2 on such a tape (M5); h+ of T01 is
the decision-time nextFundingTime (M1); a FROZEN Gate 2 needs a FROZEN Gate 1 (M2) and refused index option (b) while no fetcher
existed (M3; since 2026-10-21 `g5_index_fetch` exists and `INDEX_FROZEN_ALLOWED` admits (b) and D10, the refusal is still tested with
the constant emptied); exits are never shortened or stale (m1); no day file before the floor is opened (m2); the Gate 1 result is
checked for its kind, mode and status (m5). Adapted 2026-10-21 (pre-freeze pass): the owner decisions D7 (i) (T05 window end
2027-03-31, option a) and D8 (P-S3 moot, `moot_track_s_closed`) are written in the draft, so it carries no `owner_decision_pending`
value any more; the pending path is tested on documents set back to pending; T05 below the power floor by construction is stated.

No market data exists here: every tape is written by the tests. No test opens a connection or resolves a name (socket-level autouse
guard)."""

from __future__ import annotations

import copy
import json
import re
import socket
import urllib.request
from datetime import date
from pathlib import Path

import numpy as np
import pytest

from scripts.research_lp import calendar_versions as cv
from scripts.research_lp import cli, g5_eval, g5_fetch, g5_gate2, tape
from scripts.research_lp import separation as sep
from scripts.research_lp import xperp_capture as xc

US = tape.SECOND_US
MINUTE_US = 60 * US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * US
SPECS = Path(__file__).resolve().parents[1] / "scripts" / "research_lp" / "specs"
GATE2_SPEC = SPECS / "g5_gate2_spec.json"
GATE1_SPEC = SPECS / "g5_gate1_spec.json"
BASE = json.loads(GATE2_SPEC.read_text())
BTC, ETH = "BTC-USD_UM_XPERP-310404", "ETH-USD_UM_XPERP-310404"
PENDING = "owner_decision_pending"
BASE_CALENDARS = g5_eval.parse_spec(copy.deepcopy(BASE)).calendars
# P-L2-1 (D14, 2026-10-22): the real block keeps [2026-11-02T00:00Z ; 2026-11-03T12:00Z) out of every decisive read and puts T0 at
# 2026-11-04 or later. The synthetic DRAFT windows of these tests start on 2026-11-02: they carry a synthetic block whose excluded
# interval lies outside them (accepted at DRAFT only, reported; a FROZEN spec carries separation.BLOCK itself).
SYNTHETIC_SEPARATION = {**copy.deepcopy(sep.BLOCK), "reserved_interval_utc": ["2027-06-01T00:00:00Z", "2027-06-02T00:00:00Z"],
                        "separation_floor_utc": "2027-06-02T12:00:00Z", "excluded_interval_utc": ["2027-06-01T00:00:00Z", "2027-06-02T12:00:00Z"],
                        "g5_gate2_t0_min_utc": "2027-06-03T00:00:00Z"}


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


@pytest.fixture
def index_fetcher_exists(monkeypatch):
    """Option (b) admitted at FROZEN (CTO M3). Since 2026-10-21 the code admits it itself (`g5_index_fetch` exists, `INDEX_FROZEN_ALLOWED`
    holds (b) and D10); the fixture is kept as an explicit statement of the tests that rely on it."""
    monkeypatch.setattr(g5_gate2, "INDEX_FROZEN_ALLOWED", (g5_gate2.INDEX_OPTION_B,))


def frozen_gate1_document() -> dict:
    """A synthetic FROZEN version of the repository's Gate 1 spec (CTO M2: a FROZEN Gate 2 is read against a FROZEN Gate 1)."""
    document = json.loads(GATE1_SPEC.read_text())
    for session in document["sessions"]:
        if "verify_at_freeze" in session:
            session["verify_at_freeze"] = False
    document.update({"status": "FROZEN", "spec_version": "1.0.0-test"})
    document["freeze_fill"].update({"instruments_snapshot_path": "snapshot-g1.json", "instruments_snapshot_sha256": "1" * 64, "calendars_verified_utc": "2026-11-02T10:00:00Z",
                                    "code_digest_sha256": "2" * 64, "decision_ref_p_g5_1": "test P-G5-1", "funding_seal_receipt_sha256": "3" * 64})
    complete_gate1_calendars(document)
    return document


def complete_gate1_calendars(document: dict) -> dict:
    """The content test (Annexe B request 13, 2026-10-22) of the Gate 1 window 2026-04-28 .. 2026-10-13 needs a CPI date in October 2026:
    the draft omits 2026-10-14 (Annexe C: in the quarantine, outside the window); a FROZEN test document lists it."""
    if "2026-10-14" not in document["calendars"]["cpi"]:
        document["calendars"]["cpi"].append("2026-10-14")
    return document


# Illustrative complete Gate 2 lists for the FROZEN test documents (about two BLS dates a month; the real ones are recorded at the freeze,
# P-G5-5): the content test (Annexe B request 13) refuses a FROZEN spec without a CPI and an Employment Situation date in every month of
# [T0 ; last scored day + extension], a FOMC date every 56 days and the rule-based NYSE holidays; the half days are listed.
ILLUSTRATIVE_ES = ["2026-11-06", "2026-12-04", "2027-01-08", "2027-02-05", "2027-03-05", "2027-04-02"]
ILLUSTRATIVE_CPI = ["2026-11-10", "2026-12-10", "2027-01-13", "2027-02-10", "2027-03-10", "2027-04-13"]
ILLUSTRATIVE_HALF_DAYS = ["2026-11-27", "2026-12-24"]


def complete_gate2_calendars(document: dict) -> dict:
    document["calendars"].update({"employment_situation": list(ILLUSTRATIVE_ES), "cpi": list(ILLUSTRATIVE_CPI), "nyse_half_days": list(ILLUSTRATIVE_HALF_DAYS),
                                  "incomplete_at_draft": {}})
    return document


def written_in_the_body(document):
    """Annexe B request 14: the eight `freeze_pending:` choices written in the protocol body before a FROZEN (test documents only)."""
    if isinstance(document, dict):
        return {k: written_in_the_body(v) for k, v in document.items()}
    if isinstance(document, list):
        return [written_in_the_body(v) for v in document]
    return document.replace(g5_eval.FREEZE_PENDING_MARKER, "written in the protocol body (test):") if isinstance(document, str) else document


FROZEN_GATE1 = frozen_gate1_document()
FROZEN_GATE1_TEXT = json.dumps(FROZEN_GATE1, indent=1)
FROZEN_GATE1_SHA = g5_fetch.sha256_text(FROZEN_GATE1_TEXT)


# ----------------------------------------------------------------------------- documents


def item(inst: str, listed: str = "2026-03-30T04:30:00Z", tick: float = 0.1) -> dict:
    return {"inst_id": inst, "index_id": inst.split("_")[0], "list_time_utc": listed, "ct_val": 0.0001, "tick_sz": tick, "lot_sz": 1, "min_sz": 1}


def resolve(document: dict, t05: str | None = "a_end_2027_03_31", index: str | None = "b_history_index_candles_after_window", p_s3: str | None = "beta") -> dict:
    """The owner's choices written in the decision blocks and the owner_decisions index (None: left as in the document; PENDING: set back to
    pending — adapted 2026-10-21: the repository's draft carries D10 for the index source, so an index left pending is now written explicitly)."""
    blocks = {"events[T05_us_open].window_end": (document["events"][1]["window_end"], t05), "classifier_index_source": (document["classifier_index_source"], index),
              "universe.p_s3_track_s": (document["universe"]["p_s3_track_s"], p_s3)}
    for path, (block, choice) in blocks.items():
        if choice is not None:
            block.update({"status": PENDING if choice == PENDING else "owner_decided", "choice": choice})
        for lst in ("pending", "related_not_p_g5_10"):
            for entry in document["owner_decisions"][lst]:
                if entry["path"] == path:
                    entry["status"] = block["status"]
    return document


def frozen(document: dict, tmp_path: Path | None = None, t05_end: str = "2027-03-31") -> dict:
    """A FROZEN version: decisions resolved, every null filled, complete calendars, the funding fallback written as none, the funding
    source stated as implemented (CTO B1), the Gate 1 frozen with it = `FROZEN_GATE1` (CTO M2)."""
    resolve(document)
    document.update(written_in_the_body(document))  # Annexe B request 14 (2026-10-22): no freeze_pending: marker in a FROZEN spec
    for session in document["sessions"]:  # verified at the freeze (P-G5-5)
        if "verify_at_freeze" in session:
            session["verify_at_freeze"] = False
    document.update({"status": "FROZEN", "spec_version": "1.0.0-test"})
    document["universe"]["analysed"] = [item(BTC), item(ETH, "2026-04-01T04:00:00Z", 0.01)]
    document["events"][0]["expected_cost_bp"], document["events"][1]["expected_cost_bp"] = 9.5, 15.5  # A1: the Gate 1 design total of T01 (13 before A1)
    complete_gate2_calendars(document)  # Annexe B request 13 (2026-10-22): the content test of a FROZEN spec
    document["costs"]["funding"]["fallback"] = "none: an unknown settled rate excludes the observation (test)"
    document["costs"]["funding"]["source"] = g5_gate2.FUNDING_SOURCE
    document["freeze_fill"].update({"t0_utc": "2026-11-04T00:00:00Z", "gate1_spec_sha256": FROZEN_GATE1_SHA, "instruments_snapshot_path": "snapshot.json",
                                    "instruments_snapshot_sha256": "a" * 64, "p3_counts_selection_sha256": "b" * 64, "calendars_verified_utc": "2026-11-02T10:00:00Z",
                                    "code_digest_sha256": "c" * 64, "decision_ref_d1_capture": "test D1", "decision_ref_p_g5_10_i": "test (i) a",
                                    "decision_ref_p_g5_10_ii": "test (ii) b", "decision_ref_p_s3": "test beta", "t05_end_inclusive_utc": t05_end})
    return document


def short(document: dict, end_exclusive: str = "2026-11-05") -> dict:
    """A synthetic window (T0 = 2026-11-02 → `end_exclusive`): the window end, the T01 end and the T05 options follow; the calcul d'audit
    of the real window is dropped (its numbers belong to the full window). A DRAFT document starts on 2026-11-02 under the synthetic
    separation block (P-L2-1, adapted 2026-10-22); a FROZEN one keeps T0 = 2026-11-04 and the real block."""
    if document.get("status") != "FROZEN":
        document["window"]["start"] = "2026-11-02"
        document["pilot_separation"] = copy.deepcopy(SYNTHETIC_SEPARATION)
    last = date.fromordinal(date.fromisoformat(end_exclusive).toordinal() - 1)
    document["window"]["end_exclusive"] = end_exclusive
    document["events"][0]["window_end"] = {"end_inclusive": last.isoformat(), "end_exclusive": end_exclusive}
    options = document["events"][1]["window_end"]["options"]
    for name in ("a_end_2027_03_31", "b_end_2027_04_12"):
        options[name].update({"end_inclusive": last.isoformat(), "end_exclusive": end_exclusive,
                              "nyse_days_if_t0_2026_11_02": len(g5_gate2.nyse_days(BASE_CALENDARS, date(2026, 11, 2), last))})
    for event in document["events"]:
        event.pop("audit_expected", None)
    document["controls_common"].pop("audit_expected_utc", None)
    return document


def final_calendar_snapshot(directory: Path, retrieved: str = "2026-11-20T00:00:00Z", fomc=("2026-12-09", "2027-01-27", "2027-03-17", "2027-04-28")) -> Path:
    """Protocol 10.5: one official snapshot after the scored window (FOMC page re-read, dates unchanged by default)."""
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "v1.json").write_text(json.dumps({
        "schema": cv.SNAPSHOT_SCHEMA, "version": 1, "retrieved_utc": retrieved, "note": "test",
        "lists": {"fomc_statement": {"covers": "2026-12-01 .. 2027-04-30", "dates": list(fomc)}},
        "sources": [{"list": "fomc_statement", "url": "https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm", "published": cv.NOT_STATED,
                     "retrieved_utc": retrieved, "raw_sha256": "e" * 64}]}))
    return directory


def gate1_for(document: dict):
    """The Gate 1 read with a Gate 2 document: the synthetic FROZEN one for a FROZEN Gate 2, the repository's draft otherwise."""
    return FROZEN_GATE1 if document.get("status") == "FROZEN" else None


def parse(document: dict) -> g5_eval.Spec:
    return g5_eval.parse_spec(document, gate1_for(document))


def refused(mutate, match: str, base: dict | None = None):
    document = copy.deepcopy(base if base is not None else BASE)
    mutate(document)
    with pytest.raises(g5_eval.SpecError, match=match):
        g5_eval.parse_spec(document, gate1_for(document))


# ----------------------------------------------------------------------------- the real draft (B xviii, xxi)


def test_the_real_gate2_draft_is_accepted_and_every_block_is_read():
    spec, sha = g5_eval.load_spec(GATE2_SPEC)
    assert sha == tape.sha256_of(GATE2_SPEC) and spec.status == "DRAFT_NOT_FROZEN" and spec.source == "capture" and spec.gate == "G5_GATE2_"
    assert set(spec.requires) == set(g5_eval.CAPTURE_REQUIRES) and len(spec.requires) == 42 and "calendars_cover_the_window" in spec.requires
    # P-L2-1 (D14) and protocol 10.5 (2026-10-22): the pilot separation and the calendar versions are Gate 2 capabilities.
    assert {"pilot_separation_floor", "calendars_versioned_as_of_union"} <= set(spec.requires) <= set(g5_eval.CAPTURE_REQUIRES)
    ps = spec.gate2["pilot_separation"]
    assert ps["is_rule"] and ps["sha256"] == sep.block_sha256() and ps["draft_deviations"] == [] and spec.raw["pilot_separation"] == sep.BLOCK
    assert (g5_eval._iso(ps["floor_us"]), g5_eval._iso(ps["t0_min_us"]), ps["max_lookback_min"]) == ("2026-11-03T12:00:00Z", "2026-11-04T00:00:00Z", 540)
    # The first observation a scored instant can use (footprint start at T0, 480-minute look-back, one minute bar): after the floor.
    assert ps["first_observation_by_type_utc"] == {"T01_funding_8h": "2026-11-03T16:00:00Z", "T05_us_open": "2026-11-03T23:59:00Z"}
    assert spec.raw["calendars"]["versioning"] == cv.VERSIONING_BLOCK
    # Owner decision (a) of 2026-10-22: the freeze controls of Annexe B (13, 14, 16) are capabilities of both gates; 11 is the Gate 1's.
    assert {"calendars_content_test_at_freeze", "freeze_pending_marker_refuses_frozen", "economic_s_eco_at_least_d4"} <= set(spec.requires)
    assert "gate2_frozen_together" not in spec.requires and spec.version == "0.1.8-draft" and spec.economic["s_eco"] == 97.25
    # 0.1.7-draft (2026-10-22): the eight freeze_pending: choices (Annexe B, (1) to (7)) are written in the protocol body (10.1 to 10.4), no
    # marker is left (a FROZEN spec with one is still refused: test_freeze_pending_markers_and_the_economic_floor_at_both_gates).
    assert spec.notes["freeze_pending_markers"] == [] and spec.costs["funding_fallback"].startswith("none")
    # The content test on the draft's lists (reported, enforced at FROZEN only): the lists recorded from the official pages on 2026-10-22 pass;
    # incomplete_at_draft keeps two blocking entries (the rule for a date changed after the freeze, the 10:00 ET releases).
    content = spec.audit["calendar_content"]
    assert content["range"] == ["2026-11-04", "2027-03-31"] and content["holidays_by_rule"] == ["2026-11-26", "2026-12-25", "2027-01-01", "2027-01-18", "2027-02-15", "2027-03-26"]
    assert content["problems"] == [] and spec.notes["calendars.content_problems"] == [] and content["half_days"] == ["2026-11-27", "2026-12-24"]
    # 0.1.8-draft: the rule for a date changed after the freeze is written (protocol 10.5, calendars.versioning): fomc_statement is no longer blocking.
    assert sorted(spec.gate2["calendars"]["incomplete"]) == ["ten_am_et_releases"]
    assert not set(spec.requires) & set(g5_eval.GATE1_ONLY) and set(g5_eval.HISTORY_REQUIRES) & set(spec.requires) == set(g5_eval.HISTORY_REQUIRES) - set(g5_eval.GATE1_ONLY)
    # Owner decisions: every block decided since 2026-10-21 — D10 (index source, both sources, primary by selection_rule), D7 (i) (T05 window
    # end 2027-03-31, option a, no extension) and D8 (P-S3 moot: track S closed, BTC and ETH stay); no owner_decision_pending value is left.
    assert spec.gate2["pending"] == [] and spec.gate2["pending_values"] == []
    assert spec.gate2["decisions"] == {"classifier_index_source": g5_gate2.INDEX_CHOICE_D10, "t05_window_end": "a_end_2027_03_31", "p_s3": g5_gate2.P_S3_MOOT}
    assert all(e["status"] == "owner_decided" for lst in ("pending", "related_not_p_g5_10") for e in spec.raw["owner_decisions"][lst])
    assert spec.gate2["universe"] == {"p_s3": "moot_track_s_closed", "p_s3_scope": spec.raw["universe"]["p_s3_track_s"]["gate2_scope"], "btc_eth_removed": False}
    assert "index-tickers" in xc.CHANNELS and "index-tickers" not in spec.raw["capture"]["channels_conditional"]
    # freeze_fill: the three decision references hold the owner's dated texts (accepted before the freeze); every other value is null until then.
    refs = ("decision_ref_p_g5_10_i", "decision_ref_p_g5_10_ii", "decision_ref_p_s3")
    assert spec.universe == () and spec.freeze_fill["null"] == [k for k in g5_gate2.FREEZE_FILL_KEYS if k not in refs] and spec.gate2["t0_provisional"]
    assert '"Nn"' in spec.freeze_fill["decision_ref_p_g5_10_i"] and "On essaye des deux un par un" in spec.freeze_fill["decision_ref_p_g5_10_ii"]
    assert '"Clore s"' in spec.freeze_fill["decision_ref_p_s3"] and all("2026-10-21" in spec.freeze_fill[k] for k in refs)
    assert spec.freeze_fill["t05_end_inclusive_utc"] is None  # written at the freeze (2027-03-31 under D7)
    assert (spec.start.isoformat(), spec.end_exclusive.isoformat(), spec.gate2["t0_date"].isoformat()) == ("2026-11-04", "2027-04-01", "2026-11-04")
    t01, t05 = spec.types
    assert t01.gate2["window_end"]["end_exclusive"] == date(2027, 4, 1) and not t05.gate2["window_end"]["provisional"]
    assert t05.gate2["window_end"]["choice"] == "a_end_2027_03_31" and t05.gate2["window_end"]["end_exclusive"] == date(2027, 4, 1) and not spec.gate2["beyond_allowed"]
    # D7 (i): T05 below the power floor by construction, stated (audit, notes). On the draft (T0 provisional 2026-11-02, CPI and Employment
    # Situation lists recorded 2026-10-22: 5 + 5 dates on NYSE days of the window) the bound is 102 NYSE days - 10 = 92 < 100 (adapted
    # 2026-10-22; 100 with the two CPI dates of 0.1.6-draft); calendars_complete stays False while incomplete_at_draft is not empty.
    pbc = spec.audit["power_by_construction"]["T05_us_open"]
    # 0.1.8-draft (P-L2-1): T0 provisional 2026-11-04, 100 NYSE days - 10 = 90.
    assert (pbc["raw_instants_upper_bound"], pbc["min_instants"], pbc["not_informative_by_construction"], pbc["calendars_complete"]) == (90, 100, True, False)
    assert spec.notes["power_by_construction"]["T05_us_open"]["window_end_choice"] == "a_end_2027_03_31" and set(spec.audit["power_by_construction"]) == {"T05_us_open"}
    assert t01.gate2["exit_until_min"] == 60 and t05.gate2["exit_until_min"] == 75 and t01.gate2["fill_sim"] == {"kind": "lp_sim_post_only_touch", "decided_at_min": -5,
                                                                                                                   "resting_until_min": 1}
    assert t01.gate2["construction_proxy"] == {"open_from_min": -5, "open_to_min": 0} and t05.gate2["construction_proxy"] is None and t01.fill_proxy is None
    assert t01.inconclusive_if == ("classifier_index_source_unresolved_or_unavailable", "classifier_missing_instrument_day_share_gt_0_50", "C6_failed_or_not_computable")
    assert t01.proxy_missing_share_max == 0.5 and t01.validation["rate_high"] == 0.0004 and t01.controls["within"] == "spec_window"
    # The design totals are kept for the descriptive Gate 1 construction; the stress add-on of the measured terms (proposed by the draft).
    # A1 (adapted): the T01 entry leg is measured by the fill conditioning of the Gate 1 construction, as at the Gate 1 (13 / 19.5 bp before A1).
    assert (t01.expected_cost_bp, t01.expected_cost_bp_stress, t05.expected_cost_bp, t05.expected_cost_bp_stress) == (9.5, 14.25, 15.5, 23.25)
    assert t01.entry_adverse_selection == "measured_by_fill_proxy" and "T01_funding_8h:adverse_selection_entry_leg" in spec.gate2["identity_checked"]
    assert spec.costs["stress_add_bp_per_maker_leg"] == 1.75 and spec.costs["stress_add_bp_per_taker_entry"] == 1.5 and spec.costs["slippage_bp_per_taker_leg"] == 2.0
    # Owner decision (b) of 2026-10-22: through primary, touch (and queue) a descriptive comparison only.
    assert spec.gate2["sim"] == {"rule": "through", "descriptive_rules": ("touch", "queue"), "latency_ms": 250.0, "stale_s": 10.0, "funding_fill_grace_min": 1.0, "notional": 100.0}
    assert "2026-10-22" in spec.notes["execution_sim.entry_fill_rule_decision"] and "fill_rules_touch_and_queue" in spec.descriptive["reports"]
    assert spec.gate2["quality"] == {"coverage_min": 0.9, "stale_us": 10 * MINUTE_US, "share_max": 0.2, "classifier_missing_share_max": 0.5}
    assert spec.verdict["unusable_share_max"] == 0.2 and spec.sealing["verdicts"] == () and spec.gate2_inscription["FAIL"] == "not_evaluated"
    assert spec.multiplicity["tests"] == ("G5-Gate2-T01", "G5-Gate2-T05") and spec.frozen_candle == {"applies": True, "kind": "no_print", "count_field": "excluded_no_print"}
    assert spec.gate2["capture"]["channels_required"] == ("trades", "books5", "mark-price", "funding-rate") and spec.descriptive["reports"] == g5_gate2.DESCRIPTIVE_REPORTS
    # Calcul d'audit from the spec's own lists (LP-G5 §10.3, Annexe B; docs-b): every number of the draft is recomputed.
    assert spec.audit["T01_funding_8h"] == {"calendar_days": 150, "raw_instants_upper_bound": 449, "not_informative_expected_if_f_inst_below": pytest.approx(100 / 449)}
    assert spec.audit["T05_us_open"] == {"nyse_days_if_t0_2026_11_02.to_2027_03_31": 102, "nyse_days_if_t0_2026_11_02.to_2027_04_12": 110,
                                         "nyse_days_if_t0_2026_11_05.to_2027_03_31": 99, "nyse_days_if_t0_2026_11_05.to_2027_04_12": 107}
    hours = spec.audit["control_hours"]
    assert hours["us_winter_uk_winter_t0_to_2027_03_13"]["T01"] == {"ordinary": "2026-11-04", "CPI / Employment Situation": "2026-11-06", "FOMC": "2026-12-09"}
    assert hours["us_summer_uk_winter_2027_03_14_to_2027_03_27"]["T05"] == {"ordinary": "2027-03-15", "FOMC": "2027-03-17"}
    assert g5_gate2.control_hours_on(spec, t01, date(2026, 11, 4)) == ["03:00", "04:00", "05:00", "06:00", "10:00", "11:00", "12:00", "13:00", "18:00", "19:00"]
    assert g5_gate2.control_hours_on(spec, t05, date(2026, 11, 4)) == ["05:30", "09:30", "17:30"]
    assert g5_gate2.control_hours_on(spec, t01, date(2026, 11, 2)) == []  # before T0 (2026-11-04 since P-L2-1): no control
    assert g5_gate2.control_hours_on(spec, t01, date(2027, 3, 29)) == ["03:00", "04:00", "05:00", "10:00", "11:00", "12:00", "18:00", "22:00"]  # as Gate 1 (§7.1)
    # Identity with the Gate 1 spec named by frozen_with (trap 7).
    assert {"T01_funding_8h:classifier", "T01_funding_8h:validation", "T05_us_open:generator in New York time", "sessions", "macro_filter.releases", "statistics",
            "economic", "multiplicity", "verdict.C4_C5_C7"} <= set(spec.gate2["identity_checked"])
    assert spec.gate2["calendars"]["covers"] == (date(2026, 11, 2), date(2027, 4, 30)) and spec.gate2["calendars"]["incomplete"]
    notes = spec.notes
    assert notes["capture_dataset_id"] == BASE["capture_dataset_id"] and notes["verdict.pass_effect"] == BASE["verdict"]["pass_effect"]
    assert notes["owner_decisions.pending"] == spec.gate2["pending"] and notes["costs.funding.fallback"] == BASE["costs"]["funding"]["fallback"]
    assert notes["classifier_index_source.frozen_allowed"] == [g5_gate2.INDEX_OPTION_B, g5_gate2.INDEX_CHOICE_D10]


def test_a_frozen_gate2_spec_is_refused_while_an_owner_decision_or_a_null_remains(index_fetcher_exists):
    """(B) xxi, owner_decisions.rule: FROZEN refused while owner_decision_pending is present anywhere, or a null (the spec's note). The
    index option (b) is admitted at FROZEN (CTO M3: `g5_index_fetch`, 2026-10-21). Adapted 2026-10-21: the draft holds no pending decision
    any more (D7, D8, D10 written), so FROZEN on the draft is refused for its nulls, and a pending block is set back explicitly."""
    refused(lambda d: d.update({"status": "FROZEN", "spec_version": "1.0.0"}), "null values")
    refused(lambda d: (resolve(d, t05=PENDING), d.update({"status": "FROZEN", "spec_version": "1.0.0"})), "owner_decision_pending")
    spec = parse(frozen(copy.deepcopy(BASE)))
    assert spec.frozen and spec.gate2["pending"] == [] and spec.gate2["decisions"] == {"classifier_index_source": "b_history_index_candles_after_window",
                                                                                         "t05_window_end": "a_end_2027_03_31", "p_s3": "beta"}
    assert spec.types[1].gate2["window_end"]["end_exclusive"] == date(2027, 4, 1) and not spec.gate2["beyond_allowed"] and len(spec.universe) == 2
    for undo, match in (
        (lambda d: d["events"][1]["window_end"].update({"status": PENDING, "choice": PENDING}), "owner_decision_pending"),  # one decision left
        (lambda d: d["universe"]["p_s3_track_s"].update({"status": PENDING, "choice": PENDING}), "owner_decision_pending"),
        (lambda d: d["freeze_fill"].update({"decision_ref_p_s3": None}), "null"),
        (lambda d: d["events"][0].update({"expected_cost_bp": None}), "null"),
        (lambda d: d["universe"].update({"analysed": None}), "null"),
        (lambda d: d["calendars"].update({"incomplete_at_draft": {"employment_situation": "to record"}}), "incomplete_at_draft"),
        # Adapted 2026-10-22: the draft writes the fallback none (choice (7)) and no marker is left; the refusals are kept on explicit texts.
        (lambda d: d["costs"]["funding"].update({"fallback": "GATES 5.1: REST public/funding-rate-history read after T0 only (test)"}), "not implemented"),
        (lambda d: d["costs"]["funding"].update({"fallback": "none ; freeze_pending: to write (test)"}), "freeze_pending: in a FROZEN spec"),  # request 14
        (lambda d: d["quality"].update({"status": "freeze_pending: to write (test)"}), r"freeze_pending: in a FROZEN spec at \['spec.quality.status'\]"),
        (lambda d: d["calendars"]["employment_situation"].remove("2027-02-05"), "content test.*employment_situation: no date in 2027-02"),  # request 13
        (lambda d: d["costs"]["funding"].update({"source": BASE["costs"]["funding"]["source"]}), "implemented rule"),  # CTO B1: the draft text
        (lambda d: d["freeze_fill"].update({"gate1_spec_sha256": None}), "null"),
        (lambda d: d["freeze_fill"].update({"t05_end_inclusive_utc": "2027-04-12"}), "differs from the chosen end"),
        (lambda d: d["universe"].update({"analysed": [item(ETH, "2026-04-01T04:00:00Z", 0.01)]}), "always_included"),
        (lambda d: d["owner_decisions"]["pending"][0].update({"status": PENDING}), "owner_decision_pending|differs"),
        (lambda d: d.update({"spec_version": "1.0.0-draft"}), "final version"),
        (lambda d: d["sessions"][5].update({"verify_at_freeze": True}), "verify_at_freeze"),
    ):
        refused(undo, match, base=frozen(copy.deepcopy(BASE)))
    # The other options: (b) 2027-04-12 (beyond 2027-03-31 under its two decisions), (c) the calendar rule computed from T0.
    b = frozen(copy.deepcopy(BASE), t05_end="2027-04-12")
    b["events"][1]["window_end"]["choice"] = "b_end_2027_04_12"
    spec_b = parse(b)
    assert spec_b.types[1].gate2["window_end"]["end_exclusive"] == date(2027, 4, 13) and spec_b.gate2["beyond_allowed"]
    assert spec_b.end_exclusive == date(2027, 4, 27)  # the data-gap extension of 14 days is allowed beyond 2027-03-31 under (b)
    c = frozen(copy.deepcopy(BASE))
    c["events"][1]["window_end"]["choice"] = "c_end_by_calendar_rule"
    # T0 of the FROZEN fixture: 2026-11-04 since P-L2-1 (adapted 2026-10-22; 2026-11-02 before).
    end_c = g5_gate2.t05_end_by_calendar_rule(parse(frozen(copy.deepcopy(BASE))).calendars, date(2026, 11, 4), 100)  # the complete lists of a FROZEN spec
    c["freeze_fill"]["t05_end_inclusive_utc"] = end_c.isoformat()
    assert parse(c).types[1].gate2["window_end"]["end_inclusive"] == end_c and end_c == g5_gate2.nyse_days(parse(c).calendars, date(2026, 11, 4), end_c, exclude_macro=True)[99]
    # Index option (a) and an undecided P-S3 alpha are refused at any status (not implemented / not decided).
    refused(lambda d: resolve(d, index="a_capture_index_tickers"), "not the owner's decision")  # adapted 2026-10-21: (a) alone is not D10
    refused(lambda d: resolve(d, p_s3="alpha"), "Gate 2 scope")
    # D8 (2026-10-21): P-S3 moot, BTC and ETH stay at both gates; a FROZEN spec without them is refused under moot_track_s_closed as under beta.
    moot = frozen(copy.deepcopy(BASE))
    resolve(moot, p_s3=g5_gate2.P_S3_MOOT)
    assert parse(moot).gate2["decisions"]["p_s3"] == g5_gate2.P_S3_MOOT and not parse(moot).gate2["universe"]["btc_eth_removed"]
    refused(lambda d: d["universe"].update({"analysed": [item(ETH, "2026-04-01T04:00:00Z", 0.01)]}), "always_included", base=moot)
    refused(lambda d: resolve(d, p_s3="gamma"), "p_s3_track_s")
    alpha = resolve(copy.deepcopy(BASE), p_s3="alpha")
    alpha["universe"]["p_s3_track_s"]["gate2_scope"] = "gate1_and_gate2"
    alpha["universe"]["analysed"] = [item(BTC)]
    refused(lambda d: None, "removes BTC and ETH", base=alpha)
    alpha["universe"]["analysed"] = [item("SOL-USD_UM_XPERP-310404", "2026-04-01T04:25:00Z", 0.01)]
    assert parse(alpha).gate2["universe"]["btc_eth_removed"]
    # status decided, choice pending (adapted 2026-10-21: the draft's block is decided by D10, so the pending choice is written here)
    refused(lambda d: d["classifier_index_source"].update({"status": "owner_decided", "choice": PENDING}), "disagree")
    refused(lambda d: d["classifier_index_source"].update({"status": PENDING}), "disagree")  # status pending, choice decided


def test_t05_gate2_is_not_informative_by_construction_under_d7():
    """Owner decision D7 (i), 2026-10-21: the T05 Gate 2 window stops at 2027-03-31 (option a, no extension, no data-gap extension beyond
    it). The reader computes the most T05 instants the frozen window can hold (one per NYSE day outside the CPI / Employment Situation
    lists, from T0) and states that the floor of 100 is out of reach — written in the audit, the notes and the T05 verdict reasons, never
    hidden; the floor rule itself (power_floor.below_floor) then gives G5_GATE2_NOT_INFORMATIVE whatever the data."""
    base = frozen(copy.deepcopy(BASE))
    # Adapted 2026-10-22: a FROZEN spec carries complete lists (the content test, Annexe B request 13; illustrative dates, about two BLS dates a
    # month, the real ones are recorded at the freeze, P-G5-5). T0 = 2026-11-04 (P-L2-1, adapted 2026-10-22; 2026-11-02 gave 102 - 10 = 92):
    # 100 NYSE days - 10 = 90. (The draft's two CPI dates gave 100 = the floor: such incomplete lists can no longer be FROZEN.)
    pbc = parse(base).audit["power_by_construction"]["T05_us_open"]
    assert (pbc["raw_instants_upper_bound"], pbc["not_informative_by_construction"], pbc["calendars_complete"]) == (90, True, True)
    incomplete = copy.deepcopy(base)
    incomplete["calendars"].update({"cpi": ["2026-11-10", "2026-12-10"], "employment_situation": []})
    refused(lambda d: None, "content test", base=incomplete)
    # P-L2-1 (D14): a T0 before 2026-11-04 is refused at FROZEN; the realistic T0 once P3 runs after the pilot (P3 from the separation floor
    # 2026-11-03T12:00Z, 24 h at least, freeze after it): 2026-11-05, 99 NYSE days - 10 = 89 < 100.
    early = copy.deepcopy(base)
    early["freeze_fill"]["t0_utc"] = "2026-11-03T00:00:00Z"
    refused(lambda d: None, r"on or after window.start .*pilot_separation.g5_gate2_t0_min_utc", base=early)
    early["window"]["start"] = "2026-11-02"  # the window moved back as well: refused by the separation itself
    refused(lambda d: None, r"pilot_separation \(P-L2-1\).*window.start 2026-11-02.*freeze_fill.t0_utc 2026-11-03", base=early)
    refused(lambda d: d["pilot_separation"].update({"margin_s": 0, "separation_floor_utc": "2026-11-03T00:00:00Z",
                                                    "excluded_interval_utc": ["2026-11-02T00:00:00Z", "2026-11-03T00:00:00Z"]}),
            r"a FROZEN spec carries research_lp.separation.BLOCK", base=base)
    full = copy.deepcopy(base)
    full["freeze_fill"]["t0_utc"] = "2026-11-05T00:00:00Z"
    pbc = parse(full).audit["power_by_construction"]["T05_us_open"]
    assert pbc["t0"] == "2026-11-05"
    assert pbc["raw_instants_upper_bound"] == 99 - 10 == 89 and pbc["not_informative_by_construction"] and pbc["window_end_choice"] == "a_end_2027_03_31"
    assert pbc["end_exclusive_with_max_extension"] == "2027-04-01"  # no extension beyond 2027-03-31 under D7
    # Under the refused option (b) the extension may go beyond 2027-03-31: the bound grows (stated for comparison only, D7 refused it).
    b = copy.deepcopy(full)
    b["events"][1]["window_end"]["choice"] = "b_end_2027_04_12"
    b["freeze_fill"]["t05_end_inclusive_utc"] = "2027-04-12"
    assert parse(b).audit["power_by_construction"]["T05_us_open"]["raw_instants_upper_bound"] > 89


def test_the_strict_gate2_reader_refuses_unknown_keys_changed_rules_and_wrong_audits():
    t01 = lambda d: d["events"][0]  # noqa: E731
    t05 = lambda d: d["events"][1]  # noqa: E731
    cases = [
        (lambda d: d.update({"fetch_plan": {}}), "unknown key"),
        (lambda d: d["execution_sim"].update({"queue_model": "x"}), "unknown key"),
        (lambda d: d["classifier_index_source"]["options"].update({"c_guess": {}}), "unknown key"),
        (lambda d: t01(d)["fill_sim"].update({"ttl_s": 60}), "unknown key"),
        (lambda d: d["quality"].pop("status"), "missing"),
        (lambda d: d["requires"].remove("calendars_cover_the_window"), "without declaring"),
        (lambda d: d["requires"].append("fill_proxy_through_candles"), "not applicable"),
        (lambda d: d["requires"].append("gate2_capture_simulation"), "not implemented"),
        (lambda d: d.update({"quarantine_floor_utc": "2026-11-01T00:00:00Z"}), "implemented rule"),
        (lambda d: d["window"].update({"start": "2026-10-20"}), "quarantine floor"),
        (lambda d: d["window"].update({"end_exclusive": "2027-04-15"}), "GATES 1.3"),
        (lambda d: d["window"]["data_gap_extension"].update({"max_days": 40}), "covers"),
        (lambda d: d["calendars"].update({"covers": "2026-11-02 .. 2027-04-10 (short)"}), "covers"),
        (lambda d: d["calendars"].update({"covers_rule": "the evaluator warns"}), "covers_rule"),
        (lambda d: d["calendars"]["cpi"].append("2027-06-10"), "outside calendars.covers"),
        (lambda d: d["calendars"]["dst"].update({"us_2027_start": "2027-03-21"}), "not a DST change"),
        (lambda d: d["macro_filter"].update({"utc_note": d["macro_filter"]["utc_note"].replace("19:00 UTC", "18:00 UTC")}), "utc_note"),
        (lambda d: t05(d).update({"utc_time_note": t05(d)["utc_time_note"].replace("14:30", "13:30", 1)}), "utc_time_note"),
        (lambda d: d["price"].update({"forward_fill": "previous"}), "implemented rule"),
        (lambda d: d["frozen_candle"].update({"rule": "keep"}), "implemented rule"),
        (lambda d: d["statistics"].update({"measure": "net_bp"}), "not implemented"),
        (lambda d: d["costs"].update({"stress_rule": d["costs"]["stress_rule"].replace("1.75 bp", "1 bp")}), "implemented rule"),
        (lambda d: d["costs"]["design_values_gate1"].update({"adverse_selection_bp_per_maker_leg": 2.0}), "implemented rule|differs from the Gate 1"),
        (lambda d: d["costs"].update({"taker_fee_bp": 4.0}), "differs from the Gate 1"),
        (lambda d: d["execution_sim"].update({"entry_fill_rule": "through", "descriptive_fill_rules": ["through", "queue"]}), "primary rule"),
        (lambda d: d["execution_sim"].update({"entry_fill_rule": "touch", "descriptive_fill_rules": ["through", "queue"]}), "owner's decision of 2026-10-22"),
        (lambda d: d["execution_sim"].update({"descriptive_fill_rules": ["queue"]}), "owner's decision of 2026-10-22"),
        (lambda d: d["execution_sim"].update({"entry_fill_rule_decision": "owner, later"}), "entry_fill_rule_decision"),
        (lambda d: d["execution_sim"].pop("entry_fill_rule_decision"), "missing"),
        (lambda d: d["economic"].update({"s_eco_usd_per_year": 97.0}), "97.25"),
        (lambda d: d["calendars"].update({"content_rule": "a lenient test"}), "implemented rule"),
        (lambda d: d["calendars"].pop("nyse_half_days"), "missing"),
        (lambda d: d["calendars"]["nyse_half_days"].append("2027-06-10"), "outside calendars.covers"),
        (lambda d: d["calendars"].update({"content_exceptions": [{"list": "fomc_statement", "period": "2026-12", "change": "no_release_in_month",
                                                                  "written_utc": "2026-11-01T00:00:00Z", "source": "x", "reason": "y"}]}), "not implemented"),
        (lambda d: d["sessions"][1].update({"on_nyse_half_days": "1 pm"}), "HH:MM"),
        (lambda d: d["sessions"][2].update({"on_nyse_half_days": "13:00"}), "only an instant session of the NYSE trading days"),
        (lambda d: d["execution_sim"].update({"status_counts": ["filled"]}), "statuses"),
        (lambda d: d["execution_sim"].update({"order_notional_usd": 50.0}), "notional"),
        (lambda d: d["capture"].update({"channels_required": ["trades", "bbo-tbt"]}), "xperp_capture.CHANNELS"),
        (lambda d: d["capture"].update({"channels_in_code_2026_10_21": ["trades"]}), "xperp_capture.CHANNELS"),
        (lambda d: d["capture"]["channels_conditional"].update({"trades": "x"}), "already channels"),
        (lambda d: d["quality"].update({"unusable_share_inconclusive": 0.3}), "differs from quality"),
        (lambda d: d["quality"].update({"classifier_missing_instrument_day_share_inconclusive": 0.4}), "differs from quality"),
        (lambda d: d["verdict"]["inconclusive_global"].remove("gate1_result_sha256_mismatch"), "missing"),
        (lambda d: d["verdict"]["criteria"].update({"C3": "mean event sim_net_bp at x 1.5 (point) > 0"}), "implemented rule"),
        (lambda d: d["multiplicity"]["programme"].update({"tests": ["G5-Gate2-T01"]}), "one test per decisive type"),
        (lambda d: d["sealing"]["gate2_own_verdicts"].update({"rule": "seal like the Gate 1"}), "no sealing"),
        (lambda d: d["gate2_inscription"]["rule"].update({"G5_GATE1_FAIL": "evaluated"}), "implemented rule"),
        (lambda d: d["owner_decisions"]["pending"].pop(0), "not listed"),
        (lambda d: d["universe"].update({"liquidity_rule_g2_3_3": "applied"}), "not applied"),
        # identity with the Gate 1 (frozen_with, trap 7)
        (lambda d: t01(d)["classifier"].update({"min_coverage_share": 0.8}), "differs from the Gate 1"),
        (lambda d: t01(d)["c7"].update({"event_hour_utc": 8}), "differs from the Gate 1"),
        (lambda d: t05(d)["controls"].update({"minute_of_hour": 0}), "differs from the Gate 1"),
        (lambda d: d["sessions"][0].update({"start": "09:00", "end": "09:00"}), "differs from the Gate 1"),
        (lambda d: d["controls_common"].update({"margin_min": 30}), "differs from the Gate 1|calcul|control rule"),
        (lambda d: d["power_floor"].update({"min_instants": 90}), "differs from the Gate 1|floor"),
        (lambda d: d["frozen_with"].update({"spec_id": "LP-G5-GATE1-2025"}), "frozen_with"),
        (lambda d: t01(d).update({"gate1_identity": t01(d)["gate1_identity"] + ", moon"}), "checkable"),
        # entry / exit describe the simulated execution (Annexe B gap 5)
        (lambda d: t01(d)["entry"].update({"kind": "passive_limit"}), "not implemented"),
        (lambda d: t05(d)["exit"].update({"passive_until_min": 70}), "exit_until_min"),
        (lambda d: t01(d)["fill_sim"].update({"decided_at_min": -1}), "decided at arm_min"),
        # calcul d'audit
        (lambda d: t01(d)["audit_expected"].update({"raw_instants_upper_bound_if_t0_2026_11_02": 450}), "calcul d'audit"),
        (lambda d: t01(d)["audit_expected"].update({"calendar_days_if_t0_2026_11_02": 151}), "calcul d'audit"),
        (lambda d: t05(d)["audit_expected"]["nyse_days_if_t0_2026_11_05"].update({"to_2027_03_31": 100}), "calcul d'audit"),
        (lambda d: t05(d)["window_end"]["options"]["b_end_2027_04_12"].update({"nyse_days_if_t0_2026_11_02": 109}), "calcul d'audit"),
        (lambda d: d["controls_common"]["audit_expected_utc"]["us_winter_uk_winter_t0_to_2027_03_13"].update({"T05": "05:30 09:30 17:30 21:30 (17:30 out on FOMC days)"}),
         "control rule gives"),
        (lambda d: d["controls_common"]["audit_expected_utc"]["us_winter_uk_winter_t0_to_2027_03_13"].update(
            {"T01": "03 04 05 06 10 11 12 13 18 19 h (12 h out on CPI / Employment Situation days, 18 and 19 h out on FOMC days)"}), "implies"),
        (lambda d: t05(d)["window_end"]["options"]["c_end_by_calendar_rule"].update({"rule": t05(d)["window_end"]["options"]["c_end_by_calendar_rule"]["rule"].replace("100", "90")}),
         "floor"),
    ]
    for mutate, match in cases:
        refused(mutate, match)


# ----------------------------------------------------------------------------- the synthetic capture


def funding_row(ts: int, funding_time: int, next_time: int, sett_rate: float, state: int, predicted: float = 5e-4) -> tuple:
    """One `funding` layout row (tape.FUNDING_DTYPE): state 1 settled, 0 processing, -1 unknown."""
    return (ts, ts + 1, funding_time, predicted, next_time, np.nan, 0.0011, sett_rate, state, 1, 0.00375, -0.00375, 0.0001)


def faithful_funding(start_us: int, end_us: int, rate_of=lambda s: 5e-4, unsettled=(), processing_rate=None) -> list[tuple]:
    """The `funding-rate` messages of docs-v5 on an 8 h grid: every hour at hh:00:30 a settled message whose fundingTime is the upcoming
    settlement and whose settFundingRate is the rate of the **previous** settlement (docs-v5: "If settState = settled, it is the funding
    rate that is being used for previous settlement cycle"); 5 s after each settlement a processing message whose fundingTime is the
    settlement being processed (its settFundingRate `processing_rate`, default the settled rate, ignored by the evaluator). `unsettled`:
    settlements whose rate is never published settled (their hourly messages stay processing: the rate is unknown)."""
    rows = []
    for t in range(start_us, end_us, HOUR_US):
        ts = t + 30 * US
        nxt = (ts // (8 * HOUR_US) + 1) * 8 * HOUR_US
        prev = nxt - 8 * HOUR_US
        settled = prev not in unsettled
        rows.append(funding_row(ts, nxt, nxt + 8 * HOUR_US, rate_of(prev) if settled else np.nan, 1 if settled else 0, rate_of(nxt)))
        if t % (8 * HOUR_US) == 0:
            rows.append(funding_row(t + 5 * US, t, t + 8 * HOUR_US, rate_of(t) if processing_rate is None else processing_rate, 0, rate_of(t)))
    return rows


def write_capture(root: Path, insts=((BTC, "BTC-USD"),), days: int = 3, channels=("trades", "books5", "mark-price", "funding-rate"), gap: tuple | None = None,
                  before_floor: bool = False, index_root: Path | None = None, step_s: int = 10, premium_of=None, funding=None) -> None:
    """`days` days from 2026-11-02 of a quiet one-tick book (bid 100.0, ask 100.2) every `step_s` (10) s, prints every `step_s` s alternating
    sellers at the bid and buyers at the ask (buyers at 100.2 from 14:30:10 to 14:35 on 11-03: the T05 sign is +1; sellers at 100.0 the
    minute before), a mark of 100.1 × (1 + premium) every minute (`premium_of(ts)`; default +0.0012 over the eight hours before 2026-11-03
    07:56: P̂(07:55) is long), index candles at 100.1, the docs-v5 funding messages of `faithful_funding` (`funding(day0, end)`; default:
    settlements every 8 h at 5 bp). `gap`: (start, end) µs without any book snapshot; `before_floor`: rows dated before the quarantine
    floor (to be dropped)."""
    store = tape.TapeStore(root, "values", source={"ws": "synthetic", "channels": list(channels)}, not_before="2026-11-02T00:00:00Z")
    index_store = tape.TapeStore(index_root, "values", source={"rest": "synthetic"}, not_before="2026-11-02T00:00:00Z") if index_root else store
    day0 = tape.parse_iso_utc("2026-11-02T00:00:00Z")
    pos_from, pos_to = tape.parse_iso_utc("2026-11-02T23:00:00Z"), tape.parse_iso_utc("2026-11-03T07:56:00Z")
    if premium_of is None:
        premium_of = lambda ts: 0.0012 if pos_from <= ts < pos_to else 0.0  # noqa: E731
    t05 = tape.parse_iso_utc("2026-11-03T14:30:00Z")
    messages = (funding or faithful_funding)(day0, day0 + days * DAY_US)
    for (inst, index_id), s in ((x, sec) for x in insts for sec in range(0, days * tape.DAY_S, step_s)):
        ts = day0 + s * US
        if gap is None or not gap[0] <= ts < gap[1]:
            store.append("books5", inst, ts, (ts, ts + 1000, s, [100.0, 99.9, 99.8, 99.7, 99.6], [5.0] * 5, [100.2, 100.3, 100.4, 100.5, 100.6], [5.0] * 5))
        buyer = s % 20 != 0
        if t05 - 60 * US <= ts <= t05:
            buyer = False
        elif t05 < ts <= t05 + 5 * MINUTE_US:
            buyer = True
        store.append("trades", inst, ts, (ts, ts + 1000, 100.2 if buyer else 100.0, 2.0, 1 if buyer else 0, s, 1))
        if s % 60 == 0:
            store.append("mark", inst, ts, (ts, ts + 1000, 100.1 * (1 + premium_of(ts))))
            index_store.append("index_1m", index_id, ts - MINUTE_US, (ts - MINUTE_US, ts, 100.1, 100.1, 100.1, 100.1, 1))
    for inst, _ in insts:
        for row in messages:
            store.append("funding", inst, row[0], row)
    for st in {id(store): store, id(index_store): index_store}.values():
        st.checkpoint()
        st.finalize_days_before(max(tape.day_of_date("2026-11-08"), tape.day_of_us(day0 + (days + 1) * DAY_US)), force=True)
    if before_floor:  # a row dated before the floor inside a day file of the window (a corrupted or replayed file): dropped by the reader
        inst = insts[0][0]
        path = root / "trades" / inst / f"{inst}-2026-11-02.npz"
        rows = tape.load_rows("trades", path)
        early = np.zeros(1, dtype=tape.TRADES_DTYPE)
        early["ts_us"], early["recv_us"], early["price"], early["size"], early["taker_buy"], early["trade_id"], early["count"] = day0 - 30 * MINUTE_US, day0, 90.0, 1.0, 1, -1, 1
        tape.save_rows(path, np.concatenate([early, rows]))


def short_spec(t05: str | None = "a_end_2027_03_31", index: str | None = "b_history_index_candles_after_window", end: str = "2026-11-05", universe=None, mutate=None) -> g5_eval.Spec:
    document = short(resolve(copy.deepcopy(BASE), t05=t05, index=index), end)
    document["universe"]["analysed"] = universe if universe is not None else [item(BTC, "2026-09-01T00:00:00Z")]
    if mutate is not None:
        mutate(document)
    return g5_eval.parse_spec(document)


def with_fill_rule(spec: g5_eval.Spec, rule: str) -> g5_eval.Spec:
    """The in-memory spec with another primary fill rule. Since the owner decision (b) of 2026-10-22 the reader admits `through` only; the
    simulator's mechanics (grace, remainder, stale and crossed books, band, exits) are checked here under `touch`, the descriptive
    comparison, on a tape whose prints sit at the touch; the primary `through` is checked on the same tape."""
    import dataclasses

    return dataclasses.replace(spec, gate2={**spec.gate2, "sim": {**spec.gate2["sim"], "rule": rule}})


@pytest.fixture(scope="module")
def capture_root(tmp_path_factory) -> Path:
    root = tmp_path_factory.mktemp("capture") / "tape"
    write_capture(root, before_floor=True)
    return root


def test_the_capture_series_minute_bars_quarantine_floor_and_classifier(capture_root: Path):
    spec = short_spec()
    s = g5_eval.load_capture_series(capture_root, spec.instrument(BTC), spec, {"instId": BTC, "maxPxLmtPct": "0.01"})
    assert s.coverage["rows_before_quarantine_floor_dropped"]["trades"] == 1 and spec.pad_start_us == g5_eval.QUARANTINE_END_US  # never before the floor
    k = int(s.index_of([tape.parse_iso_utc("2026-11-03T08:00:00Z")])[0])
    # c(t) = last print in (t − 60 s ; t]; the minute bar's low / high / prints from the prints (price_last_print_per_minute).
    assert s.close[k] == 100.0 and s.low[k] == 100.0 and s.high[k] == 100.2 and s.vol[k] == 6  # the print at 08:00:00 is a seller's
    t01, t05 = spec.types
    h = tape.parse_iso_utc("2026-11-03T08:00:00Z")
    assert s.p_hat(s.index_of([h - 5 * MINUTE_US]), t01.classifier)[0] == pytest.approx(0.0012, rel=1e-6)  # captured mark, index_1m (option b)
    assert s.coverage["unusable_days"] == [d for d in range(tape.day_of_date("2026-11-05"), tape.day_of_date("2026-11-19"))] and s.coverage["band_pct"] == 0.01
    # Option (ii) pending: no index is read, the classifier cannot be computed.
    blind = short_spec(index=PENDING)  # adapted 2026-10-21 (D10 in the draft): pending written explicitly
    s2 = g5_eval.load_capture_series(capture_root, blind.instrument(BTC), blind, {"instId": BTC})
    assert np.isnan(s2.index).all() and s2.coverage["index_choice"] == PENDING
    # The index candles of another directory (--index-tape): same values.
    assert g5_eval.minute_bars(np.array([60, 120], dtype=np.int64) * US, np.array([30, 60, 61, 119], dtype=np.int64) * US, np.array([1.0, 2.0, 5.0, 3.0]))[0].tolist() == [2.0, 3.0]


def test_the_gate2_simulation_maker_taker_exit_grace_and_stress(capture_root: Path):
    primary = short_spec()
    s = g5_eval.load_capture_series(capture_root, primary.instrument(BTC), primary, {"instId": BTC})
    t01, t05 = primary.types
    h = tape.parse_iso_utc("2026-11-03T08:00:00Z")
    h5 = tape.parse_iso_utc("2026-11-03T14:30:00Z")
    # Owner decision (b) of 2026-10-22: the primary fill rule is through, for every passive leg. On this tape the sellers print at the bid and
    # the buyers at the ask, never through: the T01 post-only long at the bid stays unfilled (excluded from the primary, counted in the fill
    # rate); the T05 passive exit at the ask never fills and leaves as a taker at the bid at h + 75 (fees 5 + 5, slippage 2 + 2).
    assert primary.gate2["sim"]["rule"] == "through"
    m = g5_eval.measure(s, [h], t01, primary, "event")
    assert m["sim_status"]["unfilled"] == 1 and m["fill"] == {"attempted": 1, "filled": 0} and m["net_bp"].size == 0
    m5 = g5_eval.measure(s, [h5], t05, primary, "event")
    assert m5["net_bp"][0] == pytest.approx((100.0 - 100.2) / 100.2 * 1e4 - 10.0 - 4.0, abs=0.05)
    # A seller strictly through the bid (99.9) before h fills the long under through, at its own price 100.0.
    prints = s.prints
    j = int(np.searchsorted(prints.ts, h - 2 * MINUTE_US + 3 * US))
    s.prints = g5_eval.lp_sim.Prints(np.insert(prints.ts, j, h - 2 * MINUTE_US + 3 * US), np.insert(prints.price, j, 99.9), np.insert(prints.size, j, 1.0),
                                     np.insert(prints.taker_buy, j, 0))
    try:
        sim, status = g5_eval.measure_lp_sim(s, np.array([h]), np.array([1], dtype=np.int8), t01, primary)
        assert status["filled"] == 1 and sim["sim_markout_0_bp"][0] == pytest.approx(10.0, abs=0.05)
    finally:
        s.prints = prints
    # The mechanics below under touch, the descriptive comparison (never decisive).
    spec = with_fill_rule(primary, "touch")
    # T01: post-only long at the bid 100.0 armed at h − 5, filled by the next seller before h (the settlement is crossed: the long pays
    # 5 bp); passive exit at the ask 100.2 from h + 15: +20 bp, fees 2 + 2; stress: fees × 1.5 + 1.75 bp per filled maker leg (two).
    m = g5_eval.measure(s, [h], t01, spec, "event")
    assert m["execution"] == "lp_sim" and m["sim_status"]["filled"] == 1 and m["net_bp"][0] == pytest.approx(20.0 - 4.0 - 5.0, abs=0.05)
    assert m["funding_bp"][0] == pytest.approx(-5.0) and m["net_stress_bp"][0] == pytest.approx(20.0 - 6.0 - 5.0 - 3.5, abs=0.05) and m["sim"]["sim_maker_legs"][0] == 2.0
    assert m["sim"]["sim_markout_0_bp"][0] == pytest.approx(10.0, abs=0.05) and m["counts"]["kept"] == 1 and m["fill"] == {"attempted": 1, "filled": 1}
    # T05: 09:30 New York = 14:30 UTC in November; taker at the ask after the latency (+2 bp slippage), passive exit at the ask from
    # h + 60: 0 bp, fees 5 + 2, slippage 2; stress: (fees + slippage) × 1.5 + 1.75 (exit maker leg) + 1.5 (taker entry).
    assert [x for x, _, _ in g5_eval.local_time_instants(t05, spec)][1] == h5
    m5 = g5_eval.measure(s, [h5], t05, spec, "event")
    assert m5["sign"].tolist() == [1] and m5["net_bp"][0] == pytest.approx(0.0 - 7.0 - 2.0, abs=0.05) and m5["net_stress_bp"][0] == pytest.approx(-13.5 - 1.75 - 1.5, abs=0.05)
    assert m5["sim"]["sim_half_spread_bp"][0] == pytest.approx(0.1 / 100.1 * 1e4, rel=1e-6)
    # A fill in [h ; h + 1) crosses the settlement ("may take up to a minute": funding_fill_grace_min); without the grace it does not.
    import dataclasses

    late = dataclasses.replace(t01, arm_min=0, rest_until_min=1, return_window=(0, 15))
    sim, status = g5_eval.measure_lp_sim(s, np.array([h]), np.array([1], dtype=np.int8), late, spec)
    assert status["filled"] == 1 and sim["sim_funding_bp"][0] == pytest.approx(-5.0)
    no_grace = with_fill_rule(short_spec(mutate=lambda d: d["execution_sim"].update({"funding_fill_grace_min": 0})), "touch")
    sim, _ = g5_eval.measure_lp_sim(s, np.array([h]), np.array([1], dtype=np.int8), late, no_grace)
    assert sim["sim_funding_bp"][0] == pytest.approx(0.0)
    # The remainder of an exit that never fills passively leaves as a taker at the bid at h + exit_until_min, with its slippage.
    no_buyer = s.prints
    s.prints = g5_eval.lp_sim.Prints(no_buyer.ts, no_buyer.price, no_buyer.size, np.zeros(no_buyer.ts.size, dtype=np.int8))
    try:
        sim, status = g5_eval.measure_lp_sim(s, np.array([h]), np.array([1], dtype=np.int8), t01, spec)
        assert status["filled"] == 1 and sim["sim_rt_bp"][0] == pytest.approx(0.0) and sim["sim_fee_bp"][0] == pytest.approx(2.0 + 5.0) and sim["sim_slippage_bp"][0] == 2.0
    finally:
        s.prints = no_buyer
    # A stale book at the decision (no snapshot in the last 10 s) and a post-only that would cross at activation are counted, not filled.
    stale = g5_eval.lp_sim.Book(s.book.ts[s.book.ts < h - 5 * MINUTE_US - 20 * US], s.book.bid_px[s.book.ts < h - 5 * MINUTE_US - 20 * US], s.book.bid_sz[s.book.ts < h - 5 * MINUTE_US - 20 * US],
                                s.book.ask_px[s.book.ts < h - 5 * MINUTE_US - 20 * US], s.book.ask_sz[s.book.ts < h - 5 * MINUTE_US - 20 * US])
    book = s.book
    s.book = stale
    try:
        assert g5_eval.measure_lp_sim(s, np.array([h]), np.array([1], dtype=np.int8), t01, spec)[1]["stale"] == 1
    finally:
        s.book = book
    j = int(np.searchsorted(book.ts, h - 5 * MINUTE_US, side="right"))  # a snapshot 0.1 s after the decision: the ask drops to the bid
    s.book = g5_eval.lp_sim.Book(np.insert(book.ts, j, h - 5 * MINUTE_US + 100_000), np.insert(book.bid_px, j, [99.9, 99.8, 99.7, 99.6, 99.5], axis=0),
                                 np.insert(book.bid_sz, j, [5.0] * 5, axis=0), np.insert(book.ask_px, j, [100.0, 100.1, 100.2, 100.3, 100.4], axis=0),
                                 np.insert(book.ask_sz, j, [5.0] * 5, axis=0))
    try:
        assert g5_eval.measure_lp_sim(s, np.array([h]), np.array([1], dtype=np.int8), t01, spec)[1]["crossed"] == 1
    finally:
        s.book = book
    # The band rule is counted, not modelled (execution_sim.band_rejected): a tiny maxPxLmtPct counts the entry, the fill stands.
    s.coverage["band_pct"] = 1e-6
    sim, status = g5_eval.measure_lp_sim(s, np.array([h]), np.array([1], dtype=np.int8), t01, spec)
    assert status["band_rejected"] == 1 and status["filled"] == 1
    s.coverage["band_pct"] = None
    # A key minute without any print excludes the instrument-instant (excluded_no_print), before the classifier.
    vol = s.vol.copy()
    s.vol[int(s.index_of([h + 15 * MINUTE_US])[0])] = 0
    try:
        m = g5_eval.measure(s, [h], t01, spec, "event")
        assert m["counts"]["excluded_no_print"] == 1 and m["counts"]["kept"] == 0
    finally:
        s.vol[:] = vol
    # The descriptive Gate 1 construction on the prints: through proxy (a seller at 100.0 is not strictly below c(h − 5) − 1 tick),
    # design costs 9.5 bp (A1, as at the Gate 1: the entry leg is measured by the fill conditioning; 13 bp before A1).
    g1 = g5_eval.measure(s, [h], t01, spec, "event", {"construction": "gate1"})
    assert g1["execution"] == "candles" and g1["counts"]["unfilled"] == 1 and g1["costs"]["total_bp"] == 9.5


def test_quality_unusable_days_and_the_data_gap_extension(tmp_path: Path):
    a = tape.day_of_date("2026-11-02")
    ts = np.arange(a * DAY_US, (a + 3) * DAY_US, 10 * US, dtype=np.int64)
    assert g5_gate2.unusable_days(ts, a, a + 4, 0.9, 10 * MINUTE_US) == {a + 3}  # no snapshot on the fourth day
    holed = ts[(ts < (a + 1) * DAY_US + 5 * HOUR_US) | (ts > (a + 1) * DAY_US + 5 * HOUR_US + 11 * MINUTE_US)]
    assert g5_gate2.unusable_days(holed, a, a + 3, 0.9, 10 * MINUTE_US) == {a + 1}  # a stale period of 11 min crossing the day
    thin = ts[(ts // MINUTE_US) % 10 != 0]  # 10 % of the minutes without a snapshot: coverage 90 %, usable
    assert g5_gate2.unusable_days(thin, a, a + 3, 0.9, 10 * MINUTE_US) == set()
    end = date(2027, 4, 1)
    assert g5_gate2.extended_end(end, 3, 14, False) == end and g5_gate2.extended_end(end, 3, 14, True) == date(2027, 4, 4)
    assert g5_gate2.extended_end(date(2026, 12, 1), 20, 14, False) == date(2026, 12, 15)
    # A day lost by every instrument extends the windows (within 2027-03-31); its instants are removed (excluded_unusable_instrument_day).
    root = tmp_path / "tape"
    gap = (tape.parse_iso_utc("2026-11-04T00:10:00Z"), tape.parse_iso_utc("2026-11-04T23:50:00Z"))
    write_capture(root, days=4, gap=gap)
    spec = short_spec(end="2026-11-06")
    s = g5_eval.load_capture_series(root, spec.instrument(BTC), spec, {"instId": BTC})
    doc = g5_eval.run_study({BTC: s}, spec, {"gate1_verdicts": {"T01_funding_8h": "G5_GATE1_NOT_INFORMATIVE", "T05_us_open": "G5_GATE1_PASS"}})
    q = doc["summary"]["gate2"]["quality"]
    assert q["days_lost_by_every_instrument"] == ["2026-11-04"] and q["extension_days"] == 1 and q["instrument_days_unusable"] == 1 and q["instrument_days_scored"] == 4
    assert doc["summary"]["gate2"]["window_utc"]["T05_us_open"][1] == "2026-11-07T00:00:00Z"
    assert any("unusable_instrument_day_share_gt" in x for x in doc["summary"]["global_device_defects"])  # 2 of 4 days > 0.20
    assert doc["types"]["T01_funding_8h"]["exclusions"]["restriction"]["excluded_unusable_instrument_day"] >= 3


def test_the_inscription_owner_decisions_and_gate2_verdicts(capture_root: Path):
    gate1 = {"T01_funding_8h": "G5_GATE1_FAIL", "T05_us_open": "G5_GATE1_INCONCLUSIVE"}
    spec = short_spec()
    s = g5_eval.load_capture_series(capture_root, spec.instrument(BTC), spec, {"instId": BTC})
    doc = g5_eval.run_study({BTC: s}, spec, {"gate1_verdicts": gate1})
    t01, t05 = doc["types"]["T01_funding_8h"], doc["types"]["T05_us_open"]
    # FAIL at the Gate 1: not evaluated, p = 1 in the local Holm; INCONCLUSIVE: evaluated automatically (gate2_inscription.rule).
    assert t01["verdict_label"] == "G5_GATE2_NOT_EVALUATED" and t01["holm"]["p_used"] == 1.0 and t01["gate1_inscription"]["rule"] == "not_evaluated"
    assert t05["gate1_inscription"]["rule"] == "evaluated_automatically_without_futility" and t05["verdict_label"] == "G5_GATE2_NOT_INFORMATIVE"
    # The short window holds at most 2 T05 instants (11-02, 11-03, 11-04 minus the CPI-free count): below the floor by construction, stated.
    assert any(r.startswith("power_floor_unreachable_by_construction") and "says nothing for or against an effect" in r for r in t05["verdict_reasons"])
    assert "sealed" not in t05 and t05["statistic"]["measure"] == "sim_net_bp" and doc["summary"]["sealed_sha256"] == {}  # no sealing at the Gate 2
    assert "gate2_inscription" not in t05 and doc["summary"]["gate1_inscription"]["T01_funding_8h"]["evaluated"] is False
    # Pending owner decisions (DRAFT): every evaluated type INCONCLUSIVE (owner_decision_pending_present).
    pending = short_spec(t05=PENDING)  # adapted 2026-10-21 (D7 written in the draft): pending set back explicitly
    doc = g5_eval.run_study({BTC: g5_eval.load_capture_series(capture_root, pending.instrument(BTC), pending, {"instId": BTC})}, pending,
                            {"gate1_verdicts": {"T01_funding_8h": "G5_GATE1_PASS", "T05_us_open": "G5_GATE1_PASS"}})
    assert all(r["verdict"] == "INCONCLUSIVE" and r["verdict_reasons"][0].startswith("owner_decision_pending_present") for r in doc["types"].values())
    # The index source unresolved (P-G5-10 ii): T01 INCONCLUSIVE by construction, T05 untouched by it.
    blind = short_spec(index=PENDING, mutate=lambda d: None)  # adapted 2026-10-21 (D10 in the draft): pending written explicitly
    doc = g5_eval.run_study({BTC: g5_eval.load_capture_series(capture_root, blind.instrument(BTC), blind, {"instId": BTC})}, blind,
                            {"gate1_verdicts": {"T01_funding_8h": "G5_GATE1_PASS", "T05_us_open": "G5_GATE1_PASS"}})
    assert any(x.startswith("classifier_index_source_unresolved_or_unavailable") for x in doc["types"]["T01_funding_8h"]["verdict_reasons"])
    with pytest.raises(ValueError, match="gate1_verdicts"):
        g5_eval.run_study({BTC: s}, spec, {})
    with pytest.raises(ValueError, match="no verdict"):
        g5_gate2.inscription_of(spec, {"T01_funding_8h": "G5_GATE1_PASS"})


def test_every_gate2_descriptive_report_is_produced_without_a_p_value(capture_root: Path):
    spec = short_spec()
    s = g5_eval.load_capture_series(capture_root, spec.instrument(BTC), spec, {"instId": BTC})
    doc = g5_eval.run_study({BTC: s}, spec, {"gate1_verdicts": {"T01_funding_8h": "G5_GATE1_PASS", "T05_us_open": "G5_GATE1_PASS"}})
    produced = set(doc["common_descriptive"])
    for r in doc["types"].values():
        produced |= set(r.get("descriptive") or {})
    assert set(spec.descriptive["reports"]) <= produced
    t01, t05 = doc["types"]["T01_funding_8h"]["descriptive"], doc["types"]["T05_us_open"]["descriptive"]
    assert t01["premium_okx_channel_vs_premium_mark_index"]["pairs"] > 0 and "cohen_kappa" in t01["premium_okx_channel_vs_premium_mark_index"]
    assert set(t01["fill_rules_touch_and_queue"]) >= {"touch", "queue"} and "never rescue" in t01["fill_rules_touch_and_queue"]["rule"] and "measured_adverse_selection_bp" in t01["adverse_selection_measured_vs_design_3_5"]["events"]
    assert t05["taker_half_spread_measured_vs_design_3"]["design_bp"] == 3.0 and set(t05["dst_regime_winter_vs_summer"]) == {"rule", "us_summer_time", "us_winter_time"}
    assert t01["T02_funding_4h_settlements_premium_settstate_book"]["settlements_other_interval"] == 0 and "T18_cme_cf_brr_window" in t05
    assert doc["common_descriptive"]["T06_us_close_reversion"]["instants"] >= 2 and t05["T05_ten_am_et_release_days"]["status"] == "UNAVAILABLE"
    assert "p_one_sided" not in json.dumps(g5_eval._clean(t01)) and "p_one_sided" not in json.dumps(g5_eval._clean(doc["common_descriptive"]))


# ----------------------------------------------------------------------------- the funding-rate channel (CTO review-b B1, M1, M5)


def test_a_settled_rate_belongs_to_the_previous_settlement_as_docs_v5_says():
    """CTO B1 — docs-v5 (funding-rate): "If settState = settled, it is the funding rate that is being used for previous settlement cycle"
    and fundingTime is the upcoming settlement (the documentation's example: ts 10:11 UTC, fundingTime 16:00, settled). The rate goes
    to the largest fundingTime known at or before the message and below its own; processing rates are ignored; an unknown previous
    settlement, a missing rate, an unknown state and two different settled values are counted, never guessed."""
    s0, s1, s2, s3 = (tape.parse_iso_utc(t) for t in ("2026-11-05T00:00:00Z", "2026-11-05T08:00:00Z", "2026-11-05T16:00:00Z", "2026-11-06T00:00:00Z"))
    rows = [funding_row(s0 - HOUR_US + 30 * US, s0, s1, 1e-4, 1),  # the previous settlement (s0 - 8 h) was never seen: unattributed
            funding_row(s0 + 5 * US, s0, s1, 9e-4, 0),  # processing at s0: ignored
            funding_row(s0 + 30 * US, s1, s2, 2e-4, 1),  # settled after s0: the rate of s0 (not of s1)
            funding_row(s0 + HOUR_US + 30 * US, s1, s2, 2e-4, 1),  # the same value again: consistent
            funding_row(s1 + 5 * US, s1, s2, 7e-4, 0),
            funding_row(s1 + 30 * US, s2, s3, -3e-4, 1),  # the rate of s1 ...
            funding_row(s1 + HOUR_US + 30 * US, s2, s3, -4e-4, 1),  # ... contradicted: s1 unknown, counted
            funding_row(s2 + 30 * US, s3, s3 + 8 * HOUR_US, 5e-4, -1),  # unknown settState
            funding_row(s2 + HOUR_US, s3, s3 + 8 * HOUR_US, np.nan, 1)]  # settled without a rate
    table = np.array(list(reversed(rows)), dtype=tape.FUNDING_DTYPE)  # any order: sorted by ts
    got = g5_eval.attribute_settled_rates(table)
    assert got["settlements_us"].tolist() == [s0, s1, s2, s3] and got["rates"][0] == 2e-4 and np.isnan(got["rates"][1:]).all()
    assert got["counts"] == {"messages": 9, "settled": 6, "settled_attributed": 4, "settled_without_rate": 1, "settled_unattributed": 1, "settlements_conflicting": 1,
                             "processing_ignored": 2, "state_unknown": 1}
    # The convention of the first draft (a settled rate on its own fundingTime) would have put 1e-4 on s0 and 2e-4 on s1.
    # M1: the decision-time next settlement of each h is the nextFundingTime of the last message at or before t_arm whose fundingTime is h.
    spec = short_spec()
    t01 = spec.types[0]
    grid = g5_eval.spec_grid(spec)
    series = g5_eval.make_series(spec.instrument(BTC), grid, np.full(grid.size, 100.0), settlements_us=got["settlements_us"], rates=got["rates"], source="capture")
    series.cache["funding_messages"] = got["messages"]
    assert g5_eval.next_settlement_at_decision(series, np.array([s0, s1, s2, s3 + 8 * HOUR_US]), t01.arm_min).tolist() == [s1, s2, s3, -1]


def test_t01_at_gate2_reads_the_next_settlement_announced_at_the_decision_not_the_realised_one():
    """CTO M1 — at h − 5 the channel announces nextFundingTime = h + 8 h; after h the interval escalates to 4 h (the realised next
    settlement is h + 4 h). The Gate 2 keeps h on the decision-time information; the history rule (realised next) would drop it and
    counts it in `removed_by_next_neighbour_only`."""
    spec = short_spec()
    t01 = spec.types[0]
    p, h, e, f = (tape.parse_iso_utc(t) for t in ("2026-11-03T00:00:00Z", "2026-11-03T08:00:00Z", "2026-11-03T12:00:00Z", "2026-11-03T16:00:00Z"))
    rows = [funding_row(p - 8 * HOUR_US + 30 * US, p - 8 * HOUR_US, p, 5e-4, 0), funding_row(p - HOUR_US, p, h, 5e-4, 1), funding_row(p + 30 * US, h, h + 8 * HOUR_US, 5e-4, 1),
            funding_row(h - HOUR_US, h, h + 8 * HOUR_US, 5e-4, 1),  # the last message before t_arm = 07:55 announces 16:00
            funding_row(h + 30 * US, e, f, 6e-4, 1),  # escalation after h: next settlement 12:00, then 16:00
            funding_row(e + 30 * US, f, f + 8 * HOUR_US, 7e-4, 1), funding_row(f + 30 * US, f + 8 * HOUR_US, f + 16 * HOUR_US, 8e-4, 1)]
    got = g5_eval.attribute_settled_rates(np.array(rows, dtype=tape.FUNDING_DTYPE))
    grid = g5_eval.spec_grid(spec)
    capture = g5_eval.make_series(spec.instrument(BTC), grid, np.full(grid.size, 100.0), settlements_us=got["settlements_us"], rates=got["rates"], source="capture")
    capture.cache["funding_messages"] = got["messages"]
    kept, counts, _ = g5_eval.settlement_instants(capture, t01, spec)
    assert h in kept.tolist() and capture.rate_at([h])[0] == 6e-4 and counts["next_unknown_at_decision"] >= 1  # the last settlement has no announced next
    history = g5_eval.make_series(spec.instrument(BTC), grid, np.full(grid.size, 100.0), settlements_us=got["settlements_us"], rates=got["rates"])
    kept_h, counts_h, _ = g5_eval.settlement_instants(history, t01, spec)
    assert h not in kept_h.tolist() and counts_h["removed_by_next_neighbour_only"] >= 1


C6_CLASSES = (1, 0, -1, 0)  # class of settlement j (every 8 h from 2026-11-02 00:00): rate class and, on the agreeing tape, P̂ class
C6_RATE = {1: 6e-4, 0: 1e-4, -1: -6e-4}
C6_PREMIUM = {1: 0.0012, 0: 0.0, -1: -0.0012}
C6_DAYS = 12


def c6_class(ts: int) -> int:
    """The class of the settlement that closes the 8 h cycle (S − 8 h ; S] containing ts."""
    day0 = tape.parse_iso_utc("2026-11-02T00:00:00Z")
    j = -((day0 - ts) // (8 * HOUR_US))  # ceil((ts - day0) / 8 h): the settlement at or after ts
    return C6_CLASSES[j % 4]


@pytest.fixture(scope="module")
def c6_root(tmp_path_factory) -> Path:
    """12 days, BTC and ETH, a book and prints every minute, the mark premium of each 8 h cycle of the class of the settlement that
    closes it, docs-v5 funding messages whose settled rate has the same class (C6_RATE)."""
    root = tmp_path_factory.mktemp("c6") / "tape"
    rate_of = lambda s: C6_RATE[c6_class(s)]  # noqa: E731
    write_capture(root, insts=((BTC, "BTC-USD"), (ETH, "ETH-USD")), days=C6_DAYS, step_s=60, premium_of=lambda ts: C6_PREMIUM[c6_class(ts)],
                  funding=lambda a, b: faithful_funding(a, b, rate_of))
    return root


def c6_spec() -> g5_eval.Spec:
    return short_spec(end="2026-11-14", universe=[item(BTC, "2026-09-01T00:00:00Z"), item(ETH, "2026-09-01T00:00:00Z", 0.01)])


def test_c6_at_the_gate2_on_a_docs_faithful_capture_matrix_denominators_and_inconclusive(c6_root: Path):
    """CTO B1 + M5 — C6 at the Gate 2 with R read in the funding-rate channel: on a tape whose messages follow docs-v5, the classifier
    P̂(h − 5) agrees with the settled rate of h (sensitivity 1, false positives 0, denominators ≥ 30). Attributing each settled rate to
    its own fundingTime (the first draft's convention) shifts R by one cycle and C6 fails. A classifier that disagrees: C6 fails and
    T01 is G5_GATE2_INCONCLUSIVE (validation.fail_or_not_computable)."""
    spec = c6_spec()
    t01 = spec.types[0]
    series = {i: g5_eval.load_capture_series(c6_root, spec.instrument(i), spec, {"instId": i}) for i in (BTC, ETH)}
    raw = {i: g5_eval.restrict(s, g5_eval.settlement_instants(s, t01, spec)[0], t01, spec)[0] for i, s in series.items()}
    for s in series.values():
        assert s.coverage["funding_attribution"]["settled_unattributed"] == 0 and s.coverage["funding_attribution"]["processing_ignored"] == 3 * C6_DAYS
        assert all(s.rate_at([t])[0] == C6_RATE[c6_class(int(t))] for t in s.settlements_us[:-1].tolist())  # the last one is not settled yet
    v = g5_eval.validation_c6(series, raw, t01, spec)
    matrix = v["matrix_classifier_class|rate_class"]
    assert v["sensitivity"] == 1.0 and v["false_positive_rate"] == 0.0 and v["computable"] and v["pass"]
    assert v["denominator_rate_outside_zone"] == matrix["1|1"] + matrix["-1|-1"] >= 30 and v["denominator_rate_inside_zone"] == matrix["0|0"] >= 30
    assert sum(n for k, n in matrix.items() if k.split("|")[0] != k.split("|")[1]) == 0 and v["not_computable"] == 2  # 11-02 08:00: lookback before the floor
    # The first draft's convention: each settled rate on the fundingTime of its own message, i.e. one cycle late.
    shifted = {}
    for i, s in series.items():
        late = g5_eval.make_series(s.meta, s.grid_us, s.close, mark=s.mark, index=s.index, settlements_us=s.settlements_us,
                                   rates=np.concatenate([[np.nan], s.settlement_rates[:-1]]), source="capture")
        shifted[i] = late
    bad = g5_eval.validation_c6(shifted, raw, t01, spec)
    assert not bad["pass"] and bad["sensitivity"] < 0.8 and bad["false_positive_rate"] > 0.2
    # The study: C6 holds (T01 not inconclusive on C6; below the floor of 100 instants here), then a disagreeing classifier.
    gate1 = {"T01_funding_8h": "G5_GATE1_PASS", "T05_us_open": "G5_GATE1_PASS"}
    doc = g5_eval.run_study(series, spec, {"gate1_verdicts": gate1})
    r = doc["types"]["T01_funding_8h"]
    assert r["validation"]["pass"] and not any(x.startswith("C6_failed") for x in r["verdict_reasons"]) and r["verdict"] == "NOT_INFORMATIVE"
    for s in series.values():
        s.mark = 2.0 * s.index - s.mark  # the opposite premium: P̂ of the opposite class
        for key in [k for k in s.cache if k == "premium" or (isinstance(k, tuple) and k[0] == "p_hat")]:
            del s.cache[key]
    doc = g5_eval.run_study(series, spec, {"gate1_verdicts": gate1})
    r = doc["types"]["T01_funding_8h"]
    assert r["verdict_label"] == "G5_GATE2_INCONCLUSIVE" and any(x.startswith("C6_failed_or_not_computable") for x in r["verdict_reasons"])
    assert r["validation"]["sensitivity"] == 0.0 and not r["validation"]["pass"]


def test_processing_rates_are_ignored_and_an_unknown_settled_rate_excludes_the_observation(tmp_path: Path):
    """CTO M5 — a processing message's rate is never used; a settlement whose rate is never published settled is unknown: the T01
    instrument-settlement leaves the C6 population (realized_rate_missing) and a simulated observation crossing it is excluded and
    counted (funding_unknown, costs.funding.unknown_rate)."""
    h = tape.parse_iso_utc("2026-11-03T08:00:00Z")
    root = tmp_path / "tape"
    write_capture(root, funding=lambda a, b: faithful_funding(a, b, unsettled={h}, processing_rate=0.0099))
    spec = short_spec()
    t01 = spec.types[0]
    s = g5_eval.load_capture_series(root, spec.instrument(BTC), spec, {"instId": BTC})
    touch = with_fill_rule(spec, "touch")  # a fill is needed to cross the settlement: the prints of this tape sit at the touch
    assert np.isnan(s.rate_at([h])[0]) and s.rate_at([h + 8 * HOUR_US])[0] == 5e-4 and s.rate_at([h - 8 * HOUR_US])[0] == 5e-4  # 0.0099 (processing) never used
    assert s.coverage["funding_attribution"]["processing_ignored"] > 0 and s.coverage["settled"] == s.coverage["settlements"] - 2  # h and the last one
    kept, counts, _ = g5_eval.settlement_instants(s, t01, spec)
    assert h not in kept.tolist() and counts["realized_rate_missing"] >= 1
    m = g5_eval.measure(s, [h], t01, touch, "event")
    assert m["sim_status"]["funding_unknown"] == 1 and m["counts"]["funding_unknown"] == 1 and m["counts"]["kept"] == 0 and m["net_bp"].size == 0


def test_an_exit_is_never_shortened_or_quoted_from_a_stale_book(capture_root: Path):
    """CTO m1 — a book that stops before h + exit_until_min, an exit quote older than stale_s at the passive start or at the taker
    remainder: no_exit_quote (excluded and counted), never a shorter holding or a stale price."""
    spec = with_fill_rule(short_spec(), "touch")  # the prints of this tape sit at the touch (the exit leg fills passively under touch only)
    t01 = spec.types[0]
    s = g5_eval.load_capture_series(capture_root, spec.instrument(BTC), spec, {"instId": BTC})
    h = tape.parse_iso_utc("2026-11-03T08:00:00Z")
    one = (np.array([h]), np.array([1], dtype=np.int8))
    book, prints = s.book, s.prints

    def book_without(mask):
        return g5_eval.lp_sim.Book(book.ts[mask], book.bid_px[mask], book.bid_sz[mask], book.ask_px[mask], book.ask_sz[mask])

    assert g5_eval.measure_lp_sim(s, *one, t01, spec)[1]["filled"] == 1  # the reference: filled and exited
    try:
        s.book = book_without(book.ts <= h + 30 * MINUTE_US)  # the book stops at h + 30 < h + 60
        assert g5_eval.measure_lp_sim(s, *one, t01, spec)[1]["no_exit_quote"] == 1
        s.book = book_without((book.ts <= h + 14 * MINUTE_US) | (book.ts > h + 15 * MINUTE_US))  # no snapshot in the minute before h + 15
        assert g5_eval.measure_lp_sim(s, *one, t01, spec)[1]["no_exit_quote"] == 1
        s.book = book_without((book.ts <= h + 59 * MINUTE_US) | (book.ts > h + 60 * MINUTE_US))  # the taker remainder at h + 60 without a fresh quote
        s.prints = g5_eval.lp_sim.Prints(prints.ts, prints.price, prints.size, np.zeros(prints.ts.size, dtype=np.int8))  # no buyer: the exit stays passive
        assert g5_eval.measure_lp_sim(s, *one, t01, spec)[1]["no_exit_quote"] == 1
    finally:
        s.book, s.prints = book, prints


def test_no_day_file_before_the_quarantine_floor_is_opened(capture_root: Path, monkeypatch):
    """CTO m2 — with T0 = 2026-11-02 the padded start (T0 − 1 day) is floored: no file of 2026-11-01 is opened, tape or index tape."""
    opened = []
    original = tape.load_day

    def spy(out_dir, layout, inst, date, allow_pending=True):
        opened.append(date)
        return original(out_dir, layout, inst, date, allow_pending)

    monkeypatch.setattr(tape, "load_day", spy)
    spec = short_spec()
    s = g5_eval.load_capture_series(capture_root, spec.instrument(BTC), spec, {"instId": BTC}, index_dir=capture_root)
    assert spec.gate2["t0_date"] == date(2026, 11, 2) and min(opened) == "2026-11-02" and s.coverage["dates_read"][0] == "2026-11-02"


def test_a_frozen_gate2_needs_a_frozen_gate1_and_a_fetchable_index(tmp_path: Path, monkeypatch):
    """CTO M2 (trap 7, protocol §13 point 5): a FROZEN Gate 2 is refused against a DRAFT Gate 1 and against a FROZEN Gate 1 file that
    is not the one of freeze_fill.gate1_spec_sha256. CTO M3: index option (b) is refused at FROZEN while no fetcher can read
    history-index-candles of the Gate 2 period — since 2026-10-21 `g5_index_fetch` does, and the code admits (b) and D10 (the refusal is
    still checked with the constant emptied). CTO B1: the draft text of costs.funding.source is accepted at DRAFT only."""
    from scripts.research_lp import g5_index_fetch

    document = frozen(copy.deepcopy(BASE))
    assert g5_gate2.INDEX_FROZEN_ALLOWED == (g5_gate2.INDEX_OPTION_B, g5_gate2.INDEX_CHOICE_D10) == g5_index_fetch.CHOICES_NEEDING_B
    assert g5_eval.parse_spec(document, FROZEN_GATE1).gate2["frozen_with"]["status"] == "FROZEN"
    monkeypatch.setattr(g5_gate2, "INDEX_FROZEN_ALLOWED", ())
    with pytest.raises(g5_eval.SpecError, match="not admitted at FROZEN"):
        g5_eval.parse_spec(document, FROZEN_GATE1)
    monkeypatch.setattr(g5_gate2, "INDEX_FROZEN_ALLOWED", (g5_gate2.INDEX_OPTION_B, g5_gate2.INDEX_CHOICE_D10))
    with pytest.raises(g5_eval.SpecError, match="FROZEN Gate 1"):
        g5_eval.parse_spec(document)  # the repository's Gate 1 is DRAFT_NOT_FROZEN
    with pytest.raises(g5_eval.SpecError, match="FROZEN Gate 1"):
        g5_eval.parse_spec(document, json.loads(GATE1_SPEC.read_text()))
    good, other = tmp_path / "g1.json", tmp_path / "g1-other.json"
    good.write_text(FROZEN_GATE1_TEXT)
    other.write_text(FROZEN_GATE1_TEXT + "\n")
    spec = g5_eval.parse_spec(document, good)
    assert spec.gate2["frozen_with"] == {"spec": g5_gate2.GATE1_SPEC_PATH, "spec_id": "LP-G5-GATE1-2026", "status": "FROZEN", "sha256": FROZEN_GATE1_SHA}
    with pytest.raises(g5_eval.SpecError, match="gate1_spec_sha256"):
        g5_eval.parse_spec(document, other)
    path = tmp_path / "g2.json"
    path.write_text(json.dumps(document))
    assert g5_eval.load_spec(path, good)[0].frozen
    # The funding source: the draft's own text is accepted at DRAFT (reported superseded), the implemented text at both statuses.
    draft = g5_eval.parse_spec(copy.deepcopy(BASE))
    assert draft.notes["costs.funding.source_status"] == g5_gate2.FUNDING_SOURCE_DRAFT_STATUS and BASE["costs"]["funding"]["source"] == g5_gate2.FUNDING_SOURCE_DRAFT
    fixed = copy.deepcopy(BASE)
    fixed["costs"]["funding"]["source"] = g5_gate2.FUNDING_SOURCE
    assert g5_eval.parse_spec(fixed).notes["costs.funding.source_status"] == "implemented"
    refused(lambda d: d["costs"]["funding"].update({"source": "settled rate attributed to fundingTime"}), "implemented rule")


# ----------------------------------------------------------------------------- the command line (B xviii, xxi)


def test_the_gate2_command_line_refuses_before_reading_and_runs_end_to_end(tmp_path: Path, capsys, index_fetcher_exists):
    root = tmp_path / "capture"
    write_capture(root, insts=((BTC, "BTC-USD"), (ETH, "ETH-USD")))
    document = short(frozen(copy.deepcopy(BASE)), "2026-11-05")
    document["freeze_fill"]["t05_end_inclusive_utc"] = "2026-11-04"
    document["universe"]["analysed"] = [item(BTC, "2026-09-01T00:00:00Z"), item(ETH, "2026-09-01T00:00:00Z", 0.01)]
    snapshot = tmp_path / "snapshot.json"
    rows = [{"instId": u["inst_id"], "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": u["index_id"], "ctVal": str(u["ct_val"]), "tickSz": str(u["tick_sz"]),
             "lotSz": "1", "minSz": "1", "listTime": str(tape.parse_iso_utc(u["list_time_utc"]) // 1000), "maxPxLmtPct": "0.01"} for u in document["universe"]["analysed"]]
    snapshot.write_text(json.dumps({"code": "0", "data": rows}))
    p3 = tmp_path / "p3-counts.json"
    p3.write_text(json.dumps({"selection": "test"}))
    gate1_spec = tmp_path / "gate1-frozen.json"  # the FROZEN Gate 1 frozen with this Gate 2 (CTO M2)
    gate1_spec.write_text(FROZEN_GATE1_TEXT)
    assert tape.sha256_of(gate1_spec) == FROZEN_GATE1_SHA == document["freeze_fill"]["gate1_spec_sha256"]

    def gate1_result_file(path: Path, mode: str = "tape", status: str = "FROZEN", read: bool | None = None) -> Path:
        summary = {"mode": mode, "verdicts": {"T01_funding_8h": "G5_GATE1_FAIL", "T05_us_open": "G5_GATE1_NOT_INFORMATIVE"}}
        if read is not None:
            summary["market_data_read"] = read
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps({"kind": "lp-g5-eval", "spec": {"sha256": FROZEN_GATE1_SHA, "spec_id": "LP-G5-GATE1-2026", "status": status, "source": "history"},
                                    "summary": summary}))
        return path

    gate1_result = gate1_result_file(tmp_path / "run1" / "g5.json")
    document["freeze_fill"].update({"instruments_snapshot_path": str(snapshot), "instruments_snapshot_sha256": tape.sha256_of(snapshot),
                                    "p3_counts_selection_sha256": tape.sha256_of(p3), "code_digest_sha256": cli.code_digest()})
    spec_path = tmp_path / "gate2.json"
    spec_path.write_text(json.dumps(document, indent=1))
    sha = tape.sha256_of(spec_path)
    out = tmp_path / "run2" / "g5.json"
    index_sha = g5_fetch.directory_digest(root / "index_1m")[0]  # P-G5-10 (ii) option b: the register's sha256 of the index tape (CTO M3)
    args = ["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--expect-spec-sha256", sha, "--gate1-result", str(gate1_result),
            "--expect-gate1-result-sha256", tape.sha256_of(gate1_result), "--p3-counts-file", str(p3), "--gate1-spec", str(gate1_spec),
            "--expect-index-tape-sha256", index_sha, "--out-json", str(out), "--out-md", str(tmp_path / "run2" / "g5.md"),
            "--calendar-snapshots", str(final_calendar_snapshot(tmp_path / "calendars"))]
    # Protocol 10.5 (2026-10-22): a FROZEN run without a final calendar snapshot is refused before any market value.
    with pytest.raises(SystemExit, match="no final calendar snapshot"):
        cli.main(args[:-2])
    # Trap 7 (CTO M2): read against the repository's DRAFT Gate 1, the FROZEN Gate 2 is refused before anything is read.
    with pytest.raises(SystemExit, match="FROZEN Gate 1"):
        cli.main([a for i, a in enumerate(args) if a != "--gate1-spec" and (i == 0 or args[i - 1] != "--gate1-spec")])
    other_gate1 = tmp_path / "gate1-other.json"
    other_gate1.write_text(FROZEN_GATE1_TEXT + "\n")
    with pytest.raises(SystemExit, match="gate1_spec_sha256"):  # a FROZEN Gate 1, but not the one frozen in freeze_fill
        cli.main([str(other_gate1) if a == str(gate1_spec) else a for a in args])
    # CTO M3: the index tape is checked against its register sha256 (absent or different: refuse_run).
    with pytest.raises(SystemExit, match="expect-index-tape-sha256"):
        cli.main([a for i, a in enumerate(args) if a != "--expect-index-tape-sha256" and (i == 0 or args[i - 1] != "--expect-index-tape-sha256")])
    with pytest.raises(SystemExit, match="expect-index-tape-sha256"):
        cli.main([("0" * 64) if a == index_sha else a for a in args])
    assert cli.main(args) == 0
    printed = json.loads(capsys.readouterr().out)
    result = json.loads(out.read_text(), parse_constant=lambda c: pytest.fail(f"non-finite number {c}"))
    assert printed["verdicts"] == {"T01_funding_8h": "G5_GATE2_NOT_EVALUATED", "T05_us_open": "G5_GATE2_NOT_INFORMATIVE"} and result["gate1_result"]["sha256"] == tape.sha256_of(gate1_result)
    assert result["gate1_result"]["market_data_read"] is True and result["gate1_result"]["note"] is None and result["index_tape"]["digest"] == index_sha
    # P-L2-1 (adapted 2026-10-22): the rows of [2026-11-02T00:00Z ; 2026-11-03T12:00Z) are dropped before any computation; the settled-rate
    # messages of [12:00 ; 16:00) on 2026-11-03 refer to the 08:00 settlement, before the floor, and stay unattributed (never used).
    cov = result["coverage"][BTC]
    assert result["spec"]["notes"]["costs.funding.source_status"] == "implemented" and cov["funding_attribution"]["settled_unattributed"] == 4
    # the 2026-11-02 day file is never opened (padded start floored at the separation floor), the first half of 2026-11-03 is dropped.
    assert cov["rows_in_pilot_separation_dropped"]["trades"] == (12 * 3600) // 10 and cov["dates_read"][0] == "2026-11-03"
    assert result["pilot_separation"]["is_rule"] and result["calendar_versions"]["final_snapshot"] and result["calendar_versions"]["changes"] == []
    assert result["types"]["T05_us_open"]["statistic"]["measure"] == "sim_net_bp" and not (out.parent / "sealed").exists() and '"pass_programme"' not in out.read_text()
    assert "inscription (résultat du Gate 1)" in (tmp_path / "run2" / "g5.md").read_text() and result["coverage"][BTC]["rows_before_quarantine_floor_dropped"]["trades"] == 0
    # Decided before any market value is read (exit 2): a wrong register sha256 of the Gate 1 result, a DRAFT spec, a missing channel.
    bad = list(args)
    bad[bad.index("--expect-gate1-result-sha256") + 1] = "0" * 64
    assert cli.main(bad) == 2
    refusal = json.loads(capsys.readouterr().out)
    assert refusal["market_data_read"] is False and any(x.startswith("gate1_result_sha256_mismatch") for x in refusal["device"])
    # CTO m5: the Gate 1 result is a tape run of the FROZEN Gate 1 (a synthetic study or a DRAFT spec: refused before reading).
    for kwargs, reason in (({"mode": "synthetic"}, "is not a tape run"), ({"status": "DRAFT_NOT_FROZEN"}, "not the FROZEN Gate 1")):
        result_file = gate1_result_file(tmp_path / f"run1-{reason[:6].strip()}" / "g5.json", **kwargs)
        a = list(args)
        a[a.index("--gate1-result") + 1], a[a.index("--expect-gate1-result-sha256") + 1] = str(result_file), tape.sha256_of(result_file)
        assert cli.main(a) == 2
        assert any(reason in x for x in json.loads(capsys.readouterr().out)["device"])
    # A refused Gate 1 run (no market data read, every type INCONCLUSIVE) inscribes every type; its effect is written in the report.
    refused_result = gate1_result_file(tmp_path / "run1-refused" / "g5.json", read=False)
    a = list(args)
    a[a.index("--gate1-result") + 1], a[a.index("--expect-gate1-result-sha256") + 1] = str(refused_result), tape.sha256_of(refused_result)
    assert cli.main(a) == 0
    capsys.readouterr()
    written = json.loads(out.read_text())["gate1_result"]
    assert written["market_data_read"] is False and written["note"] == g5_gate2.GATE1_REFUSED_NOTE and g5_gate2.GATE1_REFUSED_NOTE in (tmp_path / "run2" / "g5.md").read_text()
    draft_doc = copy.deepcopy(document)
    draft_doc.update({"status": "DRAFT_NOT_FROZEN", "spec_version": "0.9-draft"})
    p = tmp_path / "draft.json"
    p.write_text(json.dumps(draft_doc))
    a = list(args)
    a[a.index("--spec") + 1], a[a.index("--expect-spec-sha256") + 1] = str(p), tape.sha256_of(p)
    assert cli.main(a) == 2 and any(x.startswith("status_not_frozen") for x in json.loads(capsys.readouterr().out)["device"])
    thin = tmp_path / "thin"
    write_capture(thin, days=1, channels=("trades", "books5"))
    a = list(args)
    a[a.index("--tape") + 1] = str(thin)
    assert cli.main(a) == 2 and any("capture_manifest_missing" in x for x in json.loads(capsys.readouterr().out)["device"])
    # The evaluator never reads a path under the Gate 1 sealed/ directory; the P3 counts file must match its sha256 (refuse_run).
    sealed = tmp_path / "run1" / "sealed" / "T01_funding_8h.json"
    sealed.parent.mkdir()
    sealed.write_text("{}")
    a = list(args)
    a[a.index("--gate1-result") + 1] = str(sealed)
    with pytest.raises(SystemExit, match="sealed"):
        cli.main(a)
    p3.write_text("changed")
    with pytest.raises(SystemExit, match="p3"):
        cli.main(args)
    with pytest.raises(SystemExit, match="capture"):  # Gate 2 options refused with a history spec
        cli.main(["g5-eval", "--tape", str(root), "--spec", str(GATE1_SPEC), "--expect-spec-sha256", "0" * 64, "--gate1-result", str(gate1_result), "--out-json", str(tmp_path / "x.json")])
    capsys.readouterr()


# ----------------------------------------------------------------------------- D10: index source chosen on quality (P-G5-10 (ii), 2026-10-21)

D10 = g5_gate2.INDEX_CHOICE_D10
A_SRC, B_SRC = g5_gate2.INDEX_OPTION_A, g5_gate2.INDEX_OPTION_B
GATE1_PASS = {"T01_funding_8h": "G5_GATE1_PASS", "T05_us_open": "G5_GATE1_PASS"}


def add_index_ticks(root: Path, rel: float, hole: tuple | None = None, days: int = 3, index_id: str = "BTC-USD") -> None:
    """The captured `index_tick` layout (D10 (a)): a push every 30 s at 100.1 × (1 + rel) (the index candles of `write_capture` close at
    100.1: |a − b| / b = rel); `hole` = (start, end) µs without any push."""
    store = tape.TapeStore(root, "values")
    day0 = tape.parse_iso_utc("2026-11-02T00:00:00Z")
    for sec in range(0, days * tape.DAY_S, 30):
        ts = day0 + sec * US
        if hole is None or not hole[0] <= ts < hole[1]:
            store.append("index_tick", index_id, ts, (ts, ts + 1000, 100.1 * (1.0 + rel)))
    store.checkpoint()
    store.finalize_days_before(tape.day_of_date("2026-11-08"), force=True)


@pytest.fixture(scope="module")
def d10_base(tmp_path_factory) -> Path:
    root = tmp_path_factory.mktemp("d10") / "base"
    write_capture(root)
    return root


def d10_root(base: Path, tmp_path: Path, rel: float, hole: tuple | None = None) -> Path:
    import shutil

    root = tmp_path / "tape"
    shutil.copytree(base, root)
    add_index_ticks(root, rel, hole)
    return root


def d10_series(root: Path, spec) -> dict:
    return {BTC: g5_eval.load_capture_series(root, spec.instrument(BTC), spec, {"instId": BTC})}


def test_d10_selection_rule_is_read_strictly_and_frozen_only_with_option_b_fetchable(monkeypatch):
    """D10 replaces owner_decision_pending of P-G5-10 (ii): the block is decided, its selection_rule read key by key and text by text, the
    owner's thresholds (98 %, 0.5 bp) are the only ones accepted; a FROZEN spec choosing D10 needs option (b) fetchable (CTO M3)."""
    spec, _ = g5_eval.load_spec(GATE2_SPEC)
    assert spec.gate2["index_choice"] == D10 and spec.raw["classifier_index_source"]["status"] == "owner_decided"
    assert spec.raw["owner_decisions"]["pending"][1]["status"] == "owner_decided" and spec.raw["owner_decisions"]["pending"][0]["status"] == "owner_decided"  # D7 (i) too
    rule = spec.raw["classifier_index_source"]["selection_rule"]
    assert (rule["coverage_min_share"], rule["agreement_max_median_abs_bp"]) == (0.98, 0.5) and rule["sources"] == [A_SRC, B_SRC]
    sel = lambda d: d["classifier_index_source"]["selection_rule"]  # noqa: E731
    for mutate, match in (
        (lambda d: sel(d).update({"coverage_min_share": 0.97}), "owner's D10 values"),
        (lambda d: sel(d).update({"agreement_max_median_abs_bp": 1.0}), "owner's D10 values"),
        (lambda d: sel(d).update({"rule": sel(d)["rule"].replace("otherwise b_history", "otherwise a_capture")}), "implemented rule"),
        (lambda d: sel(d).update({"sources": [B_SRC, A_SRC]}), "sources"),
        (lambda d: sel(d).update({"tie_break": "a"}), "unknown key"),
        (lambda d: sel(d).pop("forbidden"), "missing"),
        (lambda d: d["classifier_index_source"].pop("selection_rule"), "selection_rule: missing"),
        (lambda d: d["classifier_index_source"]["options"][A_SRC].update({"idx_minute": "first idxPx of the minute"}), "implemented rule"),
    ):
        refused(mutate, match)
    d10_frozen = frozen(copy.deepcopy(BASE))
    resolve(d10_frozen, index=D10)
    assert parse(d10_frozen).gate2["index_choice"] == D10  # since 2026-10-21: the fetcher of (b) exists (g5_index_fetch)
    monkeypatch.setattr(g5_gate2, "INDEX_FROZEN_ALLOWED", ())  # without it, refused (CTO M3)
    with pytest.raises(g5_eval.SpecError, match="D10 needs option b"):
        parse(d10_frozen)
    monkeypatch.setattr(g5_gate2, "INDEX_FROZEN_ALLOWED", (B_SRC,))  # (b) alone admitted: D10 follows
    assert parse(d10_frozen).gate2["index_choice"] == D10


def test_d10_choice_rule_on_coverage_and_agreement_only():
    """The rule alone (no T01 read): (a) when its coverage >= 98 % and the median |a − b| <= 0.5 bp on the common minutes, else (b); the
    record is hashed and its tampering detected."""
    n = 1000
    b = np.full(n, 100.0)
    a_good = b * (1 + 0.3e-4)  # 0.3 bp
    choice = g5_gate2.select_index_source({"BTC-USD": (a_good, b), "ETH-USD": (a_good.copy(), b.copy())})
    assert choice["primary"] == A_SRC and choice["sensitivity"] == B_SRC and choice["conditions"] == {"coverage_met": True, "agreement_met": True}
    assert choice["measures"]["coverage_a"] == 1.0 and choice["measures"]["median_abs_diff_bp"] == pytest.approx(0.3) and choice["measures"]["common_pairs"] == 2 * n
    assert g5_gate2.verify_index_selection(choice) == [] and len(choice["sha256"]) == 64
    exactly = a_good.copy()
    exactly[:20] = np.nan  # 98.0 % exactly: the rule is met (>=, compared as a fraction)
    assert g5_gate2.select_index_source({"BTC-USD": (exactly, b)})["primary"] == A_SRC
    holed = a_good.copy()
    holed[:21] = np.nan  # 97.9 %: (b) by coverage, whatever the agreement
    by_cov = g5_gate2.select_index_source({"BTC-USD": (holed, b)})
    assert by_cov["primary"] == B_SRC and by_cov["conditions"] == {"coverage_met": False, "agreement_met": True}
    by_gap = g5_gate2.select_index_source({"BTC-USD": (b * (1 + 0.6e-4), b)})  # 0.6 bp: (b) by disagreement
    assert by_gap["primary"] == B_SRC and by_gap["conditions"] == {"coverage_met": True, "agreement_met": False}
    no_b = g5_gate2.select_index_source({"BTC-USD": (a_good, np.full(n, np.nan))})  # no common minute: agreement undefined, rule not met
    assert no_b["primary"] == B_SRC and no_b["measures"]["median_abs_diff_bp"] is None
    tampered = {**choice, "primary": B_SRC}
    assert g5_gate2.verify_index_selection(tampered) and g5_gate2.verify_index_selection(None)
    assert g5_gate2.verify_index_selection({**by_gap, "measures": {**by_gap["measures"], "median_abs_diff_bp": 0.1}}) == ["the recorded index choice does not match its sha256"]


@pytest.mark.parametrize("case", ["a", "b_by_coverage", "b_by_disagreement"])
def test_d10_choice_on_a_synthetic_capture(d10_base: Path, tmp_path: Path, case: str):
    """End to end on a capture: the index_tick layout (a) and the index_1m candles (b) are both loaded; the choice on the window minutes of
    T01 puts the primary in the series before any evaluation; the record (sha256) is in the result."""
    day0 = tape.parse_iso_utc("2026-11-02T00:00:00Z")
    rel, hole, expected = {"a": (0.1e-4, None, A_SRC), "b_by_coverage": (0.1e-4, (day0 + 10 * HOUR_US, day0 + 12 * HOUR_US), B_SRC),
                           "b_by_disagreement": (1e-4, None, B_SRC)}[case]
    root = d10_root(d10_base, tmp_path, rel, hole)
    spec = short_spec(index=D10)
    series = d10_series(root, spec)
    assert np.isnan(series[BTC].index).all() and series[BTC].coverage["index_minutes_by_source"][A_SRC] > 0
    selection = g5_gate2.apply_index_selection(series, spec)
    m = selection["measures"]
    assert selection["primary"] == expected and m["pairs"] == 3 * 1440 and m["window_utc"] == ["2026-11-02T00:00:00Z", "2026-11-05T00:00:00Z"]
    if case == "b_by_coverage":
        # no push in [10:00 ; 12:00): g = 10:00 still reads the 09:59:30 push, g = 10:01 .. 11:59 are missing (119 minutes, 97.2 %)
        assert m["pairs_a"] == 3 * 1440 - 119 and m["coverage_a"] < 0.98 and m["median_abs_diff_bp"] == pytest.approx(0.1, abs=1e-6)
    elif case == "b_by_disagreement":
        assert m["coverage_a"] == 1.0 and m["median_abs_diff_bp"] == pytest.approx(1.0, abs=1e-6)
    else:
        assert m["coverage_a"] == 1.0 and m["median_abs_diff_bp"] == pytest.approx(0.1, abs=1e-6)
    s = series[BTC]
    assert s.cache["index_source_used"] == expected and np.array_equal(s.index, s.cache["index_sources"][expected], equal_nan=True)
    doc = g5_eval.run_study(series, spec, {"gate1_verdicts": GATE1_PASS, "index_selection": selection})
    index = doc["summary"]["gate2"]["index"]
    assert index["primary"] == expected and index["source_used"] == expected and index["selection_sha256"] == selection["sha256"] and index["state"] == "available"


def test_d10_t01_is_never_evaluated_without_a_recorded_choice(d10_base: Path, tmp_path: Path):
    root = d10_root(d10_base, tmp_path, 0.1e-4)
    spec = short_spec(index=D10)
    series = d10_series(root, spec)
    with pytest.raises(ValueError, match="not evaluated without a recorded index choice"):
        g5_eval.run_study(series, spec, {"gate1_verdicts": GATE1_PASS})  # no choice recorded
    selection = g5_gate2.apply_index_selection(series, spec)
    for bad in ({**selection, "primary": B_SRC}, {**selection, "sha256": "0" * 64}, {**selection, "rule": {**selection["rule"], "coverage_min_share": 0.5}}):
        with pytest.raises(ValueError, match="not evaluated without a recorded index choice"):
            g5_eval.run_study(series, spec, {"gate1_verdicts": GATE1_PASS, "index_selection": bad})
    g5_gate2.use_index_source(series, B_SRC)  # the series carry the other source than the recorded primary: refused as well
    with pytest.raises(ValueError, match="series without the primary index source"):
        g5_eval.run_study(series, spec, {"gate1_verdicts": GATE1_PASS, "index_selection": selection})
    doc = g5_eval.run_study(series, spec, {"gate1_verdicts": GATE1_PASS, "index_selection": selection, "index_role": "sensitivity"})  # the reported sensitivity
    assert doc["summary"]["gate2"]["index"]["role"] == "sensitivity" and doc["summary"]["gate2"]["index"]["source_used"] == B_SRC


def test_d10_the_command_line_records_the_choice_before_t01_and_reports_the_sensitivity(d10_base: Path, tmp_path: Path, capsys, index_fetcher_exists):
    """FROZEN spec choosing D10 (option b's fetcher simulated): the choice file `<out>.index-selection.json` is written before the study, its
    sha256 is in the result next to the source used for T01; the other source is reported as a sensitivity of T01, never decisive."""
    root = d10_root(d10_base, tmp_path, 1e-4)  # 1 bp apart: (b) is the primary, (a) the sensitivity
    document = short(frozen(copy.deepcopy(BASE)), "2026-11-05")
    resolve(document, index=D10)
    document["freeze_fill"]["t05_end_inclusive_utc"] = "2026-11-04"
    document["universe"]["analysed"] = [item(BTC, "2026-09-01T00:00:00Z"), item(ETH, "2026-09-01T00:00:00Z")]  # ETH: no row captured (pairs without any source)
    snapshot = tmp_path / "snapshot.json"
    snapshot.write_text(json.dumps({"code": "0", "data": [{"instId": u["inst_id"], "instType": "FUTURES", "ruleType": "xperp", "state": "live", "uly": u["index_id"], "ctVal": "0.0001",
                                                           "tickSz": "0.1", "lotSz": "1", "minSz": "1", "listTime": str(tape.parse_iso_utc("2026-09-01T00:00:00Z") // 1000)}
                                                          for u in document["universe"]["analysed"]]}))
    p3 = tmp_path / "p3.json"
    p3.write_text("{}")
    gate1_spec = tmp_path / "gate1-frozen.json"
    gate1_spec.write_text(FROZEN_GATE1_TEXT)
    gate1_result = tmp_path / "run1" / "g5.json"
    gate1_result.parent.mkdir()
    gate1_result.write_text(json.dumps({"kind": "lp-g5-eval", "spec": {"sha256": FROZEN_GATE1_SHA, "spec_id": "LP-G5-GATE1-2026", "status": "FROZEN", "source": "history"},
                                        "summary": {"mode": "tape", "verdicts": GATE1_PASS}}))
    document["freeze_fill"].update({"instruments_snapshot_path": str(snapshot), "instruments_snapshot_sha256": tape.sha256_of(snapshot),
                                    "p3_counts_selection_sha256": tape.sha256_of(p3), "code_digest_sha256": cli.code_digest()})
    spec_path = tmp_path / "gate2.json"
    spec_path.write_text(json.dumps(document, indent=1))
    out = tmp_path / "run2" / "g5.json"
    args = ["g5-eval", "--tape", str(root), "--spec", str(spec_path), "--expect-spec-sha256", tape.sha256_of(spec_path), "--gate1-result", str(gate1_result),
            "--expect-gate1-result-sha256", tape.sha256_of(gate1_result), "--p3-counts-file", str(p3), "--gate1-spec", str(gate1_spec),
            "--expect-index-tape-sha256", g5_fetch.directory_digest(root / "index_1m")[0], "--out-json", str(out),
            "--calendar-snapshots", str(final_calendar_snapshot(tmp_path / "calendars"))]
    assert cli.main(args) == 0
    capsys.readouterr()
    recorded = json.loads((tmp_path / "run2" / "g5.index-selection.json").read_text())
    result = json.loads(out.read_text())
    assert recorded["primary"] == B_SRC and recorded["measures"]["index_ids"] == ["BTC-USD", "ETH-USD"] and g5_gate2.verify_index_selection(recorded) == [] and result["index_selection"]["sha256"] == recorded["sha256"]
    assert result["summary"]["gate2"]["index"]["source_used"] == B_SRC and result["summary"]["gate2"]["index"]["selection_sha256"] == recorded["sha256"]
    sens = result["index_sensitivity"]
    assert sens["source"] == A_SRC and sens["role"] == "sensitivity_reported_never_decisive" and sens["selection_sha256"] == recorded["sha256"]
    assert sens["T01_funding_8h"]["verdict_label"].startswith("G5_GATE2_") and result["types"]["T01_funding_8h"]["verdict_label"].startswith("G5_GATE2_")


# ----------------------------------------------------------------------------- the freeze controls of 2026-10-22 (owner decision (a); Annexe B 11, 13, 14, 16)


def test_the_nyse_holiday_generator_and_the_calendar_content_test():
    """Annexe B request 13 (review-b quant M4): the rule-based NYSE holidays (Easter, the nth weekdays, the observed days, New Year's Day
    on a Saturday not observed), the monthly CPI / Employment Situation dates, a FOMC date every 56 days, the dated and sourced
    exceptions, the half days and the us_cash_close session moved to 13:00 ET on them."""
    rule = g5_eval.nyse_holidays_by_rule
    assert g5_eval.easter_sunday(2026) == date(2026, 4, 5) and g5_eval.easter_sunday(2027) == date(2027, 3, 28) and g5_eval.easter_sunday(2024) == date(2024, 3, 31)
    assert sorted(rule(2026)) == [date(2026, 1, 1), date(2026, 1, 19), date(2026, 2, 16), date(2026, 4, 3), date(2026, 5, 25), date(2026, 6, 19), date(2026, 7, 3),
                                  date(2026, 9, 7), date(2026, 11, 26), date(2026, 12, 25)]
    assert date(2027, 6, 18) in rule(2027) and date(2027, 7, 5) in rule(2027) and date(2027, 12, 24) in rule(2027)  # Saturday -> Friday, Sunday -> Monday
    assert date(2021, 12, 31) not in rule(2021) and not any(d.month == 1 and d.day <= 2 for d in rule(2022))  # New Year's Day 2022 (Saturday) not observed
    assert date(2022, 6, 20) in rule(2022) and not any(d.month == 6 and d.day in (18, 19, 20) for d in rule(2021))  # Juneteenth from 2022 only
    frozen_doc = frozen(copy.deepcopy(BASE))
    spec = parse(frozen_doc)
    assert spec.audit["calendar_content"]["problems"] == [] and spec.audit["calendar_content"]["range"] == ["2026-11-04", "2027-03-31"]
    cal, sessions = spec.calendars, spec.sessions

    def problems(first="2026-11-02", last="2027-03-31", **changes):
        return g5_eval.calendar_content({**cal, **changes}, sessions, date.fromisoformat(first), date.fromisoformat(last))["problems"]

    assert problems() == []
    assert problems(cpi=cal["cpi"] - {date(2027, 1, 13)}) == ["calendars.cpi: no date in 2027-01 (R = 2026-11-02 .. 2027-03-31) and no dated, sourced exception"]
    exception = {"list": "cpi", "period": (2027, 1), "change": "no_release_in_month", "written_utc": "2026-11-20T10:00:00Z"}
    assert problems(cpi=cal["cpi"] - {date(2027, 1, 13)}, content_exceptions=(exception,)) == []  # a dated, sourced exception (postponement, shutdown)
    assert "is excepted but the list holds a date" in problems(content_exceptions=(exception,))[0]
    assert problems(fomc_statement=frozenset({date(2026, 12, 9), date(2027, 3, 17)})) == ["calendars.fomc_statement: no date in the 56-day span 2026-12-10 .. 2027-02-03"]
    assert problems(nyse_holidays=cal["nyse_holidays"] - {date(2027, 1, 18)}) == ["calendars.nyse_holidays: 2027-01-18 missing (rule-based generator, CALENDAR_CONTENT_RULE)"]
    extra = cal["nyse_holidays"] | {date(2027, 1, 11)}
    assert "2027-01-11 is not a rule-based NYSE holiday" in problems(nyse_holidays=extra)[0]
    closure = {"list": "nyse_holidays", "period": date(2027, 1, 11), "change": "extra_closure", "written_utc": "2027-01-05T10:00:00Z"}
    assert problems(nyse_holidays=extra, content_exceptions=(closure,)) == []  # a national day of mourning, written and sourced
    assert problems(nyse_half_days=frozenset({date(2026, 11, 28)})) == ["calendars.nyse_half_days: 2026-11-28 is not a NYSE trading day"]
    plain = tuple({**s, "half_day_min": None} for s in sessions)
    assert "no session carries on_nyse_half_days" in g5_eval.calendar_content(cal, plain, date(2026, 11, 2), date(2027, 3, 31))["problems"][0]
    # The half day moves the us_cash_close exclusion to 13:00 New York (18:00 UTC in winter); the other sessions keep their time.
    closes = sorted(a for a, b, n in g5_eval.session_intervals(spec, tape.parse_iso_utc("2026-11-27T00:00:00Z"), tape.parse_iso_utc("2026-11-28T00:00:00Z"))
                    if n == "us_cash_close" and tape.iso_utc(a)[:10] == "2026-11-27")
    assert [tape.iso_utc(a)[11:16] for a in closes] == ["18:00"]
    ordinary = [a for a, b, n in g5_eval.session_intervals(spec, tape.parse_iso_utc("2026-11-30T00:00:00Z"), tape.parse_iso_utc("2026-12-01T00:00:00Z"))
                if n == "us_cash_close" and tape.iso_utc(a)[:10] == "2026-11-30"]
    assert [tape.iso_utc(a)[11:16] for a in ordinary] == ["21:00"]
    # Exceptions are read strictly: a known list, a month (cpi / es) or a date (holidays), a dated text, a source.
    for bad, match in (({"list": "cpi", "period": "2027-01-13", "change": "no_release_in_month", "written_utc": "2026-11-20T10:00:00Z", "source": "s", "reason": "r"}, "YYYY-MM"),
                       ({"list": "cpi", "period": "2027-01", "change": "extra_closure", "written_utc": "2026-11-20T10:00:00Z", "source": "s", "reason": "r"}, "not implemented"),
                       ({"list": "cpi", "period": "2027-01", "change": "no_release_in_month", "written_utc": "yesterday", "source": "s", "reason": "r"}, "ISO"),
                       ({"list": "cpi", "period": "2027-01", "change": "no_release_in_month", "written_utc": "2026-11-20T10:00:00Z", "reason": "r"}, "missing")):
        refused(lambda d: d["calendars"].update({"content_exceptions": [bad]}), match)
    # The problems of a draft are reported, not enforced; since 2026-10-22 (0.4.1-draft) the Gate 1 lists carry the CPI of October 2026
    # (2026-10-14, bls.gov) and pass; removing it brings the problem back.
    gate1 = g5_eval.load_spec(GATE1_SPEC)[0]
    assert gate1.audit["calendar_content"]["problems"] == [] and date(2026, 10, 14) in gate1.calendars["cpi"]
    assert g5_eval.calendar_content({**gate1.calendars, "cpi": gate1.calendars["cpi"] - {date(2026, 10, 14)}}, gate1.sessions, date(2026, 4, 28), date(2026, 10, 13))["problems"] == [
        "calendars.cpi: no date in 2026-10 (R = 2026-04-28 .. 2026-10-13) and no dated, sourced exception"]


def test_freeze_pending_markers_and_the_economic_floor_at_both_gates():
    """Annexe B requests 14 and 16: a FROZEN spec (either gate) with a key or a value containing `freeze_pending:` is refused; S_eco >= 97.25
    at both gates (GATES 1.2 amended, D4), at every status."""
    assert g5_eval.marker_paths({"a": ["x", "freeze_pending: y"], "freeze_pending: k": 1, "b": {"c": "freeze_pending without colon"}}) == [
        "spec.a[1]", "spec.freeze_pending: k (key)"]
    gate1 = copy.deepcopy(FROZEN_GATE1)
    assert g5_eval.parse_spec(copy.deepcopy(gate1)).frozen
    gate1["universe"]["selection_bias_declared"] += " ; freeze_pending: to write"
    with pytest.raises(g5_eval.SpecError, match=r"freeze_pending: in a FROZEN spec at \['spec.universe.selection_bias_declared'\]"):
        g5_eval.parse_spec(gate1)
    draft1 = json.loads(GATE1_SPEC.read_text())
    draft1["universe"]["selection_bias_declared"] += " ; freeze_pending: to write"
    assert g5_eval.parse_spec(draft1).notes["freeze_pending_markers"] == ["spec.universe.selection_bias_declared"]  # a draft keeps it, listed
    for document in (json.loads(GATE1_SPEC.read_text()), copy.deepcopy(BASE)):
        document["economic"]["s_eco_usd_per_year"] = 97.0
        with pytest.raises(g5_eval.SpecError, match="97.25"):
            g5_eval.parse_spec(document)
    raised = copy.deepcopy(BASE)
    raised["economic"]["s_eco_usd_per_year"] = 98.6  # the owner may raise S_eco (365 / 360 reading) before a freeze: both specs together
    with pytest.raises(g5_eval.SpecError, match="frozen_with: economic differs"):
        g5_eval.parse_spec(raised)


def frozen_gate2_for(gate1_document: dict, gate1_sha: str) -> dict:
    """A FROZEN Gate 2 document frozen with a (synthetic) FROZEN Gate 1 document: the shared blocks the identity check compares are copied
    from that Gate 1 (floor, statistics), its sha256 written in freeze_fill (gate2_frozen_together, Annexe B request 11)."""
    document = frozen(copy.deepcopy(BASE))
    document["power_floor"].update({k: gate1_document["power_floor"][k] for k in ("min_instants", "min_days")})
    document["statistics"]["bootstrap"].update({k: gate1_document["statistics"]["bootstrap"][k] for k in ("resamples", "seed", "block_days")})
    c = document["events"][1]["window_end"]["options"]["c_end_by_calendar_rule"]
    c["rule"] = c["rule"].replace("reaches 100,", f"reaches {gate1_document['power_floor']['min_instants']},")
    for event in document["events"]:
        event.pop("audit_expected", None)
    document["freeze_fill"]["gate1_spec_sha256"] = gate1_sha
    return document


def test_the_gate1_reads_its_gate2_before_any_market_value(tmp_path: Path):
    """Annexe B request 11 (`gate2_frozen_together`, review-b quant B1): the Gate 1 side of frozen_with. `check_frozen_together` admits a
    FROZEN Gate 2 that names the Gate 1 read by its sha256 and passes the strict reader against it; it lists a problem otherwise (absent,
    not FROZEN, owner_decision_pending, freeze_pending: marker, null, another Gate 1 sha256). The repository's Gate 1 names the Gate 2 file."""
    gate1_spec = g5_eval.parse_spec(copy.deepcopy(FROZEN_GATE1))
    assert gate1_spec.raw["frozen_with"] == {"spec": g5_eval.GATE2_SPEC_PATH, "spec_id": "LP-G5-GATE2-2026", "rule": g5_eval.GATE1_FROZEN_WITH_RULE}
    assert "gate2_frozen_together" in gate1_spec.requires and gate1_spec.notes["frozen_with"]["spec"] == g5_eval.GATE2_SPEC_PATH
    path = tmp_path / "g2.json"

    def check(document) -> dict:
        path.write_text(json.dumps(document) if isinstance(document, dict) else document)
        return g5_gate2.check_frozen_together(gate1_spec, FROZEN_GATE1_SHA, path)

    good = check(frozen(copy.deepcopy(BASE)))
    assert good["problems"] == [] and good["sha256"] == tape.sha256_of(path) and good["status"] == "FROZEN" and good["gate1_spec_sha256"] == FROZEN_GATE1_SHA
    for mutate, match in ((lambda d: d.update({"status": "DRAFT_NOT_FROZEN"}), "not FROZEN"),
                          (lambda d: d["freeze_fill"].update({"gate1_spec_sha256": "9" * 64}), "is not the sha256"),
                          (lambda d: d["quality"].update({"status": "freeze_pending: to write"}), "freeze_pending: at"),
                          (lambda d: d["freeze_fill"].update({"decision_ref_d1_capture": None}), "null at"),
                          (lambda d: d["events"][1]["window_end"].update({"status": PENDING, "choice": PENDING}), "owner_decision_pending at"),
                          (lambda d: d["costs"].update({"taker_fee_bp": 4.0}), "strict Gate 2 reader refuses it"),
                          (lambda d: d.update({"spec_id": "LP-G5-GATE2-OTHER"}), "is not the Gate 2 of frozen_with")):
        document = frozen(copy.deepcopy(BASE))
        mutate(document)
        assert any(re.search(match, p) for p in check(document)["problems"]), match
    assert "not JSON" in check("{")["problems"][0]
    assert "absent" in g5_gate2.check_frozen_together(gate1_spec, FROZEN_GATE1_SHA, tmp_path / "none.json")["problems"][0]
    # The repository's Gate 2 draft is refused as the Gate 2 of a Gate 1 run (DRAFT, nulls, another Gate 1 sha256; adapted 2026-10-22:
    # no freeze_pending: marker is left since 0.1.7-draft, the eight choices being written in the protocol body).
    real = g5_gate2.check_frozen_together(gate1_spec, FROZEN_GATE1_SHA, GATE2_SPEC)
    assert real["sha256"] == tape.sha256_of(GATE2_SPEC) and real["status"] == "DRAFT_NOT_FROZEN" and len(real["problems"]) >= 3
    assert not any("freeze_pending" in p for p in real["problems"]) and any("not FROZEN" in p for p in real["problems"])


def test_no_test_can_open_a_socket():
    with pytest.raises(AssertionError):
        socket.create_connection(("127.0.0.1", 9))
    with pytest.raises(AssertionError):
        urllib.request.urlopen("https://eea.okx.com/api/v5/public/time")
    assert xc.QUARANTINE_FLOOR == "2026-11-02T00:00:00Z" and g5_fetch.END_EXCLUSIVE == "2026-10-14T00:00:00Z"
