"""The strict reader of the G5 **Gate 2** machine spec `specs/g5_gate2_spec.json` (schema `g5-spec/2`, `source: capture`) and the
Gate 2 helpers of the evaluator (LP-G5 §2.3 (B) xxi, §5.5, §8, §10, Annexe B). `g5_eval.parse_spec` dispatches a capture spec here;
the measures, the simulation (`measure_lp_sim`), the study and the command line stay in `g5_eval`.

What this module enforces (fail-closed, `SpecError`):

- every key known at every level, every text that states a rule equal to the implemented rule, `requires` equal to
  `g5_eval.CAPTURE_REQUIRES` (B xviii);
- **owner decisions** (`owner_decisions_resolved_required_for_freeze`): the three decision blocks — `events[T05_us_open].window_end`
  (P-G5-10 (i)), `classifier_index_source` (P-G5-10 (ii)), `universe.p_s3_track_s` (P-S3) — carry `status` / `choice`
  (`owner_decision_pending`, or `owner_decided` with one of their options); a `FROZEN` spec is refused while the string
  `owner_decision_pending` or a `null` remains anywhere in the document (the spec's own note), while `calendars.incomplete_at_draft`
  is not empty, or while a declared-but-unimplemented path is chosen (index option (a) alone: not the owner's decision, D10 reaches it
  through its selection rule only; the REST funding fallback: `g5_fetch` cannot read after END_EXCLUSIVE, a FROZEN spec writes
  `costs.funding.fallback` as `none…`; the draft text of `costs.funding.source`, which attributes a settled rate to its own
  fundingTime against docs-v5, CTO B1). Index option (b), and D10 which needs (b) as comparison and fallback, are admitted at
  FROZEN since 2026-10-21 (`INDEX_FROZEN_ALLOWED`, CTO M3 closed): `g5_index_fetch` reads history-index-candles of the Gate 2
  period once, after the window, from the FROZEN spec only. At `DRAFT_NOT_FROZEN` the spec is accepted and every type of a
  study is `G5_GATE2_INCONCLUSIVE` (`owner_decision_pending_present`) while a decision block is pending;
- **owner decisions D7 and D8** (2026-10-21): `events[T05_us_open].window_end` = `a_end_2027_03_31` (no extension; T05 below the
  power floor by construction, `power_by_construction`, stated in the audit, the notes and the T05 verdict reasons) and
  `universe.p_s3_track_s` = `moot_track_s_closed` (track S closed, P-S3 moot, BTC and ETH stay at both gates);
- **identity with the Gate 1** (`frozen_with`, `gate1_identity`, piège 7): the fields each type names in `gate1_identity`, and the
  sessions, macro releases, eligibility, unit, floor, α, threshold, bootstrap, margin, fees, stress multiplier, design costs,
  Holm family, m_max, C4, C5, C7 and the economic constants are equal to those of the Gate 1 spec named by `frozen_with.spec`
  (or given by `--gate1-spec`); a `FROZEN` Gate 2 is refused unless that Gate 1 is `FROZEN` too and, read from a file, its sha256
  is `freeze_fill.gate1_spec_sha256` (protocol §13 point 5, CTO M2);
- **calcul d'audit** on the spec's own lists (no data): T01 150 calendar days, 449 raw instants and f_inst 0.223 from T0 =
  2026-11-02; T05 NYSE days 102 / 110 (T0 2026-11-02) and 99 / 107 (T0 2026-11-05); the options' NYSE-day counts; the control
  hours of the three DST regimes (`controls_common.audit_expected_utc`, computed by the evaluator's own control rule); the UTC
  notes of the macro releases and of T05, and the DST dates (zoneinfo, New York checked against `events.us_dst_active`).

**Owner decisions of 2026-10-22.** (a) « Finir les contrôles G5 réalisables dès maintenant » (Annexe B requests 11, 13, 14, 16): a
`FROZEN` spec is refused while a key or a value contains the marker `freeze_pending:` (`g5_eval.refuse_freeze_pending`) or while its calendars
fail the content test over [T0 ; last scored day + data-gap extension] (`g5_eval.calendar_content`, `CALENDAR_CONTENT_RULE`); S_eco >= 97.25
at both gates (`g5_eval.check_s_eco`); `check_frozen_together` is the Gate 1 side of `frozen_with` (`gate2_frozen_together`: the Gate 1 run
reads this file before any market value). (b) « Choisir la traversée comme remplissage principal du Gate 2, et garder le simple contact comme
comparaison descriptive »: `execution_sim.entry_fill_rule` = `through` (`PRIMARY_FILL_RULE`), `touch` among the descriptive rules
(`fill_rules_touch_and_queue`), a comparison that never decides and never rescues a FAIL, a NOT_INFORMATIVE or an INCONCLUSIVE.

**P-L2-1 (D14, 2026-10-22) and protocol 10.5.** `pilot_separation` (capability `pilot_separation_floor`): the L2 capture pilot's
development window [2026-11-02T00:00Z ; 2026-11-03T00:00Z) + 12 h margin is kept out of every observation the Gate 2 uses; the block
equals `separation.BLOCK` at FROZEN, `window.start` and `freeze_fill.t0_utc` are on or after 2026-11-04 (first 00:00 UTC at or after the
floor + 540 min), the first observation of every type (`first_observation_by_type_utc`) is after the floor, and `g5_eval.load_capture_series`
drops the excluded rows. `calendars.versioning` (capability `calendars_versioned_as_of_union`) equals `calendar_versions.VERSIONING_BLOCK`:
dates changed after the freeze are read from dated, hashed official snapshots, classified as of each decision instant, and an instant is
kept only if every version admits it; a FROZEN run needs a final snapshot (`g5-eval --calendar-snapshots`).

Runtime helpers: T0 and the per-type window ends (with the data-gap extension), the unusable instrument-days (`quality`), the
inscription read from the Gate 1 result (its sha256, kind, mode and FROZEN spec; the effect of a refused Gate 1 run written, CTO m5),
the capture manifest check, the index tape against its register sha256 (option b, CTO M3), the universe against the freeze snapshot;
**D10** (owner decision of 2026-10-21 on P-G5-10 (ii)): `classifier_index_source.selection_rule` read strictly, the per-minute index of
each source (`index_minutes_from_ticks` for the captured `index_tick` layout, `index_minutes_from_candles` for `index_1m`), the coverage
of (a) and the agreement between (a) and (b) on the common minutes, the choice of the primary source and its sha256
(`select_index_source`, `verify_index_selection`), computed before any T01 evaluation (`apply_index_selection`); `g5_eval.run_study`
refuses a D10 spec without a recorded, verified choice."""

from __future__ import annotations

import hashlib
import json
import re
from datetime import date as Date
from datetime import datetime, timedelta
from pathlib import Path

import numpy as np

from . import calendar_versions as cv
from . import events as ev
from . import separation as sep
from . import g5_eval as g5
from . import g5_fetch, lp_sim, tape
from . import xperp_capture as xc
from .g5_eval import SpecError, _bool, _check_keys, _choice, _exact, _int, _int_pair, _iso_us, _list, _num, _str_list, _text
from .g5_eval import _date as _parse_date

GATE = "G5_GATE2_"
GATE1_SPEC_PATH = "python-orchestrator/scripts/research_lp/specs/g5_gate1_spec.json"
PENDING = "owner_decision_pending"
DECIDED = "owner_decided"
DECISION_PATHS = ("events[T05_us_open].window_end", "classifier_index_source", "universe.p_s3_track_s")
INDEX_OPTIONS = ("a_capture_index_tickers", "b_history_index_candles_after_window")
INDEX_OPTION_A = "a_capture_index_tickers"
INDEX_OPTION_B = "b_history_index_candles_after_window"
# D10 (owner, 2026-10-21): both sources, the primary chosen by `selection_rule` on coverage and agreement only, before any T01 evaluation.
INDEX_CHOICE_D10 = "d10_both_primary_by_quality_rule"
INDEX_CHOICES = INDEX_OPTIONS + (INDEX_CHOICE_D10,)
INDEX_IMPLEMENTED = (INDEX_OPTION_B, INDEX_CHOICE_D10)  # (b) from an index_1m tape; D10 from index_tick (capture) and index_1m
# CTO M3: the options a FROZEN spec may choose. Option (b) reads history-index-candles of the Gate 2 period: g5_fetch cannot (its EndGuard
# drops every row at or after END_EXCLUSIVE 2026-10-14, a module constant, untouched); the dedicated fetcher `g5_index_fetch` (2026-10-21)
# does, once, after the end of the window, from the FROZEN spec whose sha256 is given, inside [first t_arm - 480 min - margin ; window end),
# never before the quarantine floor, with a hashed receipt whose index_1m digest is the --expect-index-tape-sha256 of g5-eval. D10 needs (b)
# as comparison and fallback: admitted with it.
INDEX_FROZEN_ALLOWED: tuple = (INDEX_OPTION_B, INDEX_CHOICE_D10)
INDEX_B_NOT_FETCHABLE = ("classifier_index_source: the choice is not admitted at FROZEN (INDEX_FROZEN_ALLOWED): option b_history_index_candles_after_window "
                         "needs its dedicated fetcher (g5_index_fetch: FROZEN spec and its sha256, after the end of the window, window guard "
                         "[first t_arm - 480 min - margin ; window end), hashed receipt ; CTO M3)")
T05_END_OPTIONS = ("a_end_2027_03_31", "b_end_2027_04_12", "c_end_by_calendar_rule")
# D8 (owner, 2026-10-21): track S closed without follow-up, P-S3 moot, BTC and ETH stay in the universe at both gates (neither alpha nor beta).
P_S3_MOOT = "moot_track_s_closed"
P_S3_CHOICES = ("alpha", "beta", P_S3_MOOT)
P_S3_SCOPES = ("gate1_only", "gate1_and_gate2")
GATES_1_3_LAST_SCORED_DAY = Date(2027, 3, 31)  # GATES §1.3: end of scoring <= 2027-03-31 (and D1: capture until then at the latest)
NOT_EVALUATED = "NOT_EVALUATED"

# Texts that state an implemented rule (copied from the spec, matched exactly).
QUARANTINE_RULE = ("nothing timestamped in [end_exclusive_utc ; quarantine_floor_utc) is ever read ; the Gate 2 reads capture rows with ts >= quarantine_floor_utc only "
                   "(xperp_capture.QUARANTINE_FLOOR and g5_fetch.END_EXCLUSIVE, module constants, checked for equality)")
WINDOW_TEXTS = {
    "start_rule": ("T0 = first 00:00 UTC >= max(quarantine_floor_utc, pilot_separation.g5_gate2_t0_min_utc, freeze of this spec, end of P3) ; written in "
                   "freeze_fill.t0_utc ; days captured before T0 are never scored"),
    "lookback_before_t0": ("allowed for the classifier only (the 480 min of mark and index before t_arm), never before quarantine_floor_utc nor before "
                           "pilot_separation.separation_floor_utc, never scored"),
    "instant_in_window_if": "the whole footprint of the instant lies in [T0 ; end_exclusive of its type)",
}
DATA_GAP_RULE = "one day added per unusable day (quality.unusable_instrument_day), never on a result"
DATA_GAP_BEYOND = "only under the same two decisions as P-G5-10 (i) option b ; otherwise unusable days are not replaced and are counted"
OWNER_RULE = ("every value owner_decision_pending is replaced by the owner's dated choice (text copied in freeze_fill and in the register journal) before status FROZEN ; "
              "the evaluator refuses a FROZEN spec that still contains owner_decision_pending anywhere")
FREEZE_FILL_KEYS = ("t0_utc", "gate1_spec_sha256", "instruments_snapshot_path", "instruments_snapshot_sha256", "p3_counts_selection_sha256", "calendars_verified_utc",
                    "code_digest_sha256", "decision_ref_d1_capture", "decision_ref_p_g5_10_i", "decision_ref_p_g5_10_ii", "decision_ref_p_s3", "t05_end_inclusive_utc")
ALWAYS_INCLUDED = "BTC-USD_UM_XPERP-<...> and ETH-USD_UM_XPERP-<...> (G2 3.5), subject to universe.p_s3_track_s"
ANALYSED_RULE = "list of {inst_id, index_id, list_time_utc, ct_val, tick_sz, lot_sz, min_sz} copied from the freeze snapshot ; index_id = uly"
UNIVERSE_MAX = 40  # G2 §3.4 ceiling, universe.rule
PRICE = {"source": "last", "series": "capture trades layout: price of the last print with ts in (t - 60 s ; t]",
         "boundary_convention": "c(t) = last print of the minute ending at t (Gate 1: close of the 1m candle opening at t - 60 s)", "mixing_sources": "refused",
         "mark_and_index_use": "classifier_premium_proxy_only", "book_use": "simulated execution only (entry, fills, exit) ; never a reference price",
         "missing_key_price": "exclude_instrument_instant_and_count", "forward_fill": "none", "above_max_missing_share": "exclude_instrument_from_type_and_count"}
FROZEN_CANDLE = {"rule": "exclude_instrument_instant_if_no_print_in_any_key_minute", "key_candles": "events[].key_candles_end_min (minute bars built from prints)",
                 "report": "share_by_instrument"}
COSTS_MEASURED = {"adverse_selection": "measured_in_simulation", "taker_half_spread": "measured_in_simulation"}
STRESS_RULE = ("fees and slippage x {m:g} ; the measured terms are inside the simulated prices and cannot be multiplied there, so the stressed net also subtracts {k:g} x "
               "the Gate 1 design value of each measured term: {a:g} bp per filled maker leg (adverse selection {as_:g} bp) and {h:g} bp per taker entry (half spread {hs:g} bp)")
DESIGN_USE = "descriptive net_bp and stress add-on only"
# CTO B1: the draft's text attributes a settled rate to the message's own fundingTime, which docs-v5 contradicts ("If settState = settled, it is
# the funding rate that is being used for previous settlement cycle"; fundingTime is the upcoming settlement). The evaluator attributes it to the
# previous settlement (`g5_eval.attribute_settled_rates`); the draft text is accepted at DRAFT_NOT_FROZEN only (reported), a FROZEN spec states
# FUNDING_SOURCE (the schema-sample wording answers quant review-b M3: no rate value is read before the freeze).
FUNDING_SOURCE_DRAFT = ("capture funding-rate channel: settled rate of each settlement (settFundingRate with settState settled, attributed to fundingTime) ; field "
                        "semantics UNKNOWN, to verify on the documentation and the first capture before freeze")
FUNDING_SOURCE = ("capture funding-rate channel: settled rate of each settlement = settFundingRate of a message with settState settled, attributed to the previous "
                  "settlement of the instrument (the largest fundingTime carried by a message at or before it, below its own fundingTime and at or before its ts ; "
                  "docs-v5: if settState = settled, it is the funding rate used for the previous settlement cycle) ; settState processing ignored ; checked on the "
                  "documentation and on a schema sample of the capture (field names, settState values, fundingTime against ts ; no rate printed)")
FUNDING_SOURCE_DRAFT_STATUS = ("superseded (CTO B1): the draft text attributes the settled rate to fundingTime; g5_eval attributes it to the previous settlement "
                               "(docs-v5); a FROZEN spec states costs.funding.source = g5_gate2.FUNDING_SOURCE")
FUNDING = {"source": FUNDING_SOURCE,
           "formula": "funding_bp = -side * sum(settled rates of the instrument's settlements T with first_fill - execution_sim.funding_fill_grace_min < T <= exit) * 1e4",
           "unknown_rate": "observation excluded and counted", "cap_stress": "descriptive_only"}
FUNDING_FALLBACK_NOT_IMPLEMENTED = ("costs.funding.fallback: a REST read of the Gate 2 period is not implemented (g5_fetch persists nothing at or after END_EXCLUSIVE "
                                    "2026-10-14) and needs a new reading decision: an unknown settled rate excludes the observation (unknown_rate)")
SIM_TEXTS = {"stale_rule": "book older than stale_s at the decision: intention not placed, counted (as G2 section 5)",
             "post_only": "an order that would cross the opposite touch at activation is cancelled and counted (crossed)",
             "size": "lp_sim.InstrumentSpec.size_for_notional with ct_val, lot_sz, min_sz of the freeze snapshot ; size 0 = skipped_size, counted",
             "band_rejected": "counted (maxPxLmtPct), not modelled"}
INDEX_TEXTS = {"if_unresolved_or_unavailable": "T01_funding_8h = G5_GATE2_INCONCLUSIVE by construction (written in advance, protocol P-G5-10)",
               "mark_minute": "last markPx of the capture mark-price channel in (g - 60 s ; g] ; none = minute missing",
               "b_idx_minute": "close of the 1m index candle ending at g (Gate 1 convention)"}
# D10 selection rule: the parameters fixed by the owner and the texts of the implemented rule (matched exactly).
D10_COVERAGE_MIN_SHARE = 0.98
D10_AGREEMENT_MAX_MEDIAN_ABS_BP = 0.5
D10_KEYS = ("decision_ref", "status", "sources", "window_minutes", "coverage", "coverage_min_share", "agreement", "agreement_max_median_abs_bp", "rule", "sensitivity",
            "record", "forbidden")
D10_TEXTS = {
    "window_minutes": "every minute boundary g with T0 <= g < end_exclusive of T01_funding_8h (before any data-gap extension), for every distinct index_id of universe.analysed",
    "coverage": "share of the (index_id, g) pairs of window_minutes with an idx_minute from a_capture_index_tickers",
    "agreement": "median, over the (index_id, g) pairs of window_minutes where both sources have an idx_minute, of |idx_a(g) - idx_b(g)| / idx_b(g) x 1e4 (bp)",
    "rule": ("primary = a_capture_index_tickers if coverage >= coverage_min_share and agreement <= agreement_max_median_abs_bp (no common pair: agreement undefined, "
             "rule not met) ; otherwise b_history_index_candles_after_window"),
    "sensitivity": "the other source: T01 recomputed with it, reported as a sensitivity, never decisive, never used to choose",
    "record": ("the choice is computed from coverage and agreement only, hashed (sha256 of the canonical JSON of the rule, the measures and the primary) and written "
               "in the result before any T01 evaluation ; the evaluator refuses to evaluate T01 without a recorded choice"),
    "forbidden": "comparing the T01 results obtained with each source in order to choose",
}
INDEX_A_IDX_MINUTE = "last idxPx received in (g - 60 s ; g] ; none = minute missing"

CALENDAR_INCOMPLETE_KEYS = ("cpi", "employment_situation", "fomc_statement", "nyse_holidays", "ten_am_et_releases")
INCOMPLETE_RULE = "every list complete and calendars_verified_utc set before FROZEN ; the evaluator refuses a FROZEN spec whose incomplete_at_draft is not empty"
CRITERIA_TEXT = {
    "C1": "D point >= threshold_bp and LB90(D) > 0 (measure statistics.measure)",
    "C2": "mean event sim_net_bp (point, costs included) > 0",
    "C3": "mean event sim_net_bp under costs.stress_rule (point) > 0",
    "C4": g5.CRITERIA_TEXT["C4"],
    "C6": "T01 only: validation of the classifier against the funding-rate channel (events[].validation)",
    "C7": g5.CRITERIA_TEXT["C7"],
}
INCONCLUSIVE_GLOBAL = ("spec_sha256_mismatch", "code_digest_mismatch", "status_not_frozen", "freeze_fill_null", "owner_decision_pending_present",
                       "gate1_result_sha256_mismatch", "capture_manifest_missing")
UNUSABLE_SHARE_RE = re.compile(r"^unusable_instrument_day_share_gt_(?P<a>\d)_(?P<b>\d+)$")
INSCRIPTION = {"G5_GATE1_PASS": "evaluated", "G5_GATE1_NOT_INFORMATIVE": "evaluated_automatically_without_futility",
               "G5_GATE1_INCONCLUSIVE": "evaluated_automatically_without_futility",
               "G5_GATE1_FAIL": "not_evaluated (counts p = 1 in multiplicity.local_holm and in the programme family)"}
INSCRIPTION_CHECK = "sha256 of the Gate 1 result equal to the register line, else refuse_run"
SEALING_EVALUATOR_RULE = "the Gate 2 evaluator never reads a path under the Gate 1 sealed/ directory"
ECONOMIC = {**g5.ECONOMIC_FIXED,
            "fills_per_day": "admitted filled orders / number of days of the type's calendar (T01: calendar days of the T01 window ; T05: NYSE trading days of the T05 window, "
                             "macro days included in the denominator)",
            "measure": "absolute event sim_net_bp (round trip), never D"}
QUALITY_RE = re.compile(r"^books5 coverage \(minutes with >= 1 snapshot\) < (?P<cov>\d+(?:\.\d+)?) % or a stale period > (?P<stale>\d+(?:\.\d+)?) min crossing the "
                        r"instrument-day \(G2 4\.4, same capture\) ; removed before any computation and counted$")
LAYOUTS_READ = ("trades", "books5", "mark", "funding")
# Owner decision (b) of 2026-10-22: the through rule is the primary fill of the Gate 2 (the pessimistic bound, as the Gate 1 proxy), the touch rule
# (head of the queue, the optimistic bound) a descriptive comparison only.
PRIMARY_FILL_RULE = "through"
DESCRIPTIVE_FILL_RULE_REQUIRED = "touch"
FILL_RULE_DECISION_DATE = "2026-10-22"
CONTROL_WITHIN = ("spec_window", "spec_window, up to the T05 window_end")
DESCRIPTIVE_REPORTS = (
    "T02_funding_4h_settlements_premium_settstate_book", "T06_us_close_reversion", "T18_cme_cf_brr_window", "fill_rules_touch_and_queue",
    "premium_okx_channel_vs_premium_mark_index", "net_bp_gate1_construction_on_last_prints", "adverse_selection_measured_vs_design_3_5",
    "taker_half_spread_measured_vs_design_3", "T01_by_photo_hour_00_08_16", "T01_dose_abs_rate", "T05_horizons_15_30", "T05_ten_am_et_release_days",
    "dst_regime_winter_vs_summer", "by_month", "by_instrument", "by_sign", "instruments_per_instant_ge_3", "excluded_share_by_instrument", "block_days_7",
    "minute_zero_T07", "portfolio_reading")
DEFERRED_LABELS = ("T02", "T06", "T18")  # strata of §9.8 named by the Gate 2 reports (no test, no type)
POPULATION_TEXT = "raw {label} instrument-settlements (eligible, interval filter passed, settled rate present, classifier computable)"
GENERATOR_STREAM = "capture funding layout (fundingTime and nextFundingTime per instrument)"
REALIZED_RATE_DEFINITION = "settled rate of the settlement (costs.funding.source) ; never the predicted rate"
CONTROL_DAYS_RE = {"funding_settlements": re.compile(r"^days on which the instrument has >= 1 raw (?P<label>\S+) event \(before classifier and fill\)$"),
                   "local_time": re.compile(r"^days on which the instrument has a raw (?P<label>\S+) event$")}
COVERS_RULE = ("the evaluator refuses a spec whose scoring window (every events[].window_end and the data gap extension included) is not inside covers, and a FROZEN "
               "spec whose lists are not complete over covers")
RATE_CLASS_RE = re.compile(r"^\+1 if settled rate >= (?P<hi>\d+(?:\.\d+)?), -1 if <= (?P<lo>-\d+(?:\.\d+)?), 0 otherwise$")
TYPE_REQUIRED = ("type", "decisive", "primary", "gate1_identity", "execution_role", "expected_cost_bp", "expected_cost_note", "days_per_year", "window_rule", "window_end",
                 "generator", "footprint_min", "footprint_note", "arm_min", "entry", "exit", "return_window_min", "exit_until_min", "key_candles_end_min", "controls", "criteria")
TYPE_OPTIONAL = ("rest_until_min", "classifier", "fill_sim", "sign", "validation", "c7", "inconclusive_if", "audit_expected", "utc_time_note",
                 "adverse_selection_entry_leg")
IDENTITY_FIELDS = {"classifier": ("classifier",), "thresholds": ("classifier", "validation"), "sign rule": ("classifier", "sign"), "footprint": ("footprint",),
                   "arm_min": ("arm_min",), "rest_until_min": ("rest_until_min",), "return window": ("return_window",), "key candles": ("key_candles",),
                   "validation": ("validation",), "controls": ("controls",), "c7": ("c7",), "criteria": ("criteria",), "generator in New York time": ("generator",)}
IDENTITY_RE = re.compile(r"^same as g5_gate1_spec\.json events\[(?P<type>[A-Za-z0-9_]+)\]: (?P<fields>.+)$")
REGIME_RE = re.compile(r"^(?P<label>[a-z_]+?)_(?:(?P<t0>t0)|(?P<y1>\d{4})_(?P<m1>\d{2})_(?P<d1>\d{2}))_to_(?P<y2>\d{4})_(?P<m2>\d{2})_(?P<d2>\d{2})$|"
                       r"^(?P<label2>[a-z_]+?)_from_(?P<y3>\d{4})_(?P<m3>\d{2})_(?P<d3>\d{2})$")
HOURS_TEXT_RE = re.compile(r"^(?P<hours>\d{2}(?: \d{2})*) h(?: \((?P<rest>.*)\))?$")
TIMES_TEXT_RE = re.compile(r"^(?P<times>\d{2}:\d{2}(?: \d{2}:\d{2})*)(?: \((?P<rest>.*)\))?$")
OUT_CLAUSE_RE = re.compile(r"^(?P<what>.+?) out on (?P<kind>CPI / Employment Situation|FOMC) days$")
MACRO_NOTE_RE = re.compile(r"^EST until (?P<d1>\d{4}-\d{2}-\d{2}): CPI and Employment Situation (?P<a>\d{2}:\d{2}) UTC, FOMC (?P<b>\d{2}:\d{2}) UTC ; EDT from "
                           r"(?P<d2>\d{4}-\d{2}-\d{2}): (?P<c>\d{2}:\d{2}) and (?P<e>\d{2}:\d{2}) UTC \(.+\)$")
T05_NOTE_RE = re.compile(r"^(?P<a>\d{2}:\d{2}) UTC from T0 to (?P<d1>\d{4}-\d{2}-\d{2}), (?P<b>\d{2}:\d{2}) UTC from (?P<d2>\d{4}-\d{2}-\d{2}) \(.+\)$")
COVERS_RE = re.compile(r"^(?P<a>\d{4}-\d{2}-\d{2}) \.\. (?P<b>\d{4}-\d{2}-\d{2})\b")
C_RULE_RE = re.compile(r"^end = the NYSE trading day on which the count of NYSE days from T0 outside the CPI and Employment Situation lists reaches (?P<n>\d+), computed at "
                       r"freeze on the frozen and complete lists, written in freeze_fill\.t05_end_inclusive_utc$")
NYSE_AUDIT_RE = re.compile(r"^nyse_days_if_t0_(?P<y>\d{4})_(?P<m>\d{2})_(?P<d>\d{2})$")
TO_RE = re.compile(r"^to_(?P<y>\d{4})_(?P<m>\d{2})_(?P<d>\d{2})$")


def _dkey(match, prefix: str = "") -> Date:
    return Date(int(match.group(prefix + "y")), int(match.group(prefix + "m")), int(match.group(prefix + "d")))


def _day(d: Date) -> int:
    return tape.day_of_date(d.isoformat())


def _day_us(d: Date) -> int:
    return _day(d) * g5.DAY_US


# ----------------------------------------------------------------------------- owner decisions (B xxi)


def scan(document, value, path: str = "spec") -> list[str]:
    """Every path of `document` holding `value` (a string, or None for the nulls)."""
    out, stack = [], [(document, path)]
    while stack:
        item, where = stack.pop()
        if isinstance(item, dict):
            stack.extend((v, f"{where}.{k}") for k, v in item.items())
        elif isinstance(item, list):
            stack.extend((v, f"{where}[{i}]") for i, v in enumerate(item))
        elif (value is None and item is None) or (value is not None and item == value):
            out.append(where)
    return sorted(out)


def resolve(document: dict, path: str):
    """`a.b`, `events[<type>].c`: the block of a decision path."""
    node = document
    for part in path.split("."):
        m = re.match(r"^(?P<key>[a-z_0-9]+)(?:\[(?P<sel>[A-Za-z0-9_]+)\])?$", part)
        if m is None or not isinstance(node, dict) or m.group("key") not in node:
            raise SpecError(f"owner_decisions: path {path!r} resolves to nothing")
        node = node[m.group("key")]
        if m.group("sel"):
            hits = [x for x in node if isinstance(x, dict) and x.get("type") == m.group("sel")] if isinstance(node, list) else []
            if len(hits) != 1:
                raise SpecError(f"owner_decisions: path {path!r}: no single item {m.group('sel')}")
            node = hits[0]
    return node


def _decision(block, where: str, choices: tuple) -> str:
    """`status` / `choice` of a decision block: (pending, pending) or (owner_decided, one of `choices`)."""
    status = _choice(block, "status", where, (PENDING, DECIDED))
    choice = _choice(block, "choice", where, (PENDING,) + tuple(choices))
    if (status == PENDING) != (choice == PENDING):
        raise SpecError(f"{where}: status {status!r} and choice {choice!r} disagree")
    return choice


def _parse_owner_decisions(block, document: dict) -> dict:
    _check_keys(block, "owner_decisions", ("rule", "pending", "related_not_p_g5_10"))
    _exact(block, "rule", "owner_decisions", OWNER_RULE)
    seen = {}
    for name in ("pending", "related_not_p_g5_10"):
        for i, item in enumerate(_list(block, name, "owner_decisions", nonempty=False)):
            where = f"owner_decisions.{name}[{i}]"
            _check_keys(item, where, ("id", "subject", "path", "status"))
            _text(item, "id", where)
            _text(item, "subject", where)
            path = _choice(item, "path", where, DECISION_PATHS)
            if path in seen:
                raise SpecError(f"{where}: {path} listed twice")
            target = resolve(document, path)
            if _choice(item, "status", where, (PENDING, DECIDED)) != target.get("status"):
                raise SpecError(f"{where}: status {item['status']!r} differs from {path}.status {target.get('status')!r}")
            seen[path] = item["id"]
    missing = [p for p in DECISION_PATHS if p not in seen]
    if missing:
        raise SpecError(f"owner_decisions: the decision blocks {missing} are not listed")
    return seen


# ----------------------------------------------------------------------------- calendars (calcul d'audit, no data)


def nyse_days(calendars: dict, first: Date, last_inclusive: Date, exclude_macro: bool = False) -> list[Date]:
    out, d = [], first
    while d <= last_inclusive:
        if g5.day_matches("nyse_trading_days", d, calendars) and not (exclude_macro and (d in calendars["cpi"] or d in calendars["employment_situation"])):
            out.append(d)
        d += timedelta(days=1)
    return out


def t05_end_by_calendar_rule(calendars: dict, t0: Date, count: int, horizon_days: int = 400) -> Date | None:
    """Option (c) of P-G5-10 (i): the NYSE day on which the count of NYSE days from T0 outside the CPI / Employment Situation lists
    reaches `count` (None beyond the horizon)."""
    days = nyse_days(calendars, t0, t0 + timedelta(days=horizon_days), exclude_macro=True)
    return days[count - 1] if len(days) >= count else None


def _check_dst(block, t0: Date) -> dict:
    _check_keys(block, "calendars.dst", ("us_2026_end", "us_2027_start", "eu_uk_2027_start"))
    out = {}
    for key, tz_name in (("us_2026_end", g5.NEW_YORK), ("us_2027_start", g5.NEW_YORK), ("eu_uk_2027_start", "Europe/London")):
        text = _text(block, key, "calendars.dst")
        d = _parse_date(text[:10], f"calendars.dst.{key}")
        tz = g5._tz(tz_name, "calendars.dst")
        before = datetime(d.year, d.month, d.day, 12, tzinfo=tz) - timedelta(days=1)
        after = datetime(d.year, d.month, d.day, 12, tzinfo=tz)
        if before.utcoffset() == after.utcoffset():
            raise SpecError(f"calendars.dst.{key}: {d} is not a DST change in {tz_name} (zoneinfo)")
        if tz_name == g5.NEW_YORK and ev.us_dst_active(d) == ev.us_dst_active(d - timedelta(days=1)):
            raise SpecError(f"calendars.dst.{key}: {d} is not a DST change for events.us_dst_active")
        if "(before T0)" in text and not d < t0:
            raise SpecError(f"calendars.dst.{key}: {d} is not before T0 {t0}")
        out[key] = d
    return out


def _parse_calendars(block, window_start: Date) -> tuple[dict, dict]:
    extra = ("covers", "covers_rule", "incomplete_at_draft", "incomplete_rule", "dst", "versioning")
    calendars = g5._parse_calendars(block, extra)
    try:  # calendar dates changed after the freeze (protocol 2.4 row 15, 10.5): the implemented rule, every text equal
        cv.check_versioning_block(block["versioning"])
    except cv.SnapshotError as error:
        raise SpecError(str(error)) from None
    if not _text(block, "covers_rule", "calendars").startswith(COVERS_RULE):
        raise SpecError(f"calendars.covers_rule: {COVERS_RULE!r} is the implemented rule (calendars_cover_the_window)")
    m = COVERS_RE.match(_text(block, "covers", "calendars"))
    if m is None:
        raise SpecError("calendars.covers must start with 'YYYY-MM-DD .. YYYY-MM-DD'")
    covers = (_parse_date(m.group("a"), "calendars.covers"), _parse_date(m.group("b"), "calendars.covers"))
    for name in ("nyse_holidays", "cpi", "employment_situation", "fomc_statement", "nyse_half_days"):
        outside = sorted(d for d in calendars[name] if not covers[0] <= d <= covers[1])
        if outside:
            raise SpecError(f"calendars.{name}: {outside} outside calendars.covers {covers[0]} .. {covers[1]}")
    incomplete = block["incomplete_at_draft"]
    if not isinstance(incomplete, dict) or any(k not in CALENDAR_INCOMPLETE_KEYS for k in incomplete) or any(not isinstance(v, str) or not v.strip() for v in incomplete.values()):
        raise SpecError(f"calendars.incomplete_at_draft: an object of texts keyed by {CALENDAR_INCOMPLETE_KEYS}")
    _exact(block, "incomplete_rule", "calendars", INCOMPLETE_RULE)
    dst = _check_dst(block["dst"], window_start)
    return calendars, {"covers": covers, "covers_rule": block["covers_rule"], "incomplete": dict(incomplete), "dst": {k: v.isoformat() for k, v in dst.items()}}


def _check_macro_note(text: str, macro: tuple) -> None:
    m = MACRO_NOTE_RE.match(text)
    if m is None:
        raise SpecError("macro_filter.utc_note: 'EST until D: CPI and Employment Situation HH:MM UTC, FOMC HH:MM UTC ; EDT from D: HH:MM and HH:MM UTC (...)' is the implemented form")
    by_name = {r["name"]: r for r in macro}
    d1, d2 = _parse_date(m.group("d1"), "macro_filter.utc_note"), _parse_date(m.group("d2"), "macro_filter.utc_note")
    if d2 != d1 + timedelta(days=1) or ev.us_dst_active(d1) or not ev.us_dst_active(d2):
        raise SpecError(f"macro_filter.utc_note: {d1} / {d2} is not the US DST change")
    for day, release, hhmm in ((d1, "cpi", m.group("a")), (d1, "employment_situation", m.group("a")), (d1, "fomc_statement", m.group("b")),
                               (d2, "cpi", m.group("c")), (d2, "employment_situation", m.group("c")), (d2, "fomc_statement", m.group("e"))):
        r = by_name[release]
        got = g5._iso(g5.local_to_utc_us(day, r["time_min"], r["tz_info"]))[11:16]
        if got != hhmm:
            raise SpecError(f"macro_filter.utc_note: {release} on {day} is {got} UTC, not {hhmm}")


# ----------------------------------------------------------------------------- blocks


def _parse_window(block) -> dict:
    _check_keys(block, "window", ("start", "start_rule", "lookback_before_t0", "instant_in_window_if", "end_exclusive", "end_rule", "data_gap_extension"))
    for key, text in WINDOW_TEXTS.items():
        _exact(block, key, "window", text)
    start, end = _parse_date(block["start"], "window.start"), _parse_date(block["end_exclusive"], "window.end_exclusive")
    if _day_us(start) < g5.QUARANTINE_END_US:
        raise SpecError(f"window.start {start}: a capture window starts at or after the quarantine floor {xc.QUARANTINE_FLOOR}")
    if end <= start:
        raise SpecError("window.end_exclusive must be after window.start")
    if end - timedelta(days=1) > GATES_1_3_LAST_SCORED_DAY:
        raise SpecError(f"window.end_exclusive {end}: GATES 1.3 ends the scoring on {GATES_1_3_LAST_SCORED_DAY} (a later end is a per-type decision)")
    _text(block, "end_rule", "window")
    gap = _check_keys(block["data_gap_extension"], "window.data_gap_extension", ("rule", "max_days", "beyond_2027_03_31"))
    _exact(gap, "rule", "window.data_gap_extension", DATA_GAP_RULE)
    _exact(gap, "beyond_2027_03_31", "window.data_gap_extension", DATA_GAP_BEYOND)
    return {"start": start, "end_exclusive": end, "max_gap_days": _int(gap, "max_days", "window.data_gap_extension", 0, 60), "end_rule": block["end_rule"]}


def _parse_freeze_fill(block, status: str, window_start: Date) -> dict:
    _check_keys(block, "freeze_fill", FREEZE_FILL_KEYS + ("rule",))
    out = {"rule": _text(block, "rule", "freeze_fill")}
    for key in FREEZE_FILL_KEYS:
        value = block[key]
        if value is None:
            out[key] = None
            continue
        if not isinstance(value, str) or not value.strip():
            raise SpecError(f"freeze_fill.{key}: expected a non-empty string or null")
        if key.endswith("sha256") and not g5.SHA256_RE.match(value):
            raise SpecError(f"freeze_fill.{key}: expected a lower-case sha256")
        if key in ("t0_utc", "calendars_verified_utc"):
            _iso_us(value, f"freeze_fill.{key}")
        if key == "t05_end_inclusive_utc":
            _parse_date(value[:10], "freeze_fill.t05_end_inclusive_utc")
        out[key] = value
    out["null"] = [k for k in FREEZE_FILL_KEYS if out[k] is None]
    if status == "FROZEN" and out["null"]:
        raise SpecError(f"freeze_fill: a FROZEN spec has no null value ({out['null']}); {out['rule']}")
    if out["t0_utc"] is not None:
        t0 = _iso_us(out["t0_utc"], "freeze_fill.t0_utc")
        if t0 % g5.DAY_US or t0 < _day_us(window_start):
            raise SpecError(f"freeze_fill.t0_utc {out['t0_utc']}: T0 is a 00:00 UTC on or after window.start ({WINDOW_TEXTS['start_rule']})")
    return out


def _parse_universe(block, status: str, eligibility_days: float, end_us: int) -> tuple[tuple, dict]:
    _check_keys(block, "universe", ("rule", "always_included", "global_swaps", "liquidity_rule_g2_3_3", "analysed", "analysed_rule", "p_s3_track_s", "mismatch_with_snapshot"))
    _text(block, "rule", "universe")
    _exact(block, "always_included", "universe", ALWAYS_INCLUDED)
    _text(block, "global_swaps", "universe")
    if not _text(block, "liquidity_rule_g2_3_3", "universe").startswith("not applied"):
        raise SpecError("universe.liquidity_rule_g2_3_3: only 'not applied…' is implemented (protocol 10.1 names metadata, P3 counts and listTime + 30 d only)")
    _exact(block, "analysed_rule", "universe", ANALYSED_RULE)
    _choice(block, "mismatch_with_snapshot", "universe", ("refuse_run",))
    p = _check_keys(block["p_s3_track_s"], "universe.p_s3_track_s", ("status", "decision", "gate2_scope", "choice"))
    _text(p, "decision", "universe.p_s3_track_s")
    p_s3 = _decision(p, "universe.p_s3_track_s", P_S3_CHOICES)
    scope = _text(p, "gate2_scope", "universe.p_s3_track_s")
    if p_s3 == "alpha" and scope not in P_S3_SCOPES:
        raise SpecError(f"universe.p_s3_track_s: option alpha needs its Gate 2 scope ({P_S3_SCOPES}); {scope!r} leaves it undecided (protocol Annexe B)")
    removed = p_s3 == "alpha" and scope == "gate1_and_gate2"
    days_us = int(round(eligibility_days * g5.DAY_US))
    analysed, seen = [], set()
    if block["analysed"] is None:
        if status == "FROZEN":
            raise SpecError("universe.analysed: a FROZEN spec lists its instruments (analysed_rule)")
    else:
        items = _list(block, "analysed", "universe")
        if len(items) > UNIVERSE_MAX:
            raise SpecError(f"universe.analysed: {len(items)} instruments exceed the ceiling N <= {UNIVERSE_MAX} (G2 3.4)")
        for i, item in enumerate(items):
            where = f"universe.analysed[{i}]"
            _check_keys(item, where, ("inst_id", "index_id", "list_time_utc", "ct_val", "tick_sz", "lot_sz", "min_sz"))
            inst = _text(item, "inst_id", where)
            if not xc.XPERP_RE.match(inst) or inst in seen:
                raise SpecError(f"{where}.inst_id: {inst!r} is not a distinct X-Perp id")
            seen.add(inst)
            if _text(item, "index_id", where) != g5_fetch.index_id_of({"instId": inst}):
                raise SpecError(f"{where}.index_id: not the <BASE>-USD index of {inst}")
            listed = _iso_us(item["list_time_utc"], f"{where}.list_time_utc")
            if listed + days_us >= end_us:
                raise SpecError(f"{where}: no eligible minute before the end of the window")
            analysed.append(g5.Instrument(inst, item["index_id"], listed, listed + days_us, _num(item, "ct_val", where, 0.0, lo_open=True),
                                          _num(item, "tick_sz", where, 0.0, lo_open=True), _num(item, "lot_sz", where, 0.0, lo_open=True),
                                          _num(item, "min_sz", where, 0.0, lo_open=True)))
        majors = [b for b in ("BTC", "ETH") if not any(i.inst_id.startswith(f"{b}-USD_UM_XPERP-") for i in analysed)]
        if status == "FROZEN" and majors and not removed:
            raise SpecError(f"universe.analysed: {majors} absent while always_included ({ALWAYS_INCLUDED})")
        if removed and not all(b in majors for b in ("BTC", "ETH")):
            raise SpecError("universe.analysed: P-S3 option alpha with scope gate1_and_gate2 removes BTC and ETH from the Gate 2")
    return tuple(analysed), {"p_s3": p_s3, "p_s3_scope": scope, "btc_eth_removed": removed}


def _parse_costs(block, gate1_costs: dict | None, status: str = "DRAFT_NOT_FROZEN") -> dict:
    keys = ("maker_fee_bp", "taker_fee_bp", "slippage_bp_per_taker_leg", "stress_multiplier_all_cost_terms")
    _check_keys(block, "costs", keys + ("adverse_selection", "taker_half_spread", "stress_rule", "stress_rule_status", "design_values_gate1", "g2_table_substitution", "funding",
                                        "margin_opportunity_cost"))
    out = {k: _num(block, k, "costs", 0.0) for k in keys}
    mult = out["stress_multiplier_all_cost_terms"]
    if mult < 1.0:
        raise SpecError("costs.stress_multiplier_all_cost_terms must be >= 1")
    for key, prefix in COSTS_MEASURED.items():
        if not _text(block, key, "costs").startswith(prefix):
            raise SpecError(f"costs.{key}: only '{prefix}…' is implemented (the Gate 2 measures it in lp_sim, protocol 10.2)")
    design = _check_keys(block["design_values_gate1"], "costs.design_values_gate1", ("adverse_selection_bp_per_maker_leg", "taker_entry_half_spread_bp", "slippage_bp_per_round_trip", "use"))
    _exact(design, "use", "costs.design_values_gate1", DESIGN_USE)
    for key in ("adverse_selection_bp_per_maker_leg", "taker_entry_half_spread_bp", "slippage_bp_per_round_trip"):
        out[key] = _num(design, key, "costs.design_values_gate1", 0.0)
    add_maker, add_taker = (mult - 1.0) * out["adverse_selection_bp_per_maker_leg"], (mult - 1.0) * out["taker_entry_half_spread_bp"]
    expected = STRESS_RULE.format(m=mult, k=mult - 1.0, a=add_maker, as_=out["adverse_selection_bp_per_maker_leg"], h=add_taker, hs=out["taker_entry_half_spread_bp"])
    _exact(block, "stress_rule", "costs", expected)
    _text(block, "stress_rule_status", "costs")
    if not _text(block, "g2_table_substitution", "costs").startswith("none"):
        raise SpecError("costs.g2_table_substitution: only 'none…' is implemented")
    funding = _check_keys(block["funding"], "costs.funding", ("source", "fallback", "formula", "unknown_rate", "count_field", "cap_stress"))
    source_status = "implemented"
    if status != "FROZEN" and funding.get("source") == FUNDING_SOURCE_DRAFT:
        source_status = FUNDING_SOURCE_DRAFT_STATUS
    else:
        _exact(funding, "source", "costs.funding", FUNDING_SOURCE)
    for key, text in FUNDING.items():
        if key != "source":
            _exact(funding, key, "costs.funding", text)
    out.update({"stress_add_bp_per_maker_leg": add_maker, "stress_add_bp_per_taker_entry": add_taker, "funding_count_field": _text(funding, "count_field", "costs.funding"),
                "funding_fallback": _text(funding, "fallback", "costs.funding"), "g2_table_substitution": block["g2_table_substitution"],
                "margin_opportunity_cost": _choice(block, "margin_opportunity_cost", "costs", ("negligible_reported",)), "stress_rule_status": block["stress_rule_status"],
                "funding_source_status": source_status})
    if gate1_costs is not None:
        for g2_key, g1_key in (("maker_fee_bp", "maker_fee_bp"), ("taker_fee_bp", "taker_fee_bp"), ("stress_multiplier_all_cost_terms", "stress_multiplier_all_cost_terms"),
                               ("adverse_selection_bp_per_maker_leg", "adverse_selection_bp_per_maker_leg"), ("taker_entry_half_spread_bp", "taker_entry_half_spread_bp"),
                               ("slippage_bp_per_round_trip", "slippage_bp_per_round_trip")):
            if out[g2_key] != gate1_costs[g1_key]:
                raise SpecError(f"costs.{g2_key} {out[g2_key]:g} differs from the Gate 1 {g1_key} {gate1_costs[g1_key]:g} (frozen_with: the same design values)")
    return out


def _parse_execution_sim(block, notional: float) -> dict:
    keys = ("engine", "entry_fill_rule", "entry_fill_rule_decision", "descriptive_fill_rules", "latency_ms", "stale_s", "stale_rule", "post_only", "funding_fill_grace_min",
            "funding_fill_grace_rule", "order_notional_usd", "size", "book_layout", "rpi_bias", "band_rejected", "status_counts")
    _check_keys(block, "execution_sim", keys)
    if not _text(block, "engine", "execution_sim").startswith("lp_sim primitives"):
        raise SpecError("execution_sim.engine: the implemented engine is the lp_sim primitives (g5_eval.measure_lp_sim)")
    rule = _choice(block, "entry_fill_rule", "execution_sim", lp_sim.RULES)
    descriptive = _str_list(block, "descriptive_fill_rules", "execution_sim", lp_sim.RULES)
    if rule in descriptive:
        raise SpecError("execution_sim.descriptive_fill_rules: the primary rule is not a descriptive one")
    # Owner decision (b) of 2026-10-22: through primary, touch kept as a descriptive comparison (never decisive).
    if rule != PRIMARY_FILL_RULE or DESCRIPTIVE_FILL_RULE_REQUIRED not in descriptive:
        raise SpecError(f"execution_sim: entry_fill_rule {PRIMARY_FILL_RULE!r} with {DESCRIPTIVE_FILL_RULE_REQUIRED!r} among descriptive_fill_rules is the owner's "
                        f"decision of {FILL_RULE_DECISION_DATE} (got {rule!r}, {list(descriptive)})")
    if FILL_RULE_DECISION_DATE not in _text(block, "entry_fill_rule_decision", "execution_sim"):
        raise SpecError(f"execution_sim.entry_fill_rule_decision: the owner's dated text of {FILL_RULE_DECISION_DATE}")
    for key, text in SIM_TEXTS.items():
        _exact(block, key, "execution_sim", text)
    _text(block, "funding_fill_grace_rule", "execution_sim")
    _text(block, "rpi_bias", "execution_sim")
    if not _text(block, "book_layout", "execution_sim").startswith("books5"):
        raise SpecError("execution_sim.book_layout: the implemented book is books5 (bbo-tbt is not captured: xperp_capture.CHANNELS)")
    if _num(block, "order_notional_usd", "execution_sim", 0.0, lo_open=True) != notional:
        raise SpecError("execution_sim.order_notional_usd differs from economic.order_notional_usd")
    if tuple(_str_list(block, "status_counts", "execution_sim")) != g5.SIM_STATUS:
        raise SpecError(f"execution_sim.status_counts: {list(g5.SIM_STATUS)} are the implemented statuses")
    return {"rule": rule, "descriptive_rules": descriptive, "latency_ms": _num(block, "latency_ms", "execution_sim", 0.0), "stale_s": _num(block, "stale_s", "execution_sim", 0.0, lo_open=True),
            "funding_fill_grace_min": _num(block, "funding_fill_grace_min", "execution_sim", 0.0, 60.0), "notional": notional}


def _parse_index_source(block, status: str = "DRAFT_NOT_FROZEN") -> str:
    _check_keys(block, "classifier_index_source", ("decision", "status", "choice", "why", "options", "if_unresolved_or_unavailable", "mark_minute", "single_quantity"),
                ("selection_rule",))
    _exact(block, "decision", "classifier_index_source", "P-G5-10 (ii)")
    _text(block, "why", "classifier_index_source")
    _text(block, "single_quantity", "classifier_index_source")
    for key in ("if_unresolved_or_unavailable", "mark_minute"):
        _exact(block, key, "classifier_index_source", INDEX_TEXTS[key])
    options = _check_keys(block["options"], "classifier_index_source.options", INDEX_OPTIONS)
    for name in INDEX_OPTIONS:
        item = _check_keys(options[name], f"classifier_index_source.options.{name}", ("source", "idx_minute", "requires"))
        _text(item, "source", f"classifier_index_source.options.{name}")
        _text(item, "idx_minute", f"classifier_index_source.options.{name}")
        _str_list(item, "requires", f"classifier_index_source.options.{name}", nonempty=False)
    _exact(options["b_history_index_candles_after_window"], "idx_minute", "classifier_index_source.options.b_history_index_candles_after_window", INDEX_TEXTS["b_idx_minute"])
    _exact(options[INDEX_OPTION_A], "idx_minute", f"classifier_index_source.options.{INDEX_OPTION_A}", INDEX_A_IDX_MINUTE)
    choice = _decision(block, "classifier_index_source", INDEX_CHOICES)
    if choice not in (PENDING,) + INDEX_IMPLEMENTED:
        raise SpecError(f"classifier_index_source.choice {choice!r}: not the owner's decision (D10, 2026-10-21: option a is reached only through "
                        f"selection_rule, choice {INDEX_CHOICE_D10})")
    if choice == INDEX_CHOICE_D10:
        if "selection_rule" not in block:
            raise SpecError(f"classifier_index_source.selection_rule: missing (choice {INDEX_CHOICE_D10})")
        if "index-tickers" not in xc.CHANNELS or "index_tick" not in tape.LAYOUTS:
            raise SpecError("classifier_index_source: D10 needs the index-tickers channel and the index_tick layout in the code")
    if "selection_rule" in block:
        _parse_selection_rule(block["selection_rule"])
    if status == "FROZEN" and choice not in INDEX_FROZEN_ALLOWED and not (choice == INDEX_CHOICE_D10 and INDEX_OPTION_B in INDEX_FROZEN_ALLOWED):
        raise SpecError(INDEX_B_NOT_FETCHABLE + ("" if choice != INDEX_CHOICE_D10 else " ; D10 needs option b (comparison and fallback)"))
    return choice


def _parse_selection_rule(block) -> dict:
    """D10 (owner, 2026-10-21): the strict reading of `classifier_index_source.selection_rule` — every key known, every text equal to the
    implemented rule, the two thresholds equal to the owner's (98 % of the window minutes, 0.5 bp)."""
    where = "classifier_index_source.selection_rule"
    _check_keys(block, where, D10_KEYS)
    _text(block, "decision_ref", where)
    _text(block, "status", where)
    if list(_str_list(block, "sources", where)) != [INDEX_OPTION_A, INDEX_OPTION_B]:
        raise SpecError(f"{where}.sources: [{INDEX_OPTION_A}, {INDEX_OPTION_B}] (D10)")
    for key, text in D10_TEXTS.items():
        _exact(block, key, where, text)
    coverage = _num(block, "coverage_min_share", where, 0.0, 1.0)
    agreement = _num(block, "agreement_max_median_abs_bp", where, 0.0)
    if coverage != D10_COVERAGE_MIN_SHARE or agreement != D10_AGREEMENT_MAX_MEDIAN_ABS_BP:
        raise SpecError(f"{where}: coverage_min_share {coverage} / agreement_max_median_abs_bp {agreement} differ from the owner's D10 values "
                        f"({D10_COVERAGE_MIN_SHARE} / {D10_AGREEMENT_MAX_MEDIAN_ABS_BP})")
    return {"coverage_min_share": coverage, "agreement_max_median_abs_bp": agreement}


def _parse_quality(block) -> dict:
    _check_keys(block, "quality", ("unusable_instrument_day", "unusable_share_inconclusive", "classifier_missing_instrument_day_share_inconclusive", "status"))
    m = QUALITY_RE.match(_text(block, "unusable_instrument_day", "quality"))
    if m is None:
        raise SpecError("quality.unusable_instrument_day: not the implemented rule (books5 coverage < x % or a stale period > y min crossing the instrument-day)")
    _text(block, "status", "quality")
    return {"coverage_min": float(m.group("cov")) / 100.0, "stale_us": int(round(float(m.group("stale")) * g5.MINUTE_US)),
            "share_max": _num(block, "unusable_share_inconclusive", "quality", 0.0, 1.0), "classifier_missing_share_max": _num(block, "classifier_missing_instrument_day_share_inconclusive", "quality", 0.0, 1.0)}


def _parse_capture_block(block) -> dict:
    _check_keys(block, "capture", ("command", "channels_required", "channels_conditional", "channels_in_code_2026_10_21", "instruments_metadata", "layouts_read", "manifest", "register"))
    _text(block, "command", "capture")
    required = _str_list(block, "channels_required", "capture")
    missing = [c for c in required if c not in xc.CHANNELS]
    if missing:
        raise SpecError(f"capture.channels_required: {missing} are not channels of xperp_capture.CHANNELS {list(xc.CHANNELS)}")
    cond = block["channels_conditional"]
    if not isinstance(cond, dict) or any(not isinstance(v, str) for v in cond.values()):
        raise SpecError("capture.channels_conditional: an object of texts")
    captured = [c for c in cond if c in xc.CHANNELS]
    if captured:
        raise SpecError(f"capture.channels_conditional: {captured} are already channels of xperp_capture.CHANNELS (update the spec)")
    if list(_str_list(block, "channels_in_code_2026_10_21", "capture")) != list(xc.CHANNELS):
        raise SpecError(f"capture.channels_in_code_2026_10_21 is not the code's xperp_capture.CHANNELS {list(xc.CHANNELS)}")
    if tuple(_str_list(block, "layouts_read", "capture")) != LAYOUTS_READ:
        raise SpecError(f"capture.layouts_read: the evaluator reads {list(LAYOUTS_READ)} (and index_1m under P-G5-10 (ii) b, index_tick and index_1m under D10)")
    for key in ("instruments_metadata", "manifest"):
        _text(block, key, "capture")
    _str_list(block, "register", "capture")
    return {"channels_required": required, "layouts_read": LAYOUTS_READ}


def _parse_verdict(block, types: tuple) -> dict:
    _check_keys(block, "verdict", ("precedence", "inconclusive_global", "criteria", "pass", "fail", "fail_annotation", "closure_scope", "pass_effect"))
    if list(_str_list(block, "precedence", "verdict")) != [GATE + "INCONCLUSIVE", GATE + "NOT_INFORMATIVE", "criteria_then_holm"]:
        raise SpecError(f"verdict.precedence: only [{GATE}INCONCLUSIVE, {GATE}NOT_INFORMATIVE, criteria_then_holm] is implemented")
    conditions, unusable = [], None
    for cond in _str_list(block, "inconclusive_global", "verdict"):
        if (m := UNUSABLE_SHARE_RE.match(cond)) is not None:
            unusable = g5._share_of(m)
        elif cond not in INCONCLUSIVE_GLOBAL:
            raise SpecError(f"verdict.inconclusive_global: {cond!r} is not implemented")
        conditions.append(cond)
    missing = [c for c in INCONCLUSIVE_GLOBAL if c not in conditions]
    if missing or unusable is None:
        raise SpecError(f"verdict.inconclusive_global: {missing or ['unusable_instrument_day_share_gt_<x>']} missing")
    crit = block["criteria"]
    used = sorted({c for t in types for c in t.criteria}, key=g5.CRITERIA.index)
    if not isinstance(crit, dict) or sorted(crit, key=lambda c: g5.CRITERIA.index(c) if c in g5.CRITERIA else 99) != used:
        raise SpecError(f"verdict.criteria: keys must be the criteria the types use {used}")
    texts, trim = {}, None
    for key in used:
        text = _text(crit, key, "verdict.criteria")
        if key == "C5":
            m = g5.C5_RE.match(text)
            if m is None:
                raise SpecError("verdict.criteria.C5: not the implemented rule")
            trim = float(m.group("share"))
        elif text != CRITERIA_TEXT[key]:
            raise SpecError(f"verdict.criteria.{key}: {text!r} is not the implemented rule {CRITERIA_TEXT[key]!r}")
        texts[key] = text
    _exact(block, "pass", "verdict", f"{GATE}PASS(type) iff every criterion of the type holds and holm_local_confirmed(type)")
    _exact(block, "fail", "verdict", f"{GATE}FAIL(type) otherwise, once no inconclusive condition holds and the floor is met")
    ann = _check_keys(block["fail_annotation"], "verdict.fail_annotation", ("ub90_ge_threshold", "ub90_lt_threshold"))
    return {"conditions": tuple(conditions), "fetch_error_share_max": None, "unusable_share_max": unusable, "criteria_text": texts, "c5_trim_share": trim,
            "annotation_ge": _text(ann, "ub90_ge_threshold", "verdict.fail_annotation"), "annotation_lt": _text(ann, "ub90_lt_threshold", "verdict.fail_annotation"),
            "closure_scope": _text(block, "closure_scope", "verdict"), "pass_effect": _text(block, "pass_effect", "verdict")}


def _parse_multiplicity(block, types: tuple) -> dict:
    _check_keys(block, "multiplicity", ("local_holm", "programme", "forbidden_field_names"))
    lh = _check_keys(block["local_holm"], "multiplicity.local_holm", ("alpha", "family", "p_for_not_inscribed_inconclusive_or_not_informative", "field", "status"))
    family = _str_list(lh, "family", "multiplicity.local_holm")
    if list(family) != [t.name for t in types]:
        raise SpecError(f"multiplicity.local_holm.family must be the decisive types {[t.name for t in types]}")
    if _num(lh, "p_for_not_inscribed_inconclusive_or_not_informative", "multiplicity.local_holm", 0.0, 1.0) != 1.0:
        raise SpecError("multiplicity.local_holm.p_for_not_inscribed_inconclusive_or_not_informative: only 1.0 is implemented (GATES 2.1)")
    _exact(lh, "field", "multiplicity.local_holm", "holm_local_confirmed")
    _text(lh, "status", "multiplicity.local_holm")
    pr = _check_keys(block["programme"], "multiplicity.programme", ("m_max", "tests", "immediate_if_p_le", "otherwise", "field", "gate2_effect"))
    m_max = _int(pr, "m_max", "multiplicity.programme", 1)
    alpha = _num(lh, "alpha", "multiplicity.local_holm", 0.0, 1.0, lo_open=True)
    immediate = _num(pr, "immediate_if_p_le", "multiplicity.programme", 0.0, 1.0)
    if abs(immediate - alpha / m_max) > 1e-6:
        raise SpecError(f"multiplicity.programme.immediate_if_p_le {immediate:g} is not alpha / m_max")
    tests = _str_list(pr, "tests", "multiplicity.programme")
    if list(tests) != [f"G5-Gate2-{t.label}" for t in types]:
        raise SpecError(f"multiplicity.programme.tests: {[f'G5-Gate2-{t.label}' for t in types]} (one test per decisive type, GATES 2.3)")
    _text(pr, "otherwise", "multiplicity.programme")
    _exact(pr, "field", "multiplicity.programme", "pass_programme_immediate")
    _exact(pr, "gate2_effect", "multiplicity.programme", "none on the verdict of the type")
    forbidden = _str_list(block, "forbidden_field_names", "multiplicity")
    if "pass_programme" not in forbidden:
        raise SpecError("multiplicity.forbidden_field_names must forbid pass_programme (quant B4)")
    return {"alpha": alpha, "family": family, "m_max": m_max, "immediate_if_p_le": immediate, "otherwise": pr["otherwise"], "forbidden": forbidden, "tests": tests,
            "status": lh["status"]}


def _parse_sealing(block) -> dict:
    _check_keys(block, "sealing", ("gate1_sealed_files", "gate2_own_verdicts", "visibility"))
    g1 = _check_keys(block["gate1_sealed_files"], "sealing.gate1_sealed_files", ("files", "open_after", "evaluator_rule"))
    _text(g1, "files", "sealing.gate1_sealed_files")
    _text(g1, "open_after", "sealing.gate1_sealed_files")
    _exact(g1, "evaluator_rule", "sealing.gate1_sealed_files", SEALING_EVALUATOR_RULE)
    own = _check_keys(block["gate2_own_verdicts"], "sealing.gate2_own_verdicts", ("rule", "status"))
    if not _text(own, "rule", "sealing.gate2_own_verdicts").startswith("no sealing at Gate 2"):
        raise SpecError("sealing.gate2_own_verdicts.rule: only 'no sealing at Gate 2…' is implemented")
    _text(own, "status", "sealing.gate2_own_verdicts")
    _text(block, "visibility", "sealing")
    return {"verdicts": (), "printed": (), "sealed": (), "open_after": g1["open_after"], "gate1_open_after": g1["open_after"], "rule": own["rule"]}


def _parse_inscription(block) -> dict:
    _check_keys(block, "gate2_inscription", ("input", "check", "rule", "discretion", "interim_reading"))
    _text(block, "input", "gate2_inscription")
    _exact(block, "check", "gate2_inscription", INSCRIPTION_CHECK)
    rule = _check_keys(block["rule"], "gate2_inscription.rule", tuple(INSCRIPTION))
    out = {}
    for key, text in INSCRIPTION.items():
        _exact(rule, key, "gate2_inscription.rule", text)
        out[key[len("G5_GATE1_"):]] = text.split(" ", 1)[0]
    _choice(block, "discretion", "gate2_inscription", ("none",))
    if not _text(block, "interim_reading", "gate2_inscription").startswith("none"):
        raise SpecError("gate2_inscription.interim_reading: only 'none…' is implemented (a single run after the end of the last window)")
    return out


def _parse_economic(block) -> dict:
    keys = ("s_eco_usd_per_year", "capital_usd", "leverage", "max_live", "order_notional_usd") + tuple(ECONOMIC) + ("readings",)
    _check_keys(block, "economic", keys)
    for key, value in ECONOMIC.items():
        _exact(block, key, "economic", value)
    readings = _str_list(block, "readings", "economic")
    if len(readings) != 3 or not readings[0].startswith("PASS, poursuivi") or not readings[1].startswith("PASS, non poursuivi") or readings[2] != "FAIL":
        raise SpecError("economic.readings: [\"PASS, poursuivi\", \"PASS, non poursuivi (…)\", \"FAIL\"] (GATES 1.4)")
    out = {"s_eco": _num(block, "s_eco_usd_per_year", "economic", 0.0), "capital": _num(block, "capital_usd", "economic", 0.0, lo_open=True),
           "leverage": _num(block, "leverage", "economic", 0.0, lo_open=True), "max_live": _int(block, "max_live", "economic", 1),
           "notional": _num(block, "order_notional_usd", "economic", 0.0, lo_open=True), "readings": readings, "break_even": {}}
    g5.check_s_eco(out["s_eco"])  # Annexe B request 16
    if out["max_live"] * out["notional"] / out["leverage"] > out["capital"] + 1e-9:
        raise SpecError("economic: max_live × order_notional_usd / leverage exceeds capital_usd")
    return out


def _parse_descriptive(block) -> dict:
    _check_keys(block, "descriptive", ("rule", "reports", "excluded"))
    reports = _str_list(block, "reports", "descriptive", DESCRIPTIVE_REPORTS)
    return {"rule": _text(block, "rule", "descriptive"), "reports": reports, "excluded": _text(block, "excluded", "descriptive")}


# ----------------------------------------------------------------------------- types


def _parse_window_end(item: dict, where: str, label: str, window: dict, calendars: dict, power: dict) -> dict:
    block = item["window_end"]
    if label != "T05":
        b = _check_keys(block, f"{where}.window_end", ("end_inclusive", "end_exclusive"))
        inc, exc = _parse_date(b["end_inclusive"], f"{where}.window_end"), _parse_date(b["end_exclusive"], f"{where}.window_end")
        if exc != inc + timedelta(days=1) or exc != window["end_exclusive"]:
            raise SpecError(f"{where}.window_end: {inc} / {exc} must be the window end ({window['end_exclusive']}) and its eve")
        return {"choice": None, "end_inclusive": inc, "end_exclusive": exc, "beyond_gates_1_3": False, "provisional": False, "options": {}}
    _check_keys(block, f"{where}.window_end", ("decision", "status", "choice", "options"))
    _exact(block, "decision", f"{where}.window_end", "P-G5-10 (i)")
    options = _check_keys(block["options"], f"{where}.window_end.options", T05_END_OPTIONS)
    parsed = {}
    for name in T05_END_OPTIONS:
        o = options[name]
        w = f"{where}.window_end.options.{name}"
        if name == "c_end_by_calendar_rule":
            _check_keys(o, w, ("rule", "expected_end", "status", "consequence", "requires"))
            m = C_RULE_RE.match(_text(o, "rule", w))
            if m is None:
                raise SpecError(f"{w}.rule: not the implemented calendar rule")
            count = int(m.group("n"))
            if count != power["min_instants"]:
                raise SpecError(f"{w}.rule: the count {count} is the floor power_floor.min_instants {power['min_instants']}")
            for key in ("expected_end", "status", "consequence"):
                _text(o, key, w)
            parsed[name] = {"count": count, "requires": _str_list(o, "requires", w, nonempty=False)}
            continue
        _check_keys(o, w, ("end_inclusive", "end_exclusive", "nyse_days_if_t0_2026_11_02", "raw_instants_expected", "consequence", "requires"))
        inc, exc = _parse_date(o["end_inclusive"], f"{w}.end_inclusive"), _parse_date(o["end_exclusive"], f"{w}.end_exclusive")
        if exc != inc + timedelta(days=1):
            raise SpecError(f"{w}: end_exclusive is the day after end_inclusive")
        expected = len(nyse_days(calendars, Date(2026, 11, 2), inc))
        if _int(o, "nyse_days_if_t0_2026_11_02", w, 0) != expected:
            raise SpecError(f"{w}.nyse_days_if_t0_2026_11_02 = {o['nyse_days_if_t0_2026_11_02']} but the frozen holidays give {expected} (calcul d'audit)")
        _text(o, "raw_instants_expected", w)
        _text(o, "consequence", w)
        requires = _str_list(o, "requires", w, nonempty=False)
        if inc > GATES_1_3_LAST_SCORED_DAY and not requires:
            raise SpecError(f"{w}: an end after {GATES_1_3_LAST_SCORED_DAY} requires the GATES 1.3 exception and the D1 extension")
        parsed[name] = {"end_inclusive": inc, "end_exclusive": exc, "requires": requires}
    choice = _decision(block, f"{where}.window_end", T05_END_OPTIONS)
    out = {"choice": choice, "options": parsed, "provisional": choice == PENDING}
    if choice == PENDING:
        out.update({"end_inclusive": window["end_exclusive"] - timedelta(days=1), "end_exclusive": window["end_exclusive"], "beyond_gates_1_3": False})
    elif choice == "c_end_by_calendar_rule":
        out.update({"rule_count": parsed[choice]["count"], "beyond_gates_1_3": True})  # end computed from T0 in parse_capture_spec
    else:
        inc = parsed[choice]["end_inclusive"]
        out.update({"end_inclusive": inc, "end_exclusive": parsed[choice]["end_exclusive"], "beyond_gates_1_3": inc > GATES_1_3_LAST_SCORED_DAY})
    return out


def _parse_capture_type(item, position: int, ctx: dict) -> g5.TypeSpec:
    where = f"events[{position}]"
    _check_keys(item, where, TYPE_REQUIRED, TYPE_OPTIONAL)
    name = _text(item, "type", where)
    label = name.split("_", 1)[0]
    if not re.match(r"^T\d+$", label):
        raise SpecError(f"{where}.type: {name!r} must start with its T<nn> label")
    _bool(item, "decisive", where, only=True)
    _bool(item, "primary", where, only=True)
    m = IDENTITY_RE.match(_text(item, "gate1_identity", where))
    if m is None or m.group("type") != name:
        raise SpecError(f"{where}.gate1_identity: 'same as g5_gate1_spec.json events[{name}]: <fields>' is the implemented form")
    identity = [f.strip() for f in m.group("fields").split(",")]
    unknown = [f for f in identity if f not in IDENTITY_FIELDS]
    if unknown:
        raise SpecError(f"{where}.gate1_identity: {unknown} are not checkable fields ({sorted(IDENTITY_FIELDS)})")
    role = _choice(item, "execution_role", where, g5.ROLES)
    _text(item, "expected_cost_note", where)
    _text(item, "footprint_note", where)
    _choice(item, "window_rule", where, ("spec_window",))
    calendars, costs = ctx["calendars"], ctx["costs"]
    gen = item["generator"]
    kind = _choice(_check_keys(gen, f"{where}.generator", ("kind",), ("stream", "require_realized_rate", "realized_rate_definition", "interval_filter", "fallback_hours", "tz",
                                                                       "time", "days", "exclude_dates_ref")), "kind", f"{where}.generator", ("funding_settlements", "local_time"))
    if kind == "funding_settlements":
        _check_keys(gen, f"{where}.generator", ("kind", "stream", "require_realized_rate", "realized_rate_definition", "interval_filter", "fallback_hours"))
        _exact(gen, "stream", f"{where}.generator", GENERATOR_STREAM)
        _exact(gen, "realized_rate_definition", f"{where}.generator", REALIZED_RATE_DEFINITION)
        generator = g5._parse_generator({**{k: v for k, v in gen.items() if k != "realized_rate_definition"}, "stream": "funding"}, f"{where}.generator", "capture", calendars)
    else:
        generator = g5._parse_generator(gen, f"{where}.generator", "capture", calendars)
    footprint = _int_pair(item, "footprint_min", where)
    arm = _int(item, "arm_min", where)
    if not footprint[0] <= arm <= footprint[1] or arm == -1:
        raise SpecError(f"{where}.arm_min must lie inside the footprint and is never -1 (trap 4)")
    rest = None
    if role == "maker_maker":
        rest = _int(item, "rest_until_min", where)
        if not arm < rest <= footprint[1]:
            raise SpecError(f"{where}.rest_until_min must be in (arm_min, footprint end]")
    elif "rest_until_min" in item:
        raise SpecError(f"{where}.rest_until_min: a taker entry does not rest")
    if ("classifier" in item) == ("sign" in item):
        raise SpecError(f"{where}: exactly one of classifier and sign")
    classifier = g5._parse_classifier(item["classifier"], f"{where}.classifier", "capture", arm) if "classifier" in item else None
    sign = g5._parse_sign(item["sign"], f"{where}.sign", arm, footprint) if "sign" in item else None
    rw = _int_pair(item, "return_window_min", where)
    if rw[0] != arm or not footprint[0] <= rw[0] < rw[1] <= footprint[1]:
        raise SpecError(f"{where}.return_window_min: from the decision to an exit inside the footprint")
    until = _int(item, "exit_until_min", where)
    if until <= rw[1]:
        raise SpecError(f"{where}.exit_until_min must be after the passive exit start h{rw[1]:+d}")
    fill_sim = None
    if role == "maker_maker":
        if "fill_sim" not in item:
            raise SpecError(f"{where}: a maker_maker type needs fill_sim")
        f = _check_keys(item["fill_sim"], f"{where}.fill_sim", ("kind", "applies_to", "decided_at_min", "resting_until_min", "price", "rule", "unfilled", "fill_in_h_to_h_plus_1", "report"))
        _choice(f, "kind", f"{where}.fill_sim", ("lp_sim_post_only_touch",))
        if list(_str_list(f, "applies_to", f"{where}.fill_sim")) != ["events", "controls"]:
            raise SpecError(f"{where}.fill_sim.applies_to: events and controls alike")
        if _int(f, "decided_at_min", f"{where}.fill_sim") != arm or _int(f, "resting_until_min", f"{where}.fill_sim") != rest:
            raise SpecError(f"{where}.fill_sim: decided at arm_min, resting until rest_until_min")
        _exact(f, "price", f"{where}.fill_sim", "own touch at the decision (bid if s = +1, ask if s = -1), rounded to tick_sz")
        _exact(f, "rule", f"{where}.fill_sim", "execution_sim.entry_fill_rule")
        _exact(f, "unfilled", f"{where}.fill_sim", g5.FILL_PROXY_FIXED["unfilled"])
        _exact(f, "fill_in_h_to_h_plus_1", f"{where}.fill_sim", "crosses the settlement and pays (execution_sim.funding_fill_grace_min)")
        _exact(f, "report", f"{where}.fill_sim", g5.FILL_PROXY_FIXED["report"])
        fill_sim = {"kind": "lp_sim_post_only_touch", "decided_at_min": arm, "resting_until_min": rest}
    elif "fill_sim" in item:
        raise SpecError(f"{where}.fill_sim: a taker entry has no resting fill")
    entry = item["entry"]
    if role == "maker_maker":
        _check_keys(entry, f"{where}.entry", ("kind", "price"))
        _choice(entry, "kind", f"{where}.entry", ("post_only_touch",))
        _exact(entry, "price", f"{where}.entry", f"own touch at h{arm:+d} plus latency")
    else:
        _check_keys(entry, f"{where}.entry", ("kind", "price", "slippage_bp", "half_spread"))
        _choice(entry, "kind", f"{where}.entry", ("taker",))
        _exact(entry, "price", f"{where}.entry", f"opposite touch at h{arm:+d} plus latency")
        _exact(entry, "slippage_bp", f"{where}.entry", "costs.slippage_bp_per_taker_leg")
        _exact(entry, "half_spread", f"{where}.entry", "measured")
    ex = _check_keys(item["exit"], f"{where}.exit", ("kind", "passive_from_min", "passive_until_min", "price", "remainder"))
    _choice(ex, "kind", f"{where}.exit", ("passive_then_taker",))
    if _int(ex, "passive_from_min", f"{where}.exit") != rw[1] or _int(ex, "passive_until_min", f"{where}.exit") != until:
        raise SpecError(f"{where}.exit: passive from the return window end h{rw[1]:+d} until exit_until_min h{until:+d}")
    _exact(ex, "price", f"{where}.exit", f"opposite touch from max(h{rw[1]:+d}, fill + latency)" if role == "maker_maker" else f"opposite touch from h{rw[1]:+d}")
    _exact(ex, "remainder", f"{where}.exit", f"taker at h{until:+d}")
    keys = item["key_candles_end_min"]
    if not isinstance(keys, list) or not keys or any(isinstance(k, bool) or not isinstance(k, int) for k in keys) or len(set(keys)) != len(keys):
        raise SpecError(f"{where}.key_candles_end_min: expected distinct integers")
    keys = tuple(sorted(int(k) for k in keys))
    needed = {rw[0], rw[1]} | ({sign["from_min"], sign["to_min"]} if sign else set())
    if not needed <= set(keys) or any(not footprint[0] <= k <= footprint[1] for k in keys):
        raise SpecError(f"{where}.key_candles_end_min: must contain the ends of the return and sign windows, inside the footprint")
    criteria = _str_list(item, "criteria", where, g5.CRITERIA)
    if list(criteria) != [c for c in g5.CRITERIA if c in criteria] or not {"C1", "C2", "C3", "C4", "C5"} <= set(criteria):
        raise SpecError(f"{where}.criteria: C1-C5 are required, in order (C6, C7 for T01)")
    validation = None
    if "C6" in criteria:
        if classifier is None or "validation" not in item:
            raise SpecError(f"{where}: C6 validates the classifier")
        v = item["validation"]
        keys_v = ("criterion", "population", "classifier_class", "rate_class", "rate_source") + tuple(g5.VALIDATION_FIXED) + ("sensitivity_min", "false_positive_rate_max",
                                                                                                                             "min_denominator", "fail_or_not_computable")
        _check_keys(v, f"{where}.validation", keys_v)
        _choice(v, "criterion", f"{where}.validation", ("C6",))
        _exact(v, "population", f"{where}.validation", POPULATION_TEXT.format(label=label))
        for key, value in g5.VALIDATION_FIXED.items():
            _exact(v, key, f"{where}.validation", value)
        c = g5.CLASS_RE.match(_text(v, "classifier_class", f"{where}.validation"))
        if c is None or c.group("field") != "P_hat(t_arm)" or float(c.group("hi")) != classifier["high"] or float(c.group("lo")) != classifier["low"]:
            raise SpecError(f"{where}.validation.classifier_class: the classifier's own thresholds at t_arm")
        r = RATE_CLASS_RE.match(_text(v, "rate_class", f"{where}.validation"))
        if r is None:
            raise SpecError(f"{where}.validation.rate_class: '+1 if settled rate >= x, -1 if <= -y, 0 otherwise' is the implemented rule")
        _exact(v, "rate_source", f"{where}.validation", "costs.funding.source (funding-rate channel)")
        _exact(v, "fail_or_not_computable", f"{where}.validation", GATE + "INCONCLUSIVE")
        validation = {"rate_high": float(r.group("hi")), "rate_low": float(r.group("lo")), "sensitivity_min": _num(v, "sensitivity_min", f"{where}.validation", 0.0, 1.0),
                      "false_positive_rate_max": _num(v, "false_positive_rate_max", f"{where}.validation", 0.0, 1.0),
                      "min_denominator": _int(v, "min_denominator", f"{where}.validation", 1)}
    elif "validation" in item:
        raise SpecError(f"{where}.validation without C6")
    c7 = None
    if "C7" in criteria:
        b = _check_keys(item.get("c7"), f"{where}.c7", ("event_hour_utc", "controls", "require_point_gt_bp"))
        _choice(b, "controls", f"{where}.c7", ("same_utc_days",))
        c7 = {"event_hour_utc": _int(b, "event_hour_utc", f"{where}.c7", 0, 23), "require_point_gt_bp": _num(b, "require_point_gt_bp", f"{where}.c7")}
    elif "c7" in item:
        raise SpecError(f"{where}.c7 without C7")
    ct = _check_keys(item["controls"], f"{where}.controls", ("minute_of_hour", "days", "within", "default_settlement_grid_utc_hours"))
    dm = CONTROL_DAYS_RE[generator["kind"]].match(_text(ct, "days", f"{where}.controls"))
    if dm is None or dm.group("label") != label:
        raise SpecError(f"{where}.controls.days: not the implemented rule for a {generator['kind']} type")
    _choice(ct, "within", f"{where}.controls", CONTROL_WITHIN)
    controls = {"minute_of_hour": _int(ct, "minute_of_hour", f"{where}.controls", 0, 59),
                "grid_hours": g5._hours(ct["default_settlement_grid_utc_hours"], f"{where}.controls.default_settlement_grid_utc_hours"), "within": "spec_window"}
    inconclusive, missing_share = (), None
    if "inconclusive_if" in item:
        items = _str_list(item, "inconclusive_if", where)
        for cond in items:
            pm = g5.PROXY_MISSING_RE.match(cond)
            if pm is not None and pm.group("kind") == "classifier" and classifier is not None:
                missing_share = g5._share_of(pm)
                if abs(missing_share - ctx["quality"]["classifier_missing_share_max"]) > 1e-12:
                    raise SpecError(f"{where}.inconclusive_if: {cond} differs from quality.classifier_missing_instrument_day_share_inconclusive")
            elif cond == "classifier_index_source_unresolved_or_unavailable" and classifier is not None:
                pass
            elif cond == "C6_failed_or_not_computable" and validation is not None:
                pass
            else:
                raise SpecError(f"{where}.inconclusive_if: {cond!r} is not implemented for this type")
        inconclusive = items
    if classifier is not None and ("classifier_index_source_unresolved_or_unavailable" not in inconclusive or (validation is not None and "C6_failed_or_not_computable" not in inconclusive)):
        raise SpecError(f"{where}.inconclusive_if: a classifier type lists classifier_index_source_unresolved_or_unavailable and C6_failed_or_not_computable")
    window_end = _parse_window_end(item, where, label, ctx["window"], calendars, ctx["power"])
    if "utc_time_note" in item:
        if generator["kind"] != "local_time":
            raise SpecError(f"{where}.utc_time_note belongs to a local-time type")
        n = T05_NOTE_RE.match(_text(item, "utc_time_note", where))
        if n is None:
            raise SpecError(f"{where}.utc_time_note: 'HH:MM UTC from T0 to D, HH:MM UTC from D (...)' is the implemented form")
        for day, hhmm in ((ctx["window"]["start"], n.group("a")), (_parse_date(n.group("d1"), where), n.group("a")), (_parse_date(n.group("d2"), where), n.group("b"))):
            got = g5._iso(g5.local_to_utc_us(day, generator["minute_of_day"], generator["tz_info"]))[11:16]
            if got != hhmm:
                raise SpecError(f"{where}.utc_time_note: {generator['tz']} {gen['time']} on {day} is {got} UTC, not {hhmm}")
    # Amendment A1: the entry-leg rule of the descriptive Gate 1 construction (through proxy on the minute bars of the prints), the same as
    # the Gate 1 type's (identity checked in check_gate1_identity); the primary simulation measures every term and is not affected.
    entry_adverse = g5._choice(item, "adverse_selection_entry_leg", where, g5.ENTRY_ADVERSE_VALUES) if "adverse_selection_entry_leg" in item else g5.ENTRY_ADVERSE_DESIGN
    if entry_adverse == g5.ENTRY_ADVERSE_MEASURED and (role != "maker_maker" or rest is None):
        raise SpecError(f"{where}.adverse_selection_entry_leg: measured_by_fill_proxy needs a resting entry conditioned on the construction proxy (A1)")
    cost_t = g5.cost_terms(role, costs, entry_adverse)
    expected = item["expected_cost_bp"]
    if expected is not None and (isinstance(expected, bool) or not isinstance(expected, (int, float)) or abs(float(expected) - cost_t["total_bp"]) > 1e-9):
        raise SpecError(f"{where}.expected_cost_bp: null (measured) or the Gate 1 design total {cost_t['total_bp']:g} kept for the descriptive net_bp")
    audit = item.get("audit_expected") or {}
    if not isinstance(audit, dict):
        raise SpecError(f"{where}.audit_expected must be an object")
    gate2 = {"exit_until_min": until, "fill_sim": fill_sim, "window_end": window_end, "identity": identity, "gate1_identity": item["gate1_identity"],
             "construction_proxy": {"open_from_min": arm, "open_to_min": rest - 1} if rest is not None else None, "entry": dict(entry), "exit": dict(ex)}
    return g5.TypeSpec(name, label, position, role, cost_t["total_bp"], cost_t["stress_total_bp"], _num(item, "days_per_year", where, 0.0, 366.0, lo_open=True), "spec_window",
                       generator, footprint, arm, rest, classifier, None, sign, {"kind": entry["kind"], "price": entry["price"]}, {"kind": ex["kind"], "price": ex["price"]}, rw, keys,
                       validation, controls, c7, criteria, inconclusive, missing_share, audit, gate2, raw=item, entry_adverse_selection=entry_adverse)


# ----------------------------------------------------------------------------- identity with the Gate 1 (frozen_with, piège 7)


def _same(a, b) -> bool:
    return json.dumps(a, sort_keys=True, default=str) == json.dumps(b, sort_keys=True, default=str)


def _type_view(t: g5.TypeSpec, field: str):
    if field == "classifier":
        return t.classifier
    if field == "validation":
        return t.validation
    if field == "sign":
        return t.sign
    if field == "generator":
        return {k: v for k, v in t.generator.items() if k not in ("tz_info", "stream", "exclude_days")}  # the date lists differ by construction
    if field == "controls":
        return {k: v for k, v in t.controls.items() if k != "within"}
    return getattr(t, field)


def check_gate1_identity(spec2_types: tuple, parts: dict, gate1: g5.Spec) -> list[str]:
    """The fields each Gate 2 type names in `gate1_identity`, and the shared blocks, equal to the Gate 1 spec's."""
    checked = []
    for t in spec2_types:
        try:
            t1 = gate1.type(t.name)
        except KeyError:
            raise SpecError(f"frozen_with: {t.name} is not a type of the Gate 1 spec") from None
        if t.role != t1.role or t.days_per_year != t1.days_per_year:
            raise SpecError(f"frozen_with: {t.name} execution_role / days_per_year differ from the Gate 1")
        if t.entry_adverse_selection != t1.entry_adverse_selection:  # A1: the same assumed costs for the Gate 1 construction at both gates
            raise SpecError(f"frozen_with: {t.name} adverse_selection_entry_leg {t.entry_adverse_selection} differs from the Gate 1 ({t1.entry_adverse_selection}, A1)")
        checked.append(f"{t.name}:adverse_selection_entry_leg")
        for name in t.gate2["identity"]:
            for field in IDENTITY_FIELDS[name]:
                if not _same(_type_view(t, field), _type_view(t1, field)):
                    raise SpecError(f"frozen_with: {t.name} {name} ({field}) differs from the Gate 1 spec (gate1_identity)")
            checked.append(f"{t.name}:{name}")
    rule_of = lambda s: {k: v for k, v in s.items() if k not in ("tz_info", "verify_at_freeze")}  # noqa: E731 - the verification status is not a rule
    shared = {"sessions": ([rule_of(s) for s in parts["sessions"]], [rule_of(s) for s in gate1.sessions]),
              "macro_filter.releases": ([{k: v for k, v in m.items() if k != "tz_info"} for m in parts["macro"]], [{k: v for k, v in m.items() if k != "tz_info"} for m in gate1.macro]),
              "eligibility": ((parts["eligibility_days"], parts["eligibility_count_field"]), (gate1.eligibility_days, gate1.eligibility_count_field)),
              "unit": (parts["unit"], gate1.unit), "power_floor": (parts["power"], gate1.power),
              "statistics": ({k: v for k, v in parts["statistics"].items()}, dict(gate1.statistics)),
              "controls_common.margin_min": (parts["controls_common"]["margin_min"], gate1.controls_common["margin_min"]),
              "controls_common.exclusions": (sorted(k for k in parts["controls_common"]["components"]), sorted(gate1.controls_common["components"])),
              "economic": ({k: parts["economic"][k] for k in ("s_eco", "capital", "leverage", "max_live", "notional", "readings")},
                           {k: gate1.economic[k] for k in ("s_eco", "capital", "leverage", "max_live", "notional", "readings")}),
              "multiplicity": ((parts["multiplicity"]["alpha"], list(parts["multiplicity"]["family"]), parts["multiplicity"]["m_max"], parts["multiplicity"]["immediate_if_p_le"]),
                               (gate1.multiplicity["alpha"], list(gate1.multiplicity["family"]), gate1.multiplicity["m_max"], gate1.multiplicity["immediate_if_p_le"])),
              "verdict.C4_C5_C7": ((parts["verdict"]["criteria_text"].get("C4"), parts["verdict"]["c5_trim_share"], parts["verdict"]["criteria_text"].get("C7"),
                                    parts["verdict"]["annotation_ge"], parts["verdict"]["annotation_lt"]),
                                   (gate1.verdict["criteria_text"].get("C4"), gate1.verdict["c5_trim_share"], gate1.verdict["criteria_text"].get("C7"),
                                    gate1.verdict["annotation_ge"], gate1.verdict["annotation_lt"])),
              "descriptive.rule": (parts["descriptive"]["rule"], gate1.descriptive["rule"])}
    for key, (a, b) in shared.items():
        if not _same(a, b):
            raise SpecError(f"frozen_with: {key} differs from the Gate 1 spec (both specs are frozen together with the same rules, trap 7)")
        checked.append(key)
    return checked


# ----------------------------------------------------------------------------- calcul d'audit of the capture types


def audit_capture_settlements(tspec: g5.TypeSpec, t0: Date, end_exclusive: Date, min_instants: int) -> dict:
    """T01 from T0: the default-grid settlements whose footprint lies in [T0 ; end) and whose neighbours are known (the capture
    carries nextFundingTime, so the last one keeps its successor)."""
    t0_us, end_us = _day_us(t0), _day_us(end_exclusive)
    grid = [d * g5.DAY_US + h * g5.HOUR_US for d in range(_day(t0) - 1, _day(end_exclusive) + 1) for h in tspec.controls["grid_hours"]]
    present = set(grid)
    gen = tspec.generator
    kept = [s for s in grid if s + tspec.footprint[0] * g5.MINUTE_US >= t0_us and s + tspec.footprint[1] * g5.MINUTE_US < end_us
            and (s - gen["previous_gap_us"]) in present and (s + gen["next_gap_us"]) in present]
    return {"calendar_days": (end_exclusive - t0).days, "raw_instants_upper_bound": len(kept), "not_informative_expected_if_f_inst_below": min_instants / len(kept) if kept else None}


def _check_type_audits(types: tuple, calendars: dict, power: dict) -> dict:
    out = {}
    for t in types:
        audit = t.audit_expected
        if t.generator["kind"] == "funding_settlements":
            allowed = ("calendar_days_if_t0_2026_11_02", "raw_instants_upper_bound_if_t0_2026_11_02", "raw_instants_note", "not_informative_expected_if_f_inst_below")
            unknown = [k for k in audit if k not in allowed]
            if unknown:
                raise SpecError(f"{t.name}.audit_expected: unknown key(s) {unknown}")
            computed = audit_capture_settlements(t, Date(2026, 11, 2), t.gate2["window_end"]["end_exclusive"], power["min_instants"])
            for key, ckey in (("calendar_days_if_t0_2026_11_02", "calendar_days"), ("raw_instants_upper_bound_if_t0_2026_11_02", "raw_instants_upper_bound")):
                if key in audit and audit[key] != computed[ckey]:
                    raise SpecError(f"{t.name}.audit_expected.{key} = {audit[key]!r} but the grid from T0 = 2026-11-02 gives {computed[ckey]} (calcul d'audit)")
            key = "not_informative_expected_if_f_inst_below"
            if key in audit and (computed[key] is None or abs(float(audit[key]) - computed[key]) > 5e-4):
                raise SpecError(f"{t.name}.audit_expected.{key} = {audit[key]!r} but min_instants / raw instants = {computed[key]}")
            out[t.name] = computed
        else:
            computed = {}
            for key, value in audit.items():
                m = NYSE_AUDIT_RE.match(key)
                if key == "halves":
                    if not isinstance(value, str):
                        raise SpecError(f"{t.name}.audit_expected.halves: a text")
                    continue
                if m is None or not isinstance(value, dict):
                    raise SpecError(f"{t.name}.audit_expected: unknown key {key!r}")
                t0 = _dkey(m)
                for to_key, n in value.items():
                    tm = TO_RE.match(to_key)
                    if tm is None:
                        raise SpecError(f"{t.name}.audit_expected.{key}: unknown key {to_key!r}")
                    got = len(nyse_days(calendars, t0, _dkey(tm)))
                    if got != n:
                        raise SpecError(f"{t.name}.audit_expected.{key}.{to_key} = {n!r} but the frozen holidays give {got} NYSE days (calcul d'audit)")
                    computed[f"{key}.{to_key}"] = got
            out[t.name] = computed
    return out


def _regime_days(key: str, t0: Date) -> tuple[Date, Date]:
    m = REGIME_RE.match(key)
    if m is None:
        raise SpecError(f"controls_common.audit_expected_utc: {key!r} is not '<regime>_t0_to_YYYY_MM_DD', '<regime>_YYYY_MM_DD_to_YYYY_MM_DD' or '<regime>_from_YYYY_MM_DD'")
    if m.group("label2"):
        return Date(int(m.group("y3")), int(m.group("m3")), int(m.group("d3"))), Date(9999, 12, 31)
    first = t0 if m.group("t0") else Date(int(m.group("y1")), int(m.group("m1")), int(m.group("d1")))
    return first, Date(int(m.group("y2")), int(m.group("m2")), int(m.group("d2")))


def control_hours_on(spec: g5.Spec, tspec: g5.TypeSpec, day: Date, common: dict | None = None) -> list[str]:
    """The control instants (HH:MM UTC) of one type on one day for an instrument settled on the default 8 h grid and eligible long
    before (calcul d'audit: the evaluator's own control rule, no data)."""
    meta = g5.Instrument("AUDIT-USD_UM_XPERP-000000", "AUDIT-USD", 0, 0, 1.0, 1.0)
    d_us = _day_us(day)
    grid = np.arange(d_us - 2 * g5.DAY_US, d_us + 3 * g5.DAY_US + g5.MINUTE_US, g5.MINUTE_US, dtype=np.int64)
    settlements = np.arange(d_us - 2 * g5.DAY_US, d_us + 3 * g5.DAY_US, 8 * g5.HOUR_US, dtype=np.int64)
    series = g5.make_series(meta, grid, np.full(grid.size, 1.0), settlements_us=settlements, rates=np.zeros(settlements.size), d_cov_start_us=None, source="capture")
    common = g5.common_instants(spec) if common is None else common
    if tspec.generator["kind"] == "local_time":
        raw = np.array([m for m, d, _ in g5.local_time_instants(tspec, spec, d_us, d_us + g5.DAY_US)], dtype=np.int64)
    else:
        raw = settlements[(settlements >= d_us) & (settlements < d_us + g5.DAY_US)]
    if raw.size == 0:
        return []
    controls, _ = g5.control_instants(series, tspec, spec, raw, g5.exclusion_intervals(series, tspec, spec, common), type_window(spec, tspec))
    return [g5._iso(int(c))[11:16] for c in controls.tolist() if int(c) // g5.DAY_US == _day(day)]


def _first_day(spec: g5.Spec, first: Date, last: Date, kind: str) -> Date | None:
    cal = spec.calendars
    d = first
    while d <= min(last, spec.end_exclusive - timedelta(days=1)):
        if g5.day_matches("nyse_trading_days", d, cal):
            cpi_es = d in cal["cpi"] or d in cal["employment_situation"]
            fomc = d in cal["fomc_statement"]
            if (kind == "ordinary" and not cpi_es and not fomc) or (kind == "cpi_es" and cpi_es and not fomc) or (kind == "fomc" and fomc and not cpi_es):
                return d
        d += timedelta(days=1)
    return None


def check_control_hours(spec: g5.Spec, block: dict) -> dict:
    """`controls_common.audit_expected_utc`: per DST regime, the control hours of an ordinary NYSE weekday and the hours removed on
    the CPI / Employment Situation and FOMC days of the frozen lists, recomputed by the control rule (no data)."""
    _text(block, "rule", "controls_common.audit_expected_utc")
    out = {}
    t0 = spec.gate2["t0_date"]
    by_label = {t.label: t for t in spec.types}
    common = g5.common_instants(spec)
    for key, regime in block.items():
        if key == "rule":
            continue
        first, last = _regime_days(key, t0)
        if not isinstance(regime, dict) or set(regime) - set(by_label):
            raise SpecError(f"controls_common.audit_expected_utc.{key}: an object keyed by the type labels {sorted(by_label)}")
        out[key] = {}
        for label, text in regime.items():
            tspec = by_label[label]
            rx = TIMES_TEXT_RE if tspec.controls["minute_of_hour"] else HOURS_TEXT_RE
            m = rx.match(_text(regime, label, f"controls_common.audit_expected_utc.{key}"))
            if m is None:
                raise SpecError(f"controls_common.audit_expected_utc.{key}.{label}: not a list of control times")
            listed = m.group("times") if tspec.controls["minute_of_hour"] else m.group("hours")
            expected = [x if ":" in x else f"{x}:00" for x in listed.split()]
            ordinary = _first_day(spec, first, last, "ordinary")
            if ordinary is None:
                raise SpecError(f"controls_common.audit_expected_utc.{key}: no ordinary NYSE weekday of the regime inside the window")
            got = control_hours_on(spec, tspec, ordinary, common)
            if got != expected:
                raise SpecError(f"controls_common.audit_expected_utc.{key}.{label}: the control rule gives {got} on {ordinary}, the spec writes {expected}")
            checks = {"ordinary": ordinary.isoformat()}
            rest = (m.group("rest") or "").strip()
            for clause in [c.strip() for c in rest.split(",") if c.strip()] if rest and rest != "as Gate 1" else []:
                cm = OUT_CLAUSE_RE.match(clause)
                if cm is None:
                    raise SpecError(f"controls_common.audit_expected_utc.{key}.{label}: clause {clause!r} is not '<times> out on <CPI / Employment Situation|FOMC> days'")
                removed = [x if ":" in x else f"{x}:00" for x in cm.group("what").replace(" h", "").replace(" and ", " ").split()]
                day = _first_day(spec, first, last, "cpi_es" if cm.group("kind").startswith("CPI") else "fomc")
                if day is None:
                    checks[cm.group("kind")] = "no such day in the frozen lists of the regime (not checkable)"
                    continue
                got_day = control_hours_on(spec, tspec, day, common)
                want = [h for h in expected if h not in removed]
                if got_day != want:
                    raise SpecError(f"controls_common.audit_expected_utc.{key}.{label}: on {day} ({cm.group('kind')}) the rule gives {got_day}, the spec implies {want}")
                checks[cm.group("kind")] = day.isoformat()
            out[key][label] = checks
    return out


# ----------------------------------------------------------------------------- the entry point


TOP_REQUIRED = ("spec_id", "spec_schema", "spec_version", "status", "protocol", "protocol_sections", "note", "frozen_with", "source", "dataset_id", "capture_dataset_id",
                "end_exclusive_utc", "quarantine_floor_utc", "quarantine_rule", "window", "requires", "owner_decisions", "freeze_fill", "universe", "eligibility", "price",
                "frozen_candle", "unit", "statistics", "power_floor", "costs", "execution_sim", "classifier_index_source", "controls_common", "sessions", "sessions_note",
                "calendars", "macro_filter", "events", "verdict", "multiplicity", "sealing", "gate2_inscription", "economic", "quality", "capture", "descriptive",
                "pilot_separation")


def _parse_pilot_separation(block, status: str) -> dict:
    """P-L2-1 (D14, 2026-10-22; capability `pilot_separation_floor`): the block read strictly. Its keys are those of
    `separation.BLOCK`; its instants are consistent (floor = end of the reserved interval + margin, excluded interval = [start of the
    reserved interval ; floor), T0 minimum = first 00:00 UTC at or after floor + max look-back, nothing before the quarantine floor).
    A FROZEN spec carries `separation.BLOCK` itself (every text equal); a draft may carry a synthetic block (tests), reported."""
    _check_keys(block, "pilot_separation", tuple(sep.BLOCK))
    _check_keys(block["applies_to"], "pilot_separation.applies_to", tuple(sep.BLOCK["applies_to"]))
    for key in ("decision", "rule", "reading"):
        _text(block, key, "pilot_separation")
    reserved = block["reserved_interval_utc"]
    excluded = block["excluded_interval_utc"]
    if not (isinstance(reserved, list) and len(reserved) == 2 and isinstance(excluded, list) and len(excluded) == 2):
        raise SpecError("pilot_separation: reserved_interval_utc and excluded_interval_utc are [start, end] pairs of UTC instants")
    r0, r1 = _iso_us(reserved[0], "pilot_separation.reserved_interval_utc"), _iso_us(reserved[1], "pilot_separation.reserved_interval_utc")
    margin_s = _int(block, "margin_s", "pilot_separation", 0, 7 * 86400)
    floor = _iso_us(block["separation_floor_utc"], "pilot_separation.separation_floor_utc")
    lookback = _int(block, "max_lookback_min", "pilot_separation", 0, 7 * 1440)
    t0_min = _iso_us(block["g5_gate2_t0_min_utc"], "pilot_separation.g5_gate2_t0_min_utc")
    if r0 < g5.QUARANTINE_END_US or r1 <= r0:
        raise SpecError("pilot_separation.reserved_interval_utc: a non-empty interval at or after the quarantine floor")
    if floor != r1 + margin_s * tape.SECOND_US:
        raise SpecError("pilot_separation.separation_floor_utc: end of the reserved interval + margin_s")
    if [_iso_us(excluded[0], "pilot_separation.excluded_interval_utc"), _iso_us(excluded[1], "pilot_separation.excluded_interval_utc")] != [r0, floor]:
        raise SpecError("pilot_separation.excluded_interval_utc: [start of the reserved interval ; separation_floor_utc]")
    need = floor + lookback * g5.MINUTE_US
    if t0_min % g5.DAY_US or t0_min < need or t0_min - g5.DAY_US >= need:
        raise SpecError("pilot_separation.g5_gate2_t0_min_utc: the first 00:00 UTC at or after separation_floor_utc + max_lookback_min")
    is_rule = block == sep.BLOCK
    if status == "FROZEN" and not is_rule:
        raise SpecError(f"pilot_separation: a FROZEN spec carries research_lp.separation.BLOCK (P-L2-1, sha256 {sep.block_sha256()}), every text equal")
    return {"reserved_start_us": r0, "reserved_end_us": r1, "floor_us": floor, "t0_min_us": t0_min,
            "t0_min_date": datetime.fromtimestamp(t0_min / tape.SECOND_US, tz=g5.UTC).date(), "max_lookback_min": lookback, "is_rule": is_rule,
            "sha256": sep.block_sha256(block), "rule_sha256": sep.block_sha256()}


def _load_gate1(document: dict, gate1) -> tuple[g5.Spec, str | None]:
    """The Gate 1 spec of `frozen_with` (or `gate1`: a file path, a document or a `Spec`) and the sha256 of its file (None when given
    in memory)."""
    fw = _check_keys(document["frozen_with"], "frozen_with", ("spec", "spec_id", "rule"))
    _exact(fw, "spec", "frozen_with", GATE1_SPEC_PATH)
    _text(fw, "rule", "frozen_with")
    sha = None
    if gate1 is None or isinstance(gate1, (str, Path)):
        path = g5.REPO_ROOT / GATE1_SPEC_PATH if gate1 is None else Path(gate1)
        if not path.is_file():
            raise SpecError(f"frozen_with.spec: {path} absent: the Gate 2 spec is read against its Gate 1")
        spec1, sha = g5.load_spec(path)
    elif isinstance(gate1, g5.Spec):
        spec1 = gate1
    else:
        spec1 = g5.parse_spec(gate1)
    if spec1.source != "history" or fw["spec_id"] != spec1.spec_id:
        raise SpecError(f"frozen_with.spec_id {fw['spec_id']!r} is not the Gate 1 spec read ({spec1.spec_id!r})")
    return spec1, sha


def parse_capture_spec(document: dict, gate1=None) -> g5.Spec:
    """The strict reader of the Gate 2 spec (see the module docstring); `gate1` replaces the file of `frozen_with.spec`."""
    _check_keys(document, "spec", TOP_REQUIRED)
    spec_id = _text(document, "spec_id", "spec")
    if "GATE2" not in spec_id:
        raise SpecError(f"spec_id {spec_id!r} does not name the Gate 2 of a capture spec")
    version = _text(document, "spec_version", "spec")
    status = _choice(document, "status", "spec", g5.STATUSES)
    if status == "FROZEN":
        if "draft" in version.lower():
            raise SpecError(f"spec_version {version!r}: a FROZEN spec carries a final version")
        pending, nulls = scan(document, PENDING), scan(document, None)
        if pending:
            raise SpecError(f"owner_decision_pending in a FROZEN spec at {pending} ({OWNER_RULE})")
        if nulls:
            raise SpecError(f"null values in a FROZEN spec at {nulls} (note: toute valeur null ou owner_decision_pending interdit le statut FROZEN)")
    markers = g5.refuse_freeze_pending(document, status)  # Annexe B request 14
    for key in ("protocol", "protocol_sections", "note", "dataset_id", "capture_dataset_id"):
        _text(document, key, "spec")
    _choice(document, "source", "spec", ("capture",))
    _exact(document, "end_exclusive_utc", "spec", g5_fetch.END_EXCLUSIVE)
    _exact(document, "quarantine_floor_utc", "spec", xc.QUARANTINE_FLOOR)
    _exact(document, "quarantine_rule", "spec", QUARANTINE_RULE)
    gate1_spec, gate1_sha = _load_gate1(document, gate1)
    window = _parse_window(document["window"])
    items = _str_list(document, "requires", "spec")
    unknown = sorted(set(items) - set(g5.CAPABILITIES))
    if unknown:
        raise SpecError(f"requires {unknown}: not implemented by {g5.EVALUATOR_VERSION}")
    extra = sorted(set(items) - set(g5.CAPTURE_REQUIRES))
    if extra:
        raise SpecError(f"requires {extra}: not applicable to a capture spec")
    missing = sorted(set(g5.CAPTURE_REQUIRES) - set(items))
    if missing:
        raise SpecError(f"the spec relies on {missing} without declaring it in requires (the version lock of (B) xviii)")
    freeze_fill = _parse_freeze_fill(document["freeze_fill"], status, window["start"])
    separation = _parse_pilot_separation(document["pilot_separation"], status)  # P-L2-1 (D14)
    deviations = []
    if window["start"] < separation["t0_min_date"]:
        deviations.append(f"window.start {window['start']} before pilot_separation.g5_gate2_t0_min_utc {separation['t0_min_date']}")
    if freeze_fill["t0_utc"] is not None and _iso_us(freeze_fill["t0_utc"], "freeze_fill.t0_utc") < separation["t0_min_us"]:
        deviations.append(f"freeze_fill.t0_utc {freeze_fill['t0_utc']} before pilot_separation.g5_gate2_t0_min_utc {separation['t0_min_date']}")
    if not separation["is_rule"]:
        deviations.append("pilot_separation differs from research_lp.separation.BLOCK (draft only)")
    if status == "FROZEN" and deviations:
        raise SpecError(f"pilot_separation (P-L2-1): {deviations}")
    separation["draft_deviations"] = deviations
    if status == "FROZEN":  # trap 7, protocol §13 point 5 (CTO M2): both specs are frozen in the same freeze line
        if not gate1_spec.frozen:
            raise SpecError(f"frozen_with: a FROZEN Gate 2 spec is read against a FROZEN Gate 1 spec; {gate1_spec.spec_id} is {gate1_spec.status} "
                            "(both are frozen in the same freeze line, protocol section 13 point 5, trap 7)")
        if gate1_sha is not None and freeze_fill["gate1_spec_sha256"] != gate1_sha:
            raise SpecError(f"freeze_fill.gate1_spec_sha256 {freeze_fill['gate1_spec_sha256']} is not the sha256 {gate1_sha} of the FROZEN Gate 1 spec read")
    t0 = window["start"] if freeze_fill["t0_utc"] is None else datetime.fromtimestamp(_iso_us(freeze_fill["t0_utc"], "freeze_fill.t0_utc") / tape.SECOND_US, tz=g5.UTC).date()
    el = _check_keys(document["eligibility"], "eligibility", ("days_after_list_time", "reference", "applies_to", "count_field"))
    eligibility_days = _num(el, "days_after_list_time", "eligibility", 0.0)
    _choice(el, "reference", "eligibility", ("footprint_start",))
    if list(_str_list(el, "applies_to", "eligibility")) != ["events", "controls"]:
        raise SpecError("eligibility.applies_to: the rule applies to the events and the controls alike")
    count_field = _text(el, "count_field", "eligibility")
    pr = _check_keys(document["price"], "price", tuple(PRICE) + ("max_missing_share_in_event_footprints_per_instrument",))
    for key, text in PRICE.items():
        _exact(pr, key, "price", text)
    price = {"max_missing_share": _num(pr, "max_missing_share_in_event_footprints_per_instrument", "price", 0.0, 1.0), "series": pr["series"]}
    fc = _check_keys(document["frozen_candle"], "frozen_candle", tuple(FROZEN_CANDLE) + ("gate1_equivalent", "count_field"))
    for key, text in FROZEN_CANDLE.items():
        _exact(fc, key, "frozen_candle", text)
    _text(fc, "gate1_equivalent", "frozen_candle")
    frozen_candle = {"applies": True, "kind": "no_print", "count_field": _text(fc, "count_field", "frozen_candle")}
    unit = g5._parse_unit(document["unit"])
    statistics = g5._parse_statistics(document["statistics"], "capture")
    power = g5._parse_power(document["power_floor"], GATE)
    economic = _parse_economic(document["economic"])
    costs = _parse_costs(document["costs"], gate1_spec.costs, status)
    sim = _parse_execution_sim(document["execution_sim"], economic["notional"])
    index_choice = _parse_index_source(document["classifier_index_source"], status)
    controls_common = g5._parse_controls_common(document["controls_common"], "capture")
    sessions = g5._parse_sessions(document["sessions"], status)
    _text(document, "sessions_note", "spec")
    calendars, cal_extra = _parse_calendars(document["calendars"], window["start"])
    if status == "FROZEN" and cal_extra["incomplete"]:
        raise SpecError(f"calendars.incomplete_at_draft {sorted(cal_extra['incomplete'])}: {INCOMPLETE_RULE}")
    macro = g5._parse_macro(document["macro_filter"], calendars, "capture")
    if "utc_note" in document["macro_filter"]:
        _check_macro_note(_text(document["macro_filter"], "utc_note", "macro_filter"), macro)
    quality = _parse_quality(document["quality"])
    ctx = {"calendars": calendars, "costs": costs, "window": window, "power": power, "quality": quality}
    types = tuple(_parse_capture_type(item, i, ctx) for i, item in enumerate(_list(document, "events", "spec")))
    names = [t.name for t in types]
    if len(set(names)) != len(names) or len({t.label for t in types}) != len(types):
        raise SpecError(f"duplicate event types or labels: {names}")
    if [t for t in types if t.label == "T05"] == []:
        raise SpecError("events: the T05 window_end decision (P-G5-10 (i)) needs the T05_us_open type")
    # T05 option (c): the end from T0 and the calendar rule; at FROZEN the freeze_fill copy must agree with the chosen end.
    for t in types:
        we = t.gate2["window_end"]
        if we["choice"] == "c_end_by_calendar_rule":
            end = t05_end_by_calendar_rule(calendars, t0, we["rule_count"])
            if end is None or end > cal_extra["covers"][1]:
                raise SpecError(f"{t.name}.window_end option c: the calendar rule ends beyond calendars.covers")
            we.update({"end_inclusive": end, "end_exclusive": end + timedelta(days=1), "computed_from_t0": t0.isoformat()})
        if we["choice"] not in (None, PENDING) and freeze_fill["t05_end_inclusive_utc"] is not None:
            written = _parse_date(freeze_fill["t05_end_inclusive_utc"][:10], "freeze_fill.t05_end_inclusive_utc")
            if written != we["end_inclusive"]:
                raise SpecError(f"freeze_fill.t05_end_inclusive_utc {written} differs from the chosen end {we['end_inclusive']} ({we['choice']})")
    t05_choice = next(t.gate2["window_end"]["choice"] for t in types if t.label == "T05")
    beyond_allowed = t05_choice in ("b_end_2027_04_12", "c_end_by_calendar_rule")
    grid_end = max(extended_end(t.gate2["window_end"]["end_exclusive"], window["max_gap_days"], window["max_gap_days"], beyond_allowed) for t in types)
    # calendars_cover_the_window (calendars.covers_rule): T0, every type end, every T05 option and the data-gap extension inside covers.
    covers = cal_extra["covers"]
    if t0 < covers[0]:
        raise SpecError(f"calendars.covers starts {covers[0]}, after T0 {t0}")
    last_days = {f"{t.name}.window_end": t.gate2["window_end"]["end_inclusive"] for t in types}
    for t in types:
        for name, option in t.gate2["window_end"]["options"].items():
            if "end_inclusive" in option:
                last_days[f"{t.name}.window_end.options.{name}"] = option["end_inclusive"]
    for where, last in last_days.items():
        if last + timedelta(days=window["max_gap_days"]) > covers[1]:
            raise SpecError(f"calendars.covers ends {covers[1]}, before {where} {last} + the data-gap extension of {window['max_gap_days']} days ({COVERS_RULE})")
    if grid_end - timedelta(days=1) > covers[1]:
        raise SpecError(f"calendars.covers ends {covers[1]}, before the last scored day with the data-gap extension ({grid_end - timedelta(days=1)})")
    # Annexe B request 13 (review-b quant M4): the content test over R = [T0 ; last scored day + data-gap extension].
    content = g5.calendar_content(calendars, sessions, t0, grid_end - timedelta(days=1))
    g5.refuse_calendar_content(content, status)
    if "type_instants" in controls_common["components"]:
        target = controls_common["components"]["type_instants"]
        if target[0] not in names or next(t for t in types if t.name == target[0]).generator["kind"] != "local_time":
            raise SpecError(f"controls_common.exclusions: {target[0]} is not a local_time type of the spec")
    verdict = _parse_verdict(document["verdict"], types)
    if abs(verdict["unusable_share_max"] - quality["share_max"]) > 1e-12:
        raise SpecError("verdict.inconclusive_global: unusable_instrument_day_share_gt_<x> differs from quality.unusable_share_inconclusive")
    multiplicity = _parse_multiplicity(document["multiplicity"], types)
    if abs(multiplicity["alpha"] - statistics["alpha"]) > 1e-12:
        raise SpecError("multiplicity.local_holm.alpha differs from statistics.alpha_one_sided")
    sealing = _parse_sealing(document["sealing"])
    inscription = _parse_inscription(document["gate2_inscription"])
    descriptive = _parse_descriptive(document["descriptive"])
    capture = _parse_capture_block(document["capture"])
    universe, uni_extra = _parse_universe(document["universe"], status, eligibility_days, _day_us(grid_end))
    decisions = _parse_owner_decisions(document["owner_decisions"], document)
    if status == "FROZEN":
        raise_if = []
        if costs["funding_fallback"] and not costs["funding_fallback"].startswith("none"):
            raise_if.append(FUNDING_FALLBACK_NOT_IMPLEMENTED)
        if raise_if:
            raise SpecError("; ".join(raise_if))
    pending = scan(document, PENDING)
    pending_paths = sorted({p for p in DECISION_PATHS if resolve(document, p).get("status") == PENDING})
    parts = {"sessions": sessions, "macro": macro, "eligibility_days": eligibility_days, "eligibility_count_field": count_field, "unit": unit, "power": power, "statistics": statistics,
             "controls_common": controls_common, "economic": economic, "multiplicity": multiplicity, "verdict": verdict, "descriptive": descriptive}
    identity = check_gate1_identity(types, parts, gate1_spec)
    gate2 = {"t0_date": t0, "t0_us": _day_us(t0), "t0_provisional": freeze_fill["t0_utc"] is None, "window": window, "grid_end": grid_end, "beyond_allowed": beyond_allowed,
             "max_gap_days": window["max_gap_days"], "sim": sim, "index_choice": index_choice, "quality": quality, "capture": capture, "inscription": inscription,
             "decisions": {"classifier_index_source": index_choice, "t05_window_end": t05_choice, "p_s3": uni_extra["p_s3"]}, "decision_ids": decisions,
             "pending": pending_paths, "pending_values": pending, "calendars": cal_extra,
             "frozen_with": {"spec": GATE1_SPEC_PATH, "spec_id": gate1_spec.spec_id, "status": gate1_spec.status, "sha256": gate1_sha},
             "identity_checked": identity, "universe": uni_extra, "funding_fallback": costs["funding_fallback"],
             "funding_fallback_status": "not implemented: " + FUNDING_FALLBACK_NOT_IMPLEMENTED, "gate1_sealing_open_after": sealing["gate1_open_after"], "pilot_separation": separation}
    # P-L2-1: the first observation any scored instant of a type can use (footprint start at T0, classifier look-back, one minute bar) is at
    # or after the separation floor; a FROZEN spec that breaks it is refused (the loader drops the excluded rows in any case).
    first_obs = {}
    for t in types:
        first = _day_us(t0) - t.footprint[0] * g5.MINUTE_US + (t.lookback_min - 1) * g5.MINUTE_US
        first_obs[t.name] = g5._iso(first)
        if first < separation["floor_us"] and _day_us(grid_end) > separation["reserved_start_us"]:  # the read range [first ; grid end) meets the excluded interval
            separation["draft_deviations"].append(f"{t.name}: first observation {g5._iso(first)} inside the excluded interval")
    separation["first_observation_by_type_utc"] = first_obs
    if status == "FROZEN" and separation["draft_deviations"]:
        raise SpecError(f"pilot_separation (P-L2-1): {separation['draft_deviations']}")
    start = t0
    spec = g5.Spec(document, spec_id, version, status, "capture", document["dataset_id"], document["protocol"], GATE, start, grid_end, tuple(items), freeze_fill, universe, (),
                   eligibility_days, count_field, price, frozen_candle, unit, statistics, power, costs, controls_common, sessions, calendars, macro, types, verdict, multiplicity,
                   sealing, {**inscription, "rule": dict(INSCRIPTION)}, economic, descriptive, None, gate2, {}, {})
    audit = _check_type_audits(types, calendars, power)
    audit["power_by_construction"] = power_by_construction(spec, bool(cal_extra["incomplete"]))
    audit["calendar_content"] = content
    if "audit_expected_utc" in document["controls_common"]:
        audit["control_hours"] = check_control_hours(spec, document["controls_common"]["audit_expected_utc"])
    spec.audit.update(audit)
    spec.notes.update({"note": document["note"], "protocol_sections": document["protocol_sections"], "frozen_with.rule": document["frozen_with"]["rule"],
                       "capture_dataset_id": document["capture_dataset_id"], "window.end_rule": window["end_rule"], "universe.rule": document["universe"]["rule"],
                       "universe.global_swaps": document["universe"]["global_swaps"], "universe.liquidity_rule_g2_3_3": document["universe"]["liquidity_rule_g2_3_3"],
                       "universe.p_s3_track_s.decision": document["universe"]["p_s3_track_s"]["decision"], "statistics.measure_definition": document["statistics"]["measure_definition"],
                       "costs.stress_rule_status": costs["stress_rule_status"], "costs.funding.fallback": costs["funding_fallback"], "costs.g2_table_substitution": costs["g2_table_substitution"],
                       "costs.funding.source_status": costs["funding_source_status"], "classifier_index_source.frozen_allowed": list(INDEX_FROZEN_ALLOWED),
                       "execution_sim.rpi_bias": document["execution_sim"]["rpi_bias"], "execution_sim.funding_fill_grace_rule": document["execution_sim"]["funding_fill_grace_rule"],
                       "classifier_index_source.why": document["classifier_index_source"]["why"], "classifier_index_source.single_quantity": document["classifier_index_source"]["single_quantity"],
                       "controls_common.rule": document["controls_common"]["rule"], "sessions_note": document["sessions_note"], "calendars.sources": list(calendars["sources"]),
                       "calendars.read_by": calendars["read_by"], "calendars.incomplete_at_draft": cal_extra["incomplete"], "macro_filter.use": document["macro_filter"]["use"],
                       "macro_filter.ten_am_et_releases": document["macro_filter"]["ten_am_et_releases"], "verdict.closure_scope": verdict["closure_scope"],
                       "verdict.pass_effect": verdict["pass_effect"], "multiplicity.local_holm.status": multiplicity["status"], "multiplicity.programme.otherwise": multiplicity["otherwise"],
                       "sealing.gate1_open_after": sealing["gate1_open_after"], "sealing.gate2_own_verdicts.rule": sealing["rule"], "sealing.visibility": document["sealing"]["visibility"],
                       "gate2_inscription.input": document["gate2_inscription"]["input"], "gate2_inscription.discretion": document["gate2_inscription"]["discretion"],
                       "gate2_inscription.interim_reading": document["gate2_inscription"]["interim_reading"], "quality.status": document["quality"]["status"],
                       "capture.command": document["capture"]["command"], "capture.channels_conditional": document["capture"]["channels_conditional"],
                       "capture.register": document["capture"]["register"], "descriptive.excluded": descriptive["excluded"], "freeze_fill.rule": freeze_fill["rule"],
                       "owner_decisions.pending": pending_paths, "sessions_to_verify_at_freeze": [s["name"] for s in sessions if s["verify_at_freeze"]],
                       "classifier.threshold_origin": {t.name: t.classifier["threshold_origin"] for t in types if t.classifier is not None},
                       "types.gate1_identity": {t.name: t.gate2["gate1_identity"] for t in types},
                       "types.notes": {t.name: {k: t.raw[k] for k in ("expected_cost_note", "footprint_note", "utc_time_note") if k in t.raw} for t in types},
                       "types.window_end_option": {t.name: t.raw["window_end"]["options"].get(t.gate2["window_end"]["choice"]) for t in types
                                                   if isinstance(t.raw["window_end"], dict) and "options" in t.raw["window_end"]},
                       "classifier_index_source.option": document["classifier_index_source"]["options"].get(index_choice),
                       "frozen_candle.gate1_equivalent": document["frozen_candle"]["gate1_equivalent"], "calendars.covers_rule": cal_extra["covers_rule"],
                       "controls_common.audit_expected_utc.rule": (document["controls_common"].get("audit_expected_utc") or {}).get("rule"),
                       "sealing.gate1_sealed_files.files": document["sealing"]["gate1_sealed_files"]["files"],
                       "owner_decisions.ids": decisions, "universe.always_included": document["universe"]["always_included"],
                       "freeze_pending_markers": markers, "calendars.content_problems": content["problems"],
                       "execution_sim.entry_fill_rule_decision": document["execution_sim"]["entry_fill_rule_decision"],
                       "power_by_construction": {name: {k: v for k, v in item.items() if k in ("raw_instants_upper_bound", "min_instants", "not_informative_by_construction",
                                                                                              "window_end_choice", "calendars_complete")}
                                                 for name, item in audit["power_by_construction"].items()}})
    return spec


def check_frozen_together(gate1: g5.Spec, gate1_sha: str, path: Path) -> dict:
    """Annexe B request 11 (`gate2_frozen_together`, review-b quant B1), the Gate 1 side of `frozen_with`: the Gate 2 spec at `path` is
    FROZEN, holds no `owner_decision_pending`, no `freeze_pending:` marker and no null, names the Gate 1 spec read by its sha256
    (`freeze_fill.gate1_spec_sha256`) and passes this strict reader against that Gate 1. Returns the path, sha256, id, status and the
    problems (empty: admitted); `g5_eval.command_eval` refuses the Gate 1 run (refuse_run) before any market value when one holds."""
    out = {"path": str(path), "sha256": None, "spec_id": None, "status": None, "gate1_spec_sha256": None, "problems": []}
    if not Path(path).is_file():
        out["problems"].append(f"{path}: absent (the Gate 1 is frozen together with its Gate 2, protocol section 13 point 5)")
        return out
    data = Path(path).read_bytes()
    out["sha256"] = g5_fetch.sha256_bytes(data)
    try:
        document = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, ValueError) as error:
        out["problems"].append(f"not JSON ({error})")
        return out
    if not isinstance(document, dict):
        out["problems"].append("not a JSON object")
        return out
    freeze_fill = document.get("freeze_fill") if isinstance(document.get("freeze_fill"), dict) else {}
    out.update({"spec_id": document.get("spec_id"), "status": document.get("status"), "gate1_spec_sha256": freeze_fill.get("gate1_spec_sha256")})
    if document.get("source") != "capture" or document.get("spec_id") != gate1.raw["frozen_with"]["spec_id"]:
        out["problems"].append(f"spec_id {document.get('spec_id')!r} / source {document.get('source')!r} is not the Gate 2 of frozen_with ({gate1.raw['frozen_with']['spec_id']!r})")
    if document.get("status") != "FROZEN":
        out["problems"].append(f"status {document.get('status')!r}: the Gate 2 spec is not FROZEN")
    for label, paths in (("owner_decision_pending", scan(document, PENDING)), ("null", scan(document, None)), (g5.FREEZE_PENDING_MARKER, g5.marker_paths(document))):
        if paths:
            out["problems"].append(f"{label} at {paths[:10]}")
    if freeze_fill.get("gate1_spec_sha256") != gate1_sha:
        out["problems"].append(f"freeze_fill.gate1_spec_sha256 {freeze_fill.get('gate1_spec_sha256')} is not the sha256 {gate1_sha} of the Gate 1 spec read")
    if not out["problems"]:
        try:
            parse_capture_spec(document, gate1)
        except SpecError as error:
            out["problems"].append(f"the strict Gate 2 reader refuses it: {error}")
    return out


POWER_BY_CONSTRUCTION_RULE = ("upper bound of n for a local_time type: one instant per NYSE day of [T0 ; end of the type window with the largest data-gap extension "
                              "the chosen option allows) outside the CPI and Employment Situation lists ; n < power_floor.min_instants gives "
                              "G5_GATE2_NOT_INFORMATIVE whatever the data (power_floor.below_floor) ; with incomplete calendars the bound is too high "
                              "(exclusion dates missing) ; a statement written in advance, never a reading")


def power_by_construction(spec: g5.Spec, calendars_incomplete: bool) -> dict:
    """Calcul d'audit (no data): for each `local_time` type (T05), the most instants its frozen window can hold and whether the power floor
    is out of reach by construction (P-G5-10 (i), owner decision D7 of 2026-10-21: end 2027-03-31, no extension, NOT_INFORMATIVE by
    construction). Stated in the audit, the notes and the type's verdict reasons, never hidden."""
    out = {}
    for t in spec.types:
        if t.generator["kind"] != "local_time":
            continue
        we = t.gate2["window_end"]
        hi_date = extended_end(we["end_exclusive"], spec.gate2["max_gap_days"], spec.gate2["max_gap_days"], spec.gate2["beyond_allowed"])
        n = len(g5.local_time_instants(t, spec, spec.gate2["t0_us"], _day_us(hi_date)))
        floor = int(spec.power["min_instants"])
        out[t.name] = {"window_end_choice": we["choice"], "t0": spec.gate2["t0_date"].isoformat(), "t0_provisional": spec.gate2["t0_provisional"],
                       "end_exclusive_with_max_extension": hi_date.isoformat(), "raw_instants_upper_bound": n, "min_instants": floor,
                       "not_informative_by_construction": n < floor, "calendars_complete": not calendars_incomplete, "rule": POWER_BY_CONSTRUCTION_RULE}
    return out


# ----------------------------------------------------------------------------- runtime helpers (used by g5_eval)


def extended_end(end_exclusive: Date, extension_days: int, max_gap_days: int, beyond_allowed: bool) -> Date:
    """`window.data_gap_extension`: one day per lost day, at most `max_gap_days`; beyond 2027-03-31 only under the decisions of
    P-G5-10 (i) option b (GATES 1.3 exception and D1 extension; option c needs the same two)."""
    ext = min(max(int(extension_days), 0), int(max_gap_days))
    end = end_exclusive + timedelta(days=ext)
    if not beyond_allowed:
        end = min(end, max(end_exclusive, GATES_1_3_LAST_SCORED_DAY + timedelta(days=1)))
    return end


def type_window(spec: g5.Spec, tspec: g5.TypeSpec, extension_days: int = 0) -> tuple[int, int]:
    """`[T0 ; end)` of a type, in µs, with the data-gap extension of `extended_end`."""
    end = extended_end(tspec.gate2["window_end"]["end_exclusive"], extension_days, spec.gate2["max_gap_days"], spec.gate2["beyond_allowed"])
    return spec.gate2["t0_us"], _day_us(end)


def unusable_days(book_ts_us: np.ndarray, first_day: int, last_day_exclusive: int, coverage_min: float, stale_us: int) -> set:
    """`quality.unusable_instrument_day`: UTC days (numbers) whose books5 coverage (minutes with >= 1 snapshot) is below `coverage_min`
    or that a stale period (no snapshot for more than `stale_us`) crosses."""
    ts = np.unique(np.asarray(book_ts_us, dtype=np.int64))
    out = set()
    if last_day_exclusive <= first_day:
        return out
    minute_days = (np.unique(ts // g5.MINUTE_US) * g5.MINUTE_US) // g5.DAY_US
    per_day = np.zeros(last_day_exclusive - first_day, dtype=np.int64)
    inside = (minute_days >= first_day) & (minute_days < last_day_exclusive)
    np.add.at(per_day, (minute_days[inside] - first_day).astype(np.int64), 1)
    for i, n in enumerate(per_day.tolist()):
        if n < coverage_min * 1440:
            out.add(first_day + i)
    if ts.size > 1:
        gaps = np.nonzero(np.diff(ts) > stale_us)[0]
        for j in gaps.tolist():
            a, b = int(ts[j]), int(ts[j + 1])
            for d in range(a // g5.DAY_US, (b - 1) // g5.DAY_US + 1):
                if first_day <= d < last_day_exclusive:
                    out.add(d)
    return out


def inscription_of(spec: g5.Spec, gate1_verdicts: dict) -> dict:
    """`gate2_inscription.rule` applied to the Gate 1 verdict labels: `evaluated` or `not_evaluated` per type (no discretion)."""
    out = {}
    for t in spec.types:
        label = gate1_verdicts.get(t.name)
        if label not in INSCRIPTION:
            raise ValueError(f"the Gate 1 result has no verdict for {t.name} (got {label!r}): {spec.gate2_inscription['rule']}")
        out[t.name] = {"gate1_verdict": label, "rule": spec.gate2_inscription[label[len("G5_GATE1_"):]], "evaluated": not spec.gate2_inscription[label[len("G5_GATE1_"):]].startswith("not")}
    return out


GATE1_REFUSED_NOTE = ("the Gate 1 run read no market data (global device defects: every type G5_GATE1_INCONCLUSIVE): under protocol section 8 and "
                      "gate2_inscription.rule every type is then evaluated automatically at the Gate 2, without futility")


def read_gate1_result(path: Path, expected_sha256: str, spec: g5.Spec) -> tuple[dict | None, list[str]]:
    """The Gate 1 result JSON of `g5-eval` (`gate2_inscription.input`): its sha256 against the register line, its kind (`lp-g5-eval`),
    its mode (`tape`, never a synthetic study), its spec (the FROZEN history spec of `freeze_fill.gate1_spec_sha256`, CTO m5); never a
    path under a `sealed/` directory (`sealing.gate1_sealed_files.evaluator_rule`). A refused Gate 1 run (no market data read) is
    accepted as such and its effect written (`GATE1_REFUSED_NOTE`)."""
    path = Path(path)
    if any(part == "sealed" for part in path.resolve().parts):
        raise SystemExit(f"{path}: {SEALING_EVALUATOR_RULE}")
    if not path.is_file():
        return None, [f"gate1_result_sha256_mismatch: {path} absent"]
    data = path.read_bytes()
    sha = g5_fetch.sha256_bytes(data)
    defects = []
    if not expected_sha256 or expected_sha256.lower() != sha:
        defects.append(f"gate1_result_sha256_mismatch: file {sha}, register {expected_sha256 or None}")
    try:
        document = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, ValueError):
        return None, defects + ["gate1_result_sha256_mismatch: not JSON"]
    if not isinstance(document, dict):
        return None, defects + ["gate1_result_sha256_mismatch: not a JSON object"]
    s1 = document.get("spec") or {}
    summary = document.get("summary") or {}
    if s1.get("sha256") != spec.freeze_fill.get("gate1_spec_sha256") or s1.get("spec_id") != spec.gate2["frozen_with"]["spec_id"]:
        defects.append(f"gate1_result_sha256_mismatch: the result was produced by the spec {s1.get('sha256')}, freeze_fill.gate1_spec_sha256 is {spec.freeze_fill.get('gate1_spec_sha256')}")
    if document.get("kind") != "lp-g5-eval":
        defects.append(f"gate1_result_sha256_mismatch: kind {document.get('kind')!r} is not lp-g5-eval")
    if summary.get("mode") != "tape":
        defects.append(f"gate1_result_sha256_mismatch: mode {summary.get('mode')!r} is not a tape run")
    if s1.get("status") != "FROZEN" or s1.get("source") != "history":
        defects.append(f"gate1_result_sha256_mismatch: produced by a {s1.get('status')!r} {s1.get('source')!r} spec, not the FROZEN Gate 1 (history)")
    verdicts = summary.get("verdicts") or {}
    read = summary.get("market_data_read", True) is not False
    return {"sha256": sha, "verdicts": verdicts, "path": str(path), "market_data_read": read, "note": None if read else GATE1_REFUSED_NOTE}, defects


def check_index_tape(index_dir: Path, expected_sha256: str) -> dict:
    """P-G5-10 (ii) option b (CTO M3): the index tape is a values tape whose `index_1m/` digest (`g5_fetch.directory_digest`) equals the
    register's sha256 given by `--expect-index-tape-sha256`; otherwise refuse_run (`SystemExit`), before any market value is read."""
    index_dir = Path(index_dir)
    manifest = tape.read_manifest(index_dir)
    if manifest is None or manifest.get("mode") != "values":
        raise SystemExit(f"{index_dir}: no values tape manifest for the index (refuse_run, P-G5-10 (ii) option b)")
    folder = index_dir / "index_1m"
    if not folder.is_dir():
        raise SystemExit(f"{folder}: absent (refuse_run, P-G5-10 (ii) option b)")
    digest, files = g5_fetch.directory_digest(folder)
    if not expected_sha256 or expected_sha256.lower() != digest:
        raise SystemExit(f"{folder}: digest {digest} differs from --expect-index-tape-sha256 {expected_sha256 or None} (refuse_run: the register's sha256 of the index tape)")
    return {"path": str(folder), "digest": digest, "files": files, "not_before": manifest.get("not_before")}


def check_capture_manifest(manifest: dict | None, spec: g5.Spec) -> list[str]:
    """`capture_manifest_missing`: a values tape whose not_before is the quarantine floor (or later) and whose channels hold the
    channels the spec requires."""
    if manifest is None:
        return ["capture_manifest_missing: no tape manifest"]
    problems = []
    if manifest.get("mode") != "values":
        problems.append("capture_manifest_missing: not a values tape")
    if manifest.get("kind") == g5_fetch.MANIFEST_KIND:
        problems.append("capture_manifest_missing: a 1-minute history is not a capture tape")
    if tape.parse_iso_utc(str(manifest.get("not_before") or "1970-01-01T00:00:00Z")) < g5.QUARANTINE_END_US:
        problems.append(f"capture_manifest_missing: not_before below the quarantine floor {xc.QUARANTINE_FLOOR}")
    channels = set((manifest.get("source") or {}).get("channels") or [])
    lacking = [c for c in spec.gate2["capture"]["channels_required"] if c not in channels]
    if lacking:
        problems.append(f"capture_manifest_missing: channels {lacking} not captured")
    return problems


def check_universe(spec: g5.Spec, rows: dict) -> list[str]:
    """`universe.rule` (G2 §3) against the freeze snapshot: listTime, tickSz, ctVal, lotSz, minSz, uly of every analysed instrument;
    instType FUTURES, ruleType xperp, state live, listTime <= T0 − 30 d, expTime >= T0 + 365 d when stated."""
    problems = []
    t0 = spec.gate2["t0_us"]
    days_us = int(round(spec.eligibility_days * g5.DAY_US))
    for meta in spec.universe:
        row = rows.get(meta.inst_id)
        if row is None:
            problems.append(f"{meta.inst_id}: absent from the snapshot")
            continue
        if xc._i(row.get("listTime"), 0) * 1000 != meta.list_time_us:
            problems.append(f"{meta.inst_id}: listTime differs")
        for key, value in (("tickSz", meta.tick_sz), ("ctVal", meta.ct_val), ("lotSz", meta.lot_sz), ("minSz", meta.min_sz)):
            if xc._f(row.get(key)) != value:
                problems.append(f"{meta.inst_id}: {key} differs")
        if g5_fetch.index_id_of(row) != meta.index_id:
            problems.append(f"{meta.inst_id}: uly differs")
        if str(row.get("instType", "FUTURES")) != "FUTURES" or str(row.get("ruleType", "xperp")) != "xperp" or str(row.get("state", "live")) != "live":
            problems.append(f"{meta.inst_id}: not a live FUTURES xperp")
        if meta.list_time_us > t0 - days_us:
            problems.append(f"{meta.inst_id}: listTime after T0 - {spec.eligibility_days:g} d")
        exp = xc._i(row.get("expTime"), 0) * 1000
        if exp and exp < t0 + 365 * g5.DAY_US:
            problems.append(f"{meta.inst_id}: expTime before T0 + 365 d")
    return problems


# ----------------------------------------------------------------------------- D10: index source selection (P-G5-10 (ii), owner 2026-10-21)


def index_minutes_from_ticks(grid: np.ndarray, ts_us: np.ndarray, idx_px: np.ndarray) -> np.ndarray:
    """Option (a) on the minute grid: at each boundary g the last captured idxPx with exchange ts in (g − 60 s ; g]; NaN otherwise."""
    grid = np.asarray(grid, dtype=np.int64)
    out = np.full(grid.size, np.nan)
    ts_us, idx_px = np.asarray(ts_us, dtype=np.int64), np.asarray(idx_px, dtype=np.float64)
    if ts_us.size == 0:
        return out
    order = np.argsort(ts_us, kind="stable")
    ts_us, idx_px = ts_us[order], idx_px[order]
    pos = np.searchsorted(ts_us, grid, side="right") - 1
    valid = pos >= 0
    safe = np.where(valid, pos, 0)
    ok = valid & (grid - ts_us[safe] < g5.MINUTE_US) & np.isfinite(idx_px[safe])
    out[ok] = idx_px[safe][ok]
    return out


def index_minutes_from_candles(grid: np.ndarray, rows: np.ndarray) -> np.ndarray:
    """Option (b) on the minute grid: at each boundary g the close of the confirmed 1-minute index candle ending at g; NaN otherwise."""
    out = np.full(np.asarray(grid).size, np.nan)
    rows = rows[rows["confirm"] != 0] if rows.size else rows
    if rows.size:
        g5._place(np.asarray(grid, dtype=np.int64), rows["ts_us"].astype(np.int64), rows["close"].astype(np.float64), out)
    return out


def selection_window_us(spec: g5.Spec) -> tuple[int, int]:
    """`selection_rule.window_minutes`: [T0 ; end_exclusive of T01_funding_8h), before any data-gap extension."""
    t01 = next(t for t in spec.types if t.name == "T01_funding_8h")
    return type_window(spec, t01, 0)


def _selection_payload(record: dict) -> dict:
    return {k: record[k] for k in ("decision", "rule", "measures", "conditions", "primary", "sensitivity")}


def selection_sha256(record: dict) -> str:
    text = json.dumps(_selection_payload(record), sort_keys=True, separators=(",", ":"), allow_nan=False, ensure_ascii=True)
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def select_index_source(pairs: dict, window_utc: tuple[str, str] | None = None, coverage_min_share: float = D10_COVERAGE_MIN_SHARE,
                        agreement_max_bp: float = D10_AGREEMENT_MAX_MEDIAN_ABS_BP) -> dict:
    """D10 — the choice of the primary index source from coverage and agreement only (`selection_rule`). `pairs` = {index_id: (idx_a, idx_b)},
    the two per-minute series of one index restricted to the window minutes (NaN = no idx_minute). Coverage of (a) = covered pairs / all
    pairs (compared exactly as a fraction); agreement = median of |a − b| / b × 1e4 over the pairs where both exist (undefined without a
    common pair: the rule is not met). Nothing of T01 is read. The record carries its sha256."""
    from fractions import Fraction

    total = cov_a = cov_b = 0
    diffs: list[float] = []
    per_index = {}
    for index_id in sorted(pairs):
        a, b = (np.asarray(x, dtype=np.float64) for x in pairs[index_id])
        if a.shape != b.shape:
            raise ValueError(f"{index_id}: the two index series differ in length")
        ok_a, ok_b = np.isfinite(a) & (a > 0), np.isfinite(b) & (b > 0)
        both = ok_a & ok_b
        d = (np.abs(a[both] - b[both]) / b[both] * 1e4).tolist()
        per_index[index_id] = {"pairs": int(a.size), "a": int(ok_a.sum()), "b": int(ok_b.sum()), "common": int(both.sum()),
                               "median_abs_diff_bp": float(np.median(d)) if d else None}
        total += int(a.size)
        cov_a += int(ok_a.sum())
        cov_b += int(ok_b.sum())
        diffs.extend(d)
    median = float(np.median(np.array(diffs))) if diffs else None
    coverage_met = bool(total > 0 and Fraction(cov_a, total) >= Fraction(str(coverage_min_share)))
    agreement_met = bool(median is not None and median <= agreement_max_bp)
    primary = INDEX_OPTION_A if coverage_met and agreement_met else INDEX_OPTION_B
    record = {"decision": "D10 (2026-10-21)",
              "rule": {"sources": [INDEX_OPTION_A, INDEX_OPTION_B], "coverage_min_share": coverage_min_share, "agreement_max_median_abs_bp": agreement_max_bp,
                       "window_minutes": D10_TEXTS["window_minutes"], "rule": D10_TEXTS["rule"]},
              "measures": {"window_utc": list(window_utc) if window_utc else None, "index_ids": sorted(pairs), "pairs": total, "pairs_a": cov_a, "pairs_b": cov_b,
                           "coverage_a": (cov_a / total) if total else None, "coverage_b": (cov_b / total) if total else None, "common_pairs": len(diffs),
                           "median_abs_diff_bp": median, "per_index": per_index},
              "conditions": {"coverage_met": coverage_met, "agreement_met": agreement_met},
              "primary": primary, "sensitivity": INDEX_OPTION_B if primary == INDEX_OPTION_A else INDEX_OPTION_A}
    record["sha256"] = selection_sha256(record)
    return record


def verify_index_selection(record, spec: g5.Spec | None = None) -> list[str]:
    """Problems of a recorded D10 choice: absent, hash differing from its content, rule parameters other than the owner's, or a primary
    that does not follow from the recorded conditions."""
    if not isinstance(record, dict) or "sha256" not in record:
        return ["no recorded index choice (D10: T01 is never evaluated without it)"]
    problems = []
    try:
        if selection_sha256(record) != record["sha256"]:
            problems.append("the recorded index choice does not match its sha256")
    except (KeyError, TypeError, ValueError) as error:
        return [f"the recorded index choice is malformed ({error})"]
    rule = record["rule"]
    if rule.get("coverage_min_share") != D10_COVERAGE_MIN_SHARE or rule.get("agreement_max_median_abs_bp") != D10_AGREEMENT_MAX_MEDIAN_ABS_BP:
        problems.append("the recorded index choice used other thresholds than D10")
    cond = record["conditions"]
    expected = INDEX_OPTION_A if cond.get("coverage_met") and cond.get("agreement_met") else INDEX_OPTION_B
    if record["primary"] != expected or {record["primary"], record["sensitivity"]} != {INDEX_OPTION_A, INDEX_OPTION_B}:
        problems.append("the recorded primary does not follow from the recorded conditions")
    if spec is not None:
        lo, hi = selection_window_us(spec)
        if (record["measures"].get("window_utc") or [None, None]) != [g5._iso(lo), g5._iso(hi)]:
            problems.append("the recorded index choice was computed on another window")
    return problems


def use_index_source(series_by_inst: dict, source: str) -> None:
    """Put the per-minute index of `source` (D10: `index_sources` of each series) in `series.index`; the premium cache is cleared."""
    for series in series_by_inst.values():
        sources = series.cache.get("index_sources")
        if sources is None or source not in sources:
            raise ValueError(f"{series.inst}: no index series {source!r} loaded (D10)")
        series.index = np.array(sources[source], dtype=np.float64)
        for key in [k for k in series.cache if k == "premium" or (isinstance(k, tuple) and k and k[0] == "p_hat")]:
            del series.cache[key]
        series.cache["index_source_used"] = source
        series.coverage["index_minutes"] = int(np.isfinite(series.index).sum())
        series.coverage["index_source_used"] = source


def apply_index_selection(series_by_inst: dict, spec: g5.Spec) -> dict:
    """D10, run before any T01 evaluation: the two index series of each distinct index id on the window minutes, the choice
    (`select_index_source`), then the primary put in every series (`use_index_source`). Returns the hashed record."""
    lo, hi = selection_window_us(spec)
    pairs: dict = {}
    for series in series_by_inst.values():
        sources = series.cache.get("index_sources")
        if sources is None:
            raise ValueError(f"{series.inst}: the D10 index sources were not loaded")
        if series.meta.index_id in pairs:
            continue
        mask = (series.grid_us >= lo) & (series.grid_us < hi)
        pairs[series.meta.index_id] = (np.asarray(sources[INDEX_OPTION_A])[mask], np.asarray(sources[INDEX_OPTION_B])[mask])
    record = select_index_source(pairs, (g5._iso(lo), g5._iso(hi)))
    use_index_source(series_by_inst, record["primary"])
    return record
