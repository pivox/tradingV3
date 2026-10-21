"""Event study of the G5 clock / calendar types on the 1-minute X-Perp history (Gate 1) or on the capture tape (Gate 2),
driven entirely by the frozen machine specs `specs/g5_gate1_spec.json` and `specs/g5_gate2_spec.json` (schema `g5-spec/2`) —
nothing here is searched.

    python -m scripts.research_lp.cli g5-eval --tape DIR --spec specs/g5_gate1_spec.json --expect-spec-sha256 HEX \\
        --out-json R.json --out-md R.md [--instruments-file SNAPSHOT] [--expect-funding-seal-sha256 HEX]
    python -m scripts.research_lp.cli g5-eval --tape CAPTURE --spec specs/g5_gate2_spec.json --expect-spec-sha256 HEX \\
        --gate1-result G1.json --expect-gate1-result-sha256 HEX --p3-counts-file P3 [--gate1-spec G1_SPEC] \\
        [--index-tape DIR --expect-index-tape-sha256 HEX] --out-json R.json --out-md R.md

Authority: `docs/handbook/research/LP-G5-DRAFT-PREREGISTRATION.md` (§2.3 (B) i–xxii, §4–§8, §10, Annexes A–C). The text
of the protocol prevails over the spec; the spec is the only file the evaluator reads; this module implements it.

**Strict reading (B xviii).** `parse_spec` accepts the schema `g5-spec/2` only. Every key, at every level, must be
known, and every value with an implemented meaning must be the implemented one: an unknown key, an unknown value
or a missing key raises `SpecError` (fail-closed: nothing is evaluated). `requires` must name exactly the capabilities
of `CAPABILITIES` that the spec's source needs (`HISTORY_REQUIRES`, `CAPTURE_REQUIRES`): a capability this module does
not implement, or one it implements but the spec omits, is refused. Descriptive texts (`note`, `rule`, …) are copied
into the reports; texts that state a rule (`formula`, `criteria.C4`, `classifier_class`, …) are matched against the
implemented rule (numbers in them are parsed and used). The spec is checked against itself at parse time: costs per
execution role against `expected_cost_bp`, `eligible_from_utc` against `list_time_utc + days`, the T05 calendar audit
(116 / 105 / 103 / 53–52), the T01 instant audit (211, 0.474), the economic break-evens and the fetch-plan request
estimates (13 023 / 22 606 / 22 606 / 50) are recomputed from the spec's own lists (calcul d'audit, no data).

**Market data only under a frozen spec (B xviii).** `command_eval` opens no market value unless the spec is `FROZEN`,
its `freeze_fill` complete, its sha256 equal to `--expect-spec-sha256`, the code digest equal to
`freeze_fill.code_digest_sha256`, the instruments snapshot hash equal to `freeze_fill.instruments_snapshot_sha256` and
the spec's universe equal to the snapshot (listTime, tickSz, ctVal, uly), the sealed funding receipt equal to
`freeze_fill.funding_seal_receipt_sha256` and its archive equal to the receipt (refuse_run otherwise, CTO M4, m3). A spec / code / status / freeze_fill defect
gives `G5_GATE1_INCONCLUSIVE` for every type without reading anything (exit 2). `run_study` evaluates in-memory series
(synthetic tests): there the frozen status is recorded, not required.

Implemented (§2.3 (B)):
- (i) the reference price is `candles_1m.close` (`c(t)` = close of the candle opening at t − 60 s); mark and index only
  feed the classifier; no forward fill; unconfirmed candles dropped and counted;
- (ii) T01 instants = `fundingTime` of `funding_hist` kept when the gaps to the previous and to the next settlement are
  8 h ± 1 min (unknown neighbour excluded and counted), realized rate required (never the predicted rate); the instants
  removed by the next-neighbour criterion alone are counted (`removed_by_next_neighbour_only`: h+ is known after the decision,
  CTO M1, a protocol decision at the freeze); at the Gate 2, h+ is the decision-time `nextFundingTime` (`NEXT_AT_DECISION_RULE`);
- (iii) D_cov per instrument = its first settlement whose predecessor is served (sealed archive + rows), global D_cov
  from the sealed receipt — required, equal to `freeze_fill.funding_seal_receipt_sha256` (CTO M4); T01 events and controls
  inside D_cov; unknown D_cov ⇒ T01 INCONCLUSIVE;
- (iv) one classifier P̂(t_arm) = Σ k·π / Σ k over the 480 minute boundaries ending at t_arm = h − 5 (π = (m − idx) / idx,
  weights 1…480, renormalised over the present ones, ≥ 90 % present), ±0.0009, the same function at events and controls;
- (v) C6: the 3 × 3 matrix (class of P̂ × class of R_h at ±0.0004), sensitivity, false-positive rate, denominators;
- (vi) the `through` fill proxy of T01 on the six candles opening at h−5 … h, at events and controls;
- (vii) eligibility: an instrument-instant exists only if its footprint starts ≥ listTime + 30 days (events, controls);
- (viii) zero-volume key candles exclude the instrument-instant; an instrument missing > 20 % of its event-footprint
  minutes leaves the type;
- (ix) unit = the instant (equal weight within an instant, ≥ 1 instrument), n = unique event instants;
- (x) T05 = 09:30 America/New_York of the frozen NYSE days, CPI and Employment Situation dates excluded;
- (xi) controls at the type's minute of the hour, every admissible hour of the instrument's event days, admissible
  when the control footprint is ≥ 60 min from every footprint of the exclusion union (settlements of the instrument, the
  type's default grid, T05 instants, sessions, macro releases); no random draw;
- (xii) sessions and macro releases from local time (`zoneinfo`; New York checked against `events.us_dst_active`);
- (xiii) costs by execution role (maker_maker 13 bp at the design rule, 9.5 bp with the A1 entry leg measured by the fill
  conditioning; taker_maker 15.5 bp; stress × 1.5 on every term);
- (xiv) T05 funding outside D_cov assumed zero, counted (`funding_unknown`); control funding computed, non-zero counted;
- (xv) verdict precedence INCONCLUSIVE > NOT_INFORMATIVE > criteria C1–C7 then Holm; FAIL annotated;
- (xvi) `holm_local_confirmed` (K = 2, α 0.10) and `pass_programme_immediate` (p ≤ 0.10 / 7); no `pass_programme` field;
- (xvii) no information after the decision: a classifier evaluated elsewhere than at t_arm, a sign window ending after
  `arm_min`, a lookback ending after t_arm are refused at parse time;
- (xviii) the strict schema, `requires`, the frozen status, the END_EXCLUSIVE constant, the universe against the snapshot;
- (xx) INCONCLUSIVE and NOT_INFORMATIVE types are sealed: D, LB90, UB90, p, means, criteria and the type's descriptive
  reports go to `sealed/<type>.json` (sha256 printed), nothing else; economic reading at 365 / 252 days a year;
  day-block bootstrap (10 000 resamples, seed 20261102; 7-day blocks descriptive); the descriptive reports of §9 under
  the warning of `descriptive.rule`;
- (xxi) Gate 2 (`source: capture`, `specs/g5_gate2_spec.json`, read strictly by `g5_gate2.parse_capture_spec`: owner decisions
  pending ⇒ FROZEN refused, identity with the Gate 1 spec, calcul d'audit of its calendars and control hours): rows before the
  quarantine floor dropped; c(t) = last print of the minute, minute bars from the prints, a key minute without print excludes
  (`minute_bars`, `load_capture_series`); the entry and exit simulated on the captured book (`measure_lp_sim`: post-only at the
  own touch decided at h − 5 for T01, resting until h + 1, a fill within the grace after a settlement crossing it; taker at the
  opposite touch + slippage for T05; passive exit then taker remainder; stress = fees and slippage × 1.5 plus 0.5 × the design
  value of each measured term; band rule counted; a book ending before the planned exit or a stale exit quote ⇒ `no_exit_quote`);
  P̂ on the captured mark and the index of the P-G5-10 (ii) choice (option b: an index tape checked against its register sha256;
  refused at FROZEN while no fetcher can read it, CTO M3); C6 and the funding cost on the settled rate of the `funding-rate`
  channel attributed to the **previous** settlement (`attribute_settled_rates`, docs-v5, CTO B1); per-type windows [T0 ; end)
  with the data-gap extension, unusable instrument-days
  removed (share > 20 % ⇒ INCONCLUSIVE); the types inscribed by the Gate 1 result (FAIL: not evaluated, p = 1); no sealing;
  the deferred strata of §9.8 as descriptive reports.

Amendment A1 (LP-G5 « Amendement A1 (2026-10-21) », written before any freeze and any reading; capabilities `a1_*`, Gate 1 only):
- the adverse selection of the T01 entry leg is **measured by the conditioning on the fill** (`events[].adverse_selection_entry_leg:
  measured_by_fill_proxy`): 0 in the assumed cost of the filled (primary) measure, the passive exit leg keeps its design value; T01
  9.5 bp (stress 14.25) instead of 13 (19.5), the pre-A1 total kept as a reported sensitivity (`cost_sensitivity_pre_a1`); without
  the conditioning (the descriptive variant without the fill proxy) nothing measures the entry leg and the design value applies;
- the reported, never decisive A / B / C table (A = signal: gross return of every classified instrument-instant; B = execution: the
  same return at the filled ones; C = net: the primary net_bp and the portfolio reading), the filled / unfilled analysis, the taker
  variant of T01, the chronological stability, the diagnostic classification (a) / (b) / (c) / (d) written in advance in the
  spec's `descriptive.a1` block (reported, never a verdict); C1–C7, Holm and the economic gate unchanged;
- a footprint crossing the window end (END_EXCLUSIVE in the Gate 1 spec) or starting before the eligibility is excluded and counted
  (`crosses_end_exclusive`, `excluded_ineligible`), at the events and at the controls.

Statistics: D = mean(event instants) − mean(control instants); bootstrap over blocks of UTC days (events and controls
of a day resampled together; LB90 / UB90 = quantiles 0.10 / 0.90; one-sided p = P*(D* ≤ 0), `GATES` §2.2). Block sums
use `math.fsum`; the resample sums are integer-count matrix products of those block sums.

Guards: the spec window may not touch the quarantine [2026-10-14, 2026-11-02); every history row at or after
`g5_fetch.END_EXCLUSIVE` is dropped; `end_exclusive_utc` of the spec must equal the constant.
"""

from __future__ import annotations

import argparse
import bisect
import json
import math
import re
import tarfile
import time
from dataclasses import dataclass, field
from datetime import date as _date
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

import numpy as np

from . import events as ev
from . import g5_fetch, lp_sim, tape
from . import xperp_capture as xc

EVALUATOR_VERSION = "g5_eval/3"
SPEC_SCHEMA = "g5-spec/2"
MINUTE_US = 60 * tape.SECOND_US
HOUR_US = 60 * MINUTE_US
DAY_US = tape.DAY_S * tape.SECOND_US
QUARANTINE_START_US = g5_fetch.END_EXCLUSIVE_US  # 2026-10-14T00:00:00Z
QUARANTINE_END_US = xc.quarantine_floor_us()  # 2026-11-02T00:00:00Z
PAD_DAYS = 1  # read one day before the window: P̂ looks back 480 minutes, footprints start before h
STATUSES = ("DRAFT_NOT_FROZEN", "FROZEN")
ROLES = ("maker_maker", "taker_maker")
VERDICTS = ("PASS", "FAIL", "NOT_INFORMATIVE", "INCONCLUSIVE")
CRITERIA = ("C1", "C2", "C3", "C4", "C5", "C6", "C7")
UTC = timezone.utc
NEW_YORK = "America/New_York"

# The versioned whitelist of capabilities (B xviii). Each key is a (B) function of the protocol, implemented here.
CAPABILITIES: dict[str, str] = {
    "spec_schema_g5_v2_strict_keys": "(B) xviii: schema g5-spec/2, key whitelist at every level, implemented values only",
    "status_frozen_required_for_market_data": "(B) xviii: FROZEN status and a complete freeze_fill before any market value is read",
    "end_exclusive_constant_check": "(B) xviii: end_exclusive_utc equal to g5_fetch.END_EXCLUSIVE, quarantine floor equal to xperp_capture's",
    "universe_from_spec_checked_against_snapshot": "(B) xviii, vii: universe.analysed checked against the freeze snapshot (listTime, tickSz, ctVal, uly)",
    "eligibility_list_time_plus_days": "(B) vii: instrument-instant only if its footprint starts at listTime + days or later (events and controls)",
    "price_source_last_only": "(B) i: reference price candles_1m.close; mark and index for the classifier only; no mixed-source return",
    "candle_confirm_and_vol_zero_exclusion": "(B) viii: confirm = 0 dropped and counted; vol = 0 on a key candle excludes the instrument-instant",
    "unit_instant_equal_weight": "(B) ix: unit = instant, equal weight within an instant, n = unique event instants",
    "generator_funding_settlements_interval_filter": "(B) ii: T01 instants = fundingTime with 8 h ± 1 min to both neighbours, realized rate required",
    "funding_coverage_window": "(B) iii: D_cov from the sealed receipt (required, freeze_fill.funding_seal_receipt_sha256); T01 events and controls inside D_cov; unknown D_cov => INCONCLUSIVE",
    "classifier_premium_wavg_at_arm": "(B) iv: one classifier P_hat(t_arm), weights 1..480, coverage >= 90 %, +-0.0009, events and controls",
    "classifier_validation_confusion_3x3": "(B) v: C6 matrix P_hat class x R_h class, sensitivity, false positives, denominators",
    "fill_proxy_through_candles": "(B) vi: through fill proxy on the candles opening from t_arm to h, events and controls",
    "generator_local_time_exclude_dates": "(B) x: T05 at 09:30 America/New_York on frozen NYSE days, CPI / Employment Situation excluded",
    "controls_minute_of_hour_all_admissible": "(B) xi: controls at the type's minute of the hour, every admissible hour, no draw",
    "exclusion_by_footprint_with_margin": "(B) xi: admissibility by footprints at margin_min of every exclusion footprint",
    "sessions_local_time_dst": "(B) xii: sessions converted from local time with zoneinfo (DST)",
    "macro_filter_new_york_time": "(B) xii: CPI / Employment Situation / FOMC in New York time, checked against events.us_dst_active",
    "costs_by_execution_role": "(B) xiii: maker_maker / taker_maker cost terms, stress on every term, expected totals checked",
    "funding_unknown_outside_coverage_counted": "(B) xiv: funding outside D_cov assumed zero and counted; control funding computed",
    "no_information_after_decision": "(B) xvii: no condition, classifier or sign observed after arm_min",
    "verdict_c1_c7_precedence": "(B) xv: INCONCLUSIVE > NOT_INFORMATIVE > C1-C7 then Holm; FAIL annotation",
    "holm_local_and_programme_fields": "(B) xvi: holm_local_confirmed (K = 2) and pass_programme_immediate (p <= alpha / m_max)",
    "sealed_statistics_for_inconclusive_and_not_informative": "(B) xx: sealed/<type>.json for INCONCLUSIVE and NOT_INFORMATIVE, sha256 printed",
    "economic_reading_days_per_year": "(B) xx: plafond_USD_an with the type's days per year, S_eco, pursued / not pursued",
    "bootstrap_day_blocks": "(B) xx: day-block bootstrap, events and controls jointly, seed and resamples of the spec",
    "descriptive_reports_g5_v2": "(B) xx: the descriptive reports of section 9, warning at the head of the Markdown",
    # (B) xxi — the Gate 2 (`source: capture`, `specs/g5_gate2_spec.json`), implemented in `g5_gate2` and in the capture paths below.
    "owner_decisions_resolved_required_for_freeze": "(B) xxi: owner_decision_pending anywhere (or a null) refuses FROZEN; at DRAFT it makes every type INCONCLUSIVE",
    "capture_source_quarantine_floor_check": "(B) xxi: capture rows before xperp_capture.QUARANTINE_FLOOR dropped and counted; the tape's not_before checked",
    "price_last_print_per_minute": "(B) xxi: c(t) = last print with ts in (t - 60 s ; t]; minute bars (low, high, prints) built from the prints",
    "key_minute_without_print_exclusion": "(B) xxi: a key minute without any print excludes the instrument-instant (the capture analogue of vol = 0)",
    "classifier_mark_and_index_from_capture_or_index_history": "(B) xxi: P_hat on the captured mark and the index of the owner's P-G5-10 (ii) choice",
    "gate2_sim_maker_post_only_touch": "(B) xxi: T01 post-only at the own touch decided at h + arm_min, resting until h + rest_until_min (lp_sim fill rule)",
    "gate2_sim_taker_entry_opposite_touch_slippage": "(B) xxi: T05 taker entry at the opposite touch after the latency, plus the slippage per taker leg",
    "gate2_sim_passive_exit_taker_remainder": "(B) xxi: passive exit at the opposite touch from the exit offset until exit_until_min, remainder taker",
    "gate2_funding_fill_grace_crossing": "(B) xxi: a fill within funding_fill_grace_min after a settlement crosses it and pays",
    "gate2_classifier_validation_funding_channel": "(B) xxi: C6 on the settled rate of the funding-rate channel, attributed to the previous settlement (docs-v5)",
    "gate2_stress_add_on_for_measured_terms": "(B) xxi: C3 stress = fees and slippage x m plus (m - 1) x the design value of each measured term",
    "gate2_window_end_per_type": "(B) xxi: per-type window end (T05 per P-G5-10 (i)), footprint inside [T0 ; end), data-gap extension",
    "gate2_inscription_from_gate1_result": "(B) xxi: the Gate 1 result decides which types are evaluated (FAIL: not evaluated, p = 1)",
    "gate2_quality_unusable_instrument_day": "(B) xxi: books5 coverage < 90 % or a stale period > 10 min: instrument-day removed; share > 20 % => INCONCLUSIVE",
    "calendars_cover_the_window": "(B) xxi, P-G5-5: the scoring window (every type end, the data-gap extension) inside calendars.covers; FROZEN needs complete lists",
    # Amendment A1 (LP-G5, 2026-10-21, before any freeze and any reading) — the Gate 1.
    "a1_entry_adverse_selection_measured_by_fill_proxy": "A1: adverse_selection_entry_leg measured_by_fill_proxy: 0 in the assumed cost of the filled measure, exit leg at "
                                                          "its design value (T01 9.5 / 14.25 bp), pre-A1 total reported as a sensitivity; checked against expected_cost_bp",
    "a1_footprint_boundaries_counted": "A1: a footprint crossing the window end (END_EXCLUSIVE) or starting before the eligibility excluded and counted, events and controls",
    "a1_descriptive_abc_signal_execution_net": "A1: A (signal, every classified instant) / B (execution, filled) / C (net, primary and portfolio) table, events, "
                                               "controls and difference, n, fill rate, mean, median, LB90 / UB90 (no p-value)",
    "a1_descriptive_filled_vs_unfilled_signal": "A1: A at the filled against A at the unfilled instants, events and controls, gap, fill rates",
    "a1_descriptive_taker_variant_without_fill_conditioning": "A1: taker entry at c(t_arm) + design half spread, no fill conditioning, taker_maker costs",
    "a1_descriptive_chronological_stability": "A1: D and n per calendar month and per half of the type's window (C and A)",
    "a1_diagnostic_classification_reported_never_a_verdict": "A1: (a) no edge / (b) too weak for the costs / (c) badly executed / (d) insufficiently "
                                                            "documented, rules of descriptive.a1, reported in the type's descriptives, never a verdict",
}
A1_CAPABILITIES = tuple(k for k in CAPABILITIES if k.startswith("a1_"))
GATE1_ONLY = ("candle_confirm_and_vol_zero_exclusion", "funding_coverage_window", "fill_proxy_through_candles", "funding_unknown_outside_coverage_counted",
              "sealed_statistics_for_inconclusive_and_not_informative") + A1_CAPABILITIES
GATE2_ONLY = ("owner_decisions_resolved_required_for_freeze", "capture_source_quarantine_floor_check", "price_last_print_per_minute", "key_minute_without_print_exclusion",
              "classifier_mark_and_index_from_capture_or_index_history", "gate2_sim_maker_post_only_touch", "gate2_sim_taker_entry_opposite_touch_slippage",
              "gate2_sim_passive_exit_taker_remainder", "gate2_funding_fill_grace_crossing", "gate2_classifier_validation_funding_channel", "gate2_stress_add_on_for_measured_terms",
              "gate2_window_end_per_type", "gate2_inscription_from_gate1_result", "gate2_quality_unusable_instrument_day", "calendars_cover_the_window")
HISTORY_REQUIRES = tuple(k for k in CAPABILITIES if k not in GATE2_ONLY)
CAPTURE_REQUIRES = tuple(k for k in CAPABILITIES if k not in GATE1_ONLY)

# Texts that state an implemented rule: the spec must carry exactly these (a different text would be a different rule).
PRICE_RULES = {"history": {"source": "last", "series": "candles_1m.close", "boundary_convention": "c(t) = close of the candle opening at t - 60 s"}}
PRICE_FIXED = {"mixing_sources": "refused", "mark_and_index_use": "classifier_premium_proxy_only", "confirm_zero_rows": "drop_and_count",
               "missing_key_price": "exclude_instrument_instant_and_count", "forward_fill": "none", "above_max_missing_share": "exclude_instrument_from_type_and_count"}
FROZEN_CANDLE = {"history": {"rule": "exclude_instrument_instant_if_vol_eq_0_on_any_key_candle", "key_candles": "events[].key_candles_end_min",
                             "count_field": "excluded_vol_zero", "report": "share_by_instrument"}}
UNIT_FIXED = {"kind": "instant", "instant": "minute-aligned UTC timestamp", "instrument_weight": "equal_within_instant", "instant_weight": "equal",
              "n_definition": "number of unique event instants with >= min_instruments_per_instant retained instruments"}
UNIT_REPORTS = ("instruments_per_instant_distribution", "stratum_min_instruments_3")
STAT_DIFFERENCE = "D = mean over event instants - mean over control instants"
P_VALUE_RULE = "share of resamples with D* <= 0"
FUNDING_RULES = {"history": {"source": "funding_hist.realized_rate"}}
FUNDING_FIXED = {"formula": "funding_bp = -side * sum(realized rates of the instrument's settlements T with entry < T <= exit) * 1e4",
                 "outside_coverage": "assumed_zero_not_observed_and_counted", "count_field": "funding_unknown", "cap_stress": "descriptive_only"}
CONTROLS_FIXED = {"candidates": "every hour of the type's control days at controls.minute_of_hour", "selection": "all_admissible",
                  "admissible_if": "control footprint end <= exclusion start - margin_min or control footprint start >= exclusion end + margin_min, for every exclusion footprint",
                  "funding_at_controls": "computed; expected 0; any non-zero count reported as anomaly"}
SESSION_DAYS = ("nyse_trading_days", "mon_fri", "all", "fri")
PREMIUM_PROXY = {"history": "pi(g) = (m(g) - idx(g)) / idx(g) at minute boundary g, m and idx = closes of mark_1m and index_1m candles ending at g",
                 "capture": "pi(g) = (m(g) - idx(g)) / idx(g) at minute boundary g ; m = classifier_index_source.mark_minute ; idx = classifier_index_source (P-G5-10 ii)"}
FILL_PROXY_FIXED = {"limit_price": "c(t_arm)", "long_filled_if": "min(low) < c(t_arm) - tick_sz", "short_filled_if": "max(high) > c(t_arm) + tick_sz",
                    "unfilled": "exclude_from_primary_and_count", "report": "fill_rate_events_and_controls"}
VALIDATION_FIXED = {"report": "3x3 matrix (classifier class x rate class), always printed", "sensitivity": "share of rate class != 0 with classifier class == rate class",
                    "false_positive_rate": "share of rate class == 0 with classifier class != 0"}
CRITERIA_TEXT = {
    "C1": "D point >= threshold_bp and LB90(D) > 0",
    "C2": "mean event net_bp (point, costs included) > 0",
    "C3": "mean event net_bp at stress_multiplier_all_cost_terms (point) > 0",
    "C4": "D point > 0 in each half ; halves = sorted distinct event days of the type cut at the lower median day (first half = days <= median) ; controls of the same days",
    "C6": "T01 only: validation of the classifier (events[].validation)",
    "C7": "T01 only: D point > 0 on the event instants at 16:00 UTC against the controls of the same UTC days",
}
C5_RE = re.compile(r"^LB90\(D\) > 0 after removing the ceil\((?P<share>0\.\d+) n\) event instants with the highest per-instant net ; controls unchanged ; same bootstrap seed and resamples$")
INCONCLUSIVE_GLOBAL = ("spec_sha256_mismatch", "code_digest_mismatch", "status_not_frozen", "freeze_fill_null", "stream_end_reason_missing")
FETCH_ERROR_RE = re.compile(r"^fetch_job_error_share_gt_(?P<a>\d)_(?P<b>\d+)$")
INCONCLUSIVE_TYPE_FIXED = ("funding_coverage_unknown", "C6_failed_or_not_computable", "classifier_index_source_unresolved_or_unavailable")
# The share of instrument-days without P̂ (LP-G5 §4.5): `proxy_…` in the Gate 1 spec, `classifier_…` in the Gate 2 spec (same rule).
PROXY_MISSING_RE = re.compile(r"^(?P<kind>proxy|classifier)_missing_instrument_day_share_gt_(?P<a>\d)_(?P<b>\d+)$")
SEALING_PRINTED = ("verdict", "reasons", "n_instants", "n_days", "exclusion_counts", "fill_rate", "validation_matrix")
SEALING_SEALED = ("D", "lb90", "ub90", "p_one_sided", "event_mean", "control_mean", "criteria_values", "halves", "c5", "c7", "by_month", "by_instrument", "by_hour",
                  "economic", "type_descriptives")
GATE2_INSCRIPTION_VALUES = {"PASS": ("inscribed",), "NOT_INFORMATIVE": ("inscribed_automatically_without_futility",),
                            "INCONCLUSIVE": ("inscribed_automatically_without_futility",), "FAIL": ("not_inscribed",)}
ECONOMIC_FIXED = {
    "admission_order_within_instant": "inst_id_ascending",
    "fills_per_day": "admitted filled orders / number of days of the type's calendar (T01: calendar days of funding_coverage ; T05: NYSE trading days of the window with >= 1 eligible instrument, macro days included in the denominator)",
    "formula": "plafond_usd_an = fills_per_day * order_notional_usd * mean_event_net_bp / 1e4 * days_per_year",
    "measure": "absolute event net_bp (round trip), never D",
    "pursued_if": "point >= s_eco and (ub90 >= 2 * s_eco or lb90 > 0)",
    "pos_mode": "both hypotheses reported",
}
BREAK_EVEN_RE = re.compile(r"^(?P<type>[A-Za-z0-9]+_[A-Za-z0-9_]+?)_(?P<k>\d+)_fills_per_(?P<unit>event_day|day)$")
DESCRIPTIVE_REPORTS = (
    "T01_classified_by_realized_rate", "T01_dead_zone_thresholds_0_0006_minus_0_0005", "T01_without_fill_proxy", "T01_dose_abs_rate",
    "T01_v_shape_minus15_0_plus15", "T01_by_photo_hour_00_08_16", "T01_friday_0800", "T01_us_business_day_vs_not", "T01_stratum_T02_4h_2h_1h",
    "T01_mark_reference_inside_coverage", "T01_premium_balance_events_vs_controls", "T01_funding_cap_stress",
    "T05_horizons_15_30", "T05_move_ge_1_tick", "T05_macro_days_separately", "T05_ten_am_et_release_days", "T05_brr_adjacency",
    "by_month", "by_instrument", "by_sign", "regime_rv24_above_median", "instruments_per_instant_ge_3", "excluded_share_by_instrument",
    "block_days_7", "minute_zero_T07", "hourly_seasonality_last_price", "weekday_seasonality", "funding_intervals_histogram", "portfolio_reading",
    # Amendment A1 (2026-10-21): reported, never decisive.
    "abc_signal_execution_net", "T01_filled_vs_unfilled_signal", "T01_taker_variant_without_fill_conditioning", "T01_cost_sensitivity_pre_a1",
    "chronological_stability_month_and_window_halves", "diagnostic_classification_a1")
COMMON_REPORTS = ("minute_zero_T07", "hourly_seasonality_last_price", "weekday_seasonality", "funding_intervals_histogram")
A1_PER_TYPE_REPORTS = ("abc_signal_execution_net", "chronological_stability_month_and_window_halves", "diagnostic_classification_a1")
A1_FILL_PROXY_SUFFIXES = ("_filled_vs_unfilled_signal", "_taker_variant_without_fill_conditioning")  # <label> of every type with a fill proxy
A1_SENSITIVITY_SUFFIX = "_cost_sensitivity_pre_a1"  # <label> of every type whose entry-leg adverse selection is measured by the fill proxy
PER_TYPE_REPORTS = ("by_month", "by_instrument", "by_sign", "regime_rv24_above_median", "instruments_per_instant_ge_3", "excluded_share_by_instrument", "block_days_7",
                    "portfolio_reading") + A1_PER_TYPE_REPORTS
# Amendment A1: the texts of `events[].adverse_selection_entry_leg`, `events[].cost_sensitivity_pre_a1` and `descriptive.a1` (matched word for word).
ENTRY_ADVERSE_MEASURED = "measured_by_fill_proxy"
ENTRY_ADVERSE_DESIGN = "assumed_design_value"
ENTRY_ADVERSE_VALUES = (ENTRY_ADVERSE_MEASURED, ENTRY_ADVERSE_DESIGN)
COST_SENSITIVITY_USE = "reported sensitivity only: C2 / C3 values at the pre-A1 total ; never a criterion"
A1_STATUS = "amendment A1 (2026-10-21), written before any freeze and any reading ; reported, never decisive, never a verdict"
A1_DEFINITIONS = {
    "A_signal": "gross signed return of the reference price over return_window_min at every classified instrument-instant (classifier or sign passed, fill proxy off) ; "
                "no funding, no cost",
    "B_execution": "the same gross return at the instrument-instants retained by the primary measure (filled by the fill proxy ; a taker entry is filled by construction)",
    "C_net": "the primary net_bp = B + funding - the costs of the type (events[].expected_cost_bp) ; with the portfolio reading",
    "statistics": "per row (events, controls, events - controls): n instants, n instrument-instants, fill rate, mean, median, LB90 and UB90 of the day-block bootstrap "
                  "of the statistics block ; no p-value",
    "filled_vs_unfilled": "A at the filled against A at the unfilled classified instrument-instants, events and controls (instant means), gap = filled - unfilled ; "
                          "fill rates of the events and of the controls",
    "taker_variant": "taker entry at c(t_arm) plus costs.taker_entry_half_spread_bp, no fill conditioning, costs of the taker_maker role, same funding, same exit",
    "chronological_stability": "D and n per calendar month (UTC) and per half of the type's window (calendar days, first half = the first floor(L / 2) days), "
                               "for C (net_bp) and A",
}
DIAGNOSTIC_PRECEDENCE = ("d_insufficiently_documented", "a_no_edge", "c_badly_executed", "b_too_weak_for_costs", "none_of_the_four")
DIAGNOSTIC_RULES = {
    "d_insufficiently_documented": "verdict NOT_INFORMATIVE or INCONCLUSIVE, or n below power_floor, or no LB90 / UB90 for A_event - A_control, or "
                                   "LB90(A_event - A_control) <= 0 and UB90(A_event - A_control) >= threshold_bp",
    "a_no_edge": "LB90(A_event - A_control) <= 0",
    "c_badly_executed": "LB90(A_event - A_control) > 0, the type has a fill proxy, UB90(A_filled - A_unfilled at the events) < 0, mean B_event < mean A_event, "
                        "mean C_event <= 0 and mean taker-variant net at the events > 0",
    "b_too_weak_for_costs": "LB90(A_event - A_control) > 0 and mean C_event <= 0",
    "none_of_the_four": "LB90(A_event - A_control) > 0 and mean C_event > 0",
}
DIAGNOSTIC_LABELS = {"d_insufficiently_documented": "(d) insuffisamment documenté", "a_no_edge": "(a) signal sans avantage",
                     "c_badly_executed": "(c) signal exploitable mal exécuté", "b_too_weak_for_costs": "(b) avantage trop faible pour les coûts",
                     "none_of_the_four": "aucun des quatre (avantage et net positifs)"}
DEAD_ZONE_RE = re.compile(r"^(?P<label>T\d+)_dead_zone_thresholds_(?P<hi_a>\d)_(?P<hi_b>\d+)_minus_(?P<lo_a>\d)_(?P<lo_b>\d+)$")
HORIZONS_RE = re.compile(r"^(?P<label>T\d+)_horizons_(?P<h>\d+(?:_\d+)*)$")
FETCH_END_TEXT = "g5_fetch.END_EXCLUSIVE (module constant, never a parameter)"
ESTIMATE_RE = re.compile(r"^coverage_start = (?P<date>\d{4}-\d{2}-\d{2})\b")
SETTLEMENT_EXCLUSION_RE = re.compile(r"^settlements of the instrument read in (?P<stream>funding_hist|the capture funding layout) \(all intervals\), footprint \[s(?P<a>[+-]\d+) ; s(?P<b>[+-]\d+)\]$")
SETTLEMENT_STREAM = {"history": "funding_hist", "capture": "the capture funding layout"}
GRID_EXCLUSION_RE = re.compile(r"^default settlement grid of the type \(controls\.default_settlement_grid_utc_hours\), every day, footprint \[s(?P<a>[+-]\d+) ; s(?P<b>[+-]\d+)\]$")
TYPE_EXCLUSION_RE = re.compile(r"^(?P<label>\S+) event instants \(generator of (?P<type>[A-Za-z0-9_]+)\), footprint \[h(?P<a>[+-]\d+)? ; h(?P<b>[+-]\d+)?\], for every instrument$")
SESSIONS_EXCLUSION = "sessions (frozen list, local time, DST-aware)"
MACRO_EXCLUSION = "macro releases (macro_filter, New York time)"
CLASS_RE = re.compile(r"^\+1 if (?P<field>P_hat\(t_arm\)|realized_rate) >= (?P<hi>\d+(?:\.\d+)?), -1 if <= (?P<lo>-\d+(?:\.\d+)?), 0 otherwise$")
POPULATION_RE = re.compile(r"^raw (?P<label>\S+) instrument-settlements \(eligible, interval filter passed, realized rate present, classifier computable\)$")
CONTROL_DAYS_RE = {"funding_settlements": re.compile(r"^days on which the instrument has >= 1 raw (?P<label>\S+) event \(before classifier and fill proxy\)$"),
                   "local_time": re.compile(r"^days on which the instrument has a raw (?P<label>\S+) event$")}
WEIGHTS_TEXT = "k = 1..{n}, oldest = 1, boundary t_arm = {n} ; renormalised over present minutes"
COVERAGE_START_TEXT = "first settlement of funding_hist whose predecessor is also served (manifest coverage_start_us, end_reason required)"
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")


class SpecError(ValueError):
    """The specification is incomplete, inconsistent or asks for something this evaluator does not implement; nothing is evaluated."""


def _json_default(value):
    if isinstance(value, np.integer):
        return int(value)
    if isinstance(value, np.floating):
        return None if not np.isfinite(value) else float(value)
    if isinstance(value, np.bool_):
        return bool(value)
    if isinstance(value, np.ndarray):
        return [_json_default(v) if isinstance(v, (np.generic, float)) else v for v in value.tolist()]
    if isinstance(value, (set, frozenset)):
        return sorted(value)
    if isinstance(value, tuple):
        return list(value)
    if isinstance(value, float) and not math.isfinite(value):
        return None
    raise TypeError(f"not serialisable: {type(value)}")


def _clean(value):
    """A JSON-safe copy: non-finite floats → None, numpy scalars → Python, tuples → lists."""
    if isinstance(value, dict):
        return {str(k): _clean(v) for k, v in value.items()}
    if isinstance(value, (list, tuple)):
        return [_clean(v) for v in value]
    if isinstance(value, np.ndarray):
        return [_clean(v) for v in value.tolist()]
    if isinstance(value, (np.integer,)):
        return int(value)
    if isinstance(value, (np.floating, float)):
        return float(value) if math.isfinite(float(value)) else None
    if isinstance(value, np.bool_):
        return bool(value)
    return value


def _fmean(values) -> float | None:
    vals = [float(v) for v in np.asarray(values, dtype=np.float64).ravel().tolist() if math.isfinite(v)]
    return math.fsum(vals) / len(vals) if vals else None


def _fsum(values) -> float:
    return math.fsum(float(v) for v in np.asarray(values, dtype=np.float64).ravel().tolist() if math.isfinite(v))


def _iso(ts_us: int | None) -> str | None:
    if ts_us is None:
        return None
    return datetime.fromtimestamp(int(ts_us) / tape.SECOND_US, tz=UTC).strftime("%Y-%m-%dT%H:%M:%SZ")


# ----------------------------------------------------------------------------- strict reading: helpers


def _check_keys(document, where: str, required=(), optional=()) -> dict:
    if not isinstance(document, dict):
        raise SpecError(f"{where} must be an object")
    allowed = set(required) | set(optional)
    unknown = sorted(k for k in document if k not in allowed)
    if unknown:
        raise SpecError(f"{where}: unknown key(s) {unknown}; {EVALUATOR_VERSION} refuses what it does not implement")
    missing = [k for k in required if k not in document]
    if missing:
        raise SpecError(f"{where}: missing key(s) {missing}")
    return document


def _int(document: dict, key: str, where: str, lo: int | None = None, hi: int | None = None) -> int:
    value = document[key]
    if isinstance(value, bool) or not isinstance(value, int):
        raise SpecError(f"{where}.{key}: expected an integer, got {value!r}")
    if (lo is not None and value < lo) or (hi is not None and value > hi):
        raise SpecError(f"{where}.{key}: {value} outside [{lo}, {hi}]")
    return int(value)


def _num(document: dict, key: str, where: str, lo: float | None = None, hi: float | None = None, lo_open: bool = False) -> float:
    value = document[key]
    if isinstance(value, bool) or not isinstance(value, (int, float)) or not math.isfinite(float(value)):
        raise SpecError(f"{where}.{key}: expected a finite number, got {value!r}")
    value = float(value)
    if lo is not None and (value < lo or (lo_open and value == lo)):
        raise SpecError(f"{where}.{key}: {value} must be {'>' if lo_open else '>='} {lo}")
    if hi is not None and value > hi:
        raise SpecError(f"{where}.{key}: {value} must be <= {hi}")
    return value


def _bool(document: dict, key: str, where: str, only: bool | None = None) -> bool:
    value = document[key]
    if not isinstance(value, bool):
        raise SpecError(f"{where}.{key}: expected true or false, got {value!r}")
    if only is not None and value is not only:
        raise SpecError(f"{where}.{key}: only {str(only).lower()} is implemented")
    return value


def _text(document: dict, key: str, where: str) -> str:
    value = document[key]
    if not isinstance(value, str) or not value.strip():
        raise SpecError(f"{where}.{key}: expected a non-empty string, got {value!r}")
    return value


def _choice(document: dict, key: str, where: str, choices) -> str:
    value = _text(document, key, where)
    if value not in tuple(choices):
        raise SpecError(f"{where}.{key}: {value!r} is not implemented (implemented: {tuple(choices)})")
    return value


def _exact(document: dict, key: str, where: str, expected: str) -> str:
    value = _text(document, key, where)
    if value != expected:
        raise SpecError(f"{where}.{key}: {value!r} is not the implemented rule {expected!r}")
    return value


def _list(document: dict, key: str, where: str, nonempty: bool = True) -> list:
    value = document[key]
    if not isinstance(value, list) or (nonempty and not value):
        raise SpecError(f"{where}.{key}: expected a{' non-empty' if nonempty else ''} list")
    return value


def _str_list(document: dict, key: str, where: str, choices=None, nonempty: bool = True) -> tuple[str, ...]:
    items = _list(document, key, where, nonempty)
    if any(not isinstance(i, str) or not i.strip() for i in items) or len(set(items)) != len(items):
        raise SpecError(f"{where}.{key}: expected distinct non-empty strings")
    if choices is not None:
        unknown = [i for i in items if i not in tuple(choices)]
        if unknown:
            raise SpecError(f"{where}.{key}: {unknown} not implemented (implemented: {tuple(choices)})")
    return tuple(items)


def _int_pair(document: dict, key: str, where: str) -> tuple[int, int]:
    value = document[key]
    if not isinstance(value, list) or len(value) != 2 or any(isinstance(v, bool) or not isinstance(v, int) for v in value):
        raise SpecError(f"{where}.{key}: expected [from, to] integers")
    if value[0] >= value[1]:
        raise SpecError(f"{where}.{key}: from must be < to")
    return int(value[0]), int(value[1])


def _hours(values, where: str) -> tuple[int, ...]:
    if not isinstance(values, list) or not values:
        raise SpecError(f"{where}: expected a non-empty list of hours")
    out = []
    for value in values:
        if isinstance(value, bool) or not isinstance(value, int) or not 0 <= value <= 23:
            raise SpecError(f"{where}: hours must be integers within 0..23, got {value!r}")
        out.append(int(value))
    if len(set(out)) != len(out):
        raise SpecError(f"{where}: duplicate hours")
    return tuple(sorted(out))


def _hhmm(text, where: str) -> int:
    parts = str(text).strip().split(":")
    if len(parts) != 2 or not all(p.isdigit() and len(p) == 2 for p in parts):
        raise SpecError(f"{where}: time {text!r} is not HH:MM")
    hour, minute = int(parts[0]), int(parts[1])
    if hour > 23 or minute > 59:
        raise SpecError(f"{where}: time {text!r} out of range")
    return hour * 60 + minute


def _date(text, where: str) -> _date:
    if not isinstance(text, str):
        raise SpecError(f"{where}: dates must be 'YYYY-MM-DD' strings, got {text!r}")
    try:
        return datetime.strptime(text, "%Y-%m-%d").date()
    except ValueError:
        raise SpecError(f"{where}: {text!r} is not a YYYY-MM-DD date") from None


def _dates(values, where: str) -> tuple[_date, ...]:
    if not isinstance(values, list):
        raise SpecError(f"{where}: expected a list of dates")
    days = [_date(v, where) for v in values]
    if len(set(days)) != len(days):
        raise SpecError(f"{where}: duplicate dates")
    return tuple(sorted(days))


def _iso_us(text, where: str) -> int:
    if not isinstance(text, str) or not text.endswith("Z"):
        raise SpecError(f"{where}: {text!r} is not an ISO UTC instant ending in Z")
    try:
        return tape.parse_iso_utc(text)
    except ValueError:
        raise SpecError(f"{where}: {text!r} is not an ISO UTC instant") from None


def _tz(name, where: str) -> ZoneInfo:
    if not isinstance(name, str) or not name:
        raise SpecError(f"{where}: expected a time-zone name")
    try:
        return ZoneInfo(name)
    except (ZoneInfoNotFoundError, ValueError):
        raise SpecError(f"{where}: unknown time zone {name!r}") from None


def _min_offset(text: str | None) -> int:
    return int(text) if text else 0


def _share_of(match) -> float:
    return float(f"{match.group('a')}.{match.group('b')}")


# ----------------------------------------------------------------------------- the specification


@dataclass(frozen=True)
class Instrument:
    inst_id: str
    index_id: str
    list_time_us: int
    eligible_from_us: int
    ct_val: float
    tick_sz: float
    lot_sz: float = 1.0  # Gate 2 (`universe.analysed_rule`): the simulated size; the Gate 1 universe carries no lot
    min_sz: float = 1.0


@dataclass(frozen=True)
class TypeSpec:
    name: str
    label: str  # "T01", "T05": the prefix of the type name, used by the spec's texts
    position: int
    role: str
    expected_cost_bp: float
    expected_cost_bp_stress: float
    days_per_year: float
    window_rule: str  # funding_coverage | spec_window
    generator: dict
    footprint: tuple
    arm_min: int
    rest_until_min: int | None
    classifier: dict | None
    fill_proxy: dict | None
    sign: dict | None
    entry: dict
    exit: dict
    return_window: tuple
    key_candles: tuple
    validation: dict | None
    controls: dict
    c7: dict | None
    criteria: tuple
    inconclusive_if: tuple
    proxy_missing_share_max: float | None
    audit_expected: dict
    gate2: dict | None
    raw: dict = field(default_factory=dict, compare=False)
    # Amendment A1: how the adverse selection of the entry leg enters the cost of the filled measure, and the pre-A1 totals (sensitivity).
    entry_adverse_selection: str = ENTRY_ADVERSE_DESIGN
    cost_sensitivity: dict | None = None

    @property
    def key_price_offsets(self) -> tuple:
        """Minute offsets (relative to h) where the reference price must exist: decision, return window, sign window."""
        offsets = {self.arm_min, self.return_window[0], self.return_window[1]}
        if self.sign is not None:
            offsets |= {self.sign["from_min"], self.sign["to_min"]}
        return tuple(sorted(offsets))

    @property
    def lookback_min(self) -> int:
        """Lowest offset read (the classifier looks back from t_arm)."""
        lows = [self.footprint[0], self.arm_min]
        if self.classifier is not None:
            lows.append(self.arm_min - int(self.classifier["lookback_min"]) + 1)
        return min(lows)


@dataclass(frozen=True)
class Spec:
    raw: dict
    spec_id: str
    version: str
    status: str
    source: str
    dataset_id: str
    protocol: str
    gate: str  # "G5_GATE1_" | "G5_GATE2_"
    start: _date
    end_exclusive: _date
    requires: tuple
    freeze_fill: dict
    universe: tuple
    excluded: tuple
    eligibility_days: float
    eligibility_count_field: str
    price: dict
    frozen_candle: dict
    unit: dict
    statistics: dict
    power: dict
    costs: dict
    controls_common: dict
    sessions: tuple
    calendars: dict
    macro: tuple
    types: tuple
    verdict: dict
    multiplicity: dict
    sealing: dict
    gate2_inscription: dict
    economic: dict
    descriptive: dict
    fetch_plan: dict | None
    gate2: dict | None
    audit: dict
    notes: dict = field(default_factory=dict)  # the spec's descriptive texts, copied into every report

    @property
    def start_us(self) -> int:
        return tape.day_of_date(self.start.isoformat()) * DAY_US

    @property
    def end_us(self) -> int:
        return tape.day_of_date(self.end_exclusive.isoformat()) * DAY_US

    @property
    def pad_start_us(self) -> int:
        pad = self.start_us - PAD_DAYS * DAY_US
        return max(pad, QUARANTINE_END_US) if self.source == "capture" else pad

    @property
    def frozen(self) -> bool:
        return self.status == "FROZEN"

    def instrument(self, inst_id: str) -> Instrument:
        for item in self.universe:
            if item.inst_id == inst_id:
                return item
        raise KeyError(inst_id)

    def type(self, name: str) -> TypeSpec:
        for item in self.types:
            if item.name == name:
                return item
        raise KeyError(name)

    @property
    def family(self) -> tuple:
        return tuple(self.multiplicity["family"])


# ----------------------------------------------------------------------------- calendars and local time


def local_to_utc_us(day: _date, minute_of_day: int, tz: ZoneInfo) -> int:
    """The UTC instant (µs) of a local wall-clock time; New York is checked against `events.us_dst_active` (B xii)."""
    hour, minute = divmod(int(minute_of_day), 60)
    moment = datetime(day.year, day.month, day.day, hour, minute, tzinfo=tz)
    if getattr(tz, "key", "") == NEW_YORK:
        expected_h = -4 if ev.us_dst_active(day) else -5
        if moment.utcoffset() != timedelta(hours=expected_h):
            raise SpecError(f"{day} {hour:02d}:{minute:02d} {NEW_YORK}: zoneinfo offset {moment.utcoffset()} differs from events.us_dst_active ({expected_h} h)")
    return int(moment.astimezone(UTC).timestamp()) * tape.SECOND_US


def day_matches(rule: str, day: _date, calendars: dict) -> bool:
    if rule == "all":
        return True
    if rule == "fri":
        return day.weekday() == 4
    if rule == "mon_fri":
        return day.weekday() < 5
    if rule == "nyse_trading_days":
        return day.weekday() < 5 and day not in calendars["nyse_holidays"]
    raise SpecError(f"unknown day rule {rule!r}")


def _local_days(lo_us: int, hi_us: int) -> list[_date]:
    first = datetime.fromtimestamp(lo_us / tape.SECOND_US, tz=UTC).date() - timedelta(days=2)
    last = datetime.fromtimestamp(hi_us / tape.SECOND_US, tz=UTC).date() + timedelta(days=2)
    return [first + timedelta(days=i) for i in range((last - first).days + 1)]


def session_intervals(spec: Spec, lo_us: int, hi_us: int) -> list[tuple[int, int, str]]:
    """Every session of the frozen list as UTC [start, end] intervals intersecting [lo, hi) (local time, DST-aware)."""
    out = []
    for session in spec.sessions:
        for day in _local_days(lo_us, hi_us):
            if not day_matches(session["days"], day, spec.calendars):
                continue
            a = local_to_utc_us(day, session["start_min"], session["tz_info"])
            b = local_to_utc_us(day, session["end_min"], session["tz_info"])
            if b >= lo_us - DAY_US and a < hi_us + DAY_US:
                out.append((a, b, session["name"]))
    return out


def macro_intervals(spec: Spec, lo_us: int, hi_us: int) -> list[tuple[int, int, str]]:
    """The macro releases (T11 filter) as UTC [time, time + duration] intervals, from New York time."""
    out = []
    for release in spec.macro:
        for day in spec.calendars[release["dates_ref"]]:
            a = local_to_utc_us(day, release["time_min"], release["tz_info"])
            b = a + int(release["duration_min"]) * MINUTE_US
            if b >= lo_us - DAY_US and a < hi_us + DAY_US:
                out.append((a, b, release["name"]))
    return out


def local_time_instants(tspec: TypeSpec, spec: Spec, lo_us: int | None = None, hi_us: int | None = None, apply_exclusions: bool = True) -> list[tuple[int, _date, bool]]:
    """Instants of a `local_time` generator inside [lo, hi) (default: the spec window): `(utc_us, local_date, excluded)`, the
    excluded flag set on the dates of `exclude_dates_ref` (CPI / Employment Situation for T05); with `apply_exclusions` those are dropped."""
    gen = tspec.generator
    lo_us = spec.start_us if lo_us is None else lo_us
    hi_us = spec.end_us if hi_us is None else hi_us
    out = []
    for day in _local_days(lo_us, hi_us):
        if not day_matches(gen["days"], day, spec.calendars):
            continue
        moment = local_to_utc_us(day, gen["minute_of_day"], gen["tz_info"])
        if not lo_us <= moment < hi_us:
            continue
        excluded = day in gen["exclude_days"]
        if excluded and apply_exclusions:
            continue
        out.append((moment, day, excluded))
    return out


# ----------------------------------------------------------------------------- strict reading: blocks


def _parse_requires(document: dict, source: str) -> tuple:
    items = _str_list(document, "requires", "spec")
    unknown = sorted(set(items) - set(CAPABILITIES))
    if unknown:
        raise SpecError(f"requires {unknown}: not implemented by {EVALUATOR_VERSION} (implemented: {sorted(CAPABILITIES)})")
    expected = HISTORY_REQUIRES if source == "history" else CAPTURE_REQUIRES
    extra = sorted(set(items) - set(expected))
    if extra:
        raise SpecError(f"requires {extra}: not applicable to a {source} spec")
    missing = sorted(set(expected) - set(items))
    if missing:
        raise SpecError(f"the spec relies on {missing} without declaring it in requires (the version lock of (B) xviii)")
    return items


FREEZE_FILL_KEYS = ("instruments_snapshot_path", "instruments_snapshot_sha256", "calendars_verified_utc", "code_digest_sha256", "decision_ref_p_g5_1")
# CTO M4 (P-G5-3, §4.7, §13 point 5): the sha256 of the sealed funding receipt, written in the register and in the freeze line, is also a
# freeze_fill value. Optional key while the spec is a draft written before it existed (absent = null); a FROZEN spec carries it.
FREEZE_FILL_SEAL_KEY = "funding_seal_receipt_sha256"


def _parse_freeze_fill(block, status: str) -> dict:
    keys = FREEZE_FILL_KEYS + (FREEZE_FILL_SEAL_KEY,)
    _check_keys(block, "freeze_fill", FREEZE_FILL_KEYS + ("rule",), (FREEZE_FILL_SEAL_KEY,))
    out = {"rule": _text(block, "rule", "freeze_fill"), "absent": [k for k in keys if k not in block]}
    for key in keys:
        value = block.get(key)
        if value is None:
            out[key] = None
            continue
        if not isinstance(value, str) or not value.strip():
            raise SpecError(f"freeze_fill.{key}: expected a non-empty string or null")
        if key.endswith("sha256") and not SHA256_RE.match(value):
            raise SpecError(f"freeze_fill.{key}: expected a lower-case sha256")
        if key == "calendars_verified_utc":
            _iso_us(value, "freeze_fill.calendars_verified_utc")
        out[key] = value
    nulls = [k for k in keys if out[k] is None]
    out["null"] = nulls
    if status == "FROZEN" and nulls:
        raise SpecError(f"freeze_fill: a FROZEN spec has no null value ({nulls}); {out['rule']} (freeze_fill.{FREEZE_FILL_SEAL_KEY}: the sha256 of the sealed "
                        "funding receipt, P-G5-3, §4.7, §13 point 5, CTO M4)")
    return out


def _parse_universe(block, eligibility_days: float, end_us: int, source: str) -> tuple[tuple, tuple]:
    _check_keys(block, "universe", ("rule", "selection_bias_declared", "analysed", "excluded_never_eligible", "mismatch_with_snapshot"))
    _text(block, "rule", "universe")
    _text(block, "selection_bias_declared", "universe")
    _choice(block, "mismatch_with_snapshot", "universe", ("refuse_run",))
    days_us = int(round(eligibility_days * DAY_US))
    analysed, seen = [], set()
    for i, item in enumerate(_list(block, "analysed", "universe")):
        where = f"universe.analysed[{i}]"
        _check_keys(item, where, ("inst_id", "index_id", "list_time_utc", "eligible_from_utc", "ct_val", "tick_sz"))
        inst = _text(item, "inst_id", where)
        if not xc.XPERP_RE.match(inst):
            raise SpecError(f"{where}.inst_id: {inst!r} is not an X-Perp id")
        if inst in seen:
            raise SpecError(f"{where}: duplicate instrument {inst}")
        seen.add(inst)
        index_id = _text(item, "index_id", where)
        if index_id != g5_fetch.index_id_of({"instId": inst}):
            raise SpecError(f"{where}.index_id: {index_id!r} is not the <BASE>-USD index of {inst}")
        listed = _iso_us(item["list_time_utc"], f"{where}.list_time_utc")
        eligible = _iso_us(item["eligible_from_utc"], f"{where}.eligible_from_utc")
        if eligible != listed + days_us:
            raise SpecError(f"{where}.eligible_from_utc: {item['eligible_from_utc']} is not list_time_utc + {eligibility_days:g} days")
        if eligible >= end_us:
            raise SpecError(f"{where}: no eligible minute before the end ({_iso(end_us)}): it belongs to excluded_never_eligible")
        analysed.append(Instrument(inst, index_id, listed, eligible, _num(item, "ct_val", where, 0.0, lo_open=True), _num(item, "tick_sz", where, 0.0, lo_open=True)))
    excluded = []
    for i, item in enumerate(_list(block, "excluded_never_eligible", "universe", nonempty=False)):
        where = f"universe.excluded_never_eligible[{i}]"
        _check_keys(item, where, ("inst_id", "list_time_utc", "reason", "fetched"))
        inst = _text(item, "inst_id", where)
        if inst in seen:
            raise SpecError(f"{where}: {inst} is both analysed and excluded")
        seen.add(inst)
        listed = _iso_us(item["list_time_utc"], f"{where}.list_time_utc")
        _choice(item, "reason", where, ("no_eligible_minute_before_end_exclusive",))
        _bool(item, "fetched", where, only=False)
        if listed + days_us < end_us:
            raise SpecError(f"{where}: {inst} has eligible minutes before the end: it cannot be excluded as never eligible")
        excluded.append({"inst_id": inst, "list_time_us": listed})
    return tuple(analysed), tuple(excluded)


def _parse_price(block, source: str) -> dict:
    keys = ("source", "series", "boundary_convention") + tuple(PRICE_FIXED) + ("max_missing_share_in_event_footprints_per_instrument",)
    _check_keys(block, "price", keys)
    for key, value in PRICE_RULES[source].items():
        _exact(block, key, "price", value)
    for key, value in PRICE_FIXED.items():
        _exact(block, key, "price", value)
    return {"max_missing_share": _num(block, "max_missing_share_in_event_footprints_per_instrument", "price", 0.0, 1.0), "series": block["series"]}


def _parse_frozen_candle(block, source: str) -> dict:
    _check_keys(block, "frozen_candle", tuple(FROZEN_CANDLE[source]))
    for key, value in FROZEN_CANDLE[source].items():
        _exact(block, key, "frozen_candle", value)
    return {"applies": source == "history", "count_field": block["count_field"]}


def _parse_unit(block) -> dict:
    _check_keys(block, "unit", tuple(UNIT_FIXED) + ("min_instruments_per_instant", "report"))
    for key, value in UNIT_FIXED.items():
        _exact(block, key, "unit", value)
    reports = _str_list(block, "report", "unit", UNIT_REPORTS)
    return {"min_instruments": _int(block, "min_instruments_per_instant", "unit", 1), "reports": reports}


STAT_MEASURE = {"history": "net_bp", "capture": "sim_net_bp"}
STAT_DESCRIPTIVE_MEASURES = ("net_bp (Gate 1 construction on last prints with the Gate 1 design costs)", "r_bp")


def _parse_statistics(block, source: str = "history") -> dict:
    extra = ("measure_definition", "descriptive_measures") if source == "capture" else ()
    _check_keys(block, "statistics", ("measure", "difference", "alpha_one_sided", "threshold_bp", "bootstrap", "descriptive_sensitivity") + extra)
    _choice(block, "measure", "statistics", (STAT_MEASURE[source],))
    if source == "capture":
        _text(block, "measure_definition", "statistics")
        if tuple(_str_list(block, "descriptive_measures", "statistics")) != STAT_DESCRIPTIVE_MEASURES:
            raise SpecError(f"statistics.descriptive_measures: {list(STAT_DESCRIPTIVE_MEASURES)} are the implemented descriptive measures of the Gate 2")
    _exact(block, "difference", "statistics", STAT_DIFFERENCE)
    alpha = _num(block, "alpha_one_sided", "statistics", 0.0, 1.0, lo_open=True)
    boot = _check_keys(block["bootstrap"], "statistics.bootstrap", ("kind", "block_days", "resamples", "seed", "events_and_controls_jointly", "lb_quantile", "ub_quantile", "p_value"))
    _choice(boot, "kind", "statistics.bootstrap", ("day_block",))
    _bool(boot, "events_and_controls_jointly", "statistics.bootstrap", only=True)
    _exact(boot, "p_value", "statistics.bootstrap", P_VALUE_RULE)
    lb, ub = _num(boot, "lb_quantile", "statistics.bootstrap", 0.0, 1.0), _num(boot, "ub_quantile", "statistics.bootstrap", 0.0, 1.0)
    if abs(lb - alpha) > 1e-12 or abs(ub - (1.0 - alpha)) > 1e-12:
        raise SpecError(f"statistics.bootstrap: lb_quantile / ub_quantile ({lb:g} / {ub:g}) must be alpha / 1 - alpha ({alpha:g}): LB90 > 0 is the one-sided test at alpha")
    sens = _check_keys(block["descriptive_sensitivity"], "statistics.descriptive_sensitivity", ("block_days",))
    return {"alpha": alpha, "threshold_bp": _num(block, "threshold_bp", "statistics"), "block_days": _int(boot, "block_days", "statistics.bootstrap", 1),
            "resamples": _int(boot, "resamples", "statistics.bootstrap", 100), "seed": _int(boot, "seed", "statistics.bootstrap"), "lb_quantile": lb, "ub_quantile": ub,
            "sensitivity_block_days": _int(sens, "block_days", "statistics.descriptive_sensitivity", 1)}


def _parse_power(block, gate: str) -> dict:
    _check_keys(block, "power_floor", ("min_instants", "min_days", "below_floor", "extension_on_result"))
    _exact(block, "below_floor", "power_floor", gate + "NOT_INFORMATIVE")
    _bool(block, "extension_on_result", "power_floor", only=False)
    return {"min_instants": _int(block, "min_instants", "power_floor", 1), "min_days": _int(block, "min_days", "power_floor", 1)}


def _parse_costs(block, source: str) -> dict:
    keys = ("maker_fee_bp", "taker_fee_bp", "adverse_selection_bp_per_maker_leg", "slippage_bp_per_round_trip", "taker_entry_half_spread_bp", "stress_multiplier_all_cost_terms")
    _check_keys(block, "costs", keys + ("g2_table_substitution", "funding", "margin_opportunity_cost"))
    out = {key: _num(block, key, "costs", 0.0) for key in keys}
    if out["stress_multiplier_all_cost_terms"] < 1.0:
        raise SpecError("costs.stress_multiplier_all_cost_terms must be >= 1")
    if not _text(block, "g2_table_substitution", "costs").startswith("none"):
        raise SpecError("costs.g2_table_substitution: only 'none…' is implemented (G5 is frozen before the G2 reading, P-G5-9)")
    funding = _check_keys(block["funding"], "costs.funding", ("source", "formula", "outside_coverage", "count_field", "cap_stress"))
    for key, value in {**FUNDING_RULES[source], **FUNDING_FIXED}.items():
        _exact(funding, key, "costs.funding", value)
    out["margin_opportunity_cost"] = _choice(block, "margin_opportunity_cost", "costs", ("negligible_reported",))
    out["funding_count_field"] = funding["count_field"]
    out["g2_table_substitution"] = block["g2_table_substitution"]
    return out


def cost_terms(role: str, costs: dict, entry_adverse: str = ENTRY_ADVERSE_DESIGN) -> dict:
    """Round-trip cost terms of an execution role (bp, LP-G5 §6): fees at the role, adverse selection on each maker leg whose
    adverse selection is assumed, the taker entry half-spread, slippage; `stress_total_bp` multiplies every term.

    Amendment A1: `entry_adverse = measured_by_fill_proxy` (maker_maker only) — the entry leg is a resting order kept only when the
    fill proxy fills it, so the adverse move that follows the fill is already inside the measured return: its assumed term is 0 and
    only the passive exit leg keeps the design value (T01: 4 + 3.5 + 2 = 9.5 bp, stress 14.25). The default (`assumed_design_value`)
    is the pre-A1 rule (T01 13 / 19.5 bp) — the reported sensitivity, and the rule of any measure without the fill conditioning."""
    if entry_adverse not in ENTRY_ADVERSE_VALUES:
        raise SpecError(f"adverse_selection_entry_leg {entry_adverse!r} is not implemented ({ENTRY_ADVERSE_VALUES})")
    if role == "maker_maker":
        fees, maker_legs, half_spread = 2.0 * costs["maker_fee_bp"], 2, 0.0
    elif role == "taker_maker":
        if entry_adverse == ENTRY_ADVERSE_MEASURED:
            raise SpecError("adverse_selection_entry_leg measured_by_fill_proxy: a taker entry is not a resting order, no fill conditioning measures it (A1)")
        fees, maker_legs, half_spread = costs["taker_fee_bp"] + costs["maker_fee_bp"], 1, costs["taker_entry_half_spread_bp"]
    else:
        raise SpecError(f"unknown execution role {role!r}")
    assumed_legs = maker_legs - (1 if entry_adverse == ENTRY_ADVERSE_MEASURED else 0)
    adverse = assumed_legs * costs["adverse_selection_bp_per_maker_leg"]
    terms = {"fees_bp": fees, "adverse_selection_bp": adverse, "taker_entry_half_spread_bp": half_spread, "slippage_bp": costs["slippage_bp_per_round_trip"]}
    total = math.fsum(terms.values())
    mult = costs["stress_multiplier_all_cost_terms"]
    return {"role": role, **terms, "maker_legs": maker_legs, "maker_legs_with_assumed_adverse_selection": assumed_legs,
            "adverse_selection_entry_leg": entry_adverse if role == "maker_maker" else "not_applicable_taker_entry", "total_bp": total, "stress_multiplier": mult,
            "stress_total_bp": math.fsum(v * mult for v in terms.values())}


def type_cost_terms(tspec: "TypeSpec", costs: dict, conditioned_on_fill: bool = True) -> dict:
    """The assumed costs of a type's measure (A1): the type's entry-leg rule when the measure is conditioned on the fill proxy (the
    primary), the design value otherwise (nothing measures the entry leg of a measure that does not condition on the fill)."""
    return cost_terms(tspec.role, costs, tspec.entry_adverse_selection if conditioned_on_fill else ENTRY_ADVERSE_DESIGN)


def _parse_controls_common(block, source: str) -> dict:
    _check_keys(block, "controls_common", ("rule", "candidates", "selection", "random_draws", "admissible_if", "margin_min", "exclusions", "funding_at_controls"),
                ("audit_expected_utc",) if source == "capture" else ())
    _text(block, "rule", "controls_common")
    for key, value in CONTROLS_FIXED.items():
        _exact(block, key, "controls_common", value)
    if _int(block, "random_draws", "controls_common") != 0:
        raise SpecError("controls_common.random_draws: only 0 is implemented (every admissible hour, no draw)")
    components: dict = {}
    for i, text in enumerate(_str_list(block, "exclusions", "controls_common")):
        where = f"controls_common.exclusions[{i}]"
        if (m := SETTLEMENT_EXCLUSION_RE.match(text)) is not None:
            stream = SETTLEMENT_STREAM[source]
            if m.group("stream") != stream:
                raise SpecError(f"{where}: the settlements of a {source} spec are read in {stream}")
            name, value = "settlements", (int(m.group("a")), int(m.group("b")))
        elif (m := GRID_EXCLUSION_RE.match(text)) is not None:
            name, value = "default_grid", (int(m.group("a")), int(m.group("b")))
        elif (m := TYPE_EXCLUSION_RE.match(text)) is not None:
            name, value = "type_instants", (m.group("type"), _min_offset(m.group("a")), _min_offset(m.group("b")), m.group("label"))
        elif text == SESSIONS_EXCLUSION:
            name, value = "sessions", True
        elif text == MACRO_EXCLUSION:
            name, value = "macro", True
        else:
            raise SpecError(f"{where}: {text!r} is not an implemented exclusion")
        if name in components:
            raise SpecError(f"{where}: exclusion {name} listed twice")
        components[name] = value
    for name in ("settlements", "default_grid"):
        if name in components and components[name][0] >= components[name][1]:
            raise SpecError(f"controls_common.exclusions: footprint of {name} is empty")
    return {"margin_us": _int(block, "margin_min", "controls_common", 0) * MINUTE_US, "margin_min": block["margin_min"], "components": components}


def _parse_sessions(items, status: str = "DRAFT_NOT_FROZEN") -> tuple:
    """The frozen session list (local time). A FROZEN spec carries no `verify_at_freeze: true` (LP-G5 §4.6, P-G5-5: verified at the
    freeze, attested by freeze_fill.calendars_verified_utc)."""
    if not isinstance(items, list) or not items:
        raise SpecError("sessions must be a non-empty list")
    out, names = [], set()
    for i, item in enumerate(items):
        where = f"sessions[{i}]"
        _check_keys(item, where, ("name", "tz", "start", "end", "days"), ("verify_at_freeze",))
        name = _text(item, "name", where)
        if name in names:
            raise SpecError(f"{where}: duplicate session {name}")
        names.add(name)
        start, end = _hhmm(item["start"], f"{where}.start"), _hhmm(item["end"], f"{where}.end")
        if end < start:
            raise SpecError(f"{where}: end before start (a session spans one local day)")
        out.append({"name": name, "tz": item["tz"], "tz_info": _tz(item["tz"], f"{where}.tz"), "start_min": start, "end_min": end,
                    "days": _choice(item, "days", where, SESSION_DAYS), "verify_at_freeze": _bool(item, "verify_at_freeze", where) if "verify_at_freeze" in item else False})
        if status == "FROZEN" and out[-1]["verify_at_freeze"]:
            raise SpecError(f"{where}: verify_at_freeze is true in a FROZEN spec (verify the session at the freeze, P-G5-5, then write false)")
    return tuple(out)


def _parse_calendars(block, extra: tuple = ()) -> dict:
    lists = ("nyse_holidays", "cpi", "employment_situation", "fomc_statement")
    _check_keys(block, "calendars", ("tz",) + lists + ("sources", "read_by") + tuple(extra))
    if _text(block, "tz", "calendars") != NEW_YORK:
        raise SpecError(f"calendars.tz: the frozen dates are New York dates ({NEW_YORK}); {block['tz']!r} is not implemented")
    out = {name: frozenset(_dates(block[name], f"calendars.{name}")) for name in lists}
    out["sources"] = _str_list(block, "sources", "calendars")
    out["read_by"] = _text(block, "read_by", "calendars")
    return out


def _calendar_ref(text: str, where: str, calendars: dict) -> str:
    if not isinstance(text, str) or not text.startswith("calendars."):
        raise SpecError(f"{where}: {text!r} is not a calendars.<list> reference")
    name = text[len("calendars."):]
    if name not in calendars or name in ("sources", "read_by"):
        raise SpecError(f"{where}: {text!r} names no date list of calendars")
    return name


def _parse_macro(block, calendars: dict, source: str = "history") -> tuple:
    _check_keys(block, "macro_filter", ("type", "evaluate", "tz", "releases", "use", "ten_am_et_releases"), ("utc_note",) if source == "capture" else ())
    _text(block, "type", "macro_filter")
    _bool(block, "evaluate", "macro_filter", only=False)
    tz = _tz(block["tz"], "macro_filter.tz")
    if block["tz"] != NEW_YORK:
        raise SpecError(f"macro_filter.tz: the releases are in New York time ({NEW_YORK})")
    _text(block, "use", "macro_filter")
    _text(block, "ten_am_et_releases", "macro_filter")
    out, names = [], set()
    for i, item in enumerate(_list(block, "releases", "macro_filter")):
        where = f"macro_filter.releases[{i}]"
        _check_keys(item, where, ("name", "time", "duration_min", "dates_ref"))
        name = _text(item, "name", where)
        if name in names:
            raise SpecError(f"{where}: duplicate release {name}")
        names.add(name)
        out.append({"name": name, "time_min": _hhmm(item["time"], f"{where}.time"), "duration_min": _int(item, "duration_min", where, 0, 24 * 60), "tz_info": tz,
                    "dates_ref": _calendar_ref(item["dates_ref"], f"{where}.dates_ref", calendars)})
    return tuple(out)


_TYPE_REQUIRED = ("type", "decisive", "primary", "execution_role", "expected_cost_bp", "expected_cost_bp_stress", "days_per_year", "window_rule", "generator",
                  "footprint_min", "arm_min", "entry", "exit", "return_window_min", "key_candles_end_min", "controls", "criteria")
_TYPE_OPTIONAL = ("funding_coverage", "rest_until_min", "classifier", "fill_proxy", "sign", "validation", "c7", "inconclusive_if", "audit_expected",
                  "adverse_selection_entry_leg", "cost_sensitivity_pre_a1")
AUDIT_KEYS = {"local_time": ("nyse_days_with_eligible_instrument", "raw_instants", "raw_instants_with_ge_3_instruments", "first_instant_utc", "halves_by_lower_median_day"),
              "funding_settlements": ("not_informative_expected_if_f_inst_below",)}
RAW_UPPER_RE = re.compile(r"^raw_instants_upper_bound_if_funding_fetched_(?P<y>\d{4})_(?P<m>\d{2})_(?P<d>\d{2})$")


def _parse_generator(block, where: str, source: str, calendars: dict) -> dict:
    kind = _choice(_check_keys(block, where, ("kind",), ("stream", "require_realized_rate", "interval_filter", "fallback_hours", "tz", "time", "days", "exclude_dates_ref")),
                   "kind", where, ("funding_settlements", "local_time"))
    if kind == "funding_settlements":
        _check_keys(block, where, ("kind", "stream", "require_realized_rate", "interval_filter", "fallback_hours"))
        _choice(block, "stream", where, ("funding_hist",) if source == "history" else ("funding",))
        _bool(block, "require_realized_rate", where, only=True)
        _choice(block, "fallback_hours", where, ("none",))
        f = _check_keys(block["interval_filter"], f"{where}.interval_filter", ("previous_gap_h", "next_gap_h", "tolerance_min", "unknown_neighbour"))
        _choice(f, "unknown_neighbour", f"{where}.interval_filter", ("exclude_and_count",))
        return {"kind": kind, "stream": block["stream"], "previous_gap_us": int(round(_num(f, "previous_gap_h", f"{where}.interval_filter", 0.0, 24.0, lo_open=True) * HOUR_US)),
                "next_gap_us": int(round(_num(f, "next_gap_h", f"{where}.interval_filter", 0.0, 24.0, lo_open=True) * HOUR_US)),
                "tolerance_us": int(round(_num(f, "tolerance_min", f"{where}.interval_filter", 0.0, 60.0) * MINUTE_US))}
    _check_keys(block, where, ("kind", "tz", "time", "days", "exclude_dates_ref"))
    refs = [_calendar_ref(r, f"{where}.exclude_dates_ref", calendars) for r in _str_list(block, "exclude_dates_ref", where, nonempty=False)]
    exclude = frozenset(d for r in refs for d in calendars[r])
    return {"kind": kind, "tz": block["tz"], "tz_info": _tz(block["tz"], f"{where}.tz"), "minute_of_day": _hhmm(block["time"], f"{where}.time"),
            "days": _choice(block, "days", where, ("nyse_trading_days", "mon_fri", "all")), "exclude_refs": tuple(refs), "exclude_days": exclude}


PREMIUM_FIELD_NAME = "premium_mark_index"


def _parse_classifier(block, where: str, source: str, arm_min: int) -> dict:
    keys = ("kind", "evaluated_at", "applies_to", "premium_proxy", "lookback_min", "weights", "min_coverage_share", "long_if_ge", "short_if_le", "else", "threshold_origin")
    _check_keys(block, where, keys + (("field_name",) if source == "capture" else ()))
    if source == "capture":
        _exact(block, "field_name", where, PREMIUM_FIELD_NAME)  # one quantity at both gates, never the channel's premium (CTO M2)
    _choice(block, "kind", where, ("premium_wavg",))
    # (B) xvii: the classifier is read at the decision, never later.
    if block["evaluated_at"] != "t_arm":
        raise SpecError(f"{where}.evaluated_at: {block['evaluated_at']!r} — the classifier is evaluated at t_arm (h{arm_min:+d}) only: anything later is information after the decision")
    if list(_str_list(block, "applies_to", where)) != ["events", "controls"]:
        raise SpecError(f"{where}.applies_to: the one classifier applies to the events and the controls alike")
    _exact(block, "premium_proxy", where, PREMIUM_PROXY[source])
    lookback = _int(block, "lookback_min", where, 1, 10_000)
    _exact(block, "weights", where, WEIGHTS_TEXT.format(n=lookback))
    _choice(block, "else", where, ("exclude_and_count",))
    _text(block, "threshold_origin", where)
    hi, lo = _num(block, "long_if_ge", where, 0.0, lo_open=True), _num(block, "short_if_le", where)
    if lo >= 0:
        raise SpecError(f"{where}.short_if_le must be negative")
    return {"kind": "premium_wavg", "lookback_min": lookback, "min_coverage": _num(block, "min_coverage_share", where, 0.0, 1.0, lo_open=True), "high": hi, "low": lo,
            "threshold_origin": block["threshold_origin"]}


def _parse_sign(block, where: str, arm_min: int, footprint: tuple) -> dict:
    _check_keys(block, where, ("kind", "from_min", "to_min", "price", "zero"))
    _choice(block, "kind", where, ("return_sign",))
    _choice(block, "price", where, ("last",))
    _choice(block, "zero", where, ("exclude_and_count",))
    a, b = _int(block, "from_min", where), _int(block, "to_min", where)
    if a >= b:
        raise SpecError(f"{where}: from_min must be < to_min")
    if b > arm_min:
        raise SpecError(f"{where}.to_min = h{b:+d} is observed after the decision at arm_min h{arm_min:+d} (look-ahead, (B) xvii)")
    if a < footprint[0]:
        raise SpecError(f"{where}: the sign window starts before the footprint")
    return {"kind": "return_sign", "from_min": a, "to_min": b}


def _parse_fill_proxy(block, where: str, source: str, arm_min: int, rest_until_min: int) -> dict:
    _check_keys(block, where, ("kind", "applies_to", "candles_open_from_min", "candles_open_to_min") + tuple(FILL_PROXY_FIXED))
    _choice(block, "kind", where, ("through_candles",))
    if list(_str_list(block, "applies_to", where)) != ["events", "controls"]:
        raise SpecError(f"{where}.applies_to: events and controls alike")
    for key, value in FILL_PROXY_FIXED.items():
        _exact(block, key, where, value)
    a, b = _int(block, "candles_open_from_min", where), _int(block, "candles_open_to_min", where)
    if a != arm_min or b != rest_until_min - 1:
        raise SpecError(f"{where}: the order rests from t_arm (h{arm_min:+d}) to h{rest_until_min:+d}: the candles open from {arm_min} to {rest_until_min - 1}")
    return {"kind": "through_candles", "open_from_min": a, "open_to_min": b}


def _parse_validation(block, where: str, label: str, classifier: dict, gate: str) -> dict:
    keys = ("criterion", "population", "classifier_class", "rate_class") + tuple(VALIDATION_FIXED) + ("sensitivity_min", "false_positive_rate_max", "min_denominator",
                                                                                                      "fail_or_not_computable")
    _check_keys(block, where, keys)
    _choice(block, "criterion", where, ("C6",))
    m = POPULATION_RE.match(_text(block, "population", where))
    if m is None or m.group("label") != label:
        raise SpecError(f"{where}.population: not the implemented population of {label} (raw instrument-settlements: eligible, interval filter, realized rate, classifier computable)")
    for key, value in VALIDATION_FIXED.items():
        _exact(block, key, where, value)
    c = CLASS_RE.match(_text(block, "classifier_class", where))
    if c is None or c.group("field") != "P_hat(t_arm)" or float(c.group("hi")) != classifier["high"] or float(c.group("lo")) != classifier["low"]:
        raise SpecError(f"{where}.classifier_class: must state the classifier's own thresholds ({classifier['high']:g} / {classifier['low']:g}) at t_arm")
    r = CLASS_RE.match(_text(block, "rate_class", where))
    if r is None or r.group("field") != "realized_rate":
        raise SpecError(f"{where}.rate_class: '+1 if realized_rate >= x, -1 if <= -y, 0 otherwise' is the implemented rule")
    _exact(block, "fail_or_not_computable", where, gate + "INCONCLUSIVE")
    return {"rate_high": float(r.group("hi")), "rate_low": float(r.group("lo")), "sensitivity_min": _num(block, "sensitivity_min", where, 0.0, 1.0),
            "false_positive_rate_max": _num(block, "false_positive_rate_max", where, 0.0, 1.0), "min_denominator": _int(block, "min_denominator", where, 1)}


def _parse_entry_adverse(item: dict, where: str, role: str, conditioned_on_fill: bool) -> tuple[str, dict | None]:
    """Amendment A1 — `adverse_selection_entry_leg` (absent = `assumed_design_value`, the pre-A1 rule) and its companion
    `cost_sensitivity_pre_a1` (required with `measured_by_fill_proxy`, refused otherwise). `measured_by_fill_proxy` needs a resting
    entry whose measure is conditioned on a fill (maker_maker with a fill proxy, or the Gate 2 construction proxy); the totals are
    checked against `cost_terms` by the caller (parse_spec, g5_gate2)."""
    rule = _choice(item, "adverse_selection_entry_leg", where, ENTRY_ADVERSE_VALUES) if "adverse_selection_entry_leg" in item else ENTRY_ADVERSE_DESIGN
    if rule == ENTRY_ADVERSE_MEASURED and (role != "maker_maker" or not conditioned_on_fill):
        raise SpecError(f"{where}.adverse_selection_entry_leg: measured_by_fill_proxy needs a resting (maker_maker) entry conditioned on a fill proxy; "
                        f"a {role} entry without fill conditioning measures nothing (A1)")
    sensitivity = None
    if "cost_sensitivity_pre_a1" in item:
        if rule != ENTRY_ADVERSE_MEASURED:
            raise SpecError(f"{where}.cost_sensitivity_pre_a1: only beside adverse_selection_entry_leg measured_by_fill_proxy (the pre-A1 total is its sensitivity, A1)")
        block = _check_keys(item["cost_sensitivity_pre_a1"], f"{where}.cost_sensitivity_pre_a1", ("adverse_selection_entry_leg", "expected_cost_bp", "expected_cost_bp_stress", "use"))
        _exact(block, "adverse_selection_entry_leg", f"{where}.cost_sensitivity_pre_a1", ENTRY_ADVERSE_DESIGN)
        _exact(block, "use", f"{where}.cost_sensitivity_pre_a1", COST_SENSITIVITY_USE)
        sensitivity = {"expected_cost_bp": _num(block, "expected_cost_bp", f"{where}.cost_sensitivity_pre_a1", 0.0),
                       "expected_cost_bp_stress": _num(block, "expected_cost_bp_stress", f"{where}.cost_sensitivity_pre_a1", 0.0)}
    elif rule == ENTRY_ADVERSE_MEASURED:
        raise SpecError(f"{where}: adverse_selection_entry_leg measured_by_fill_proxy needs cost_sensitivity_pre_a1 (the pre-A1 total stays reported, A1)")
    return rule, sensitivity


def check_type_costs(t: "TypeSpec", costs: dict) -> None:
    """(B) xiii, CTO B1-vi, A1 — the declared totals equal the cost terms of the role under the type's entry-leg rule, and the pre-A1
    sensitivity equals the design-value totals: any other pair is an inconsistency, refused."""
    costs_t = cost_terms(t.role, costs, t.entry_adverse_selection)
    if abs(costs_t["total_bp"] - t.expected_cost_bp) > 1e-9 or abs(costs_t["stress_total_bp"] - t.expected_cost_bp_stress) > 1e-9:
        raise SpecError(f"{t.name}: expected_cost_bp {t.expected_cost_bp:g} / stress {t.expected_cost_bp_stress:g} differ from the cost terms of {t.role} with "
                        f"adverse_selection_entry_leg {t.entry_adverse_selection} ({costs_t['total_bp']:g} / {costs_t['stress_total_bp']:g}) (CTO B1-vi, A1)")
    if t.cost_sensitivity is not None:
        design = cost_terms(t.role, costs, ENTRY_ADVERSE_DESIGN)
        if abs(design["total_bp"] - t.cost_sensitivity["expected_cost_bp"]) > 1e-9 or abs(design["stress_total_bp"] - t.cost_sensitivity["expected_cost_bp_stress"]) > 1e-9:
            raise SpecError(f"{t.name}.cost_sensitivity_pre_a1: {t.cost_sensitivity['expected_cost_bp']:g} / {t.cost_sensitivity['expected_cost_bp_stress']:g} differ from "
                            f"the design-value totals of {t.role} ({design['total_bp']:g} / {design['stress_total_bp']:g}) (A1)")


def _parse_type(item, position: int, source: str, gate: str, calendars: dict) -> TypeSpec:
    """One event type of a history (Gate 1) spec; the capture types are read by `g5_gate2`."""
    if source != "history":
        raise SpecError("_parse_type reads the history types only (g5_gate2 reads the capture types)")
    where = f"events[{position}]"
    _check_keys(item, where, _TYPE_REQUIRED, _TYPE_OPTIONAL)
    name = _text(item, "type", where)
    label = name.split("_", 1)[0]
    if not re.match(r"^T\d+$", label):
        raise SpecError(f"{where}.type: {name!r} must start with its T<nn> label")
    _bool(item, "decisive", where, only=True)
    _bool(item, "primary", where, only=True)
    role = _choice(item, "execution_role", where, ROLES)
    window_rule = _choice(item, "window_rule", where, ("funding_coverage", "spec_window") if source == "history" else ("spec_window",))
    generator = _parse_generator(item["generator"], f"{where}.generator", source, calendars)
    footprint = _int_pair(item, "footprint_min", where)
    arm = _int(item, "arm_min", where)
    if not footprint[0] <= arm <= footprint[1]:
        raise SpecError(f"{where}.arm_min must lie inside the footprint")
    if arm == -1:
        raise SpecError(f"{where}.arm_min = -1 is forbidden (the minute before the instant is the toxic one, LP-G5 §5.4, trap 4)")
    rest = None
    if role == "maker_maker":
        if "rest_until_min" not in item:
            raise SpecError(f"{where}: a maker_maker type needs rest_until_min (the resting order's end)")
        rest = _int(item, "rest_until_min", where)
        if not arm < rest <= footprint[1]:
            raise SpecError(f"{where}.rest_until_min must be in (arm_min, footprint end]")
    elif "rest_until_min" in item:
        raise SpecError(f"{where}.rest_until_min: a taker entry does not rest")
    if ("classifier" in item) == ("sign" in item):
        raise SpecError(f"{where}: exactly one of classifier (side from P_hat at t_arm) and sign (side from a return before the decision)")
    classifier = _parse_classifier(item["classifier"], f"{where}.classifier", source, arm) if "classifier" in item else None
    sign = _parse_sign(item["sign"], f"{where}.sign", arm, footprint) if "sign" in item else None
    if (role == "maker_maker") != ("fill_proxy" in item):
        raise SpecError(f"{where}: a resting (maker_maker) entry needs fill_proxy; a taker entry has none")
    fill = _parse_fill_proxy(item["fill_proxy"], f"{where}.fill_proxy", source, arm, rest) if "fill_proxy" in item else None
    rw = _int_pair(item, "return_window_min", where)
    if rw[0] != arm or not footprint[0] <= rw[0] < rw[1] <= footprint[1]:
        raise SpecError(f"{where}.return_window_min: the return runs from the decision (arm_min) to an exit inside the footprint")
    entry = _check_keys(item["entry"], f"{where}.entry", ("kind", "price"), ("half_spread_bp",))
    if role == "maker_maker":
        _check_keys(entry, f"{where}.entry", ("kind", "price"))
        _choice(entry, "kind", f"{where}.entry", ("passive_limit",))
    else:
        _check_keys(entry, f"{where}.entry", ("kind", "price", "half_spread_bp"))
        _choice(entry, "kind", f"{where}.entry", ("taker",))
        _exact(entry, "half_spread_bp", f"{where}.entry", "costs.taker_entry_half_spread_bp")
    _exact(entry, "price", f"{where}.entry", f"c(h{arm:+d})")
    exit_ = _check_keys(item["exit"], f"{where}.exit", ("kind", "price"))
    _choice(exit_, "kind", f"{where}.exit", ("passive_assumed",))
    _exact(exit_, "price", f"{where}.exit", f"c(h{rw[1]:+d})")
    keys = item["key_candles_end_min"]
    if not isinstance(keys, list) or not keys or any(isinstance(k, bool) or not isinstance(k, int) for k in keys) or len(set(keys)) != len(keys):
        raise SpecError(f"{where}.key_candles_end_min: expected distinct integers")
    keys = tuple(sorted(int(k) for k in keys))
    needed = {rw[0], rw[1]} | ({sign["from_min"], sign["to_min"]} if sign else set())
    if not needed <= set(keys) or any(not footprint[0] <= k <= footprint[1] for k in keys):
        raise SpecError(f"{where}.key_candles_end_min: must contain the ends of the return and sign windows {sorted(needed)}, inside the footprint")
    criteria = _str_list(item, "criteria", where, CRITERIA)
    if list(criteria) != [c for c in CRITERIA if c in criteria] or not {"C1", "C2", "C3", "C4", "C5"} <= set(criteria):
        raise SpecError(f"{where}.criteria: C1-C5 are required for every type, in order (C6, C7 for T01)")
    validation = None
    if "C6" in criteria:
        if classifier is None or "validation" not in item:
            raise SpecError(f"{where}: C6 validates the classifier: classifier and validation required")
        validation = _parse_validation(item["validation"], f"{where}.validation", label, classifier, gate)
    elif "validation" in item:
        raise SpecError(f"{where}.validation without C6 in criteria")
    c7 = None
    if "C7" in criteria:
        if "c7" not in item:
            raise SpecError(f"{where}: C7 needs its c7 block")
        block = _check_keys(item["c7"], f"{where}.c7", ("event_hour_utc", "controls", "require_point_gt_bp"))
        _choice(block, "controls", f"{where}.c7", ("same_utc_days",))
        c7 = {"event_hour_utc": _int(block, "event_hour_utc", f"{where}.c7", 0, 23), "require_point_gt_bp": _num(block, "require_point_gt_bp", f"{where}.c7")}
    elif "c7" in item:
        raise SpecError(f"{where}.c7 without C7 in criteria")
    ct = _check_keys(item["controls"], f"{where}.controls", ("minute_of_hour", "days", "within", "default_settlement_grid_utc_hours"))
    m = CONTROL_DAYS_RE[generator["kind"]].match(_text(ct, "days", f"{where}.controls"))
    if m is None or m.group("label") != label:
        raise SpecError(f"{where}.controls.days: not the implemented rule for a {generator['kind']} type ({label})")
    if _text(ct, "within", f"{where}.controls") != window_rule:
        raise SpecError(f"{where}.controls.within must equal window_rule ({window_rule}): events and controls share the window")
    controls = {"minute_of_hour": _int(ct, "minute_of_hour", f"{where}.controls", 0, 59), "grid_hours": _hours(ct["default_settlement_grid_utc_hours"], f"{where}.controls.default_settlement_grid_utc_hours"),
                "within": window_rule}
    if window_rule == "funding_coverage":
        fc = _check_keys(item.get("funding_coverage"), f"{where}.funding_coverage", ("start", "end", "unknown"))
        _exact(fc, "start", f"{where}.funding_coverage", COVERAGE_START_TEXT)
        _exact(fc, "end", f"{where}.funding_coverage", "end_exclusive_utc")
        _exact(fc, "unknown", f"{where}.funding_coverage", gate + "INCONCLUSIVE for this type")
        if generator["kind"] != "funding_settlements":
            raise SpecError(f"{where}: window_rule funding_coverage belongs to a funding_settlements type")
    elif "funding_coverage" in item:
        raise SpecError(f"{where}.funding_coverage without window_rule funding_coverage")
    inconclusive, proxy_share = (), None
    if "inconclusive_if" in item:
        items = _str_list(item, "inconclusive_if", where)
        for cond in items:
            if (pm := PROXY_MISSING_RE.match(cond)) is not None and pm.group("kind") == "proxy":
                if classifier is None:
                    raise SpecError(f"{where}.inconclusive_if: {cond} needs a classifier")
                proxy_share = _share_of(pm)
            elif cond not in INCONCLUSIVE_TYPE_FIXED:
                raise SpecError(f"{where}.inconclusive_if: {cond!r} is not implemented")
        inconclusive = items
    if window_rule == "funding_coverage" and "funding_coverage_unknown" not in inconclusive:
        raise SpecError(f"{where}.inconclusive_if: funding_coverage_unknown must be listed (funding_coverage.unknown)")
    if validation is not None and "C6_failed_or_not_computable" not in inconclusive:
        raise SpecError(f"{where}.inconclusive_if: C6_failed_or_not_computable must be listed (validation.fail_or_not_computable)")
    if "funding_coverage_unknown" in inconclusive and window_rule != "funding_coverage":
        raise SpecError(f"{where}.inconclusive_if: funding_coverage_unknown without window_rule funding_coverage")
    if "C6_failed_or_not_computable" in inconclusive and validation is None:
        raise SpecError(f"{where}.inconclusive_if: C6_failed_or_not_computable without C6")
    if "classifier_index_source_unresolved_or_unavailable" in inconclusive:
        raise SpecError(f"{where}.inconclusive_if: classifier_index_source_unresolved_or_unavailable is a capture (Gate 2) condition")
    audit = {}
    if "audit_expected" in item:
        block = item["audit_expected"]
        if not isinstance(block, dict):
            raise SpecError(f"{where}.audit_expected must be an object")
        for key, value in block.items():
            if key not in AUDIT_KEYS[generator["kind"]] and not (generator["kind"] == "funding_settlements" and RAW_UPPER_RE.match(key)):
                raise SpecError(f"{where}.audit_expected: unknown key {key!r}")
            audit[key] = value
    entry_adverse, sensitivity = _parse_entry_adverse(item, where, role, fill is not None)
    gate2 = None
    return TypeSpec(name, label, position, role, _num(item, "expected_cost_bp", where, 0.0), _num(item, "expected_cost_bp_stress", where, 0.0),
                    _num(item, "days_per_year", where, 0.0, 366.0, lo_open=True), window_rule, generator, footprint, arm, rest, classifier, fill, sign,
                    {"kind": entry["kind"], "price": entry["price"]}, {"kind": exit_["kind"], "price": exit_["price"]}, rw, keys, validation, controls, c7, criteria,
                    inconclusive, proxy_share, audit, gate2, raw=item, entry_adverse_selection=entry_adverse, cost_sensitivity=sensitivity)


def _parse_verdict(block, gate: str, types: tuple) -> dict:
    _check_keys(block, "verdict", ("precedence", "inconclusive_global", "criteria", "pass", "fail", "fail_annotation", "closure_scope"))
    if list(_str_list(block, "precedence", "verdict")) != [gate + "INCONCLUSIVE", gate + "NOT_INFORMATIVE", "criteria_then_holm"]:
        raise SpecError(f"verdict.precedence: only [{gate}INCONCLUSIVE, {gate}NOT_INFORMATIVE, criteria_then_holm] is implemented")
    conditions, fetch_share = [], None
    for cond in _str_list(block, "inconclusive_global", "verdict"):
        if (m := FETCH_ERROR_RE.match(cond)) is not None:
            fetch_share = _share_of(m)
        elif cond not in INCONCLUSIVE_GLOBAL:
            raise SpecError(f"verdict.inconclusive_global: {cond!r} is not implemented")
        conditions.append(cond)
    crit = block["criteria"]
    if not isinstance(crit, dict):
        raise SpecError("verdict.criteria must be an object")
    used = sorted({c for t in types for c in t.criteria}, key=CRITERIA.index)
    if sorted(crit, key=lambda c: CRITERIA.index(c) if c in CRITERIA else 99) != used:
        raise SpecError(f"verdict.criteria: keys {sorted(crit)} must be the criteria the types use {used}")
    texts, trim = {}, None
    for key in used:
        text = _text(crit, key, "verdict.criteria")
        if key == "C5":
            m = C5_RE.match(text)
            if m is None:
                raise SpecError("verdict.criteria.C5: not the implemented rule (LB90 after removing ceil(x n) best event instants, same draws)")
            trim = float(m.group("share"))
        elif text != CRITERIA_TEXT[key]:
            raise SpecError(f"verdict.criteria.{key}: {text!r} is not the implemented rule {CRITERIA_TEXT[key]!r}")
        texts[key] = text
    _exact(block, "pass", "verdict", f"{gate}PASS(type) iff every criterion of the type holds and holm_local_confirmed(type)")
    _exact(block, "fail", "verdict", f"{gate}FAIL(type) otherwise, once no inconclusive condition holds and the floor is met")
    ann = _check_keys(block["fail_annotation"], "verdict.fail_annotation", ("ub90_ge_threshold", "ub90_lt_threshold"))
    return {"conditions": tuple(conditions), "fetch_error_share_max": fetch_share, "criteria_text": texts, "c5_trim_share": trim,
            "annotation_ge": _text(ann, "ub90_ge_threshold", "verdict.fail_annotation"), "annotation_lt": _text(ann, "ub90_lt_threshold", "verdict.fail_annotation"),
            "closure_scope": _text(block, "closure_scope", "verdict")}


def _parse_multiplicity(block, gate: str, types: tuple, alpha: float) -> dict:
    _check_keys(block, "multiplicity", ("local_holm", "programme", "forbidden_field_names"))
    lh = _check_keys(block["local_holm"], "multiplicity.local_holm", ("alpha", "family", "p_for_inconclusive_or_not_informative", "field", "gate2_effect"))
    family = _str_list(lh, "family", "multiplicity.local_holm")
    if list(family) != [t.name for t in types]:
        raise SpecError(f"multiplicity.local_holm.family {list(family)} must be the decisive types {[t.name for t in types]}")
    if _num(lh, "p_for_inconclusive_or_not_informative", "multiplicity.local_holm", 0.0, 1.0) != 1.0:
        raise SpecError("multiplicity.local_holm.p_for_inconclusive_or_not_informative: only 1.0 is implemented (GATES 2.1)")
    _exact(lh, "field", "multiplicity.local_holm", "holm_local_confirmed")
    _exact(lh, "gate2_effect", "multiplicity.local_holm", f"required for {gate}PASS")
    pr = _check_keys(block["programme"], "multiplicity.programme", ("m_max", "immediate_if_p_le", "otherwise", "field", "gate2_effect"))
    m_max = _int(pr, "m_max", "multiplicity.programme", 1)
    local_alpha = _num(lh, "alpha", "multiplicity.local_holm", 0.0, 1.0, lo_open=True)
    immediate = _num(pr, "immediate_if_p_le", "multiplicity.programme", 0.0, 1.0)
    if abs(immediate - local_alpha / m_max) > 1e-6:
        raise SpecError(f"multiplicity.programme.immediate_if_p_le {immediate:g} is not alpha / m_max = {local_alpha / m_max:.7f}")
    _text(pr, "otherwise", "multiplicity.programme")
    _exact(pr, "field", "multiplicity.programme", "pass_programme_immediate")
    _choice(pr, "gate2_effect", "multiplicity.programme", ("none",))
    forbidden = _str_list(block, "forbidden_field_names", "multiplicity")
    if "pass_programme" not in forbidden:
        raise SpecError("multiplicity.forbidden_field_names must forbid pass_programme (quant B4)")
    return {"alpha": local_alpha, "family": family, "m_max": m_max, "immediate_if_p_le": immediate, "otherwise": pr["otherwise"], "forbidden": forbidden}


def _parse_sealing(block, gate: str) -> dict:
    _check_keys(block, "sealing", ("applies_to_verdicts", "printed", "sealed", "file", "print_sha256", "open_after"))
    if set(_str_list(block, "applies_to_verdicts", "sealing")) != {gate + "INCONCLUSIVE", gate + "NOT_INFORMATIVE"}:
        raise SpecError(f"sealing.applies_to_verdicts: only {gate}INCONCLUSIVE and {gate}NOT_INFORMATIVE are implemented (LP-G5 section 8)")
    printed = _str_list(block, "printed", "sealing", SEALING_PRINTED)
    sealed = _str_list(block, "sealed", "sealing", SEALING_SEALED)
    if set(printed) != set(SEALING_PRINTED) or set(sealed) != set(SEALING_SEALED):
        raise SpecError(f"sealing: printed must be {SEALING_PRINTED} and sealed {SEALING_SEALED} (the implemented split)")
    _exact(block, "file", "sealing", "sealed/<type>.json")
    _bool(block, "print_sha256", "sealing", only=True)
    return {"verdicts": ("INCONCLUSIVE", "NOT_INFORMATIVE"), "printed": printed, "sealed": sealed, "open_after": _text(block, "open_after", "sealing")}


def _parse_gate2_inscription(block, gate: str) -> dict:
    keys = tuple(gate + v for v in VERDICTS)
    _check_keys(block, "gate2_inscription", keys + ("discretion",))
    _choice(block, "discretion", "gate2_inscription", ("none",))
    return {v: _choice(block, gate + v, "gate2_inscription", GATE2_INSCRIPTION_VALUES[v]) for v in VERDICTS}


def _parse_economic(block) -> dict:
    keys = ("s_eco_usd_per_year", "capital_usd", "leverage", "max_live", "order_notional_usd") + tuple(ECONOMIC_FIXED) + ("readings", "audit_break_even_net_bp")
    _check_keys(block, "economic", keys)
    for key, value in ECONOMIC_FIXED.items():
        _exact(block, key, "economic", value)
    readings = _str_list(block, "readings", "economic")
    if len(readings) != 3 or not readings[0].startswith("PASS, poursuivi") or not readings[1].startswith("PASS, non poursuivi") or readings[2] != "FAIL":
        raise SpecError("economic.readings: [\"PASS, poursuivi\", \"PASS, non poursuivi (…)\", \"FAIL\"] (GATES 1.4)")
    out = {"s_eco": _num(block, "s_eco_usd_per_year", "economic", 0.0), "capital": _num(block, "capital_usd", "economic", 0.0, lo_open=True),
           "leverage": _num(block, "leverage", "economic", 0.0, lo_open=True), "max_live": _int(block, "max_live", "economic", 1),
           "notional": _num(block, "order_notional_usd", "economic", 0.0, lo_open=True), "readings": readings}
    if out["max_live"] * out["notional"] / out["leverage"] > out["capital"] + 1e-9:
        raise SpecError("economic: max_live × order_notional_usd / leverage exceeds capital_usd (the portfolio reading is not feasible)")
    be = block["audit_break_even_net_bp"]
    if not isinstance(be, dict):
        raise SpecError("economic.audit_break_even_net_bp must be an object")
    out["break_even"] = dict(be)
    return out


def _parse_descriptive(block, labels: set) -> dict:
    _check_keys(block, "descriptive", ("rule", "reports"), ("a1",))
    reports = _str_list(block, "reports", "descriptive", DESCRIPTIVE_REPORTS)
    for name in reports:
        label = name.split("_", 1)[0]
        if re.match(r"^T\d+$", label) and label not in labels:
            raise SpecError(f"descriptive.reports: {name} names no type of the spec")
    a1 = _parse_a1_block(block["a1"]) if "a1" in block else None
    return {"rule": _text(block, "rule", "descriptive"), "reports": reports, "a1": a1}


def _parse_a1_block(block) -> dict:
    """Amendment A1 — `descriptive.a1`: the status, the definitions of A / B / C and of the A1 reports, the precedence and the rules
    of the diagnostic classification, word for word (a different text would be a different rule)."""
    where = "descriptive.a1"
    _check_keys(block, where, ("status", "definitions", "classification_precedence", "classification_rules"))
    _exact(block, "status", where, A1_STATUS)
    definitions = _check_keys(block["definitions"], f"{where}.definitions", tuple(A1_DEFINITIONS))
    for key, text in A1_DEFINITIONS.items():
        _exact(definitions, key, f"{where}.definitions", text)
    if tuple(_str_list(block, "classification_precedence", where)) != DIAGNOSTIC_PRECEDENCE:
        raise SpecError(f"{where}.classification_precedence: {list(DIAGNOSTIC_PRECEDENCE)} is the implemented precedence")
    rules = _check_keys(block["classification_rules"], f"{where}.classification_rules", tuple(DIAGNOSTIC_RULES))
    for key, text in DIAGNOSTIC_RULES.items():
        _exact(rules, key, f"{where}.classification_rules", text)
    return {"status": A1_STATUS, "definitions": dict(A1_DEFINITIONS), "precedence": DIAGNOSTIC_PRECEDENCE, "rules": dict(DIAGNOSTIC_RULES)}


def _check_a1_reports(descriptive: dict, types: tuple) -> None:
    """Amendment A1 — the A1 reports are mandatory in a history spec (their capabilities are in HISTORY_REQUIRES): the three per-type
    reports, the two fill-proxy reports of every type with a fill proxy, the sensitivity of every type whose entry leg is measured by
    the fill proxy, and the `descriptive.a1` block; a fill-proxy or sensitivity report of a type without the feature is refused."""
    reports = set(descriptive["reports"])
    if descriptive["a1"] is None:
        raise SpecError("descriptive.a1: missing (the A1 definitions and the diagnostic rules are written in the spec before any reading)")
    expected = set(A1_PER_TYPE_REPORTS)
    for t in types:
        if t.fill_proxy is not None:
            expected |= {t.label + suffix for suffix in A1_FILL_PROXY_SUFFIXES}
        if t.cost_sensitivity is not None:
            expected.add(t.label + A1_SENSITIVITY_SUFFIX)
    missing = sorted(expected - reports)
    if missing:
        raise SpecError(f"descriptive.reports: the A1 reports {missing} are mandatory (reported, never decisive)")
    by_label = {t.label: t for t in types}
    for name in reports:
        label = name.split("_", 1)[0]
        if label in by_label and any(name == label + s for s in A1_FILL_PROXY_SUFFIXES) and by_label[label].fill_proxy is None:
            raise SpecError(f"descriptive.reports: {name}: {label} has no fill proxy")
        if label in by_label and name == label + A1_SENSITIVITY_SUFFIX and by_label[label].cost_sensitivity is None:
            raise SpecError(f"descriptive.reports: {name}: {label} has no cost_sensitivity_pre_a1 (its entry leg is not measured by the fill proxy)")


def _parse_fetch_plan(block, universe: tuple, eligibility_days: float) -> dict:
    _check_keys(block, "fetch_plan", ("host", "instruments", "end_exclusive", "pre_freeze", "post_freeze", "requests_total_estimate", "estimate_assumption",
                                      "rate_scale_default", "rate_scale_proposed", "max_requests"))
    _exact(block, "host", "fetch_plan", g5_fetch.DEFAULT_HOST)
    _exact(block, "instruments", "fetch_plan", "universe.analysed")
    _exact(block, "end_exclusive", "fetch_plan", FETCH_END_TEXT)
    pre = _check_keys(block["pre_freeze"], "fetch_plan.pre_freeze", ("streams", "mode", "printed", "sealed", "requests_estimate", "register_status"))
    if tuple(_str_list(pre, "streams", "fetch_plan.pre_freeze")) != g5_fetch.SEALED_STREAMS:
        raise SpecError(f"fetch_plan.pre_freeze.streams: only {list(g5_fetch.SEALED_STREAMS)} is fetched before the freeze (P-G5-3)")
    _choice(pre, "mode", "fetch_plan.pre_freeze", ("sealed",))
    if tuple(_str_list(pre, "printed", "fetch_plan.pre_freeze")) != g5_fetch.SEALED_PRINTED:
        raise SpecError(f"fetch_plan.pre_freeze.printed: the sealed fetcher prints {list(g5_fetch.SEALED_PRINTED)} only")
    _text(pre, "sealed", "fetch_plan.pre_freeze")
    _choice(pre, "register_status", "fetch_plan.pre_freeze", ("unread",))
    post = block["post_freeze"]
    _check_keys(post, "fetch_plan.post_freeze", g5_fetch.PHASES["post_freeze"])
    pages, starts, estimates = {}, {}, {"funding_hist": _int(pre, "requests_estimate", "fetch_plan.pre_freeze", 0)}
    expected_rule = {"candles_1m": "eligibility_minus_1d", "mark_1m": "max_coverage_eligibility_minus_1d", "index_1m": "max_coverage_eligibility_minus_1d"}
    for stream in g5_fetch.PHASES["post_freeze"]:
        where = f"fetch_plan.post_freeze.{stream}"
        item = _check_keys(post[stream], where, ("start", "page", "requests_estimate"), ("inst_id",) if stream == "index_1m" else ())
        rule = g5_fetch.START_RULES.get(_text(item, "start", where))
        if rule != expected_rule[stream]:
            raise SpecError(f"{where}.start: {item['start']!r} is not the implemented start of {stream} (LP-G5 §4.3)")
        if stream == "index_1m":
            _exact(_check_keys(item, where, ("start", "page", "requests_estimate", "inst_id")), "inst_id", where, "index_id")
        pages[stream] = _int(item, "page", where, 1)
        if pages[stream] != g5_fetch.STREAMS[stream].limit_max:
            raise SpecError(f"{where}.page {pages[stream]} is not the documented maximum {g5_fetch.STREAMS[stream].limit_max}")
        starts[stream] = rule
        estimates[stream] = _int(item, "requests_estimate", where, 0)
    pages["funding_hist"] = g5_fetch.STREAMS["funding_hist"].limit_max
    total = _int(block, "requests_total_estimate", "fetch_plan", 0)
    if total != sum(estimates.values()):
        raise SpecError(f"fetch_plan.requests_total_estimate {total} is not the sum of the streams' estimates {sum(estimates.values())}")
    m = ESTIMATE_RE.match(_text(block, "estimate_assumption", "fetch_plan"))
    if m is None:
        raise SpecError("fetch_plan.estimate_assumption must start with 'coverage_start = YYYY-MM-DD'")
    assumed_us = tape.day_of_date(m.group("date")) * DAY_US
    fetched = re.search(r"funding fetched on (\d{4}-\d{2}-\d{2})", block["estimate_assumption"])
    default_scale = g5_fetch.FetchConfig.__dataclass_fields__["rate_scale"].default
    if _num(block, "rate_scale_default", "fetch_plan", 0.0, 1.0, lo_open=True) != default_scale:
        raise SpecError(f"fetch_plan.rate_scale_default must be the fetcher's default {default_scale}")
    proposed = _num(block, "rate_scale_proposed", "fetch_plan", 0.0, 1.0, lo_open=True)
    _text(block, "max_requests", "fetch_plan")
    # Calcul d'audit (no data): the spec's request estimates recomputed from its universe and the assumed D_cov.
    rows = [{"instId": i.inst_id, "uly": i.index_id, "listTime": str(i.list_time_us // 1000)} for i in universe]
    computed = g5_fetch.spec_estimate(rows, eligibility_days, assumed_us, pages)
    if computed != estimates:
        raise SpecError(f"fetch_plan: request estimates {estimates} differ from the recomputation {computed} (universe, eligibility, pages, coverage_start {m.group('date')})")
    return {"host": block["host"], "pages": pages, "starts": starts, "estimates": estimates, "total": total, "estimate_coverage_start_us": assumed_us,
            "estimate_fetched_on": fetched.group(1) if fetched else None, "rate_scale_proposed": proposed, "post_freeze": {s: {"page": pages[s], "start": starts[s]} for s in starts}}


# ----------------------------------------------------------------------------- parse-time audits (calcul d'audit: the spec's own lists, no data)


def audit_local_time(tspec: TypeSpec, spec_like) -> dict:
    """T05 calendar audit: NYSE days of the window with >= 1 eligible instrument at h, raw instants (exclusions applied), instants
    with >= 3 eligible instruments, the first instant and the lower-median halves (LP-G5 Annexe C)."""
    eligible = sorted(i.eligible_from_us for i in spec_like.universe)
    rows = []
    for moment, _, excluded in local_time_instants(tspec, spec_like, apply_exclusions=False):
        count = int(np.searchsorted(np.array(eligible, dtype=np.int64), moment + tspec.footprint[0] * MINUTE_US, side="right"))
        if count >= 1:
            rows.append((moment, count, excluded))
    raw = [(m, c) for m, c, e in rows if not e]
    first_half = (len(raw) - 1) // 2 + 1 if raw else 0
    return {"nyse_days_with_eligible_instrument": len(rows), "raw_instants": len(raw), "raw_instants_with_ge_3_instruments": sum(1 for _, c in raw if c >= 3),
            "first_instant_utc": _iso(raw[0][0]) if raw else None, "halves_by_lower_median_day": [first_half, len(raw) - first_half]}


def audit_funding_settlements(tspec: TypeSpec, d_cov_us: int, end_us: int, min_instants: int) -> dict:
    """T01 instant audit: settlements of the default grid served from the assumed D_cov to END, kept when both neighbours are
    served at the filter's gaps (the first and the last fall: LP-G5 §4.4), and the f_inst below which NOT_INFORMATIVE is expected."""
    first_day, last_day = d_cov_us // DAY_US, (end_us - 1) // DAY_US
    served = sorted(d * DAY_US + h * HOUR_US for d in range(int(first_day), int(last_day) + 1) for h in tspec.controls["grid_hours"])
    served = [s for s in served if d_cov_us <= s < end_us]
    present = set(served)
    gen = tspec.generator
    kept = [s for s in served if (s - gen["previous_gap_us"]) in present and (s + gen["next_gap_us"]) in present]
    return {"raw_instants_upper_bound": len(kept), "not_informative_expected_if_f_inst_below": (min_instants / len(kept)) if kept else None}


def _check_audits(types: tuple, spec_like, fetch_plan: dict | None, economic: dict, power: dict) -> dict:
    out: dict = {}
    for t in types:
        if t.generator["kind"] == "local_time":
            computed = audit_local_time(t, spec_like)
            for key, value in t.audit_expected.items():
                if computed[key] != value:
                    raise SpecError(f"{t.name}.audit_expected.{key} = {value!r} but the spec's calendars and universe give {computed[key]!r} (calcul d'audit)")
            out[t.name] = computed
        else:
            if t.audit_expected and fetch_plan is None:
                raise SpecError(f"{t.name}.audit_expected needs the fetch plan's assumed D_cov")
            if fetch_plan is None:
                continue
            computed = audit_funding_settlements(t, fetch_plan["estimate_coverage_start_us"], spec_like.end_us, power["min_instants"])
            for key, value in t.audit_expected.items():
                m = RAW_UPPER_RE.match(key)
                if m is not None:
                    day = f"{m.group('y')}-{m.group('m')}-{m.group('d')}"
                    if fetch_plan.get("estimate_fetched_on") != day:
                        raise SpecError(f"{t.name}.audit_expected.{key}: the fetch plan assumes the funding fetched on {fetch_plan.get('estimate_fetched_on')}")
                    if computed["raw_instants_upper_bound"] != value:
                        raise SpecError(f"{t.name}.audit_expected.{key} = {value!r} but the grid from the assumed D_cov gives {computed['raw_instants_upper_bound']}")
                elif key == "not_informative_expected_if_f_inst_below":
                    if computed[key] is None or abs(float(value) - computed[key]) > 5e-4:
                        raise SpecError(f"{t.name}.audit_expected.{key} = {value!r} but min_instants / raw instants = {computed[key]}")
            out[t.name] = computed
    by_name = {t.name: t for t in types}
    for key, value in economic["break_even"].items():
        m = BREAK_EVEN_RE.match(key)
        if m is None or m.group("type") not in by_name or isinstance(value, bool) or not isinstance(value, (int, float)):
            raise SpecError(f"economic.audit_break_even_net_bp.{key}: '<type>_<k>_fills_per_day|event_day' with a number is the implemented form")
        t, k = by_name[m.group("type")], int(m.group("k"))
        if m.group("unit") == "event_day":
            audit = out.get(t.name)
            if not audit or "raw_instants" not in audit or not audit["nyse_days_with_eligible_instrument"]:
                raise SpecError(f"economic.audit_break_even_net_bp.{key}: per event day needs the local-time audit of {t.name}")
            per_day = k * audit["raw_instants"] / audit["nyse_days_with_eligible_instrument"]
        else:
            per_day = float(k)
        computed = economic["s_eco"] / (per_day * economic["notional"] / 1e4 * t.days_per_year)
        if abs(computed - float(value)) > 0.006:
            raise SpecError(f"economic.audit_break_even_net_bp.{key} = {value} but S_eco / (fills/day × notional / 1e4 × days/year) = {computed:.4f}")
        out.setdefault("break_even", {})[key] = computed
    return out


# ----------------------------------------------------------------------------- the specification: entry point


_TOP_REQUIRED = ("spec_id", "spec_schema", "spec_version", "status", "protocol", "note", "source", "dataset_id", "end_exclusive_utc", "quarantine_floor_utc", "window",
                 "requires", "freeze_fill", "universe", "eligibility", "price", "frozen_candle", "unit", "statistics", "power_floor", "costs", "controls_common", "sessions",
                 "sessions_note", "calendars", "macro_filter", "events", "verdict", "multiplicity", "sealing", "gate2_inscription", "economic", "descriptive")
_TOP_OPTIONAL = ("fetch_plan",)


@dataclass(frozen=True)
class _Partial:
    """What the calendar functions need while the spec is being read."""
    start: _date
    end_exclusive: _date
    universe: tuple
    calendars: dict
    sessions: tuple = ()
    macro: tuple = ()

    @property
    def start_us(self) -> int:
        return tape.day_of_date(self.start.isoformat()) * DAY_US

    @property
    def end_us(self) -> int:
        return tape.day_of_date(self.end_exclusive.isoformat()) * DAY_US


def parse_spec(document, gate1=None) -> Spec:
    """The strict reader of `g5-spec/2` (B xviii). Raises `SpecError` on any unknown key, unknown value, missing key, unimplemented
    capability or internal inconsistency; returns the parsed `Spec` otherwise. A `source: capture` spec (the Gate 2,
    `specs/g5_gate2_spec.json`) is read by `g5_gate2.parse_capture_spec`; `gate1` (a Gate 1 spec file path, document or `Spec`) replaces
    the file named by its `frozen_with.spec` for the identity check (`--gate1-spec`, synthetic tests); a FROZEN Gate 2 needs a FROZEN
    Gate 1 (CTO M2)."""
    if not isinstance(document, dict):
        raise SpecError("spec must be an object")
    if document.get("spec_schema") != SPEC_SCHEMA:
        raise SpecError(f"spec_schema {document.get('spec_schema')!r} is not implemented ({EVALUATOR_VERSION} reads {SPEC_SCHEMA} only)")
    if document.get("source") == "capture":
        from . import g5_gate2  # lazy: g5_gate2 imports this module

        return g5_gate2.parse_capture_spec(document, gate1)
    if gate1 is not None:
        raise SpecError("gate1: only a capture (Gate 2) spec is checked against a Gate 1 spec")
    _check_keys(document, "spec", _TOP_REQUIRED, _TOP_OPTIONAL)
    spec_id = _text(document, "spec_id", "spec")
    version = _text(document, "spec_version", "spec")
    status = _choice(document, "status", "spec", STATUSES)
    if status == "FROZEN" and "draft" in version.lower():
        raise SpecError(f"spec_version {version!r}: a FROZEN spec carries a final version")
    source = _choice(document, "source", "spec", ("history", "capture"))  # capture: dispatched above
    gate = "G5_GATE1_"
    if "GATE1" not in spec_id:
        raise SpecError(f"spec_id {spec_id!r} does not name the Gate 1 of a {source} spec")
    _text(document, "protocol", "spec")
    _text(document, "note", "spec")
    dataset_id = _text(document, "dataset_id", "spec")
    _exact(document, "end_exclusive_utc", "spec", g5_fetch.END_EXCLUSIVE)
    _exact(document, "quarantine_floor_utc", "spec", xc.QUARANTINE_FLOOR)
    window = _check_keys(document["window"], "window", ("start", "end_exclusive"))
    start, end_exclusive = _date(window["start"], "window.start"), _date(window["end_exclusive"], "window.end_exclusive")
    if start >= end_exclusive:
        raise SpecError("window.start must be before window.end_exclusive")
    start_us, end_us = tape.day_of_date(start.isoformat()) * DAY_US, tape.day_of_date(end_exclusive.isoformat()) * DAY_US
    if start_us < QUARANTINE_END_US and end_us > QUARANTINE_START_US:
        raise SpecError(f"window {start}..{end_exclusive} touches the quarantine [{g5_fetch.END_EXCLUSIVE}, {xc.QUARANTINE_FLOOR})")
    if source == "history" and end_us > g5_fetch.END_EXCLUSIVE_US:
        raise SpecError(f"a history window ends at or before END_EXCLUSIVE {g5_fetch.END_EXCLUSIVE}")
    requires = _parse_requires(document, source)
    freeze_fill = _parse_freeze_fill(document["freeze_fill"], status)
    el = _check_keys(document["eligibility"], "eligibility", ("days_after_list_time", "reference", "applies_to", "count_field"))
    eligibility_days = _num(el, "days_after_list_time", "eligibility", 0.0)
    _choice(el, "reference", "eligibility", ("footprint_start",))
    if list(_str_list(el, "applies_to", "eligibility")) != ["events", "controls"]:
        raise SpecError("eligibility.applies_to: the rule applies to the events and the controls alike")
    count_field = _text(el, "count_field", "eligibility")
    universe, excluded = _parse_universe(document["universe"], eligibility_days, end_us, source)
    price = _parse_price(document["price"], source)
    frozen_candle = _parse_frozen_candle(document["frozen_candle"], source)
    unit = _parse_unit(document["unit"])
    statistics = _parse_statistics(document["statistics"])
    power = _parse_power(document["power_floor"], gate)
    costs = _parse_costs(document["costs"], source)
    controls_common = _parse_controls_common(document["controls_common"], source)
    sessions = _parse_sessions(document["sessions"], status)
    _text(document, "sessions_note", "spec")
    calendars = _parse_calendars(document["calendars"])
    macro = _parse_macro(document["macro_filter"], calendars)
    items = _list(document, "events", "spec")
    types = tuple(_parse_type(item, i, source, gate, calendars) for i, item in enumerate(items))
    names = [t.name for t in types]
    if len(set(names)) != len(names) or len({t.label for t in types}) != len(types):
        raise SpecError(f"duplicate event types or labels: {names}")
    if "type_instants" in controls_common["components"]:
        target = controls_common["components"]["type_instants"]
        if target[0] not in names or target[3] != target[0].split("_", 1)[0] or next(t for t in types if t.name == target[0]).generator["kind"] != "local_time":
            raise SpecError(f"controls_common.exclusions: {target[0]} is not a local_time type of the spec (labelled {target[3]})")
    for t in types:
        check_type_costs(t, costs)
    verdict = _parse_verdict(document["verdict"], gate, types)
    if verdict["fetch_error_share_max"] is None and source == "history":
        raise SpecError("verdict.inconclusive_global: a history spec states fetch_job_error_share_gt_<x> (LP-G5 §4.5)")
    multiplicity = _parse_multiplicity(document["multiplicity"], gate, types, statistics["alpha"])
    sealing = _parse_sealing(document["sealing"], gate)
    gate2_inscription = _parse_gate2_inscription(document["gate2_inscription"], gate)
    economic = _parse_economic(document["economic"])
    descriptive = _parse_descriptive(document["descriptive"], {t.label for t in types})
    _check_a1_reports(descriptive, types)
    if "fetch_plan" not in document:
        raise SpecError("spec: missing key(s) ['fetch_plan'] (a history spec carries its fetch plan)")
    fetch_plan = _parse_fetch_plan(document["fetch_plan"], universe, eligibility_days)
    gate2 = None
    partial = _Partial(start, end_exclusive, universe, calendars, sessions, macro)
    audit = _check_audits(types, partial, fetch_plan, economic, power)
    notes = {"note": document["note"], "universe.rule": document["universe"]["rule"], "universe.selection_bias_declared": document["universe"]["selection_bias_declared"],
             "controls_common.rule": document["controls_common"]["rule"], "sessions_note": document["sessions_note"], "calendars.sources": list(calendars["sources"]),
             "calendars.read_by": calendars["read_by"], "macro_filter.type": document["macro_filter"]["type"], "macro_filter.use": document["macro_filter"]["use"],
             "macro_filter.ten_am_et_releases": document["macro_filter"]["ten_am_et_releases"], "costs.g2_table_substitution": costs["g2_table_substitution"],
             "verdict.closure_scope": verdict["closure_scope"], "multiplicity.programme.otherwise": multiplicity["otherwise"], "sealing.open_after": sealing["open_after"],
             "freeze_fill.rule": freeze_fill["rule"], "freeze_fill.absent_keys": freeze_fill["absent"], "gate2_inscription.discretion": document["gate2_inscription"]["discretion"],
             "sessions_to_verify_at_freeze": [s["name"] for s in sessions if s["verify_at_freeze"]],
             "classifier.threshold_origin": {t.name: t.classifier["threshold_origin"] for t in types if t.classifier is not None}}
    if fetch_plan is not None:
        notes.update({"fetch_plan.max_requests": document["fetch_plan"]["max_requests"], "fetch_plan.pre_freeze.sealed": document["fetch_plan"]["pre_freeze"]["sealed"],
                      "fetch_plan.estimate_assumption": document["fetch_plan"]["estimate_assumption"], "fetch_plan.rate_scale_proposed": fetch_plan["rate_scale_proposed"]})
    return Spec(document, spec_id, version, status, source, dataset_id, document["protocol"], gate, start, end_exclusive, requires, freeze_fill, universe, excluded,
                eligibility_days, count_field, price, frozen_candle, unit, statistics, power, costs, controls_common, sessions, calendars, macro, types, verdict,
                multiplicity, sealing, gate2_inscription, economic, descriptive, fetch_plan, gate2, audit, notes)


def load_spec(path: Path, gate1=None) -> tuple[Spec, str]:
    """The spec file and its sha256 (of the exact bytes read). `gate1` (Gate 2 only): the Gate 1 spec frozen with it — a path, a
    document or a `Spec` — instead of the file named by its `frozen_with.spec` (`--gate1-spec`, synthetic tests)."""
    data = Path(path).read_bytes()
    try:
        document = json.loads(data.decode("utf-8"))
    except (UnicodeDecodeError, ValueError) as error:
        raise SpecError(f"{path}: not JSON ({error})") from None
    return parse_spec(document, gate1), g5_fetch.sha256_bytes(data)


def guard_window(spec: Spec) -> dict:
    """The quarantine guard of a run (also checked at parse time)."""
    if spec.start_us < QUARANTINE_END_US and spec.end_us > QUARANTINE_START_US:
        raise SystemExit(f"quarantine: the window {spec.start}..{spec.end_exclusive} touches [{g5_fetch.END_EXCLUSIVE}, {xc.QUARANTINE_FLOOR})")
    if spec.source == "history" and spec.end_us > g5_fetch.END_EXCLUSIVE_US:
        raise SystemExit(f"history window must end at or before END_EXCLUSIVE {g5_fetch.END_EXCLUSIVE}")
    if spec.source == "capture" and spec.start_us < QUARANTINE_END_US:
        raise SystemExit(f"a capture window must start at or after the quarantine floor {xc.QUARANTINE_FLOOR}")
    return {"end_exclusive": g5_fetch.END_EXCLUSIVE, "quarantine_floor": xc.QUARANTINE_FLOOR, "window": [spec.start.isoformat(), spec.end_exclusive.isoformat()],
            "source": spec.source, "read_from": tape.iso_utc(spec.pad_start_us)}


# ----------------------------------------------------------------------------- minute series


@dataclass
class Series:
    """One instrument on the minute grid g_k (k = 0..N, from the padded start to the window end): every array holds, at g_k,
    the value of the candle ending at g_k (history) or of the minute ending at g_k (capture); NaN when missing."""
    meta: Instrument
    source: str
    grid_us: np.ndarray
    close: np.ndarray
    low: np.ndarray
    high: np.ndarray
    vol: np.ndarray
    mark: np.ndarray
    index: np.ndarray
    settlements_us: np.ndarray  # every settlement instant read (rate known or not), sorted, unique
    settlement_rates: np.ndarray  # realized (history) / settled (capture) rate per settlement, NaN when unknown
    d_cov_start_us: int | None  # first settlement whose predecessor is served (history); None: unknown
    coverage: dict = field(default_factory=dict)
    book: lp_sim.Book | None = None
    prints: lp_sim.Prints | None = None
    inst_spec: lp_sim.InstrumentSpec | None = None
    cache: dict = field(default_factory=dict, repr=False)

    @property
    def inst(self) -> str:
        return self.meta.inst_id

    @property
    def size(self) -> int:
        return int(self.grid_us.size)

    def index_of(self, instants_us) -> np.ndarray:
        return ((np.asarray(instants_us, dtype=np.int64) - int(self.grid_us[0])) // MINUTE_US).astype(np.int64)

    @property
    def premium(self) -> np.ndarray:
        if "premium" not in self.cache:
            with np.errstate(divide="ignore", invalid="ignore"):
                self.cache["premium"] = (self.mark - self.index) / self.index
        return self.cache["premium"]

    def at(self, array: np.ndarray, k: np.ndarray) -> np.ndarray:
        """`array[k]`, NaN outside the grid."""
        k = np.asarray(k, dtype=np.int64)
        out = np.full(k.size, np.nan)
        ok = (k >= 0) & (k < self.size)
        out[ok] = array[k[ok]]
        return out

    def rate_at(self, instants_us) -> np.ndarray:
        """The known rate of the settlement at exactly each instant (NaN: no settlement, or its rate unknown)."""
        instants = np.asarray(instants_us, dtype=np.int64)
        out = np.full(instants.size, np.nan)
        ts = self.settlements_us
        if ts.size == 0 or instants.size == 0:
            return out
        pos = np.searchsorted(ts, instants)
        hit = pos < ts.size
        hit[hit] = ts[pos[hit]] == instants[hit]
        out[hit] = self.settlement_rates[pos[hit]]
        return out

    def funding_crossed(self, open_us: np.ndarray, close_us: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
        """Per window (open, close]: settlements crossed, fsum of their known rates, and how many have an unknown rate."""
        open_us, close_us = np.asarray(open_us, dtype=np.int64), np.asarray(close_us, dtype=np.int64)
        lo = np.searchsorted(self.settlements_us, open_us, side="right")
        hi = np.searchsorted(self.settlements_us, close_us, side="right")
        count = (hi - lo).astype(np.int64)
        total = np.zeros(open_us.size)
        unknown = np.zeros(open_us.size, dtype=np.int64)
        for i in np.nonzero(count > 0)[0].tolist():
            rates = self.settlement_rates[lo[i]: hi[i]]
            known = np.isfinite(rates)
            unknown[i] = int((~known).sum())
            total[i] = math.fsum(rates[known].tolist())
        return count, total, unknown

    def p_hat(self, k_arm: np.ndarray, block: dict) -> np.ndarray:
        """(B) iv — P̂ at the boundaries `k_arm`: Σ_{j=1..n} j·π(k_arm − n + j) / Σ j over the present boundaries (π = (m − idx) / idx,
        the closes of the mark and index candles ending there), NaN when fewer than `min_coverage` × n are present. Nothing after
        k_arm is read. Cached per (k, n, coverage): the same value serves the events, the controls and the descriptive variants."""
        n, cover = int(block["lookback_min"]), float(block["min_coverage"])
        base = self.premium
        weights = np.arange(1, n + 1, dtype=np.float64)
        out = np.full(np.asarray(k_arm).size, np.nan)
        cache = self.cache.setdefault(("p_hat", n, cover), {})
        for i, kk in enumerate(np.asarray(k_arm, dtype=np.int64).tolist()):
            if kk in cache:
                out[i] = cache[kk]
                continue
            value = math.nan
            lo = kk - n + 1
            if lo >= 0 and kk < base.size:
                window = base[lo: kk + 1]
                ok = np.isfinite(window)
                if ok.sum() >= cover * n:
                    value = math.fsum((weights[ok] * window[ok]).tolist()) / math.fsum(weights[ok].tolist())
            cache[kk] = value
            out[i] = value
        return out


def covered_from_us(series: Series) -> int:
    """The earliest window opening whose crossed settlements are all covered (§4.4, §11.1): the served predecessor of D_cov (the last
    settlement before D_cov; none lies between them), or D_cov itself when no predecessor is held."""
    d_cov = int(series.d_cov_start_us)
    ts = series.settlements_us
    pos = int(np.searchsorted(ts, d_cov, side="left"))
    return int(ts[pos - 1]) if pos > 0 else d_cov


def make_series(meta: Instrument, grid_us, close, low=None, high=None, vol=None, mark=None, index=None, settlements_us=(), rates=(), d_cov_start_us: int | None = None,
                source: str = "history", coverage: dict | None = None) -> Series:
    """A `Series` from arrays (synthetic tests): `low` / `high` default to `close`, `vol` to 1, mark / index to NaN."""
    grid = np.asarray(grid_us, dtype=np.int64)
    close = np.asarray(close, dtype=np.float64)
    nan = np.full(grid.size, np.nan)
    ts = np.asarray(settlements_us, dtype=np.int64)
    rt = np.asarray(rates, dtype=np.float64) if len(rates) else np.full(ts.size, np.nan)
    order = np.argsort(ts, kind="stable")
    return Series(meta, source, grid, close, close.copy() if low is None else np.asarray(low, dtype=np.float64), close.copy() if high is None else np.asarray(high, dtype=np.float64),
                  np.ones(grid.size) if vol is None else np.asarray(vol, dtype=np.float64), nan.copy() if mark is None else np.asarray(mark, dtype=np.float64),
                  nan.copy() if index is None else np.asarray(index, dtype=np.float64), ts[order], rt[order], d_cov_start_us, coverage or {})


def spec_grid(spec: Spec) -> np.ndarray:
    return np.arange(spec.pad_start_us, spec.end_us + MINUTE_US, MINUTE_US, dtype=np.int64)


def _dates_between(start_us: int, end_us: int) -> list[str]:
    first, last = start_us // DAY_US, (end_us - 1) // DAY_US
    return [tape.date_of_day(d) for d in range(int(first), int(last) + 1)]


def _place(grid: np.ndarray, open_ts_us: np.ndarray, values: np.ndarray, target: np.ndarray) -> int:
    """`target[k] = value` for the candle opening at g_{k−1} (ending at g_k); returns the rows outside the grid."""
    k = (open_ts_us - int(grid[0])) // MINUTE_US + 1
    inside = (k >= 0) & (k < grid.size) & ((open_ts_us - int(grid[0])) % MINUTE_US == 0)
    target[k[inside]] = values[inside]
    return int((~inside).sum())


def d_cov_of(settlements_us: np.ndarray, state: dict | None) -> tuple[int | None, str]:
    """D_cov start of one instrument (LP-G5 §4.4): its first settlement whose predecessor is also served, known only when the
    job ended `reached_start` or `server_empty_before_start` (`end_reason`, CTO M1)."""
    if not state or state.get("end_reason") not in g5_fetch.COMPLETE_REASONS or not state.get("complete"):
        return None, f"unknown (end_reason {None if not state else state.get('end_reason')!r})"
    ts = np.unique(np.asarray(settlements_us, dtype=np.int64))
    if ts.size < 2:
        return None, "unknown (fewer than two settlements served)"
    return int(ts[1]), f"second settlement served ({state.get('end_reason')})"


def load_history_series(tape_dir: Path, meta: Instrument, spec: Spec, manifest: dict | None, sealed: "g5_fetch.SealedArchive | None") -> Series:
    """One instrument of a `g5_fetch` tape: last-price candles, mark and index candles from the tape, the funding history from the
    sealed archive read in memory (`g5_fetch.SealedArchive`, CTO m3; None: no funding history). Rows at or after END_EXCLUSIVE dropped
    and counted; `confirm = 0` dropped and counted."""
    grid = spec_grid(spec)
    dates = _dates_between(spec.pad_start_us, spec.end_us)
    cov: dict = {"rows_at_or_after_end_dropped": 0, "rows_outside_grid": 0, "unconfirmed_dropped": {}, "confirm_unknown": {}, "index_id": meta.index_id, "minutes": int(grid.size)}

    def candles(layout: str, key: str) -> np.ndarray:
        rows, _ = tape.load_days(tape_dir, layout, key, dates, allow_pending=False)
        keep = rows["ts_us"] < g5_fetch.END_EXCLUSIVE_US
        cov["rows_at_or_after_end_dropped"] += int((~keep).sum())
        rows = rows[keep]
        unconfirmed = rows["confirm"] == 0
        cov["unconfirmed_dropped"][layout] = int(unconfirmed.sum())
        cov["confirm_unknown"][layout] = int((rows["confirm"] < 0).sum())
        return rows[~unconfirmed]

    def place(rows: np.ndarray, column: str) -> np.ndarray:
        out = np.full(grid.size, np.nan)
        if rows.size:
            cov["rows_outside_grid"] += _place(grid, rows["ts_us"].astype(np.int64), rows[column].astype(np.float64), out)
        return out

    last, mark_rows, index_rows = candles("candles_1m", meta.inst_id), candles("mark_1m", meta.inst_id), candles("index_1m", meta.index_id)
    close, low, high, vol = place(last, "close"), place(last, "low"), place(last, "high"), place(last, "vol")
    mark, index_px = place(mark_rows, "close"), place(index_rows, "close")
    funding_dates = _dates_between(spec.pad_start_us - DAY_US, g5_fetch.END_EXCLUSIVE_US)
    frows = sealed.load_days("funding_hist", meta.inst_id, funding_dates) if sealed is not None else tape.empty_rows("funding_hist")
    sealed_manifest = sealed.manifest if sealed is not None else None
    keep = frows["ts_us"] < g5_fetch.END_EXCLUSIVE_US
    cov["rows_at_or_after_end_dropped"] += int((~keep).sum())
    frows = frows[keep]
    ts = frows["ts_us"].astype(np.int64)
    realized = frows["realized_rate"].astype(np.float64)
    states = (manifest or {}).get("g5_streams") or {}
    sealed_states = (sealed_manifest or {}).get("g5_streams") or {}
    fstate = sealed_states.get(f"{meta.inst_id}:funding_hist")
    d_cov, d_cov_source = d_cov_of(ts, fstate)
    if fstate and fstate.get("d_cov_start_us") is not None and d_cov is not None and int(fstate["d_cov_start_us"]) != d_cov:
        cov["d_cov_manifest_mismatch"] = True
    cov.update({"close_minutes": int(np.isfinite(close).sum()), "mark_minutes": int(np.isfinite(mark).sum()), "index_minutes": int(np.isfinite(index_px).sum()),
                "zero_volume_minutes": int((vol == 0).sum()), "funding_rows": int(frows.size), "funding_rows_without_realized": int((~np.isfinite(realized)).sum()),
                "d_cov_start": _iso(d_cov), "d_cov_source": d_cov_source,
                "end_reasons": {"candles_1m": (states.get(f"{meta.inst_id}:candles_1m") or {}).get("end_reason"), "mark_1m": (states.get(f"{meta.inst_id}:mark_1m") or {}).get("end_reason"),
                                "index_1m": (states.get(f"{meta.index_id}:index_1m") or {}).get("end_reason"), "funding_hist": (fstate or {}).get("end_reason")}})
    return make_series(meta, grid, close, low, high, vol, mark, index_px, ts, realized, d_cov, "history", cov)


def minute_bars(grid: np.ndarray, ts_us: np.ndarray, price: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    """(B) xxi — `(close, low, high, prints)` on the grid from the prints: at boundary g, the last, lowest and highest price and the
    number of prints with ts in (g − 60 s ; g] (`price.series`, `frozen_candle.key_candles`); NaN / 0 for a minute without print."""
    n = grid.size
    close, low, high, count = np.full(n, np.nan), np.full(n, np.nan), np.full(n, np.nan), np.zeros(n)
    ts = np.asarray(ts_us, dtype=np.int64)
    px = np.asarray(price, dtype=np.float64)
    ok = np.isfinite(px)
    ts, px = ts[ok], px[ok]
    if ts.size == 0:
        return close, low, high, count
    order = np.argsort(ts, kind="stable")
    ts, px = ts[order], px[order]
    k = ((ts - 1 - int(grid[0])) // MINUTE_US + 1).astype(np.int64)  # boundary g_k with ts in (g_k − 60 s ; g_k]
    inside = (k >= 0) & (k < n)
    k, px = k[inside], px[inside]
    if k.size == 0:
        return close, low, high, count
    uniq, first, cnt = np.unique(k, return_index=True, return_counts=True)
    close[uniq] = px[first + cnt - 1]
    low[uniq] = np.minimum.reduceat(px, first)
    high[uniq] = np.maximum.reduceat(px, first)
    count[uniq] = cnt
    return close, low, high, count


FUNDING_ATTRIBUTION_RULE = (
    "a funding-rate message with settState settled carries in settFundingRate the rate of the previous settlement cycle, its fundingTime being the "
    "upcoming settlement (docs-v5 funding-rate: 'If settState = settled, it is the funding rate that is being used for previous settlement cycle'): the "
    "rate is attributed to the largest fundingTime carried by a message at or before it that is below its own fundingTime and at or before its ts "
    "(none known: unattributed, counted; two different settled values for one settlement: unknown, counted); settState processing: rate ignored, counted; "
    "settlements = the fundingTime values carried by the messages (nextFundingTime is a forecast, read only as the decision-time next settlement of T01)")
NEXT_AT_DECISION_RULE = ("Gate 2 next neighbour h+ of T01 = nextFundingTime of the last funding-rate message at or before t_arm whose fundingTime is h "
                         "(decision-time information, (B) xvii, CTO M1); no such message: unknown neighbour, excluded and counted")


def attribute_settled_rates(rows: np.ndarray) -> dict:
    """(B) xxi, CTO B1 — the settlements of one instrument's `funding` rows (capture of the `funding-rate` channel) and their settled
    rates under `FUNDING_ATTRIBUTION_RULE`: a settled `settFundingRate` belongs to the settlement **before** the message's `fundingTime`
    (the previous cycle), never to `fundingTime` itself. Returns `settlements_us` (the distinct `fundingTime` values, sorted), `rates`
    (aligned, NaN when unknown), the counts, and the messages sorted by ts (`ts_us`, `funding_time_us`, `next_funding_time_us`) for the
    decision-time next settlement (M1)."""
    rows = np.asarray(rows)
    n = int(rows.size)
    order = np.argsort(rows["ts_us"], kind="stable") if n else np.zeros(0, dtype=np.int64)
    ts = rows["ts_us"].astype(np.int64)[order]
    ft = rows["funding_time_us"].astype(np.int64)[order]
    nft = rows["next_funding_time_us"].astype(np.int64)[order]
    state = rows["sett_state"].astype(np.int64)[order]
    sett = rows["sett_funding_rate"].astype(np.float64)[order]
    counts = {"messages": n, "settled": 0, "settled_attributed": 0, "settled_without_rate": 0, "settled_unattributed": 0, "settlements_conflicting": 0,
              "processing_ignored": 0, "state_unknown": 0}
    seen: list[int] = []  # the fundingTime values carried so far, sorted
    known: set[int] = set()
    rates: dict[int, float] = {}
    conflicting: set[int] = set()
    i = 0
    while i < n:
        j = i
        while j < n and ts[j] == ts[i]:
            j += 1
        for k in range(i, j):  # a message at the same ts counts as "at or before"
            f = int(ft[k])
            if f > 0 and f not in known:
                known.add(f)
                bisect.insort(seen, f)
        for k in range(i, j):
            st = int(state[k])
            if st == 0:
                counts["processing_ignored"] += 1
                continue
            if st != 1:
                counts["state_unknown"] += 1
                continue
            counts["settled"] += 1
            value = float(sett[k])
            if not math.isfinite(value) or int(ft[k]) <= 0:
                counts["settled_without_rate"] += 1
                continue
            pos = bisect.bisect_right(seen, min(int(ft[k]) - 1, int(ts[k]))) - 1
            if pos < 0:
                counts["settled_unattributed"] += 1
                continue
            previous = seen[pos]
            counts["settled_attributed"] += 1
            if previous in conflicting:
                continue
            if previous in rates and rates[previous] != value:
                conflicting.add(previous)
                del rates[previous]
                counts["settlements_conflicting"] += 1
                continue
            rates[previous] = value
        i = j
    settlements = np.array(seen, dtype=np.int64)
    rate = np.array([rates.get(int(t), math.nan) for t in seen], dtype=np.float64)
    return {"settlements_us": settlements, "rates": rate, "counts": counts, "messages": (ts, ft, nft), "rule": FUNDING_ATTRIBUTION_RULE}


def load_capture_series(tape_dir: Path, meta: Instrument, spec: Spec, row: dict | None = None, index_dir: Path | None = None) -> Series:
    """One instrument of the capture tape (Gate 2, B xxi): rows before the quarantine floor dropped and counted (and no day file dated
    before the floor opened, CTO m2); minute bars of the last price from the prints; the last captured mark of each minute; the index
    candles `index_1m/<index_id>` (of `index_dir`, default the tape) under P-G5-10 (ii) option b; the settlements and the settled rates
    of the funding channel attributed to the previous settlement (`attribute_settled_rates`, docs-v5, CTO B1) and its messages (the
    decision-time next settlement of T01, CTO M1); its `premium` field (descriptive only); the book, the prints and the unusable
    instrument-days (`quality`)."""
    from . import g5_gate2  # lazy: g5_gate2 imports this module

    grid = spec_grid(spec)
    dates = _dates_between(max(spec.pad_start_us, QUARANTINE_END_US), spec.end_us)  # never a day file dated before the quarantine floor (CTO m2)
    cov: dict = {"minutes": int(grid.size), "index_id": meta.index_id, "rows_before_quarantine_floor_dropped": {}}

    def load(base: Path, layout: str, key: str) -> np.ndarray:
        rows, _ = tape.load_days(base, layout, key, dates, allow_pending=False)
        keep = rows["ts_us"] >= QUARANTINE_END_US  # capture_source_quarantine_floor_check
        cov["rows_before_quarantine_floor_dropped"][layout] = int((~keep).sum())
        return rows[keep]

    def last_per_minute(ts_us: np.ndarray, values: np.ndarray) -> np.ndarray:
        out = np.full(grid.size, np.nan)
        if ts_us.size == 0:
            return out
        order = np.argsort(ts_us, kind="stable")
        ts_us, values = ts_us[order], values[order]
        idx = np.searchsorted(ts_us, grid, side="right") - 1
        valid = idx >= 0
        age = np.where(valid, grid - ts_us[np.where(valid, idx, 0)], np.iinfo(np.int64).max)
        ok = valid & (age < MINUTE_US)
        out[ok] = values[idx[ok]]
        return out

    trades = load(Path(tape_dir), "trades", meta.inst_id)
    marks = load(Path(tape_dir), "mark", meta.inst_id)
    close, low, high, prints_count = minute_bars(grid, trades["ts_us"].astype(np.int64), trades["price"].astype(np.float64))
    mark = last_per_minute(marks["ts_us"].astype(np.int64), marks["mark_px"].astype(np.float64))
    index_px = np.full(grid.size, np.nan)
    choice = spec.gate2["index_choice"]
    index_sources = None
    if choice == "b_history_index_candles_after_window":
        rows = load(Path(index_dir or tape_dir), "index_1m", meta.index_id)
        rows = rows[rows["confirm"] != 0]
        if rows.size:
            _place(grid, rows["ts_us"].astype(np.int64), rows["close"].astype(np.float64), index_px)
    elif choice == g5_gate2.INDEX_CHOICE_D10:  # both sources loaded; the primary is put in place by g5_gate2.apply_index_selection, before any T01
        ticks = load(Path(tape_dir), "index_tick", meta.index_id)
        index_sources = {g5_gate2.INDEX_OPTION_A: g5_gate2.index_minutes_from_ticks(grid, ticks["ts_us"], ticks["idx_px"]),
                         g5_gate2.INDEX_OPTION_B: g5_gate2.index_minutes_from_candles(grid, load(Path(index_dir or tape_dir), "index_1m", meta.index_id))}
        cov["index_minutes_by_source"] = {k: int(np.isfinite(v).sum()) for k, v in index_sources.items()}
    funding = load(Path(tape_dir), "funding", meta.inst_id)
    attribution = attribute_settled_rates(funding)
    ts, rate = attribution["settlements_us"], attribution["rates"]
    states, premium_ts, premium_v = {}, [], []
    for item in funding:
        ft = int(item["funding_time_us"])
        states.setdefault(ft, set()).add(int(item["sett_state"]))
        if math.isfinite(float(item["premium"])):
            premium_ts.append(int(item["ts_us"]))
            premium_v.append(float(item["premium"]))
    books = load(Path(tape_dir), "books5", meta.inst_id)
    quality = spec.gate2["quality"]
    first_day, last_day = int(spec.start_us // DAY_US), int(spec.end_us // DAY_US)
    bad = g5_gate2.unusable_days(books["ts_us"].astype(np.int64), first_day, last_day, quality["coverage_min"], quality["stale_us"])
    row = dict(row or {})
    band = xc._f(row.get("maxPxLmtPct"))
    cov.update({"close_minutes": int(np.isfinite(close).sum()), "minutes_without_print": int((prints_count == 0).sum()), "mark_minutes": int(np.isfinite(mark).sum()),
                "index_minutes": int(np.isfinite(index_px).sum()), "index_choice": choice, "settlements": int(ts.size), "settled": int(np.isfinite(rate).sum()),
                "funding_attribution": {"rule": attribution["rule"], **attribution["counts"]}, "next_at_decision_rule": NEXT_AT_DECISION_RULE,
                "book_rows": int(books.size), "trade_rows": int(trades.size), "unusable_days": sorted(bad), "band_pct": band if math.isfinite(band) else None,
                "sett_states": {str(k): sorted(v) for k, v in states.items() if k > 0}, "dates_read": [dates[0], dates[-1]] if dates else []})
    series = make_series(meta, grid, close, low, high, prints_count, mark, index_px, ts, rate, spec.pad_start_us, "capture", cov)
    series.book = lp_sim.Book.from_rows(books) if books.size else None
    series.prints = lp_sim.Prints.from_rows(trades) if trades.size else None
    series.inst_spec = lp_sim.InstrumentSpec(meta.inst_id, meta.tick_sz, meta.ct_val, meta.lot_sz, meta.min_sz)
    order = np.argsort(np.array(premium_ts, dtype=np.int64), kind="stable")
    series.cache["premium_okx"] = (np.array(premium_ts, dtype=np.int64)[order], np.array(premium_v, dtype=np.float64)[order])
    series.cache["funding_messages"] = attribution["messages"]
    if index_sources is not None:
        series.cache["index_sources"] = index_sources
    return series


# ----------------------------------------------------------------------------- instants: generators and restrictions


def next_settlement_at_decision(series: Series, instants: np.ndarray, arm_min: int) -> np.ndarray:
    """(B) xvii, CTO M1 (Gate 2) — per settlement h, the `nextFundingTime` announced by the last funding-rate message at or before
    t_arm = h + arm_min whose `fundingTime` is h (`NEXT_AT_DECISION_RULE`); −1 when no such message exists."""
    instants = np.asarray(instants, dtype=np.int64)
    out = np.full(instants.size, -1, dtype=np.int64)
    messages = series.cache.get("funding_messages")
    if messages is None or instants.size == 0 or messages[0].size == 0:
        return out
    m_ts, m_ft, m_nft = messages
    idx = np.searchsorted(m_ts, instants + int(arm_min) * MINUTE_US, side="right") - 1
    safe = np.maximum(idx, 0)
    hit = (idx >= 0) & (m_ft[safe] == instants) & (m_nft[safe] > instants)
    out[hit] = m_nft[safe][hit]
    return out


def settlement_instants(series: Series, tspec: TypeSpec, spec: Spec) -> tuple[np.ndarray, dict, np.ndarray]:
    """(B) ii — settlements of the instrument inside the window kept when the gaps to the previous and to the next settlement
    match the filter (8 h ± 1 min), with a known realized rate. Returns the kept instants, the counts and the settlements with
    another (known) interval and a known rate (the descriptive T02 stratum: 4 h, 2 h, 1 h). History: h+ is the next settlement
    served (§5.1); the instants that only the h+ criterion removes are counted (`removed_by_next_neighbour_only`: the h+ gap is
    known after the decision, CTO M1 — the protocol decides at the freeze). Capture: h+ is the decision-time `nextFundingTime`
    (`next_settlement_at_decision`, `NEXT_AT_DECISION_RULE`), never the settlement realised after t_arm."""
    gen = tspec.generator
    every = np.unique(series.settlements_us)
    inside = every[(every >= spec.start_us) & (every < spec.end_us)]
    counts = {"settlements": int(inside.size), "misaligned": int((inside % MINUTE_US != 0).sum())}
    inside = inside[inside % MINUTE_US == 0]
    pos = np.searchsorted(every, inside)
    prev_gap = np.where(pos > 0, inside - every[np.maximum(pos - 1, 0)], -1)
    if series.source == "capture":
        announced = next_settlement_at_decision(series, inside, tspec.arm_min)
        next_gap = np.where(announced > 0, announced - inside, -1)
        counts["next_unknown_at_decision"] = int((announced <= 0).sum())
    else:
        next_gap = np.where(pos + 1 < every.size, every[np.minimum(pos + 1, every.size - 1)] - inside, -1)
    known = (prev_gap >= 0) & (next_gap >= 0)
    prev_ok = (prev_gap >= 0) & (np.abs(prev_gap - gen["previous_gap_us"]) <= gen["tolerance_us"])
    next_ok = (next_gap >= 0) & (np.abs(next_gap - gen["next_gap_us"]) <= gen["tolerance_us"])
    ok = prev_ok & next_ok
    rate = series.rate_at(inside)
    has_rate = np.isfinite(rate)
    counts["unknown_neighbour"] = int((~known).sum())
    counts["interval_other"] = int((known & ~ok).sum())
    counts["realized_rate_missing"] = int((ok & ~has_rate).sum())
    counts["removed_by_next_neighbour_only"] = int((prev_ok & ~next_ok & has_rate).sum())
    kept = inside[ok & has_rate]
    counts["kept"] = int(kept.size)
    other = inside[known & ~ok & has_rate]
    return kept, counts, other


def generator_instants(series: Series, tspec: TypeSpec, spec: Spec, common: dict) -> tuple[np.ndarray, dict, np.ndarray]:
    if tspec.generator["kind"] == "funding_settlements":
        return settlement_instants(series, tspec, spec)
    instants = common["local_time"][tspec.name]
    return instants.copy(), {"generated": int(instants.size)}, np.zeros(0, dtype=np.int64)


def restrict(series: Series, instants: np.ndarray, tspec: TypeSpec, spec: Spec, window: tuple[int, int] | None = None) -> tuple[np.ndarray, dict]:
    """(B) vii, iii — an instrument-instant exists only if its footprint starts at or after the instrument's eligibility and, for a
    funding_coverage type, its instant lies in D_cov = [first settlement whose predecessor is served ; END_EXCLUSIVE) (LP-G5 §4.4:
    the settlement that opens D_cov is an instant of T01 — the audit count of 211 includes it) with its footprint before the end;
    events and controls alike. Capture (B xxi): the whole footprint inside the type's window [T0 ; end) (`window`, default
    `g5_gate2.type_window`), and no footprint touching an unusable instrument-day (`quality`, `series.coverage['unusable_days']`).
    A1 (point 7): history — a footprint ending after the window end (END_EXCLUSIVE) is excluded and counted (`crosses_end_exclusive`),
    a footprint starting before the eligibility is excluded and counted (`eligibility.count_field`), at the events and at the controls."""
    instants = np.asarray(instants, dtype=np.int64)
    start = instants + tspec.footprint[0] * MINUTE_US
    end = instants + tspec.footprint[1] * MINUTE_US
    keep = np.ones(instants.size, dtype=bool)
    counts = {"candidates": int(instants.size)}
    eligible = start >= series.meta.eligible_from_us
    counts[spec.eligibility_count_field] = int((~eligible).sum())
    keep &= eligible
    if spec.source != "capture":
        # A1 (point 7): a footprint that ends after the window end (END_EXCLUSIVE in the Gate 1 spec) would read a candle closing after it;
        # excluded and counted on its own, before the window rules. A footprint ending exactly at the end reads the candle closing at the end
        # (opened at end − 60 s, ts < END_EXCLUSIVE): kept.
        crosses = end > spec.end_us
        counts["crosses_end_exclusive"] = int((keep & crosses).sum())
        keep &= ~crosses
    if tspec.window_rule == "funding_coverage":
        if series.d_cov_start_us is None:
            counts["funding_coverage_unknown"] = int(keep.sum())
            keep[:] = False
        else:
            lo = max(int(series.d_cov_start_us), int(window[0])) if window is not None else int(series.d_cov_start_us)
            inside = instants >= lo
            counts["outside_funding_coverage"] = int((keep & ~inside).sum())
            keep &= inside
    elif spec.source == "capture":
        from . import g5_gate2  # lazy: g5_gate2 imports this module

        lo, hi = window if window is not None else g5_gate2.type_window(spec, tspec)
        inside = (start >= lo) & (end < hi)
        counts["outside_window"] = int((keep & ~inside).sum())
        keep &= inside
        bad = sorted(series.coverage.get("unusable_days") or ())
        if bad:
            touched = np.isin(start // DAY_US, bad) | np.isin(end // DAY_US, bad)
            counts["excluded_unusable_instrument_day"] = int((keep & touched).sum())
            keep &= ~touched
    else:
        inside = (instants >= spec.start_us) & (instants < spec.end_us)
        counts["outside_window"] = int((keep & ~inside).sum())
        keep &= inside
    counts["kept"] = int(keep.sum())
    return instants[keep], counts


def common_instants(spec: Spec) -> dict:
    """Instrument-independent instants: the local-time generators over the padded window (for the events: the spec window)."""
    out = {"local_time": {}, "local_time_padded": {}, "macro_excluded": {}}
    for t in spec.types:
        if t.generator["kind"] == "local_time":
            out["local_time"][t.name] = np.array([m for m, _, _ in local_time_instants(t, spec)], dtype=np.int64)
            out["local_time_padded"][t.name] = np.array([m for m, _, _ in local_time_instants(t, spec, spec.pad_start_us - DAY_US, spec.end_us + DAY_US)], dtype=np.int64)
            out["macro_excluded"][t.name] = np.array([m for m, _, e in local_time_instants(t, spec, apply_exclusions=False) if e], dtype=np.int64)
    return out


# ----------------------------------------------------------------------------- controls (B xi, xii)


def exclusion_intervals(series: Series, tspec: TypeSpec, spec: Spec, common: dict) -> dict:
    """The exclusion union of one type's controls on one instrument, by component: `name → (starts, ends)` in µs."""
    comps = spec.controls_common["components"]
    lo, hi = spec.pad_start_us - DAY_US, spec.end_us + DAY_US
    out: dict = {}
    if "settlements" in comps:
        a, b = comps["settlements"]
        s = np.unique(series.settlements_us)
        out["settlements"] = (s + a * MINUTE_US, s + b * MINUTE_US)
    if "default_grid" in comps:
        a, b = comps["default_grid"]
        days = range(int(lo // DAY_US), int(hi // DAY_US) + 1)
        s = np.array([d * DAY_US + h * HOUR_US for d in days for h in tspec.controls["grid_hours"]], dtype=np.int64)
        out["default_grid"] = (s + a * MINUTE_US, s + b * MINUTE_US)
    if "type_instants" in comps:
        name, a, b, _ = comps["type_instants"]
        s = common["local_time_padded"][name]
        out["type_instants"] = (s + a * MINUTE_US, s + b * MINUTE_US)
    if comps.get("sessions"):
        items = session_intervals(spec, lo, hi)
        out["sessions"] = (np.array([i[0] for i in items], dtype=np.int64), np.array([i[1] for i in items], dtype=np.int64))
    if comps.get("macro"):
        items = macro_intervals(spec, lo, hi)
        out["macro"] = (np.array([i[0] for i in items], dtype=np.int64), np.array([i[1] for i in items], dtype=np.int64))
    return out


def violates(starts: np.ndarray, ends: np.ndarray, a: np.ndarray, b: np.ndarray, margin_us: int) -> np.ndarray:
    """True where the footprint [a, b] is within `margin_us` of some interval [x, y]: x − margin < b and y + margin > a
    (admissible iff b ≤ x − margin or a ≥ y + margin for every interval)."""
    a, b = np.asarray(a, dtype=np.int64), np.asarray(b, dtype=np.int64)
    if np.asarray(starts).size == 0 or a.size == 0:
        return np.zeros(a.size, dtype=bool)
    order = np.argsort(starts, kind="stable")
    xs, ys = np.asarray(starts, dtype=np.int64)[order], np.asarray(ends, dtype=np.int64)[order]
    prefix_max = np.maximum.accumulate(ys)
    idx = np.searchsorted(xs, b + margin_us, side="left")
    out = np.zeros(a.size, dtype=bool)
    some = idx > 0
    out[some] = prefix_max[idx[some] - 1] > a[some] - margin_us
    return out


def control_instants(series: Series, tspec: TypeSpec, spec: Spec, raw_events: np.ndarray, union: dict, window: tuple[int, int] | None = None) -> tuple[np.ndarray, dict]:
    """(B) xi — every hour of the instrument's event days at the type's minute of the hour, kept when inside the window, eligible
    (and inside D_cov for a funding_coverage type) and admissible against every footprint of the exclusion union."""
    days = np.unique(np.asarray(raw_events, dtype=np.int64) // DAY_US)
    minute = tspec.controls["minute_of_hour"] * MINUTE_US
    cands = np.array([d * DAY_US + h * HOUR_US + minute for d in days.tolist() for h in range(24)], dtype=np.int64)
    diag: dict = {"days": int(days.size), "candidates": int(cands.size), "minute_of_hour": tspec.controls["minute_of_hour"], "margin_min": spec.controls_common["margin_min"]}
    cands = cands[(cands >= spec.start_us) & (cands < spec.end_us)]
    a = cands + tspec.footprint[0] * MINUTE_US
    b = cands + tspec.footprint[1] * MINUTE_US
    margin = spec.controls_common["margin_us"]
    bad = np.zeros(cands.size, dtype=bool)
    excluded_by = {}
    for name, (xs, ys) in union.items():
        hit = violates(xs, ys, a, b, margin)
        excluded_by[name] = int(hit.sum())
        bad |= hit
    diag["excluded_by_component"] = excluded_by
    diag["excluded_union"] = int(bad.sum())
    admitted, rcounts = restrict(series, cands[~bad], tspec, spec, window)
    diag["restriction"] = rcounts
    diag["kept"] = int(admitted.size)
    return np.unique(admitted), diag


# ----------------------------------------------------------------------------- measures (B i, iv, vi, viii, xiii, xiv)

SIM_STATUS = ("filled", "unfilled", "stale", "crossed", "skipped_size", "funding_unknown", "no_exit_quote", "unavailable")


def classify(values: np.ndarray, high: float, low: float, strict: bool = False) -> np.ndarray:
    """+1 at or above `high`, −1 at or below `low`, 0 in between or unknown; `strict`: above / below (the dead-zone variant of §9.4,
    « P̂ > +0,06 % ou < −0,05 % »)."""
    values = np.asarray(values, dtype=np.float64)
    out = np.zeros(values.size, dtype=np.int8)
    finite = np.isfinite(values)
    out[finite & ((values > high) if strict else (values >= high))] = 1
    out[finite & ((values < low) if strict else (values <= low))] = -1
    return out


def _log_bp(a: np.ndarray, b: np.ndarray) -> np.ndarray:
    with np.errstate(divide="ignore", invalid="ignore"):
        return (np.log(b) - np.log(a)) * 1e4


def measure(series: Series, instants_us, tspec: TypeSpec, spec: Spec, role: str = "event", options: dict | None = None) -> dict:
    """Per instrument-instant measures of one type (arrays aligned on the kept instants) and the exclusion counts, each excluded
    instant counted in the first rule that excludes it: out of grid → (capture: key minute without print) → missing key price →
    zero-volume key candle → classifier (not computable, inside the zone) or sign (zero) → fill proxy (window missing, unfilled) →
    unknown rate crossed. The same rules at the events and at the controls. Capture (B xxi): after the classifier / sign, the
    entry and exit are simulated on the captured book (`measure_lp_sim`); `options['construction'] = 'gate1'` gives instead the
    Gate 1 construction on the minute bars of the prints with the design costs (descriptive). `options` (descriptive variants only):
    `thresholds` (hi, lo) and `strict` of the classifier, `fill` (False: no fill proxy), `side_from_rate` (hi, lo): events signed by
    the class of R_h, `price` ('mark'), `exit_min`, `tick_move` (T05: |c(h+5) − c(h)| ≥ 1 tick), `sim_rule` (capture), `fill_status` (A1:
    with `fill` False, the proxy's verdict is recorded in `fill_flag` without excluding). Costs (A1): `type_cost_terms` — the type's
    entry-leg rule when the measure is conditioned on the fill proxy, the design value otherwise."""
    options = options or {}
    capture = spec.source == "capture"
    construction = "lp_sim" if capture and options.get("construction") != "gate1" else "candles"
    instants = np.unique(np.asarray(instants_us, dtype=np.int64))
    n = series.size
    vol_field = spec.frozen_candle["count_field"]
    counts = {key: 0 for key in ("instants", "out_of_grid", "missing_key_price", vol_field, "classifier_not_computable", "classifier_inside_zone",
                                 "zero_sign", "move_below_one_tick", "fill_window_missing", "unfilled", "funding_rate_missing", "kept")}
    counts["instants"] = int(instants.size)
    k0 = series.index_of(instants)
    exit_min = int(options.get("exit_min", tspec.return_window[1]))
    entry_min = tspec.return_window[0]
    alive = (k0 + min(tspec.footprint[0], entry_min) >= 0) & (k0 + max(tspec.footprint[1], exit_min) < n)
    counts["out_of_grid"] = int((~alive).sum())
    prices = series.mark if options.get("price") == "mark" else series.close
    offsets = sorted(set(tspec.key_price_offsets) | {exit_min})
    if capture:  # (B) xxi key_minute_without_print_exclusion: the capture analogue of vol = 0, before the missing-price rule
        silent = np.zeros(instants.size, dtype=bool)
        for off in sorted(set(tspec.key_candles) | {exit_min}):
            silent |= ~(series.at(series.vol, k0 + off) > 0)
        counts[vol_field] = int((alive & silent).sum())
        alive &= ~silent
    present = np.ones(instants.size, dtype=bool)
    for off in offsets:
        present &= np.isfinite(series.at(prices, k0 + off))
    counts["missing_key_price"] = int((alive & ~present).sum())
    alive &= present
    if spec.frozen_candle["applies"] and not capture:
        zero = np.zeros(instants.size, dtype=bool)
        for off in tspec.key_candles:
            zero |= series.at(series.vol, k0 + off) == 0
        counts[vol_field] = int((alive & zero).sum())
        alive &= ~zero
    p_hat = np.full(instants.size, np.nan)
    side = np.zeros(instants.size, dtype=np.int8)
    if tspec.classifier is not None:
        hi, lo = options.get("thresholds") or (tspec.classifier["high"], tspec.classifier["low"])
        p_hat = series.p_hat(k0 + tspec.arm_min, tspec.classifier)  # (B) iv: at t_arm, events and controls alike
        computable = np.isfinite(p_hat)
        counts["classifier_not_computable"] = int((alive & ~computable).sum())
        alive &= computable
        side = classify(p_hat, hi, lo, strict=bool(options.get("strict")))
        if role == "event" and options.get("side_from_rate") is not None:
            r_hi, r_lo = options["side_from_rate"]
            side = classify(series.rate_at(instants), r_hi, r_lo)
        counts["classifier_inside_zone"] = int((alive & (side == 0)).sum())
        alive &= side != 0
    else:
        a, b = series.at(series.close, k0 + tspec.sign["from_min"]), series.at(series.close, k0 + tspec.sign["to_min"])
        move = _log_bp(a, b)
        side = np.sign(np.nan_to_num(move, nan=0.0)).astype(np.int8)
        counts["zero_sign"] = int((alive & (side == 0)).sum())
        alive &= side != 0
        if options.get("tick_move"):
            big = np.abs(b - a) >= series.meta.tick_sz * (1.0 - 1e-6)
            counts["move_below_one_tick"] = int((alive & ~big).sum())
            alive &= big
    if construction == "lp_sim":
        return _measure_sim(series, instants, alive, side, p_hat, counts, tspec, spec, options)
    fill_attempted = int(alive.sum())
    proxy = tspec.fill_proxy if tspec.fill_proxy is not None else ((tspec.gate2 or {}).get("construction_proxy") if capture else None)
    use_fill = proxy is not None and options.get("fill", True)
    # A1: `fill_status` (with the proxy off) records, without excluding, whether the proxy would fill each instrument-instant:
    # 1 filled, 0 unfilled, -1 window missing or no proxy (the filled / unfilled analysis of the signal A).
    fill_flag = np.full(instants.size, -1, dtype=np.int8)
    if proxy is not None and (use_fill or options.get("fill_status")):
        ks = [k0 + j + 1 for j in range(proxy["open_from_min"], proxy["open_to_min"] + 1)]  # candles opening at h+j end at h+j+1
        lows = np.vstack([series.at(series.low, k) for k in ks])
        highs = np.vstack([series.at(series.high, k) for k in ks])
        known = np.all(np.isfinite(lows), axis=0) & np.all(np.isfinite(highs), axis=0)
        limit = series.at(series.close, k0 + tspec.arm_min)
        eps = series.meta.tick_sz * 1e-6
        with np.errstate(invalid="ignore"):
            long_fill = np.nanmin(np.where(np.isfinite(lows), lows, np.inf), axis=0) < limit - series.meta.tick_sz - eps
            short_fill = np.nanmax(np.where(np.isfinite(highs), highs, -np.inf), axis=0) > limit + series.meta.tick_sz + eps
        filled = ((side > 0) & long_fill) | ((side < 0) & short_fill)
        fill_flag = np.where(known, np.where(filled, 1, 0), -1).astype(np.int8)
        if use_fill:
            counts["fill_window_missing"] = int((alive & ~known).sum())
            alive &= known
            counts["unfilled"] = int((alive & ~filled).sum())
            alive &= filled
    open_us = instants + entry_min * MINUTE_US
    close_us = instants + exit_min * MINUTE_US
    f_count, f_sum, f_unknown = series.funding_crossed(open_us, close_us)  # every settlement crossed (reported; controls: anomalies)
    if series.d_cov_start_us is None:  # coverage unknown: every window outside, its funding assumed zero and counted
        outside = np.ones(instants.size, dtype=bool)
        f_sum, f_unknown = np.zeros(instants.size), np.zeros(instants.size, dtype=np.int64)
    else:
        # §11.1 (fixed 2026-10-21): only the settlements T >= D_cov are covered and they are always counted (known rate paid, unknown rate
        # excluded); a window is `outside` (funding of its uncovered part assumed zero, counted) only when it may cross a settlement before
        # D_cov, i.e. when it opens before the served predecessor of D_cov (no settlement lies between them). Before the fix the T01 instant
        # h = D_cov, whose window opens at h − 5 < D_cov, was `outside` and its own covered settlement h was assumed zero.
        d_cov = int(series.d_cov_start_us)
        _, f_sum, f_unknown = series.funding_crossed(np.minimum(np.maximum(open_us, d_cov - 1), close_us), close_us)  # covered part (max(open, D_cov − 1 µs) ; close]
        outside = open_us < covered_from_us(series)
    missing_rate = f_unknown > 0
    counts["funding_rate_missing"] = int((alive & missing_rate).sum())
    alive &= ~missing_rate
    counts["kept"] = int(alive.sum())
    keep = alive
    s = side[keep].astype(np.float64)
    kk = k0[keep]
    r_unsigned = _log_bp(series.at(prices, kk + entry_min), series.at(prices, kk + exit_min))
    funding_bp = -s * f_sum[keep] * 1e4  # covered settlements only; the uncovered part of an `outside` window is assumed zero (counted)
    costs = type_cost_terms(tspec, spec.costs, conditioned_on_fill=use_fill)  # A1: the entry leg is measured only by a fill-conditioned measure
    r_bp = s * r_unsigned
    net = np.array([math.fsum([a, b, -costs["total_bp"]]) for a, b in zip(r_bp.tolist(), funding_bp.tolist())], dtype=np.float64)
    stress = np.array([math.fsum([a, b, -costs["stress_total_bp"]]) for a, b in zip(r_bp.tolist(), funding_bp.tolist())], dtype=np.float64)
    out = {"inst": series.inst, "instants_us": instants[keep], "sign": side[keep], "r_bp": r_bp, "funding_bp": funding_bp, "net_bp": net, "net_stress_bp": stress,
           "p_hat": p_hat[keep], "rate_at_h": series.rate_at(instants[keep]), "counts": counts, "costs": costs,
           "fill": {"attempted": fill_attempted if use_fill else 0, "filled": counts["kept"] + counts["funding_rate_missing"] if use_fill else 0},
           "funding_settlements_crossed": f_count[keep], "funding_outside_coverage": int(outside[keep].sum()), "funding_nonzero": int((f_count[keep] > 0).sum()),
           "execution": "candles", "fill_flag": fill_flag[keep], "conditioned_on_fill": bool(use_fill)}
    if not capture:
        out["counts"][spec.costs["funding_count_field"]] = int(outside[keep].sum())
    return out


def _measure_sim(series: Series, instants: np.ndarray, alive: np.ndarray, side: np.ndarray, p_hat: np.ndarray, counts: dict, tspec: TypeSpec, spec: Spec,
                 options: dict) -> dict:
    """The capture branch of `measure`: the instants that pass the classifier / sign rules are simulated; an unfilled order, a stale
    or crossed book, a size of 0, an exit without quote or an unknown settled rate excludes the observation (counted by status)."""
    idx = np.nonzero(alive)[0]
    sim, status = measure_lp_sim(series, instants[idx], side[idx], tspec, spec, options)
    filled = np.isfinite(sim["sim_net_bp"])
    keep = idx[filled]
    counts["unfilled"] = int(idx.size) - int(filled.sum())
    counts["kept"] = int(filled.sum())
    counts[spec.costs["funding_count_field"]] = int(status["funding_unknown"])
    out = {"inst": series.inst, "instants_us": instants[keep], "sign": side[keep], "r_bp": sim["sim_rt_bp"][filled], "funding_bp": sim["sim_funding_bp"][filled],
           "net_bp": sim["sim_net_bp"][filled], "net_stress_bp": sim["sim_net_bp_stress"][filled], "p_hat": p_hat[keep], "rate_at_h": series.rate_at(instants[keep]),
           "counts": counts, "costs": type_cost_terms(tspec, spec.costs), "fill": {"attempted": int(idx.size), "filled": int(status["filled"])},
           "funding_settlements_crossed": sim["sim_funding_count"][filled], "funding_outside_coverage": 0, "funding_nonzero": int((sim["sim_funding_count"][filled] > 0).sum()),
           "execution": "lp_sim", "sim_status": status, "sim_rule": options.get("sim_rule", spec.gate2["sim"]["rule"]),
           "sim": {key: sim[key][filled] for key in ("sim_fee_bp", "sim_slippage_bp", "sim_maker_legs", "sim_markout_0_bp", "sim_markout_60_bp", "sim_markout_300_bp", "sim_half_spread_bp")}}
    return out


def measure_lp_sim(series: Series, instants: np.ndarray, signs: np.ndarray, tspec: TypeSpec, spec: Spec, options: dict | None = None) -> tuple[dict, dict]:
    """(B) xxi — Gate 2, on the captured book and prints (`lp_sim` primitives, `execution_sim`): decision at d = h + arm_min (a book older
    than `stale_s` at d: `stale`); `maker_maker`: post-only at the own touch of the decision snapshot, rounded to the tick, live after the
    latency (cancelled when it would cross the opposite touch then: `crossed`), resting until h + rest_until_min under the fill rule
    (`gate2_sim_maker_post_only_touch`); `taker_maker`: the opposite touch after the latency plus the slippage per taker leg
    (`gate2_sim_taker_entry_opposite_touch_slippage`); exit: passive at the opposite touch from max(h + exit offset, fill + latency)
    until h + exit_until_min, the remainder taker with its slippage (`gate2_sim_passive_exit_taker_remainder`); funding of the
    settlements in (first fill − grace, exit] (`gate2_funding_fill_grace_crossing`), unknown rate ⇒ `funding_unknown`; a book ending before
    h + exit_until_min, or an exit quote (at the passive start, or at h + exit_until_min for the taker remainder) older than `stale_s` ⇒
    `no_exit_quote` (never a shortened or stale exit, CTO m1); the stressed net
    multiplies fees and slippage and subtracts (m − 1) × the design value per filled maker leg and per taker entry
    (`gate2_stress_add_on_for_measured_terms`); the band rule (`maxPxLmtPct` against the mark at d) is counted, not modelled. NaN where
    not filled. `options`: `sim_rule` (descriptive fill rules), `exit_min` (descriptive horizons: the exit tail keeps its length)."""
    options = options or {}
    n_obs = int(np.asarray(instants).size)
    keys = ("sim_net_bp", "sim_net_bp_stress", "sim_rt_bp", "sim_fee_bp", "sim_slippage_bp", "sim_funding_bp", "sim_funding_count", "sim_maker_legs", "sim_markout_0_bp",
            "sim_markout_60_bp", "sim_markout_300_bp", "sim_half_spread_bp")
    out = {k: np.full(n_obs, np.nan) for k in keys}
    status = {key: 0 for key in SIM_STATUS}
    status["band_rejected"] = 0
    book, prints, ispec = series.book, series.prints, series.inst_spec
    if book is None or prints is None or ispec is None or book.size == 0 or spec.gate2 is None:
        status["unavailable"] = n_obs
        return out, status
    sim, costs = spec.gate2["sim"], spec.costs
    rule = options.get("sim_rule", sim["rule"])
    latency = int(round(sim["latency_ms"] * 1000))
    stale = int(round(sim["stale_s"] * tape.SECOND_US))
    grace = int(round(sim["funding_fill_grace_min"] * MINUTE_US))
    tol = ispec.tick * 1e-6
    slip = costs["slippage_bp_per_taker_leg"]
    maker_bp, taker_bp, mult = costs["maker_fee_bp"], costs["taker_fee_bp"], costs["stress_multiplier_all_cost_terms"]
    add_maker, add_taker = costs["stress_add_bp_per_maker_leg"], costs["stress_add_bp_per_taker_entry"]
    notional = sim["notional"]
    band = series.coverage.get("band_pct")
    last_ts = int(book.ts[-1])
    exit_min = int(options.get("exit_min", tspec.return_window[1]))
    until_min = exit_min + (int(tspec.gate2["exit_until_min"]) - tspec.return_window[1])
    mid = book.mid

    def mid_at(ts_us: int, strict: bool = False) -> float:
        k = book.index_at(ts_us, strict=strict)
        return float(mid[k]) if k >= 0 else math.nan

    for i in range(n_obs):
        h, s = int(instants[i]), int(signs[i])
        d = h + tspec.arm_min * MINUTE_US
        a = d + latency
        k = book.index_at(d)
        if k < 0 or d - int(book.ts[k]) > stale:
            status["stale"] += 1
            continue
        bid, ask = float(book.bid[k]), float(book.ask[k])
        if not (math.isfinite(bid) and math.isfinite(ask) and 0 < bid < ask):
            status["stale"] += 1
            continue
        ka = book.index_at(a)
        maker_legs, taker_entries = 0.0, 0.0
        if tspec.role == "maker_maker":
            price = round((bid if s > 0 else ask) / ispec.tick) * ispec.tick
            size = ispec.size_for_notional(notional, price)
            if size <= 0:
                status["skipped_size"] += 1
                continue
            if (s > 0 and price >= float(book.ask[ka]) - tol) or (s < 0 and price <= float(book.bid[ka]) + tol):
                status["crossed"] += 1
                continue
            end = h + int(tspec.rest_until_min) * MINUTE_US
            first_fill, fill_ts, filled = lp_sim._fill(book, prints, s, price, size, a, end, book.displayed(s, price, ka, tol), rule, tol)
            if filled <= 0:
                status["unfilled"] += 1
                continue
            tau = fill_ts if fill_ts >= 0 else end
            entry_px, entry_fee, entry_slip, maker_legs = price, maker_bp, 0.0, 1.0
            m0 = s * (mid_at(first_fill, strict=True) - price) / price * 1e4
            out["sim_markout_0_bp"][i] = m0
            out["sim_markout_60_bp"][i] = s * (mid_at(first_fill + 60 * tape.SECOND_US) - price) / price * 1e4
            out["sim_markout_300_bp"][i] = s * (mid_at(first_fill + 300 * tape.SECOND_US) - price) / price * 1e4
        else:
            price = float(book.ask[ka]) if s > 0 else float(book.bid[ka])
            size = ispec.size_for_notional(notional, price)
            if size <= 0:
                status["skipped_size"] += 1
                continue
            first_fill, tau, filled = a, a, size
            entry_px, entry_fee, entry_slip, taker_entries = price, taker_bp, slip, 1.0
            out["sim_half_spread_bp"][i] = s * (price - float(mid[ka])) / float(mid[ka]) * 1e4
        mark_d = series.at(series.mark, series.index_of(np.array([d])))[0]
        if band is not None and math.isfinite(mark_d) and abs(entry_px / mark_d - 1.0) > band:
            status["band_rejected"] += 1  # counted (maxPxLmtPct), not modelled
        ex_end = h + until_min * MINUTE_US
        if last_ts < ex_end:  # the book stops before the planned end of the exit: never a shortened holding (CTO m1)
            status["no_exit_quote"] += 1
            continue
        ex_start = min(max(tau + latency, h + exit_min * MINUTE_US), ex_end)
        ke = book.index_at(ex_start)
        if ke < 0 or ex_start - int(book.ts[ke]) > stale:  # an exit quote older than stale_s (CTO m1)
            status["no_exit_quote"] += 1
            continue
        exit_price = round(((float(book.ask[ke])) if s > 0 else float(book.bid[ke])) / ispec.tick) * ispec.tick
        _, ex_fill_ts, ex_filled = lp_sim._fill(book, prints, -s, exit_price, filled, ex_start, ex_end, book.displayed(-s, exit_price, ke, tol), rule, tol)
        ex_filled = max(0.0, min(float(ex_filled), float(filled)))
        if ex_fill_ts >= 0:
            exit_ts, exit_px, exit_fee, exit_slip = int(ex_fill_ts), exit_price, maker_bp, 0.0
            maker_legs += 1.0
        else:
            kl = book.index_at(ex_end)
            if kl < 0 or ex_end - int(book.ts[kl]) > stale:  # the taker remainder needs a fresh quote at h + exit_until_min (CTO m1)
                status["no_exit_quote"] += 1
                continue
            taker_px = float(book.bid[kl]) if s > 0 else float(book.ask[kl])
            rest = float(filled) - ex_filled
            exit_px = (ex_filled * exit_price + rest * taker_px) / float(filled)
            exit_fee = (ex_filled * maker_bp + rest * taker_bp) / float(filled)
            exit_slip = slip * rest / float(filled)
            maker_legs += ex_filled / float(filled)
            exit_ts = ex_end
        count, rate_sum, unknown = series.funding_crossed(np.array([int(first_fill) - grace]), np.array([int(exit_ts)]))
        if unknown[0] > 0:
            status["funding_unknown"] += 1
            continue
        funding_bp = -s * float(rate_sum[0]) * 1e4
        rt = s * (exit_px - entry_px) / entry_px * 1e4
        fees, slip_cost = entry_fee + exit_fee, entry_slip + exit_slip
        out["sim_rt_bp"][i], out["sim_fee_bp"][i], out["sim_slippage_bp"][i], out["sim_funding_bp"][i] = rt, fees, slip_cost, funding_bp
        out["sim_funding_count"][i], out["sim_maker_legs"][i] = float(count[0]), maker_legs
        out["sim_net_bp"][i] = math.fsum([rt, -fees, -slip_cost, funding_bp])
        out["sim_net_bp_stress"][i] = math.fsum([rt, -mult * fees, -mult * slip_cost, funding_bp, -add_maker * maker_legs, -add_taker * taker_entries])
        status["filled"] += 1
    return out, status


# ----------------------------------------------------------------------------- statistics (B ix, xv, xvi, xx)


def _group_fsum(keys: np.ndarray, values: np.ndarray, universe: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    """Per key of `universe` (sorted): fsum of the values and their count."""
    sums, counts = np.zeros(universe.size), np.zeros(universe.size)
    if keys.size == 0:
        return sums, counts
    order = np.argsort(keys, kind="stable")
    keys, values = keys[order], values[order]
    uniq, first, cnt = np.unique(keys, return_index=True, return_counts=True)
    pos = np.searchsorted(universe, uniq)
    for p, f, c in zip(pos.tolist(), first.tolist(), cnt.tolist()):
        sums[p] = math.fsum(values[f: f + c].tolist())
        counts[p] = c
    return sums, counts


def day_block_bootstrap(ev_values, ev_days, ct_values=None, ct_days=None, resamples: int = 1000, seed: int = 20261102, block_days: int = 1, lb_q: float = 0.10,
                        ub_q: float = 0.90, blocks=None) -> dict:
    """Mean of the events minus the mean of the controls (when given), resampling blocks of `block_days` UTC days with replacement —
    the events and the controls of a block move together (LP-G5 §7.3). `blocks` fixes the block universe (C5: the same draws after
    trimming). Returns the point, LB / UB at the spec's quantiles and the one-sided p = P*(D* ≤ 0); no statistic below two blocks."""
    ev_values = np.asarray(ev_values, dtype=np.float64)
    ev_blocks = np.asarray(ev_days, dtype=np.int64) // max(1, int(block_days))
    keep = np.isfinite(ev_values)
    ev_values, ev_blocks = ev_values[keep], ev_blocks[keep]
    with_control = ct_values is not None and ct_days is not None
    if with_control:
        ct_values = np.asarray(ct_values, dtype=np.float64)
        ct_blocks = np.asarray(ct_days, dtype=np.int64) // max(1, int(block_days))
        keep = np.isfinite(ct_values)
        ct_values, ct_blocks = ct_values[keep], ct_blocks[keep]
    out = {"n": int(ev_values.size), "n_control": int(ct_values.size) if with_control else None, "point": None, "event_mean": None, "control_mean": None, "lb90": None,
           "ub90": None, "p_one_sided": None, "blocks": 0, "resamples": int(resamples), "block_days": int(block_days), "seed": int(seed)}
    if ev_values.size == 0 or (with_control and ct_values.size == 0):
        return out
    out["event_mean"] = math.fsum(ev_values.tolist()) / ev_values.size
    out["control_mean"] = math.fsum(ct_values.tolist()) / ct_values.size if with_control else None
    out["point"] = out["event_mean"] - (out["control_mean"] if with_control else 0.0)
    if blocks is None:
        universe = np.unique(np.concatenate([ev_blocks, ct_blocks]) if with_control else ev_blocks)
    else:
        universe = np.unique(np.asarray(blocks, dtype=np.int64) // max(1, int(block_days)))
    out["blocks"] = int(universe.size)
    if universe.size < 2:
        return out
    ev_sum, ev_cnt = _group_fsum(ev_blocks, ev_values, universe)
    rng = np.random.default_rng(int(seed))
    draws = rng.integers(0, universe.size, size=(int(resamples), universe.size))
    picked = np.zeros((int(resamples), universe.size))
    rows = np.arange(int(resamples))
    for j in range(universe.size):
        picked[rows, draws[:, j]] += 1.0
    e_cnt = picked @ ev_cnt
    with np.errstate(divide="ignore", invalid="ignore"):
        stats = (picked @ ev_sum) / e_cnt
        if with_control:
            ct_sum, ct_cnt = _group_fsum(ct_blocks, ct_values, universe)
            c_cnt = picked @ ct_cnt
            stats = stats - (picked @ ct_sum) / c_cnt
            stats[c_cnt <= 0] = np.nan
    stats[e_cnt <= 0] = np.nan
    stats = stats[np.isfinite(stats)]
    if stats.size == 0:
        return out
    out["lb90"] = float(np.quantile(stats, lb_q))
    out["ub90"] = float(np.quantile(stats, ub_q))
    out["p_one_sided"] = float(np.mean(stats <= 0.0))
    out["resamples_effective"] = int(stats.size)
    return out


def holm(pvalues: dict, alpha: float) -> dict:
    """Step-down Holm at `alpha` over one-sided p-values; a missing p counts as 1. The walk stops at the first failure."""
    items = sorted(((1.0 if p is None or not math.isfinite(p) else float(p)), name) for name, p in pvalues.items())
    m = len(items)
    out: dict = {"alpha": alpha, "m": m, "tests": {}, "confirmed": []}
    failed = False
    for j, (p, name) in enumerate(items, start=1):
        threshold = alpha / (m - j + 1)
        passed = (not failed) and p <= threshold
        failed = failed or not passed
        out["tests"][name] = {"p": p, "rank": j, "threshold": threshold, "confirmed": passed}
        if passed:
            out["confirmed"].append(name)
    return out


def instant_means(parts: list[dict], key: str, min_instruments: int = 1) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """(B) ix — `(instants, values, instruments)`: per unique instant, the fsum mean over the instruments retained there (equal
    weights); instants with fewer than `min_instruments` dropped."""
    ts = [np.asarray(p["instants_us"], dtype=np.int64) for p in parts if np.asarray(p["instants_us"]).size]
    vals = [np.asarray(p[key], dtype=np.float64) for p in parts if np.asarray(p["instants_us"]).size]
    if not ts:
        return np.zeros(0, dtype=np.int64), np.zeros(0), np.zeros(0, dtype=np.int64)
    ts_all, v_all = np.concatenate(ts), np.concatenate(vals)
    ok = np.isfinite(v_all)
    ts_all, v_all = ts_all[ok], v_all[ok]
    order = np.argsort(ts_all, kind="stable")
    ts_all, v_all = ts_all[order], v_all[order]
    uniq, first, counts = np.unique(ts_all, return_index=True, return_counts=True)
    values = np.array([math.fsum(v_all[f: f + c].tolist()) / c for f, c in zip(first.tolist(), counts.tolist())], dtype=np.float64)
    keep = counts >= int(min_instruments)
    return uniq[keep], values[keep], counts[keep].astype(np.int64)


def boot(spec: Spec, ev_ts, ev_v, ct_ts=None, ct_v=None, block_days: int | None = None, blocks=None) -> dict:
    st = spec.statistics
    return day_block_bootstrap(ev_v, np.asarray(ev_ts, dtype=np.int64) // DAY_US, ct_v, None if ct_ts is None else np.asarray(ct_ts, dtype=np.int64) // DAY_US,
                               st["resamples"], st["seed"], block_days or st["block_days"], st["lb_quantile"], st["ub_quantile"], blocks)


def _point(ev_v, ct_v) -> float | None:
    e, c = _fmean(ev_v), _fmean(ct_v)
    return None if e is None or c is None else e - c


def validation_c6(series_by_inst: dict, raw_by_inst: dict, tspec: TypeSpec, spec: Spec) -> dict:
    """(B) v — on the raw instrument-settlements (eligible, interval filter, realized rate, classifier computable): the 3 × 3 matrix
    class of P̂(t_arm) × class of R_h, the sensitivity, the false-positive rate, their denominators; always reported."""
    v, c = tspec.validation, tspec.classifier
    matrix = {f"{p}|{r}": 0 for p in (-1, 0, 1) for r in (-1, 0, 1)}
    not_computable = 0
    for inst, s in series_by_inst.items():
        raw = raw_by_inst.get(inst, np.zeros(0, dtype=np.int64))
        if raw.size == 0:
            continue
        values = s.p_hat(s.index_of(raw) + tspec.arm_min, c)
        rates = s.rate_at(raw)
        ok = np.isfinite(values) & np.isfinite(rates)
        not_computable += int((~ok).sum())
        pc, rc = classify(values[ok], c["high"], c["low"]), classify(rates[ok], v["rate_high"], v["rate_low"])
        for p_, r_ in zip(pc.tolist(), rc.tolist()):
            matrix[f"{p_}|{r_}"] += 1
    outside = sum(n for k, n in matrix.items() if not k.endswith("|0"))
    inside = sum(n for k, n in matrix.items() if k.endswith("|0"))
    agree = matrix["1|1"] + matrix["-1|-1"]
    false_pos = matrix["1|0"] + matrix["-1|0"]
    sens = agree / outside if outside else None
    fpr = false_pos / inside if inside else None
    computable = outside >= v["min_denominator"] and inside >= v["min_denominator"]
    passed = bool(computable and sens is not None and fpr is not None and sens >= v["sensitivity_min"] and fpr <= v["false_positive_rate_max"])
    return {"matrix_classifier_class|rate_class": matrix, "population": int(outside + inside), "not_computable": not_computable, "sensitivity": sens,
            "false_positive_rate": fpr, "denominator_rate_outside_zone": outside, "denominator_rate_inside_zone": inside, "min_denominator": v["min_denominator"],
            "sensitivity_min": v["sensitivity_min"], "false_positive_rate_max": v["false_positive_rate_max"], "computable": bool(computable), "pass": passed,
            "thresholds": {"classifier": [c["high"], c["low"]], "rate": [v["rate_high"], v["rate_low"]]}}


def criteria_for(spec: Spec, tspec: TypeSpec, inst: dict, validation: dict | None) -> tuple[dict, dict]:
    """C1–C7 of LP-G5 §7.5 at the instant level. `inst` holds the instant-level arrays `ev_ts`, `ev`, `ev_stress`, `ct_ts`, `ct`."""
    st = spec.statistics
    ev_ts, ev_v, ct_ts, ct_v = inst["ev_ts"], inst["ev"], inst["ct_ts"], inst["ct"]
    diff = boot(spec, ev_ts, ev_v, ct_ts, ct_v)
    out: dict = {}
    point, lb = diff.get("point"), diff.get("lb90")
    out["C1"] = {"pass": bool(point is not None and lb is not None and point >= st["threshold_bp"] and lb > 0), "point": point, "lb90": lb, "ub90": diff.get("ub90"),
                 "threshold_bp": st["threshold_bp"], "p_one_sided": diff.get("p_one_sided")}
    mean_net, mean_stress = _fmean(ev_v), _fmean(inst["ev_stress"])
    out["C2"] = {"pass": bool(mean_net is not None and mean_net > 0), "event_net_mean": mean_net}
    out["C3"] = {"pass": bool(mean_stress is not None and mean_stress > 0), "event_net_stress_mean": mean_stress, "multiplier": spec.costs["stress_multiplier_all_cost_terms"]}
    days = np.unique(ev_ts // DAY_US)
    halves: dict = {}
    if days.size:
        cut = int(days[(days.size - 1) // 2])  # lower median day: first half = days <= it
        first_days, second_days = set(days[days <= cut].tolist()), set(days[days > cut].tolist())
        for label, chosen in (("first_half", first_days), ("second_half", second_days)):
            e_sel = np.isin(ev_ts // DAY_US, list(chosen))
            c_sel = np.isin(ct_ts // DAY_US, list(chosen))
            halves[label] = {"days": len(chosen), "n": int(e_sel.sum()), "n_control": int(c_sel.sum()), "point": _point(ev_v[e_sel], ct_v[c_sel])}
        halves["cut_day"] = tape.date_of_day(cut)
    out["C4"] = {"pass": bool(len(halves) == 3 and all(halves[h]["point"] is not None and halves[h]["point"] > 0 for h in ("first_half", "second_half"))), "halves": halves}
    trimmed = None
    if ev_v.size:
        drop = int(math.ceil(spec.verdict["c5_trim_share"] * ev_v.size))
        order = np.argsort(ev_v, kind="stable")
        keep = np.sort(order[: max(0, ev_v.size - drop)])
        blocks = np.unique(np.concatenate([ev_ts // DAY_US, ct_ts // DAY_US]))
        trimmed = boot(spec, ev_ts[keep], ev_v[keep], ct_ts, ct_v, blocks=blocks)
        trimmed["dropped_best"] = drop
    out["C5"] = {"pass": bool(trimmed is not None and trimmed.get("lb90") is not None and trimmed["lb90"] > 0), "lb90_without_best": trimmed.get("lb90") if trimmed else None,
                 "dropped_best": trimmed.get("dropped_best") if trimmed else 0, "trim_share": spec.verdict["c5_trim_share"]}
    if "C6" in tspec.criteria:
        out["C6"] = {"pass": bool(validation and validation["pass"]), "sensitivity": (validation or {}).get("sensitivity"),
                     "false_positive_rate": (validation or {}).get("false_positive_rate"), "computable": bool(validation and validation["computable"])}
    if "C7" in tspec.criteria:
        hour = tspec.c7["event_hour_utc"]
        sel = (ev_ts % DAY_US) // HOUR_US == hour
        sel &= (ev_ts % HOUR_US) == 0
        chosen = set((ev_ts[sel] // DAY_US).tolist())
        c_sel = np.isin(ct_ts // DAY_US, list(chosen))
        p7 = _point(ev_v[sel], ct_v[c_sel])
        out["C7"] = {"pass": bool(p7 is not None and p7 > tspec.c7["require_point_gt_bp"]), "point": p7, "event_hour_utc": hour, "n": int(sel.sum()), "n_control": int(c_sel.sum()),
                     "days": len(chosen)}
    return {k: out[k] for k in tspec.criteria}, diff


def economic_reading(parts: list[dict], tspec: TypeSpec, spec: Spec, calendar_days: int, verdict: str) -> dict:
    """GATES §1, LP-G5 §7.6 — at each event instant at most `max_live` filled orders, admitted in instrument-id order;
    plafond_USD_an = fills/day × notional × mean event net_bp / 1e4 × days/year (the absolute round trip, never D), point and
    LB90 / UB90 (day-block bootstrap of the admitted fills); « poursuivi » ⇔ point ≥ S_éco and (UB90 ≥ 2 S_éco or LB90 > 0)."""
    eco = spec.economic
    by_instant: dict[int, list[tuple[str, float]]] = {}
    for p in parts:
        for t, v in zip(np.asarray(p["instants_us"]).tolist(), np.asarray(p["net_bp"], dtype=np.float64).tolist()):
            if math.isfinite(v):
                by_instant.setdefault(int(t), []).append((str(p["inst"]), float(v)))
    fills_ts, fills_v = [], []
    for t in sorted(by_instant):
        for _, v in sorted(by_instant[t])[: eco["max_live"]]:  # inst_id ascending
            fills_ts.append(t)
            fills_v.append(v)
    out = {"measure": "net_bp (absolute round trip)", "max_live": eco["max_live"], "order_notional_usd": eco["notional"], "leverage": eco["leverage"], "capital_usd": eco["capital"],
           "days_per_year": tspec.days_per_year, "calendar_days": int(calendar_days), "s_eco_usd_per_year": eco["s_eco"], "fills": len(fills_v),
           "fills_uncapped": sum(len(v) for v in by_instant.values())}
    if not fills_v or calendar_days <= 0:
        out.update({"fills_per_day": 0.0, "usd_per_year_point": None, "usd_per_year_lb90": None, "usd_per_year_ub90": None, "pursued": False})
    else:
        stat = boot(spec, np.array(fills_ts, dtype=np.int64), np.array(fills_v))
        per_day = len(fills_v) / calendar_days

        def usd(value):
            return None if value is None else per_day * eco["notional"] * float(value) / 1e4 * tspec.days_per_year

        point, lb, ub = usd(stat["event_mean"]), usd(stat["lb90"]), usd(stat["ub90"])
        pursued = point is not None and point >= eco["s_eco"] and ((ub is not None and ub >= 2 * eco["s_eco"]) or (lb is not None and lb > 0))
        out.update({"fills_per_day": per_day, "net_bp_mean": stat["event_mean"], "net_bp_lb90": stat["lb90"], "net_bp_ub90": stat["ub90"], "usd_per_year_point": point,
                    "usd_per_year_lb90": lb, "usd_per_year_ub90": ub, "pursued": bool(pursued)})
    readings = eco["readings"]
    reading = (readings[0] if out["pursued"] else readings[1]) if verdict == "PASS" else (readings[2] if verdict == "FAIL" else spec.gate + verdict)
    overlaps = _same_instrument_overlaps(parts, tspec)
    minutes = tspec.return_window[1] - tspec.return_window[0]
    out.update({"reading": reading, "pos_mode": {"net_mode": reading, "long_short_mode": reading, "overlapping_positions_same_instrument": overlaps,
                                                 "note": "identical readings when no two positions of one instrument overlap (windows of a type never do)"},
                "margin_opportunity_cost": {"rule": spec.costs.get("margin_opportunity_cost", "negligible_reported"), "minutes_per_position": minutes,
                                            "usd_per_position_at_5pct": eco["notional"] / eco["leverage"] * minutes / (365 * 24 * 60) * 0.05,
                                            "note": "notional / leverage × window / 365 days × a 5 % rate (SOFR order of magnitude): reported, not subtracted (GATES §5.2)"}})
    return out


def _same_instrument_overlaps(parts: list[dict], tspec: TypeSpec) -> int:
    span = (tspec.return_window[1] - tspec.return_window[0]) * MINUTE_US
    total = 0
    for p in parts:
        ts = np.sort(np.asarray(p["instants_us"], dtype=np.int64))
        if ts.size > 1:
            total += int((np.diff(ts) < span).sum())
    return total


# ----------------------------------------------------------------------------- descriptive reports (LP-G5 §9; no p-value, no verdict)


def _d_summary(ev_ts, ev_v, ct_ts, ct_v) -> dict:
    e, c = _fmean(ev_v), _fmean(ct_v)
    return {"n": int(np.asarray(ev_ts).size), "n_control": int(np.asarray(ct_ts).size), "event_mean": e, "control_mean": c,
            "point": None if e is None or c is None else e - c}


def _subset_d(run: dict, ev_sel, ct_sel) -> dict:
    inst = run["instant"]
    return _d_summary(inst["ev_ts"][ev_sel], inst["ev"][ev_sel], inst["ct_ts"][ct_sel], inst["ct"][ct_sel])


def _same_days(run: dict, ev_sel) -> np.ndarray:
    inst = run["instant"]
    days = set((inst["ev_ts"][ev_sel] // DAY_US).tolist())
    return np.isin(inst["ct_ts"] // DAY_US, list(days))


def _variant_d(run: dict, spec: Spec, ev_options: dict | None, ct_options: dict | None = None, events_override: dict | None = None, controls_override: dict | None = None) -> dict:
    tspec = run["tspec"]
    ev_parts, ct_parts = [], []
    for inst, s in run["series"].items():
        ev_parts.append(measure(s, (events_override or run["events"]).get(inst, np.zeros(0, dtype=np.int64)), tspec, spec, "event", ev_options))
        ct_parts.append(measure(s, (controls_override or run["controls"]).get(inst, np.zeros(0, dtype=np.int64)), tspec, spec, "control", ct_options if ct_options is not None else ev_options))
    mi = spec.unit["min_instruments"]
    ev_ts, ev_v, _ = instant_means(ev_parts, "net_bp", mi)
    ct_ts, ct_v, _ = instant_means(ct_parts, "net_bp", mi)
    out = _d_summary(ev_ts, ev_v, ct_ts, ct_v)
    attempted = sum(p["fill"]["attempted"] for p in ev_parts)
    out["fill_rate_events"] = (sum(p["fill"]["filled"] for p in ev_parts) / attempted) if attempted else None
    return out


def _pooled(parts: list[dict], key: str) -> np.ndarray:
    arrays = [np.asarray(p[key], dtype=np.float64) for p in parts if np.asarray(p[key]).size]
    return np.concatenate(arrays) if arrays else np.zeros(0)


def _quartiles(values) -> dict:
    v = np.asarray(values, dtype=np.float64)
    v = v[np.isfinite(v)]
    if v.size == 0:
        return {"n": 0}
    return {"n": int(v.size), "mean": _fmean(v), "q25": float(np.quantile(v, 0.25)), "median": float(np.median(v)), "q75": float(np.quantile(v, 0.75))}


def _signed_path_bp(series: Series, instants: np.ndarray, signs: np.ndarray, a_min: int, b_min: int) -> np.ndarray:
    k0 = series.index_of(instants)
    return np.asarray(signs, dtype=np.float64) * _log_bp(series.at(series.close, k0 + a_min), series.at(series.close, k0 + b_min))


def _rv24_bp(series: Series, k_end: np.ndarray) -> np.ndarray:
    """Realised volatility of the 1-minute log returns of the last price over the 1 440 minutes ending at k_end (bp; ≥ 90 % present)."""
    if "r1" not in series.cache:
        with np.errstate(divide="ignore", invalid="ignore"):
            r = np.diff(np.log(series.close)) * 1e4
        series.cache["r1"] = np.concatenate([[np.nan], r])
    r1 = series.cache["r1"]
    out = np.full(np.asarray(k_end).size, np.nan)
    for i, k in enumerate(np.asarray(k_end, dtype=np.int64).tolist()):
        lo = k - 1439
        if lo < 1 or k >= series.size:
            continue
        window = r1[lo: k + 1]
        ok = np.isfinite(window)
        if ok.sum() >= 0.9 * 1440:
            out[i] = math.sqrt(math.fsum((window[ok] ** 2).tolist()))
    return out


def report_regime_rv24(run: dict, spec: Spec) -> dict:
    """rv24 at each retained instrument-instant against the median of the instrument's daily rv24 over the ≤ 30 eligible days before
    (undefined before 7 days): stress (above) / calm strata of the instant-level D (T14)."""
    flags_ev, flags_ct = [], []
    for side, parts, flags in (("event", run["ev_parts"], flags_ev), ("control", run["ct_parts"], flags_ct)):
        for p in parts:
            s = run["series"][p["inst"]]
            k = s.index_of(p["instants_us"])
            rv = _rv24_bp(s, k)
            first_day = int(max(s.meta.eligible_from_us, int(s.grid_us[0])) // DAY_US) + 1
            days = np.arange(first_day, int(spec.end_us // DAY_US) + 1)
            daily = _rv24_bp(s, s.index_of(days * DAY_US))
            med = np.full(rv.size, np.nan)
            for i, t in enumerate(np.asarray(p["instants_us"]).tolist()):
                d = t // DAY_US
                sel = (days <= d) & (days > d - 30)
                vals = daily[sel]
                vals = vals[np.isfinite(vals)]
                if vals.size >= 7:
                    med[i] = float(np.median(vals))
            flag = np.where(np.isfinite(rv) & np.isfinite(med), np.where(rv > med, 1, 0), -1)
            flags.append({"inst": p["inst"], "instants_us": p["instants_us"], "net_bp": p["net_bp"], "flag": flag})
    out = {"rule": "rv24 > median of the instrument's daily rv24 over the <= 30 previous eligible days (>= 7 days), else undefined"}
    mi = spec.unit["min_instruments"]
    for label, value in (("stress", 1), ("calm", 0), ("undefined", -1)):
        ev = [{"inst": f["inst"], "instants_us": f["instants_us"][f["flag"] == value], "net_bp": f["net_bp"][f["flag"] == value]} for f in flags_ev]
        ct = [{"inst": f["inst"], "instants_us": f["instants_us"][f["flag"] == value], "net_bp": f["net_bp"][f["flag"] == value]} for f in flags_ct]
        ets, ev_v, _ = instant_means(ev, "net_bp", mi)
        cts, ct_v, _ = instant_means(ct, "net_bp", mi)
        out[label] = _d_summary(ets, ev_v, cts, ct_v)
    return out


def report_portfolio(run: dict, spec: Spec, calendar_days: int) -> dict:
    """§9.6 — admitted instants (≤ max_live per instant, instrument-id order), fills per day, net PnL in USD, the catastrophe cut at
    3 σ15 (σ15 = σ of the 1-minute returns of the 24 h before h × √15; never tighter), under both posMode hypotheses."""
    eco, tspec = spec.economic, run["tspec"]
    rows = []
    for p in run["ev_parts"]:
        for t, s, v in zip(np.asarray(p["instants_us"]).tolist(), np.asarray(p["sign"]).tolist(), np.asarray(p["net_bp"]).tolist()):
            rows.append((int(t), str(p["inst"]), int(s), float(v)))
    rows.sort()
    admitted, by_t = [], {}
    for t, inst, s, v in rows:
        if len(by_t.setdefault(t, [])) < eco["max_live"]:
            by_t[t].append(inst)
            admitted.append((t, inst, s, v))
    cuts, pnl, pnl_cut = 0, [], []
    a, b = tspec.return_window
    for t, inst, s, v in admitted:
        series = run["series"][inst]
        k0 = int(series.index_of(np.array([t]))[0])
        r1 = series.cache.get("r1")
        if r1 is None:
            _rv24_bp(series, np.array([k0]))
            r1 = series.cache["r1"]
        window = r1[max(1, k0 - 1439): k0 + 1]
        window = window[np.isfinite(window)]
        sigma15 = float(np.std(window)) * math.sqrt(15) if window.size > 1 else math.nan
        path = s * _log_bp(series.at(series.close, np.full(b - a, k0 + a)), series.at(series.close, k0 + np.arange(a + 1, b + 1)))
        hit = np.nonzero(np.isfinite(path) & (path <= -3.0 * sigma15))[0] if math.isfinite(sigma15) else np.zeros(0, dtype=np.int64)
        pnl.append(v * eco["notional"] / 1e4)
        if hit.size:
            cuts += 1
            pnl_cut.append((v - (s * _log_bp(np.array([series.close[k0 + a]]), np.array([series.close[k0 + b]]))[0]) + float(path[hit[0]])) * eco["notional"] / 1e4)
        else:
            pnl_cut.append(v * eco["notional"] / 1e4)
    reading = {"admitted_instants": len(by_t), "fills": len(admitted), "fills_per_day": (len(admitted) / calendar_days) if calendar_days else None,
               "pnl_usd": math.fsum(pnl), "pnl_usd_with_cut_3_sigma15": math.fsum(pnl_cut), "cuts_triggered": cuts, "calendar_days": calendar_days}
    return {"net_mode": reading, "long_short_mode": reading, "overlapping_positions_same_instrument": _same_instrument_overlaps(run["ev_parts"], tspec),
            "note": "the two posMode hypotheses coincide when no two positions of one instrument overlap; no market impact assumed"}


def common_reports(series_by_inst: dict, spec: Spec, names: tuple, windows: dict | None = None) -> dict:
    """Reports that belong to no type (printed even when a type is sealed): minute 0 (T07), hourly and weekday seasonality of the
    1-minute |return| of the last price, histogram of the funding intervals."""
    out: dict = {}
    abs_ret, moh, hod, wd = [], [], [], []
    intervals: dict = {}
    for inst, s in series_by_inst.items():
        k_first = max(1, int((spec.start_us - int(s.grid_us[0])) // MINUTE_US))
        with np.errstate(divide="ignore", invalid="ignore"):
            r = np.abs(np.diff(np.log(s.close[k_first - 1:]))) * 1e4
        ends = s.grid_us[k_first:]
        ok = np.isfinite(r)
        abs_ret.append(r[ok])
        starts = ends[ok] - MINUTE_US  # the minute starting at hh:mm ends at hh:mm+1
        moh.append(((starts // MINUTE_US) % 60).astype(np.int64))
        hod.append(((starts % DAY_US) // HOUR_US).astype(np.int64))
        wd.append(((starts // DAY_US + 3) % 7).astype(np.int64))  # 1970-01-01 was a Thursday: Monday = 0
        settled = s.settlements_us[(s.settlements_us >= spec.start_us) & (s.settlements_us < spec.end_us)]
        if settled.size > 1:
            values, counts = np.unique(np.round(np.diff(settled) / HOUR_US, 2), return_counts=True)
            intervals[inst] = {"settlements": int(settled.size), "interval_h_histogram": {f"{v:g}": int(c) for v, c in zip(values.tolist(), counts.tolist())}}
    r = np.concatenate(abs_ret) if abs_ret else np.zeros(0)

    def table(keys, size, label):
        keys = np.concatenate(keys) if keys else np.zeros(0, dtype=np.int64)
        return {label(k): {"n": int((keys == k).sum()), "abs_return_bp_mean": _fmean(r[keys == k])} for k in range(size) if (keys == k).any()}

    if "minute_zero_T07" in names:
        m = np.concatenate(moh) if moh else np.zeros(0, dtype=np.int64)
        m0, mo = _fmean(r[m == 0]), _fmean(r[m != 0])
        out["minute_zero_T07"] = {"abs_return_bp_minute_0": m0, "abs_return_bp_other_minutes": mo, "ratio": (m0 / mo) if m0 is not None and mo else None,
                                  "by_minute_of_hour": table(moh, 60, lambda k: f"{k:02d}")}
    if "hourly_seasonality_last_price" in names:
        out["hourly_seasonality_last_price"] = {"by_hour_utc": table(hod, 24, lambda k: f"{k:02d}"), "spread_seasonality": "UNAVAILABLE on the 1-minute history (no book)"}
    if "weekday_seasonality" in names:
        out["weekday_seasonality"] = {"by_weekday": table(wd, 7, lambda k: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"][k])}
    if "funding_intervals_histogram" in names:
        out["funding_intervals_histogram"] = intervals
    if "T06_us_close_reversion" in names:
        out["T06_us_close_reversion"] = report_us_close_reversion(series_by_inst, spec, windows)
    return out


GATE2_TYPE_REPORTS = {
    "T02_funding_4h_settlements_premium_settstate_book": lambda t: t.generator["kind"] == "funding_settlements",
    "T18_cme_cf_brr_window": lambda t: True,
    "fill_rules_through_and_queue": lambda t: True,
    "premium_okx_channel_vs_premium_mark_index": lambda t: t.classifier is not None,
    "net_bp_gate1_construction_on_last_prints": lambda t: True,
    "adverse_selection_measured_vs_design_3_5": lambda t: t.role == "maker_maker",
    "taker_half_spread_measured_vs_design_3": lambda t: t.role == "taker_maker",
    "dst_regime_winter_vs_summer": lambda t: True,
}
DESCRIPTIVE_RULE_NOTES = {
    "T02_funding_4h_settlements_premium_settstate_book": "the T01 rule (classifier, simulation) at the settlements of another interval (4 h, 2 h, 1 h); settState counts, "
                                                         "mean |premium| of the funding-rate channel and book spread at the decision",
    "T06_us_close_reversion": "16:00 New York of the NYSE days: s = sign(c(h) - c(h-5)), reversion = -s ln(c(h+15) / c(h)) x 1e4 (last price, no cost, no test)",
    "adverse_selection_measured_vs_design_3_5": "filled maker entries: m_h = s (mid(first fill + h) - p) / p x 1e4 at h = 0 (mid just before), 60 s, 300 s; measured adverse "
                                                "selection = -mean(m_60)",
    "taker_half_spread_measured_vs_design_3": "taker entries: s (p - mid) / mid x 1e4 at the activation snapshot",
    "dst_regime_winter_vs_summer": "instants split by events.us_dst_active of their New York date",
    "premium_okx_channel_vs_premium_mark_index": "P_hat(t_arm) (premium_mark_index) against the funding-rate channel's last premium before t_arm, at the raw events and the controls: "
                                                 "3 x 3 class matrix at the classifier thresholds, Cohen's kappa, Pearson correlation (descriptive only, CTO M2)",
}


def _us_summer_time(ts_us) -> np.ndarray:
    """`events.us_dst_active` of the New York date of each instant."""
    ny = ZoneInfo(NEW_YORK)
    return np.array([ev.us_dst_active(datetime.fromtimestamp(int(t) / tape.SECOND_US, tz=UTC).astimezone(ny).date()) for t in np.asarray(ts_us).tolist()], dtype=bool)


def _channel_premium(series: Series, at_us) -> np.ndarray:
    """The funding-rate channel's `premium` (the official impact premium) last received at or before each instant (descriptive only)."""
    ts, values = series.cache.get("premium_okx", (np.zeros(0, dtype=np.int64), np.zeros(0)))
    at = np.asarray(at_us, dtype=np.int64)
    if ts.size == 0:
        return np.full(at.size, np.nan)
    pos = np.searchsorted(ts, at, side="right") - 1
    return np.where(pos >= 0, values[np.maximum(pos, 0)], np.nan)


def _book_spread_bp(series: Series, at_us) -> np.ndarray:
    book = series.book
    at = np.asarray(at_us, dtype=np.int64)
    if book is None or book.size == 0:
        return np.full(at.size, np.nan)
    k = np.searchsorted(book.ts, at, side="right") - 1
    ok = k >= 0
    out = np.full(at.size, np.nan)
    bid, ask = book.bid[k[ok]], book.ask[k[ok]]
    with np.errstate(divide="ignore", invalid="ignore"):
        out[ok] = (ask - bid) / ((ask + bid) / 2.0) * 1e4
    return out


def _premium_agreement(run: dict, tspec: TypeSpec) -> dict:
    hi, lo = tspec.classifier["high"], tspec.classifier["low"]
    mine, theirs = [], []
    for inst, s in run["series"].items():
        for instants in (run["raw"].get(inst, np.zeros(0, dtype=np.int64)), run["controls"].get(inst, np.zeros(0, dtype=np.int64))):
            if np.asarray(instants).size == 0:
                continue
            mine.append(s.p_hat(s.index_of(instants) + tspec.arm_min, tspec.classifier))
            theirs.append(_channel_premium(s, np.asarray(instants, dtype=np.int64) + tspec.arm_min * MINUTE_US))
    a = np.concatenate(mine) if mine else np.zeros(0)
    b = np.concatenate(theirs) if theirs else np.zeros(0)
    ok = np.isfinite(a) & np.isfinite(b)
    a, b = a[ok], b[ok]
    ca, cb = classify(a, hi, lo), classify(b, hi, lo)
    matrix = {f"{x}|{y}": int(((ca == x) & (cb == y)).sum()) for x in (-1, 0, 1) for y in (-1, 0, 1)}
    n = int(a.size)
    kappa = None
    if n:
        po = math.fsum(matrix[f"{x}|{x}"] for x in (-1, 0, 1)) / n
        pe = math.fsum((float((ca == x).sum()) / n) * (float((cb == x).sum()) / n) for x in (-1, 0, 1))
        kappa = (po - pe) / (1.0 - pe) if pe < 1.0 else None
    corr = float(np.corrcoef(a, b)[0, 1]) if n > 2 and np.std(a) > 0 and np.std(b) > 0 else None
    return {"pairs": n, "matrix_premium_mark_index_class|premium_okx_class": matrix, "cohen_kappa": kappa, "pearson": corr,
            "premium_mark_index_mean": _fmean(a), "premium_okx_mean": _fmean(b)}


def report_us_close_reversion(series_by_inst: dict, spec: Spec, windows: dict | None) -> dict:
    """§9.8 T06 (descriptive stratum, deferred to the Gate 2): the reversion after the 16:00 New York close (DESCRIPTIVE_RULE_NOTES)."""
    session = next((x for x in spec.sessions if x["name"] == "us_cash_close"), None)
    if session is None:
        return {"status": "UNAVAILABLE", "reason": "no us_cash_close session in the spec"}
    lo = spec.start_us
    hi = max(b for _, b in windows.values()) if windows else spec.end_us
    instants = []
    for day in _local_days(lo, hi):
        if day_matches(session["days"], day, spec.calendars):
            m = local_to_utc_us(day, session["start_min"], session["tz_info"])
            if lo <= m - 5 * MINUTE_US and m + 15 * MINUTE_US < hi:
                instants.append(m)
    instants = np.array(sorted(set(instants)), dtype=np.int64)
    values, by_inst = [], {}
    for inst, s in series_by_inst.items():
        ts = instants[instants - 5 * MINUTE_US >= s.meta.eligible_from_us]
        if ts.size == 0:
            continue
        k = s.index_of(ts)
        before, at, after = s.at(s.close, k - 5), s.at(s.close, k), s.at(s.close, k + 15)
        side = np.sign(np.nan_to_num(_log_bp(before, at), nan=0.0))
        rev = -side * _log_bp(at, after)
        ok = np.isfinite(rev) & (side != 0)
        values.append(rev[ok])
        by_inst[inst] = int(ok.sum())
    pooled = np.concatenate(values) if values else np.zeros(0)
    return {"rule": DESCRIPTIVE_RULE_NOTES["T06_us_close_reversion"], "instants": int(instants.size), "observations": int(pooled.size), "reversion_bp_mean": _fmean(pooled),
            "by_instrument_observations": by_inst, "trades_source": "not captured (G2 0.9): no RPI split"}


# ----------------------------------------------------------------------------- amendment A1: signal, execution, net (reported, never decisive)


def _row(spec: Spec, ts, values, observations: int, fill_rate=None, extra: dict | None = None) -> dict:
    """One row of an A1 table: n instants / instrument-instants / days, fill rate, mean, median and LB90 / UB90 of the mean (day-block
    bootstrap of the statistics block, one sample); no p-value."""
    ts, v = np.asarray(ts, dtype=np.int64), np.asarray(values, dtype=np.float64)
    st = boot(spec, ts, v) if v.size else {}
    out = {"n_instants": int(ts.size), "n_instrument_instants": int(observations), "n_days": int(np.unique(ts // DAY_US).size), "fill_rate": fill_rate,
           "mean": _fmean(v), "median": float(np.median(v)) if v.size else None, "lb90": st.get("lb90"), "ub90": st.get("ub90")}
    out.update(extra or {})
    return out


def _diff_row(spec: Spec, ev_ts, ev_v, ct_ts, ct_v) -> dict:
    """Events minus controls (or filled minus unfilled): point, LB90 / UB90 of the day-block bootstrap (the two samples of a day move
    together), the difference of the medians; no p-value."""
    ev_v, ct_v = np.asarray(ev_v, dtype=np.float64), np.asarray(ct_v, dtype=np.float64)
    st = boot(spec, ev_ts, ev_v, ct_ts, ct_v) if ev_v.size and ct_v.size else {}
    return {"n_instants": int(ev_v.size), "n_other_instants": int(ct_v.size), "point": st.get("point"), "lb90": st.get("lb90"), "ub90": st.get("ub90"),
            "median_difference": (float(np.median(ev_v)) - float(np.median(ct_v))) if ev_v.size and ct_v.size else None}


def _a1_parts(run: dict, spec: Spec) -> dict:
    """The A population (A1): every classified instrument-instant of the events and of the controls, the fill proxy off, the fill
    status recorded (`fill_flag`); measured once per run."""
    if "_a1_parts" not in run:
        tspec, empty = run["tspec"], np.zeros(0, dtype=np.int64)
        options = {"fill": False, "fill_status": True}
        if spec.source == "capture":
            options["construction"] = "gate1"
        run["_a1_parts"] = {side: [measure(s, run[key].get(i, empty), tspec, spec, role, options) for i, s in run["series"].items()]
                            for side, key, role in (("events", "events", "event"), ("controls", "controls", "control"))}
    return run["_a1_parts"]


def _a1_cached(run: dict, key: str, build) -> dict:
    cache = run.setdefault("_a1_reports", {})
    if key not in cache:
        cache[key] = build()
    return cache[key]


def _flag_subset(parts: list[dict], flag: int, key: str = "r_bp") -> tuple[list[dict], int]:
    out, n = [], 0
    for p in parts:
        sel = np.asarray(p["fill_flag"]) == flag
        n += int(sel.sum())
        out.append({"inst": p["inst"], "instants_us": np.asarray(p["instants_us"])[sel], key: np.asarray(p[key])[sel]})
    return out, n


def _obs(parts: list[dict]) -> int:
    return int(sum(np.asarray(p["instants_us"]).size for p in parts))


def report_abc(run: dict, spec: Spec, calendar_days: int) -> dict:
    """A1 — the A / B / C table of one type (`descriptive.a1.definitions`): A = gross signed return of every classified instant (fill
    proxy off), B = the same return at the filled (primary) instants, C = the primary net_bp (B + funding − the type's costs) and the
    portfolio reading; events, controls and events − controls; nothing here is a test."""
    tspec, mi = run["tspec"], spec.unit["min_instruments"]
    a = _a1_parts(run, spec)
    fill = run.get("fill_rate") or {}
    out: dict = {"status": A1_STATUS, "definitions": {k: A1_DEFINITIONS[k] for k in ("A_signal", "B_execution", "C_net", "statistics")},
                 "costs_bp": type_cost_terms(tspec, spec.costs), "fill_conditioning": "fill proxy (through)" if tspec.fill_proxy is not None else "none: taker entry, filled by construction"}
    arrays: dict = {}
    for row, key in (("A_signal", "r_bp"), ("B_execution", "r_bp"), ("C_net", "net_bp")):
        out[row] = {}
        for side, a_parts, b_parts in (("events", a["events"], run["ev_parts"]), ("controls", a["controls"], run["ct_parts"])):
            parts = a_parts if row == "A_signal" else b_parts
            ts, values, _ = instant_means(parts, key, mi)
            arrays[(row, side)] = (ts, values)
            if row == "A_signal":
                rate = None
            elif tspec.fill_proxy is not None:
                rate = (fill.get(side) or {}).get("rate")
            else:
                rate = 1.0
            extra = None
            if row == "C_net":
                _, st_v, _ = instant_means(parts, "net_stress_bp", mi)
                extra = {"mean_at_stress": _fmean(st_v)}
            out[row][side] = _row(spec, ts, values, _obs(parts), rate, extra)
        (ev_ts, ev_v), (ct_ts, ct_v) = arrays[(row, "events")], arrays[(row, "controls")]
        out[row]["events_minus_controls"] = _diff_row(spec, ev_ts, ev_v, ct_ts, ct_v)
    portfolio = report_portfolio(run, spec, calendar_days)["net_mode"]
    out["C_net"]["portfolio"] = {k: portfolio[k] for k in ("admitted_instants", "fills", "fills_per_day", "pnl_usd", "pnl_usd_with_cut_3_sigma15", "cuts_triggered", "calendar_days")}
    out["no_double_count"] = ("A and B carry no cost term; C subtracts each assumed term once: the entry-leg adverse selection of a fill-conditioned maker entry is "
                              "measured inside B (0 assumed, A1), the exit leg, the fees, the slippage and the taker half spread are assumed once")
    return out


def report_filled_vs_unfilled(run: dict, spec: Spec) -> dict:
    """A1 — A at the filled against A at the unfilled classified instrument-instants (instant means), at the events and at the
    controls; gap = filled − unfilled (its sign is the signature of the adverse selection); fill rates events against controls."""
    tspec, mi = run["tspec"], spec.unit["min_instruments"]
    if tspec.fill_proxy is None:
        return {"status": "NOT_APPLICABLE", "reason": "no fill proxy (taker entry, filled by construction)"}
    a = _a1_parts(run, spec)
    out: dict = {"status": A1_STATUS, "rule": A1_DEFINITIONS["filled_vs_unfilled"]}
    for side in ("events", "controls"):
        filled, n_f = _flag_subset(a[side], 1)
        unfilled, n_u = _flag_subset(a[side], 0)
        _, n_m = _flag_subset(a[side], -1)
        f_ts, f_v, _ = instant_means(filled, "r_bp", mi)
        u_ts, u_v, _ = instant_means(unfilled, "r_bp", mi)
        out[side] = {"A_filled": _row(spec, f_ts, f_v, n_f), "A_unfilled": _row(spec, u_ts, u_v, n_u), "gap_filled_minus_unfilled": _diff_row(spec, f_ts, f_v, u_ts, u_v),
                     "fill_rate": (n_f / (n_f + n_u)) if (n_f + n_u) else None, "fill_window_missing": n_m}
    ev, ct = out["events"], out["controls"]
    out["fill_rate_events_minus_controls"] = (ev["fill_rate"] - ct["fill_rate"]) if ev["fill_rate"] is not None and ct["fill_rate"] is not None else None
    ge, gc = ev["gap_filled_minus_unfilled"]["point"], ct["gap_filled_minus_unfilled"]["point"]
    out["gap_events_minus_controls"] = (ge - gc) if ge is not None and gc is not None else None
    return out


def report_taker_variant(run: dict, spec: Spec) -> dict:
    """A1 — the taker variant of a resting type (T01): entry taker at c(t_arm) plus the design half spread, no fill conditioning (the
    A population), the same funding and exit, the costs of the taker_maker role (15.5 bp, stress 23.25); D, the event net and its
    stress, as C2 / C3 would read them. Distinguishes « badly executed » from « no edge »."""
    tspec, mi = run["tspec"], spec.unit["min_instruments"]
    if tspec.fill_proxy is None:
        return {"status": "NOT_APPLICABLE", "reason": "the type's entry is already taker"}
    costs = cost_terms("taker_maker", spec.costs)
    a = _a1_parts(run, spec)
    arrays = {}
    for side in ("events", "controls"):
        parts = []
        for p in a[side]:
            r, f = np.asarray(p["r_bp"], dtype=np.float64), np.asarray(p["funding_bp"], dtype=np.float64)
            net = np.array([math.fsum([x, y, -costs["total_bp"]]) for x, y in zip(r.tolist(), f.tolist())], dtype=np.float64)
            stress = np.array([math.fsum([x, y, -costs["stress_total_bp"]]) for x, y in zip(r.tolist(), f.tolist())], dtype=np.float64)
            parts.append({"inst": p["inst"], "instants_us": p["instants_us"], "net_bp": net, "net_stress_bp": stress})
        ts, v, _ = instant_means(parts, "net_bp", mi)
        _, vs, _ = instant_means(parts, "net_stress_bp", mi)
        arrays[side] = (ts, v, vs, _obs(parts))
    (ev_ts, ev_v, ev_s, ev_n), (ct_ts, ct_v, _, ct_n) = arrays["events"], arrays["controls"]
    return {"status": A1_STATUS, "rule": A1_DEFINITIONS["taker_variant"], "costs_bp": costs, "events": _row(spec, ev_ts, ev_v, ev_n, 1.0),
            "controls": _row(spec, ct_ts, ct_v, ct_n, 1.0), "D": _diff_row(spec, ev_ts, ev_v, ct_ts, ct_v), "event_net_mean": _fmean(ev_v), "event_net_stress_mean": _fmean(ev_s),
            "note": "c(t_arm) is not conditioned on a last-price move for a classifier type: the design half spread is charged once, without bounce overlap"}


def report_cost_sensitivity(run: dict, spec: Spec) -> dict:
    """A1 — the C2 / C3 values of the type at the pre-A1 totals (the costs are constant per instrument-instant: each instant value
    moves by the difference of the totals; D does not depend on it)."""
    tspec, inst = run["tspec"], run["instant"]
    if tspec.cost_sensitivity is None:
        return {"status": "NOT_APPLICABLE", "reason": "no cost_sensitivity_pre_a1 in the spec"}
    now = type_cost_terms(tspec, spec.costs)
    pre = cost_terms(tspec.role, spec.costs, ENTRY_ADVERSE_DESIGN)
    mean, mean_stress = _fmean(inst["ev"]), _fmean(inst["ev_stress"])
    d_total, d_stress = math.fsum([pre["total_bp"], -now["total_bp"]]), math.fsum([pre["stress_total_bp"], -now["stress_total_bp"]])
    shifted = None if mean is None else math.fsum([mean, -d_total])
    shifted_stress = None if mean_stress is None else math.fsum([mean_stress, -d_stress])
    return {"status": A1_STATUS, "rule": COST_SENSITIVITY_USE,
            "a1": {"total_bp": now["total_bp"], "stress_total_bp": now["stress_total_bp"], "event_net_mean": mean, "event_net_stress_mean": mean_stress},
            "pre_a1": {"total_bp": pre["total_bp"], "stress_total_bp": pre["stress_total_bp"], "event_net_mean": shifted, "event_net_stress_mean": shifted_stress,
                       "event_net_mean_positive": None if shifted is None else bool(shifted > 0),
                       "event_net_stress_mean_positive": None if shifted_stress is None else bool(shifted_stress > 0)},
            "D": "unchanged: the same costs at the events and at the controls"}


def _type_window_days(run: dict, spec: Spec) -> tuple[int, int]:
    """The calendar days [first ; end) of the type's window: D_cov (T01, the receipt's or the earliest instrument's), the spec window
    otherwise."""
    tspec = run["tspec"]
    lo = spec.start_us
    if run.get("window") is not None:
        lo = int(run["window"][0])
    elif tspec.window_rule == "funding_coverage":
        known = [s.d_cov_start_us for s in run["series"].values() if s.d_cov_start_us is not None]
        lo = min(known) if known else spec.start_us
    return int(lo // DAY_US), int((spec.end_us - 1) // DAY_US) + 1


def report_chronology(run: dict, spec: Spec) -> dict:
    """A1 — D and n per calendar month (UTC) and per half of the type's window, for C (net_bp, the primary measure) and A (signal)."""
    inst, mi = run["instant"], spec.unit["min_instruments"]
    a = _a1_parts(run, spec)
    a_ev_ts, a_ev, _ = instant_means(a["events"], "r_bp", mi)
    a_ct_ts, a_ct, _ = instant_means(a["controls"], "r_bp", mi)
    series = {"C_net": (inst["ev_ts"], inst["ev"], inst["ct_ts"], inst["ct"]), "A_signal": (a_ev_ts, a_ev, a_ct_ts, a_ct)}

    def month(ts):
        return np.array([tape.date_of_day(int(d))[:7] for d in (np.asarray(ts, dtype=np.int64) // DAY_US).tolist()], dtype=object)

    months = sorted(set(month(inst["ev_ts"]).tolist()) | set(month(a_ev_ts).tolist()))
    by_month = {}
    for m_ in months:
        by_month[m_] = {row: _d_summary(e_ts[month(e_ts) == m_], e_v[month(e_ts) == m_], c_ts[month(c_ts) == m_], c_v[month(c_ts) == m_])
                        for row, (e_ts, e_v, c_ts, c_v) in series.items()}
    first, end = _type_window_days(run, spec)
    cut = first + (end - first) // 2
    halves = {"window": [tape.date_of_day(first), tape.date_of_day(end)], "cut_day": tape.date_of_day(cut), "rule": A1_DEFINITIONS["chronological_stability"]}
    for label, lo, hi in (("first_half", first, cut), ("second_half", cut, end)):
        halves[label] = {row: _d_summary(e_ts[(e_ts // DAY_US >= lo) & (e_ts // DAY_US < hi)], e_v[(e_ts // DAY_US >= lo) & (e_ts // DAY_US < hi)],
                                         c_ts[(c_ts // DAY_US >= lo) & (c_ts // DAY_US < hi)], c_v[(c_ts // DAY_US >= lo) & (c_ts // DAY_US < hi)])
                         for row, (e_ts, e_v, c_ts, c_v) in series.items()}
    return {"status": A1_STATUS, "by_month": by_month, "window_halves": halves}


def diagnostic_classification(run: dict, spec: Spec, calendar_days: int, verdict: str | None) -> dict:
    """A1 — the diagnostic classification of `descriptive.a1.classification_rules`, first matching rule in the precedence (d) > (a) >
    (c) > (b) > none; every input and every condition is printed; a report, never a verdict (C1–C7 and Holm decide)."""
    tspec = run["tspec"]
    abc = _a1_cached(run, "abc", lambda: report_abc(run, spec, calendar_days))
    g = abc["A_signal"]["events_minus_controls"]
    lb, ub = g["lb90"], g["ub90"]
    a_ev, b_ev, c_ev = abc["A_signal"]["events"]["mean"], abc["B_execution"]["events"]["mean"], abc["C_net"]["events"]["mean"]
    has_fill = tspec.fill_proxy is not None
    gap_ub = taker_mean = None
    if has_fill:
        fills = _a1_cached(run, "fills", lambda: report_filled_vs_unfilled(run, spec))
        taker = _a1_cached(run, "taker", lambda: report_taker_variant(run, spec))
        gap_ub, taker_mean = fills["events"]["gap_filled_minus_unfilled"]["ub90"], taker["event_net_mean"]
    n_instants = int(run["instant"]["ev_ts"].size)
    n_days = int(np.unique(run["instant"]["ev_ts"] // DAY_US).size)
    threshold = spec.statistics["threshold_bp"]
    below_floor = n_instants < spec.power["min_instants"] or n_days < spec.power["min_days"]
    conditions = {
        "verdict_not_informative_or_inconclusive": verdict in ("NOT_INFORMATIVE", "INCONCLUSIVE"),
        "n_below_power_floor": bool(below_floor),
        "no_interval_for_A_gap": lb is None or ub is None,
        "A_gap_interval_holds_0_and_threshold": bool(lb is not None and ub is not None and lb <= 0 and ub >= threshold),
        "A_gap_lb90_gt_0": bool(lb is not None and lb > 0),
        "fill_gap_ub90_lt_0_at_events": bool(gap_ub is not None and gap_ub < 0),
        "B_event_below_A_event": bool(a_ev is not None and b_ev is not None and b_ev < a_ev),
        "C_event_le_0": bool(c_ev is None or c_ev <= 0),
        "taker_variant_event_net_gt_0": bool(taker_mean is not None and taker_mean > 0),
    }
    if (conditions["verdict_not_informative_or_inconclusive"] or below_floor or conditions["no_interval_for_A_gap"]
            or conditions["A_gap_interval_holds_0_and_threshold"]):
        key = "d_insufficiently_documented"
    elif not conditions["A_gap_lb90_gt_0"]:
        key = "a_no_edge"
    elif (has_fill and conditions["fill_gap_ub90_lt_0_at_events"] and conditions["B_event_below_A_event"] and conditions["C_event_le_0"]
          and conditions["taker_variant_event_net_gt_0"]):
        key = "c_badly_executed"
    elif conditions["C_event_le_0"]:
        key = "b_too_weak_for_costs"
    else:
        key = "none_of_the_four"
    return {"status": A1_STATUS, "class": key, "label": DIAGNOSTIC_LABELS[key], "precedence": list(DIAGNOSTIC_PRECEDENCE), "rule": DIAGNOSTIC_RULES[key],
            "inputs": {"verdict": verdict, "n_instants": n_instants, "n_days": n_days, "power_floor": dict(spec.power), "threshold_bp": threshold,
                       "A_gap_point": g["point"], "A_gap_lb90": lb, "A_gap_ub90": ub, "A_event_mean": a_ev, "B_event_mean": b_ev, "C_event_mean": c_ev,
                       "fill_gap_ub90_at_events": gap_ub, "taker_variant_event_net_mean": taker_mean, "has_fill_proxy": has_fill},
            "conditions": conditions, "note": "a report, never a verdict: C1-C7, Holm and the economic gate decide (LP-G5 section 7.5, amendment A1)"}


def type_reports(run: dict, spec: Spec, names: tuple, calendar_days: int, verdict: str | None = None) -> dict:
    """The descriptive reports of one type (sealed with the type when it is INCONCLUSIVE or NOT_INFORMATIVE); `verdict` feeds the A1
    diagnostic classification (its rule (d))."""
    tspec, inst = run["tspec"], run["instant"]
    out: dict = {}
    mine = [n for n in names if n.split("_", 1)[0] == tspec.label] + [n for n in names if n in PER_TYPE_REPORTS]
    mine += [n for n in names if n in GATE2_TYPE_REPORTS and GATE2_TYPE_REPORTS[n](tspec) and n not in mine]
    ev_hour = (inst["ev_ts"] % DAY_US) // HOUR_US
    for name in mine:
        if name == "abc_signal_execution_net":  # A1
            out[name] = _a1_cached(run, "abc", lambda: report_abc(run, spec, calendar_days))
        elif name == "chronological_stability_month_and_window_halves":
            out[name] = report_chronology(run, spec)
        elif name == "diagnostic_classification_a1":
            out[name] = diagnostic_classification(run, spec, calendar_days, verdict)
        elif name.endswith("_filled_vs_unfilled_signal"):
            out[name] = _a1_cached(run, "fills", lambda: report_filled_vs_unfilled(run, spec))
        elif name.endswith("_taker_variant_without_fill_conditioning"):
            out[name] = _a1_cached(run, "taker", lambda: report_taker_variant(run, spec))
        elif name.endswith(A1_SENSITIVITY_SUFFIX):
            out[name] = report_cost_sensitivity(run, spec)
        elif name == "T01_classified_by_realized_rate" or name.endswith("_classified_by_realized_rate"):
            v = tspec.validation or {"rate_high": 0.0004, "rate_low": -0.0004}
            out[name] = {"rule": "events signed by the class of R_h (old classifier, a look-ahead of 4 minutes); controls by P_hat(t_arm)",
                         **_variant_d(run, spec, {"side_from_rate": (v["rate_high"], v["rate_low"])}, {})}
        elif (m := DEAD_ZONE_RE.match(name)) is not None:
            hi, lo = float(f"{m.group('hi_a')}.{m.group('hi_b')}"), -float(f"{m.group('lo_a')}.{m.group('lo_b')}")
            out[name] = {"thresholds": [hi, lo], "rule": "long if P_hat > hi, short if P_hat < lo (strict, LP-G5 §9.4)", **_variant_d(run, spec, {"thresholds": (hi, lo), "strict": True})}
        elif name.endswith("_without_fill_proxy"):
            out[name] = {"rule": "unconditional drift: the fill proxy off at events and controls", **_variant_d(run, spec, {"fill": False})}
        elif name.endswith("_dose_abs_rate"):
            rates = np.abs(_pooled(run["ev_parts"], "rate_at_h")) * 1e4
            net = _pooled(run["ev_parts"], "net_bp")
            buckets = {}
            for lo_, hi_ in ((0.0, 4.0), (4.0, 10.0), (10.0, 25.0), (25.0, math.inf)):
                sel = (rates >= lo_) & (rates < hi_)
                buckets[f"{lo_:g}-{hi_:g}bp"] = {"observations": int(sel.sum()), "net_bp_mean": _fmean(net[sel])}
            out[name] = {"unit": "instrument-instant", "by_abs_realized_rate": buckets}
        elif name.endswith("_v_shape_minus15_0_plus15"):
            res = {}
            for side, parts in (("events", run["ev_parts"]), ("controls", run["ct_parts"])):
                pre = [_signed_path_bp(run["series"][p["inst"]], p["instants_us"], p["sign"], -15, 0) for p in parts]
                post = [_signed_path_bp(run["series"][p["inst"]], p["instants_us"], p["sign"], 0, 15) for p in parts]
                res[side] = {"signed_return_bp_h_minus_15_to_h": _fmean(np.concatenate(pre)) if pre else None, "signed_return_bp_h_to_h_plus_15": _fmean(np.concatenate(post)) if post else None}
            out[name] = res
        elif name.endswith("_by_photo_hour_00_08_16"):
            out[name] = {f"{h:02d}:00": _subset_d(run, ev_hour == h, _same_days(run, ev_hour == h)) for h in (0, 8, 16)}
        elif name.endswith("_friday_0800"):
            fri = ((inst["ev_ts"] // DAY_US + 3) % 7) == 4
            sel_f, sel_o = (ev_hour == 8) & fri, (ev_hour == 8) & ~fri
            out[name] = {"friday_08:00": _subset_d(run, sel_f, _same_days(run, sel_f)), "other_08:00": _subset_d(run, sel_o, _same_days(run, sel_o))}
        elif name.endswith("_us_business_day_vs_not"):
            ny = ZoneInfo(NEW_YORK)
            business = np.array([day_matches("nyse_trading_days", datetime.fromtimestamp(t / tape.SECOND_US, tz=UTC).astimezone(ny).date(), spec.calendars)
                                 for t in inst["ev_ts"].tolist()], dtype=bool)
            out[name] = {"us_business_day": _subset_d(run, business, _same_days(run, business)), "weekend_or_holiday": _subset_d(run, ~business, _same_days(run, ~business))}
        elif name.endswith("_stratum_T02_4h_2h_1h"):
            parts = [measure(run["series"][i], run["other_interval"].get(i, np.zeros(0, dtype=np.int64)), tspec, spec, "event") for i in run["series"]]
            gaps = {}
            for i, s in run["series"].items():
                for t in run["other_interval"].get(i, np.zeros(0, dtype=np.int64)).tolist():
                    pos = int(np.searchsorted(s.settlements_us, t))
                    if pos > 0:
                        key = f"{(t - int(s.settlements_us[pos - 1])) / HOUR_US:g}h"
                        gaps[key] = gaps.get(key, 0) + 1
            out[name] = {"settlements_other_interval": int(sum(np.asarray(v).size for v in run["other_interval"].values())), "previous_gap_histogram": gaps,
                         "observations": int(sum(p["counts"]["kept"] for p in parts)), "net_bp_mean": _fmean(_pooled(parts, "net_bp")), "funding_bp_mean": _fmean(_pooled(parts, "funding_bp"))}
        elif name.endswith("_mark_reference_inside_coverage"):
            out[name] = {"price": "mark_1m.close", **_variant_d(run, spec, {"price": "mark"})}
        elif name.endswith("_premium_balance_events_vs_controls"):
            out[name] = {"abs_p_hat_events": _quartiles(np.abs(_pooled(run["ev_parts"], "p_hat"))), "abs_p_hat_controls": _quartiles(np.abs(_pooled(run["ct_parts"], "p_hat")))}
        elif name.endswith("_funding_cap_stress"):
            stressed = []
            for p in run["ev_parts"]:
                s = run["series"][p["inst"]]
                known = s.settlement_rates[np.isfinite(s.settlement_rates)]
                cap = float(np.max(np.abs(known))) if known.size else math.nan
                stressed.append(np.asarray(p["net_bp"]) - np.asarray(p["funding_bp"]) - abs(cap) * 1e4)
            out[name] = {"rule": "funding at the instrument's largest observed |rate| against the position (proxy of the cap: the history carries no cap)",
                         "event_net_bp_mean": _fmean(np.concatenate(stressed)) if stressed else None}
        elif (m := HORIZONS_RE.match(name)) is not None:
            out[name] = {f"exit_h{tspec.arm_min + int(h):+d}": _variant_d(run, spec, {"exit_min": tspec.arm_min + int(h)}) for h in m.group("h").split("_")}
        elif name.endswith("_move_ge_1_tick"):
            out[name] = {"rule": "|c(h+to) - c(h+from)| >= tick_sz at events and controls", **_variant_d(run, spec, {"tick_move": True})}
        elif name.endswith("_macro_days_separately"):
            events, controls = {}, {}
            for i, s in run["series"].items():
                ev_i, _ = restrict(s, run["macro_excluded"], tspec, spec)
                events[i] = ev_i
                controls[i], _ = control_instants(s, tspec, spec, ev_i, exclusion_intervals(s, tspec, spec, run["common"]))
            out[name] = {"rule": "the type's rule on the excluded macro dates (never in the test)", **_variant_d(run, spec, None, None, events, controls)}
        elif name.endswith("_ten_am_et_release_days"):
            out[name] = {"status": "UNAVAILABLE", "reason": "calendars carries no frozen list of the 10:00 ET releases (JOLTS, ISM; P-G5-5 pending)"}
        elif name.endswith("_brr_adjacency") or name == "T18_cme_cf_brr_window":
            brr = [x for x in session_intervals(spec, spec.pad_start_us, spec.end_us) if "brr" in x[2]]
            starts, ends = np.array([x[0] for x in brr], dtype=np.int64), np.array([x[1] for x in brr], dtype=np.int64)

            def overlap(ts):
                a = np.asarray(ts, dtype=np.int64) + tspec.return_window[0] * MINUTE_US
                b = np.asarray(ts, dtype=np.int64) + tspec.return_window[1] * MINUTE_US
                return violates(starts, ends, a, b, 0) if starts.size else np.zeros(np.asarray(ts).size, dtype=bool)

            ev_o, ct_o = overlap(inst["ev_ts"]), overlap(inst["ct_ts"])
            out[name] = {"rule": "return window overlapping the 15:00-16:00 Europe/London window (session cme_cf_brr_window) or not",
                         "overlapping_brr_window": _subset_d(run, ev_o, ct_o), "not_overlapping": _subset_d(run, ~ev_o, ~ct_o)}
        elif name == "T02_funding_4h_settlements_premium_settstate_book":
            empty = np.zeros(0, dtype=np.int64)
            parts = [measure(run["series"][i], run["other_interval"].get(i, empty), tspec, spec, "event") for i in run["series"]]
            gaps, states, premiums, spreads = {}, {}, [], []
            for i, s in run["series"].items():
                ts = run["other_interval"].get(i, empty)
                for t in ts.tolist():
                    pos = int(np.searchsorted(s.settlements_us, t))
                    if pos > 0:
                        key = f"{(t - int(s.settlements_us[pos - 1])) / HOUR_US:g}h"
                        gaps[key] = gaps.get(key, 0) + 1
                    for st in (s.coverage.get("sett_states") or {}).get(str(t), []):
                        states[str(st)] = states.get(str(st), 0) + 1
                decision = ts + tspec.arm_min * MINUTE_US
                premiums.append(np.abs(_channel_premium(s, decision)))
                spreads.append(_book_spread_bp(s, decision))
            out[name] = {"rule": DESCRIPTIVE_RULE_NOTES[name], "settlements_other_interval": int(sum(np.asarray(v).size for v in run["other_interval"].values())),
                         "previous_gap_histogram": gaps, "sett_state_counts": states, "observations": int(sum(p["counts"]["kept"] for p in parts)),
                         "net_bp_mean": _fmean(_pooled(parts, "net_bp")), "funding_bp_mean": _fmean(_pooled(parts, "funding_bp")),
                         "abs_premium_okx_mean": _fmean(np.concatenate(premiums)) if premiums else None, "book_spread_bp_at_decision_mean": _fmean(np.concatenate(spreads)) if spreads else None}
        elif name == "fill_rules_through_and_queue":
            out[name] = {"rule": "the same simulation under each descriptive fill rule of execution_sim (the primary rule is execution_sim.entry_fill_rule)",
                         **{rule: _variant_d(run, spec, {"sim_rule": rule}) for rule in spec.gate2["sim"]["descriptive_rules"]}}
        elif name == "premium_okx_channel_vs_premium_mark_index":
            out[name] = {"rule": DESCRIPTIVE_RULE_NOTES[name], **_premium_agreement(run, tspec)}
        elif name == "net_bp_gate1_construction_on_last_prints":
            out[name] = {"rule": "the Gate 1 construction (through proxy on the minute bars of the prints, c(t) = last print, Gate 1 design costs, channel funding)",
                         **_variant_d(run, spec, {"construction": "gate1"})}
        elif name == "adverse_selection_measured_vs_design_3_5":
            res = {"rule": DESCRIPTIVE_RULE_NOTES[name], "design_bp_per_maker_leg": spec.costs["adverse_selection_bp_per_maker_leg"]}
            for side, parts in (("events", run["ev_parts"]), ("controls", run["ct_parts"])):
                m0, m60, m300 = (np.concatenate([np.asarray((p.get("sim") or {}).get(k, np.zeros(0))) for p in parts]) if parts else np.zeros(0)
                                 for k in ("sim_markout_0_bp", "sim_markout_60_bp", "sim_markout_300_bp"))
                mean60 = _fmean(m60)
                res[side] = {"fills": int(np.isfinite(m60).sum()), "markout_0_bp_mean": _fmean(m0), "markout_60_bp_mean": mean60, "markout_300_bp_mean": _fmean(m300),
                             "measured_adverse_selection_bp": None if mean60 is None else -mean60}
            out[name] = res
        elif name == "taker_half_spread_measured_vs_design_3":
            res = {"rule": DESCRIPTIVE_RULE_NOTES[name], "design_bp": spec.costs["taker_entry_half_spread_bp"]}
            for side, parts in (("events", run["ev_parts"]), ("controls", run["ct_parts"])):
                hs = np.concatenate([np.asarray((p.get("sim") or {}).get("sim_half_spread_bp", np.zeros(0))) for p in parts]) if parts else np.zeros(0)
                res[side] = {"entries": int(np.isfinite(hs).sum()), "half_spread_bp_mean": _fmean(hs)}
            out[name] = res
        elif name == "dst_regime_winter_vs_summer":
            ev_s, ct_s = _us_summer_time(inst["ev_ts"]), _us_summer_time(inst["ct_ts"])
            out[name] = {"rule": DESCRIPTIVE_RULE_NOTES[name], "us_summer_time": _subset_d(run, ev_s, ct_s), "us_winter_time": _subset_d(run, ~ev_s, ~ct_s)}
        elif name == "by_month":
            months = sorted({tape.date_of_day(int(d))[:7] for d in (inst["ev_ts"] // DAY_US).tolist()})
            ev_m = np.array([tape.date_of_day(int(d))[:7] for d in (inst["ev_ts"] // DAY_US).tolist()])
            ct_m = np.array([tape.date_of_day(int(d))[:7] for d in (inst["ct_ts"] // DAY_US).tolist()])
            out[name] = {m_: _subset_d(run, ev_m == m_, ct_m == m_) for m_ in months}
        elif name == "by_instrument":
            res = {}
            for ep, cp in zip(run["ev_parts"], run["ct_parts"]):
                res[ep["inst"]] = {"observations": ep["counts"]["kept"], "control_observations": cp["counts"]["kept"], "event_net_bp_mean": _fmean(ep["net_bp"]),
                                   "control_net_bp_mean": _fmean(cp["net_bp"]), "point": _point(ep["net_bp"], cp["net_bp"])}
            out[name] = res
        elif name == "by_sign":
            res = {}
            for label, value in (("long", 1), ("short", -1)):
                ev = [{"inst": p["inst"], "instants_us": p["instants_us"][p["sign"] == value], "net_bp": p["net_bp"][p["sign"] == value]} for p in run["ev_parts"]]
                ct = [{"inst": p["inst"], "instants_us": p["instants_us"][p["sign"] == value], "net_bp": p["net_bp"][p["sign"] == value]} for p in run["ct_parts"]]
                ets, ev_v, _ = instant_means(ev, "net_bp", spec.unit["min_instruments"])
                cts, ct_v, _ = instant_means(ct, "net_bp", spec.unit["min_instruments"])
                res[label] = _d_summary(ets, ev_v, cts, ct_v)
            out[name] = res
        elif name == "regime_rv24_above_median":
            out[name] = report_regime_rv24(run, spec)
        elif name == "instruments_per_instant_ge_3":
            out[name] = _subset_d(run, inst["ev_n"] >= 3, inst["ct_n"] >= 3)
        elif name == "excluded_share_by_instrument":
            out[name] = {p["inst"]: {k: (v / p["counts"]["instants"]) if p["counts"]["instants"] else None for k, v in p["counts"].items() if k not in ("instants",)}
                         for p in run["ev_parts"]}
        elif name == "block_days_7":
            st = boot(spec, inst["ev_ts"], inst["ev"], inst["ct_ts"], inst["ct"], block_days=spec.statistics["sensitivity_block_days"])
            out[name] = {"block_days": spec.statistics["sensitivity_block_days"], "lb90": st.get("lb90"), "ub90": st.get("ub90"), "blocks": st.get("blocks"),
                         "note": "sensitivity only: no p-value"}
        elif name == "portfolio_reading":
            out[name] = report_portfolio(run, spec, calendar_days)
    return out


# ----------------------------------------------------------------------------- the study


def _missing_share(series: Series, raw: np.ndarray, tspec: TypeSpec) -> float:
    if raw.size == 0:
        return 0.0
    k0 = series.index_of(raw)
    ks = (k0[:, None] + np.arange(tspec.footprint[0], tspec.footprint[1] + 1)[None, :]).ravel()
    return float(np.mean(~np.isfinite(series.at(series.close, ks))))


def _proxy_missing(series_by_inst: dict, raw_by_inst: dict, tspec: TypeSpec) -> tuple[int, int]:
    """Instrument-days (days with >= 1 raw event) on which P̂ is computable at none of the day's raw events."""
    missing, total = 0, 0
    for inst, s in series_by_inst.items():
        raw = raw_by_inst.get(inst, np.zeros(0, dtype=np.int64))
        if raw.size == 0:
            continue
        ok = np.isfinite(s.p_hat(s.index_of(raw) + tspec.arm_min, tspec.classifier))
        days = raw // DAY_US
        for d in np.unique(days).tolist():
            total += 1
            missing += int(not ok[days == d].any())
    return missing, total


def _calendar_days(spec: Spec, tspec: TypeSpec, series_by_inst: dict, d_cov_global: int | None, window: tuple[int, int] | None = None) -> int:
    """Denominator of fills / day (LP-G5 §7.6): calendar days of D_cov (T01), NYSE days of the window with >= 1 eligible instrument
    at h, macro days included (T05). Capture: the type's window [T0 ; end) (`economic.fills_per_day`)."""
    if spec.source == "capture" and window is not None:
        lo, hi = window
        if tspec.generator["kind"] == "local_time":
            eligible = [s.meta.eligible_from_us for s in series_by_inst.values()]
            return sum(1 for m, _, _ in local_time_instants(tspec, spec, lo, hi, apply_exclusions=False) if any(m + tspec.footprint[0] * MINUTE_US >= e for e in eligible))
        return int((hi - lo) // DAY_US)
    if tspec.window_rule == "funding_coverage":
        start = d_cov_global
        if start is None:
            known = [s.d_cov_start_us for s in series_by_inst.values() if s.d_cov_start_us is not None]
            start = min(known) if known else None
        return 0 if start is None else int((spec.end_us - (int(start) // DAY_US) * DAY_US) // DAY_US)
    if tspec.generator["kind"] == "local_time":
        eligible = [s.meta.eligible_from_us for s in series_by_inst.values()]
        return sum(1 for m, _, _ in local_time_instants(tspec, spec, apply_exclusions=False) if any(m + tspec.footprint[0] * MINUTE_US >= e for e in eligible))
    days = set()
    for s in series_by_inst.values():
        days |= set((np.arange(max(spec.start_us, s.meta.eligible_from_us), spec.end_us, DAY_US) // DAY_US).tolist())
    return len(days)


def _sum_counts(parts: list[dict]) -> dict:
    total: dict = {}
    for p in parts:
        for key, value in p["counts"].items():
            total[key] = total.get(key, 0) + int(value)
    return total


def evaluate_type(series_by_inst: dict, tspec: TypeSpec, spec: Spec, common: dict) -> dict:
    """Instants, restrictions, controls and measures of one type on every instrument; the instant-level arrays. Capture: the type's
    window [T0 ; end) of `common['type_window']` (the data-gap extension applied by `run_study`)."""
    window = (common.get("type_window") or {}).get(tspec.name)
    if tspec.window_rule == "funding_coverage" and common.get("d_cov_global_us") is not None:
        window = (int(common["d_cov_global_us"]), spec.end_us)  # LP-G5 §4.4: T01 entier se restreint à D_cov (the registry's single window)
    events, controls, raw_all, other, by_inst = {}, {}, {}, {}, {}
    excluded_instruments = []
    gen_totals, restrict_totals, control_totals = {}, {}, {"candidates": 0, "kept": 0, "excluded_union": 0, "excluded_by_component": {}, "restriction": {}}
    for inst, s in series_by_inst.items():
        instants, gdiag, other_i = generator_instants(s, tspec, spec, common)
        raw, rdiag = restrict(s, instants, tspec, spec, window)
        other[inst], _ = restrict(s, other_i, tspec, spec, window)
        raw_all[inst] = raw
        for key, value in gdiag.items():
            gen_totals[key] = gen_totals.get(key, 0) + int(value)
        for key, value in rdiag.items():
            restrict_totals[key] = restrict_totals.get(key, 0) + int(value)
        share = _missing_share(s, raw, tspec)
        entry = {"generator": gdiag, "restriction": rdiag, "missing_share_event_footprints": share}
        if raw.size and share > spec.price["max_missing_share"]:
            excluded_instruments.append(inst)
            entry["excluded"] = f"missing share {share:.3f} > {spec.price['max_missing_share']:g} of the event-footprint minutes"
            by_inst[inst] = entry
            continue
        union = exclusion_intervals(s, tspec, spec, common)
        ct, cdiag = control_instants(s, tspec, spec, raw, union, window)
        events[inst], controls[inst] = raw, ct
        for key in ("candidates", "kept", "excluded_union"):
            control_totals[key] += int(cdiag[key])
        for key, value in cdiag["excluded_by_component"].items():
            control_totals["excluded_by_component"][key] = control_totals["excluded_by_component"].get(key, 0) + int(value)
        for key, value in cdiag["restriction"].items():
            control_totals["restriction"][key] = control_totals["restriction"].get(key, 0) + int(value)
        entry["controls"] = cdiag
        by_inst[inst] = entry
    used = {i: s for i, s in series_by_inst.items() if i in events}
    ev_parts = [measure(s, events[i], tspec, spec, "event") for i, s in used.items()]
    ct_parts = [measure(s, controls[i], tspec, spec, "control") for i, s in used.items()]
    mi = spec.unit["min_instruments"]
    ev_ts, ev_v, ev_n = instant_means(ev_parts, "net_bp", mi)
    ev_ts_s, ev_s, _ = instant_means(ev_parts, "net_stress_bp", mi)
    ct_ts, ct_v, ct_n = instant_means(ct_parts, "net_bp", mi)
    raw_instants = np.unique(np.concatenate([v for v in raw_all.values()])) if raw_all else np.zeros(0, dtype=np.int64)
    raw_obs = int(sum(v.size for v in raw_all.values()))
    kept_obs = int(sum(p["counts"]["kept"] for p in ev_parts))
    attempted = {side: sum(p["fill"]["attempted"] for p in parts) for side, parts in (("events", ev_parts), ("controls", ct_parts))}
    filled = {side: sum(p["fill"]["filled"] for p in parts) for side, parts in (("events", ev_parts), ("controls", ct_parts))}
    vol_field = spec.frozen_candle["count_field"]
    vol_share = {p["inst"]: (p["counts"].get(vol_field, 0) / p["counts"]["instants"]) if p["counts"]["instants"] else None for p in ev_parts}
    return {"tspec": tspec, "series": used, "events": events, "controls": controls, "raw": raw_all, "other_interval": other, "ev_parts": ev_parts, "ct_parts": ct_parts,
            "instant": {"ev_ts": ev_ts, "ev": ev_v, "ev_stress": ev_s, "ev_n": ev_n, "ct_ts": ct_ts, "ct": ct_v, "ct_n": ct_n},
            "exclusions": {"generator": gen_totals, "restriction": restrict_totals, "instruments_excluded_missing_share": excluded_instruments,
                           "event": _sum_counts(ev_parts), "control": _sum_counts(ct_parts), "controls": control_totals,
                           "raw_instrument_instants": raw_obs, "raw_instants": int(raw_instants.size), "kept_instrument_instants": kept_obs,
                           "f_obs": (kept_obs / raw_obs) if raw_obs else None, "f_inst": (int(ev_ts.size) / int(raw_instants.size)) if raw_instants.size else None,
                           "control_funding_nonzero_anomalies": int(sum(p["funding_nonzero"] for p in ct_parts)),
                           f"{vol_field}_share_by_instrument": vol_share if spec.frozen_candle["applies"] else None,
                           "by_instrument": by_inst},
            "fill_rate": {side: {"attempted": attempted[side], "filled": filled[side], "rate": (filled[side] / attempted[side]) if attempted[side] else None} for side in attempted}
            if tspec.fill_proxy is not None or spec.source == "capture" else None,
            "macro_excluded": common["macro_excluded"].get(tspec.name, np.zeros(0, dtype=np.int64)), "common": common, "window": window}


def _instruments_per_instant(counts: np.ndarray) -> dict:
    if counts.size == 0:
        return {"instants": 0}
    values, freq = np.unique(counts, return_counts=True)
    return {"instants": int(counts.size), "distribution": {str(int(v)): int(f) for v, f in zip(values.tolist(), freq.tolist())}, "share_ge_3": float(np.mean(counts >= 3))}


def _by_hour(run: dict) -> dict:
    inst = run["instant"]
    hours = (inst["ev_ts"] % DAY_US) // HOUR_US
    return {f"{h:02d}": _subset_d(run, hours == h, _same_days(run, hours == h)) for h in sorted(set(hours.tolist()))}


def _gate2_context(series_by_inst: dict, spec: Spec, context: dict, device_global: list) -> dict:
    """(B) xxi — the Gate 2 study conditions: the inscription read from the Gate 1 result (`gate2_inscription_from_gate1_result`), the
    owner decisions still pending (global INCONCLUSIVE), the unusable instrument-days (`quality`; share > max ⇒ global INCONCLUSIVE),
    the days lost by every instrument and the data-gap extension of each type window, the index availability (P-G5-10 (ii))."""
    from . import g5_gate2  # lazy: g5_gate2 imports this module

    verdicts1 = context.get("gate1_verdicts")
    if verdicts1 is None:
        raise ValueError("a Gate 2 study needs context['gate1_verdicts'] (the Gate 1 result, gate2_inscription.input)")
    inscription = g5_gate2.inscription_of(spec, verdicts1)
    if spec.gate2["pending"]:
        device_global.append(f"owner_decision_pending_present: {spec.gate2['pending']} (owner_decisions.rule: replaced by the owner's dated choice before FROZEN)")
    t0_day, end_day = int(spec.gate2["t0_us"] // DAY_US), max(int(g5_gate2.type_window(spec, t)[1] // DAY_US) for t in spec.types)
    unusable = {i: set(s.coverage.get("unusable_days") or ()) for i, s in series_by_inst.items()}
    scored = bad = 0
    for i, s in series_by_inst.items():  # instrument-days of the base windows on which the instrument is eligible the whole day
        days = [d for d in range(t0_day, end_day) if d * DAY_US >= s.meta.eligible_from_us]
        scored += len(days)
        bad += sum(1 for d in days if d in unusable[i])
    share = (bad / scored) if scored else None
    share_max = spec.verdict["unusable_share_max"]
    if share is not None and share > share_max:
        device_global.append(f"unusable_instrument_day_share_gt: {bad} of {scored} instrument-days unusable > {share_max:g} (quality)")
    lost = []
    for d in range(t0_day, end_day):
        eligible = [i for i, s in series_by_inst.items() if s.meta.eligible_from_us <= d * DAY_US]
        if eligible and all(d in unusable[i] for i in eligible):
            lost.append(d)
    extension = min(len(lost), spec.gate2["max_gap_days"])
    windows = {t.name: g5_gate2.type_window(spec, t, extension) for t in spec.types}
    index_minutes = sum(int(s.coverage.get("index_minutes", 0)) for s in series_by_inst.values())
    choice = spec.gate2["index_choice"]
    index_state = "unresolved" if choice == g5_gate2.PENDING else ("available" if index_minutes > 0 else "unavailable")
    index_block = {"choice": choice, "state": index_state, "minutes": index_minutes}
    if choice == g5_gate2.INDEX_CHOICE_D10:  # D10: the recorded choice (checked by run_study before this point) and the source used here
        selection = context["index_selection"]
        index_block.update({"source_used": context.get("index_role_source"), "role": context.get("index_role", "primary"), "primary": selection["primary"],
                            "sensitivity": selection["sensitivity"], "selection_sha256": selection["sha256"], "selection": selection})
    return {"inscription": inscription, "windows": windows,
            "quality": {"instrument_days_scored": scored, "instrument_days_unusable": bad, "share": share, "share_max": share_max,
                        "days_lost_by_every_instrument": [tape.date_of_day(d) for d in lost], "extension_days": extension,
                        "extension_applied": {t.name: windows[t.name][1] != g5_gate2.type_window(spec, t)[1] for t in spec.types},
                        "extension_beyond_2027_03_31_allowed": spec.gate2["beyond_allowed"]},
            "index": index_block,
            "window_utc": {n: [_iso(a), _iso(b)] for n, (a, b) in windows.items()}, "t0_provisional": spec.gate2["t0_provisional"],
            "window_end_provisional": {t.name: t.gate2["window_end"]["provisional"] for t in spec.types}}


def _d10_choice() -> str:
    from . import g5_gate2  # lazy: g5_gate2 imports this module

    return g5_gate2.INDEX_CHOICE_D10


def _require_index_selection(series_by_inst: dict, spec: Spec, context: dict) -> None:
    """D10 (P-G5-10 (ii), owner 2026-10-21): refuse the study unless `context['index_selection']` is a recorded choice whose sha256, D10
    thresholds, conditions and window verify, and every series carries the source of its role (`index_role`: primary, or the reported
    sensitivity). Sets `context['index_role_source']`."""
    from . import g5_gate2  # lazy: g5_gate2 imports this module

    selection = context.get("index_selection")
    problems = g5_gate2.verify_index_selection(selection, spec)
    role = context.get("index_role", "primary")
    if role not in ("primary", "sensitivity"):
        problems.append(f"index_role {role!r}")
    if not problems:
        expected = selection["primary"] if role == "primary" else selection["sensitivity"]
        wrong = sorted(i for i, s in series_by_inst.items() if s.cache.get("index_source_used") != expected)
        if wrong:
            problems.append(f"series without the {role} index source {expected}: {wrong[:5]}")
        context["index_role_source"] = expected
    if problems:
        raise ValueError("D10: T01 is not evaluated without a recorded index choice (classifier_index_source.selection_rule.record): " + "; ".join(problems))


def run_study(series_by_inst: dict, spec: Spec, context: dict | None = None) -> dict:
    """Every type on every instrument: instants, controls, measures, instant-level statistics, C6, criteria, verdicts, Holm, the
    economic reading, the descriptive reports, the sealing. `context`: `device` (global INCONCLUSIVE conditions that hold, with
    their reasons), `d_cov_global_us` (the receipt's D_cov; None: unknown), `fetch_quality`, `mode` (`synthetic` | `tape`); Gate 2:
    `gate1_verdicts` (`{type: "G5_GATE1_…"}` of the Gate 1 result, required)."""
    context = dict(context or {})
    mode = context.get("mode", "synthetic")
    device_global = list(context.get("device", []))
    if "d_cov_global_us" in context:
        d_cov_global = context["d_cov_global_us"]
    else:
        known = [s.d_cov_start_us for s in series_by_inst.values() if s.d_cov_start_us is not None]
        d_cov_global = min(known) if known else None
    capture = spec.source == "capture"
    if capture and spec.gate2["index_choice"] == _d10_choice():
        _require_index_selection(series_by_inst, spec, context)  # D10: T01 is never evaluated without a recorded, verified choice
    common = common_instants(spec)
    common["d_cov_global_us"] = None if capture else d_cov_global
    g2 = _gate2_context(series_by_inst, spec, context, device_global) if capture else None
    if g2 is not None:
        common["type_window"] = g2["windows"]
    runs, pre = {}, {}
    for tspec in spec.types:
        if g2 is not None and not g2["inscription"][tspec.name]["evaluated"]:
            pre[tspec.name] = {"verdict": "NOT_EVALUATED", "reasons": [f"Gate 1 verdict {g2['inscription'][tspec.name]['gate1_verdict']}: {g2['inscription'][tspec.name]['rule']}"],
                               "criteria": {}, "diff": {}, "validation": None, "proxy": None, "n_instants": 0, "n_days": 0}
            continue
        run = evaluate_type(series_by_inst, tspec, spec, common)
        runs[tspec.name] = run
        device = list(device_global)
        validation = None
        if tspec.validation is not None:  # on the raw instrument-settlements of every instrument (an instrument left out for missing prices included)
            validation = validation_c6(series_by_inst, run["raw"], tspec, spec)
        proxy = None
        if tspec.proxy_missing_share_max is not None:
            miss, total = _proxy_missing(series_by_inst, run["raw"], tspec)
            proxy = {"instrument_days_without_p_hat": miss, "instrument_days": total, "share": (miss / total) if total else None, "max": tspec.proxy_missing_share_max}
        for cond in tspec.inconclusive_if:
            if cond == "funding_coverage_unknown" and d_cov_global is None:
                device.append("funding_coverage_unknown: D_cov unknown (no sealed receipt coverage, or no instrument with a complete funding history)")
            elif cond == "C6_failed_or_not_computable" and not (validation and validation["pass"]):
                v = validation or {}
                device.append(f"C6_failed_or_not_computable: sensitivity {v.get('sensitivity')} (min {tspec.validation['sensitivity_min']}), false positives "
                              f"{v.get('false_positive_rate')} (max {tspec.validation['false_positive_rate_max']}), denominators {v.get('denominator_rate_outside_zone')} / "
                              f"{v.get('denominator_rate_inside_zone')} (min {tspec.validation['min_denominator']})")
            elif PROXY_MISSING_RE.match(cond) and proxy is not None and (proxy["share"] is None or proxy["share"] > proxy["max"]):
                device.append(f"{cond}: {proxy['instrument_days_without_p_hat']} of {proxy['instrument_days']} instrument-days without P_hat")
            elif cond == "classifier_index_source_unresolved_or_unavailable" and g2 is not None and g2["index"]["state"] != "available":
                device.append(f"classifier_index_source_unresolved_or_unavailable: P-G5-10 (ii) {g2['index']['choice']} ({g2['index']['state']}): "
                              f"{spec.raw['classifier_index_source']['if_unresolved_or_unavailable']}")
        inst = run["instant"]
        n_instants, n_days = int(inst["ev_ts"].size), int(np.unique(inst["ev_ts"] // DAY_US).size)
        criteria, diff = criteria_for(spec, tspec, inst, validation)
        if device:
            verdict, reasons = "INCONCLUSIVE", device
        else:
            reasons = []
            if n_instants < spec.power["min_instants"]:
                reasons.append(f"n_instants={n_instants} < min_instants={spec.power['min_instants']}")
            if n_days < spec.power["min_days"]:
                reasons.append(f"n_days={n_days} < min_days={spec.power['min_days']}")
            if diff.get("point") is None or diff.get("lb90") is None:
                reasons.append("no statistic (no control instant, or fewer than two day blocks)")
            by_construction = (spec.audit.get("power_by_construction") or {}).get(tspec.name) if capture else None
            if by_construction and by_construction["not_informative_by_construction"]:  # Gate 2 T05 under D7: stated, never hidden
                reasons.append(f"power_floor_unreachable_by_construction: at most {by_construction['raw_instants_upper_bound']} instants in the frozen window "
                               f"(window_end {by_construction['window_end_choice']}) < min_instants={by_construction['min_instants']} ; written in advance "
                               f"(P-G5-10 (i){', owner decision D7 of 2026-10-21' if by_construction['window_end_choice'] == 'a_end_2027_03_31' else ''}) ; "
                               "says nothing for or against an effect")
            verdict = "NOT_INFORMATIVE" if reasons else "PENDING"
        pre[tspec.name] = {"verdict": verdict, "reasons": reasons, "criteria": criteria, "diff": diff, "validation": validation, "proxy": proxy, "n_instants": n_instants, "n_days": n_days}
    pvalues = {name: (pre[name]["diff"].get("p_one_sided") if pre[name]["verdict"] == "PENDING" else 1.0) for name in spec.family}
    family = holm(pvalues, spec.multiplicity["alpha"])
    types_out, sealed_payloads = {}, {}
    for tspec in spec.types:
        name, p = tspec.name, pre[tspec.name]
        test = family["tests"].get(name, {})
        if p["verdict"] == "NOT_EVALUATED":
            types_out[name] = {"type": name, "label": tspec.label, "decisive": True, "role": tspec.role, "verdict": "NOT_EVALUATED", "verdict_label": spec.gate + "NOT_EVALUATED",
                               "verdict_reasons": p["reasons"], "n_instants": 0, "n_days": 0, "gate1_inscription": g2["inscription"][name],
                               "holm": {"p_used": pvalues.get(name), "rank": test.get("rank"), "threshold": test.get("threshold"), "confirmed": False},
                               "holm_local_confirmed": False, "pass_programme_immediate": False, "reading": spec.gate + "NOT_EVALUATED", "fail_annotation": None, "closure_scope": None}
            continue
        run = runs[name]
        holm_ok = bool(test.get("confirmed")) and p["verdict"] == "PENDING"
        all_criteria = all(c["pass"] for c in p["criteria"].values())
        annotation = None
        if p["verdict"] == "PENDING":
            verdict = "PASS" if all_criteria and holm_ok else "FAIL"
            failed = [k for k, c in p["criteria"].items() if not c["pass"]]
            reasons = (["every criterion of the type holds", "holm_local_confirmed"] if verdict == "PASS" else
                       [f"{k} fails" for k in failed] + ([] if failed or holm_ok else ["non confirmé par Holm"]))
            if verdict == "FAIL":
                ub = p["diff"].get("ub90")
                annotation = spec.verdict["annotation_lt"] if ub is not None and ub < spec.statistics["threshold_bp"] else spec.verdict["annotation_ge"]
        else:
            verdict, reasons = p["verdict"], p["reasons"]
        p_value = p["diff"].get("p_one_sided")
        immediate = bool(verdict == "PASS" and p_value is not None and p_value <= spec.multiplicity["immediate_if_p_le"])
        calendar_days = _calendar_days(spec, tspec, run["series"], d_cov_global, run.get("window"))
        economic = economic_reading(run["ev_parts"], tspec, spec, calendar_days, verdict)
        descriptive = type_reports(run, spec, spec.descriptive["reports"], calendar_days, verdict)
        descriptive["instruments_per_instant"] = {"events": _instruments_per_instant(run["instant"]["ev_n"]), "controls": _instruments_per_instant(run["instant"]["ct_n"])}
        for report in spec.unit["reports"]:  # unit.report (B ix)
            if report == "instruments_per_instant_distribution":
                descriptive[report] = descriptive["instruments_per_instant"]
            elif report == "stratum_min_instruments_3":
                descriptive[report] = _subset_d(run, run["instant"]["ev_n"] >= 3, run["instant"]["ct_n"] >= 3)
        statistic = {"D": p["diff"].get("point"), "lb90": p["diff"].get("lb90"), "ub90": p["diff"].get("ub90"), "p_one_sided": p_value,
                     "event_mean": p["diff"].get("event_mean"), "control_mean": p["diff"].get("control_mean"), "n_control_instants": int(run["instant"]["ct_ts"].size),
                     "blocks": p["diff"].get("blocks"), "resamples": p["diff"].get("resamples"), "seed": p["diff"].get("seed"), "measure": spec.raw["statistics"]["measure"]}
        criteria = p["criteria"]
        result = {"type": name, "label": tspec.label, "decisive": True, "role": tspec.role, "costs_bp": type_cost_terms(tspec, spec.costs), "days_per_year": tspec.days_per_year,
                  "window_rule": tspec.window_rule, "verdict": verdict, "verdict_label": spec.gate + verdict, "verdict_reasons": reasons, "n_instants": p["n_instants"],
                  "n_days": p["n_days"], "exclusions": {**run["exclusions"], "proxy_missing": p["proxy"]}, "fill_rate": run["fill_rate"], "validation": p["validation"],
                  "fail_annotation": annotation, "closure_scope": spec.verdict["closure_scope"] if verdict == "FAIL" else None,
                  "holm": {"p_used": pvalues.get(name), "rank": test.get("rank"), "threshold": test.get("threshold"), "confirmed": bool(test.get("confirmed"))},
                  "holm_local_confirmed": holm_ok, "pass_programme_immediate": immediate, "reading": economic["reading"],
                  "statistic": statistic, "criteria": criteria, "halves": criteria.get("C4", {}).get("halves"), "c5": criteria.get("C5"), "c7": criteria.get("C7"),
                  "by_month": descriptive.get("by_month"), "by_instrument": descriptive.get("by_instrument"), "by_hour": _by_hour(run), "economic": economic,
                  "descriptive": descriptive}
        if capture:
            result["gate1_inscription"] = g2["inscription"][name]
            result["window_utc"] = g2["window_utc"][name]
            result["sim_status"] = {side: _sum_status(run[f"{side}_parts"]) for side in ("ev", "ct")}
        else:
            result["gate2_inscription"] = spec.gate2_inscription[verdict]
        if verdict in spec.sealing["verdicts"]:
            public, sealed = _seal(result, spec)
            text = json.dumps(_clean(sealed), indent=1, sort_keys=True, ensure_ascii=False) + "\n"
            public["sealed"] = {"file": f"sealed/{name}.json", "sha256": g5_fetch.sha256_text(text), "open_after": spec.sealing["open_after"]}
            sealed_payloads[name] = text
            types_out[name] = public
        else:
            types_out[name] = result
    common_out = common_reports(series_by_inst, spec, spec.descriptive["reports"], (g2 or {}).get("windows"))
    summary = {"verdicts": {n: r["verdict_label"] for n, r in types_out.items()}, "holm_local_confirmed": [n for n, r in types_out.items() if r["holm_local_confirmed"]],
               "pass_programme_immediate": [n for n, r in types_out.items() if r["pass_programme_immediate"]],
               "readings": {n: r["reading"] for n, r in types_out.items()},
               "sealed_sha256": {n: r["sealed"]["sha256"] for n, r in types_out.items() if "sealed" in r}, "global_device_defects": device_global, "mode": mode,
               "gate2_inscription_discretion": spec.notes.get("gate2_inscription.discretion"), "pass_programme_otherwise": spec.multiplicity["otherwise"]}
    if capture:
        summary["gate1_inscription"] = {n: g2["inscription"][n] for n in types_out}
        summary["gate2"] = {k: g2[k] for k in ("quality", "index", "window_utc", "t0_provisional", "window_end_provisional")}
    else:
        summary["gate2_inscription"] = {n: r["gate2_inscription"] for n, r in types_out.items()}
    holm_public = {"alpha": family["alpha"], "m": family["m"], "tests": {n: {k: v for k, v in t.items() if k != "p" or n not in sealed_payloads} for n, t in family["tests"].items()}}
    document = {"types": types_out, "holm": holm_public, "summary": summary, "common_descriptive": common_out, "fetch_quality": context.get("fetch_quality"),
                "d_cov_global": _iso(d_cov_global), "_sealed_payloads": sealed_payloads}
    check_forbidden(document, spec)
    return document


def _sum_status(parts: list[dict]) -> dict:
    total: dict = {}
    for p in parts:
        for key, value in (p.get("sim_status") or {}).items():
            total[key] = total.get(key, 0) + int(value)
    return total


def _seal(result: dict, spec: Spec) -> tuple[dict, dict]:
    """(B) xx — the printed part of a sealed type (`sealing.printed` + the type's identity, inscription and the p = 1 used in Holm) and
    the sealed part (`sealing.sealed`)."""
    printed_map = {"verdict": ("verdict", "verdict_label"), "reasons": ("verdict_reasons",), "n_instants": ("n_instants",), "n_days": ("n_days",),
                   "exclusion_counts": ("exclusions",), "fill_rate": ("fill_rate",), "validation_matrix": ("validation",)}
    sealed_map = {"D": ("statistic", "D"), "lb90": ("statistic", "lb90"), "ub90": ("statistic", "ub90"), "p_one_sided": ("statistic", "p_one_sided"),
                  "event_mean": ("statistic", "event_mean"), "control_mean": ("statistic", "control_mean"), "criteria_values": ("criteria",), "halves": ("halves",), "c5": ("c5",),
                  "c7": ("c7",), "by_month": ("by_month",), "by_instrument": ("by_instrument",), "by_hour": ("by_hour",), "economic": ("economic",), "type_descriptives": ("descriptive",)}
    public = {k: result[k] for k in ("type", "label", "decisive", "role", "costs_bp", "days_per_year", "window_rule", "gate2_inscription")}
    for name in spec.sealing["printed"]:
        for key in printed_map[name]:
            public[key] = result[key]
    public["holm"] = {"p_used": 1.0, "confirmed": False, "note": "p = 1 for a sealed type (GATES 2.1)"}
    public["holm_local_confirmed"] = False
    public["pass_programme_immediate"] = False
    public["fail_annotation"] = None
    public["closure_scope"] = None
    public["reading"] = result["verdict_label"]
    sealed = {"type": result["type"], "verdict_label": result["verdict_label"]}
    for name in spec.sealing["sealed"]:
        path = sealed_map[name]
        value = result[path[0]]
        sealed[name] = value.get(path[1]) if len(path) > 1 and isinstance(value, dict) else value
    sealed["statistic"] = result["statistic"]
    return public, sealed


def check_forbidden(document, spec: Spec, where: str = "document") -> None:
    """(B) xvi — no key named in `multiplicity.forbidden_field_names` anywhere in the output."""
    forbidden = set(spec.multiplicity["forbidden"])
    stack = [(document, where)]
    while stack:
        value, path = stack.pop()
        if isinstance(value, dict):
            for key, item in value.items():
                if key in forbidden:
                    raise RuntimeError(f"forbidden field {key!r} at {path} (multiplicity.forbidden_field_names)")
                stack.append((item, f"{path}.{key}"))
        elif isinstance(value, (list, tuple)):
            stack.extend((item, f"{path}[{i}]") for i, item in enumerate(value))


# ----------------------------------------------------------------------------- reports


def _fmt(value, digits: int = 2, signed: bool = True) -> str:
    if value is None:
        return "—"
    return format(float(value), f"+.{digits}f" if signed else f".{digits}f")


A1_ROW_LABELS = {"A_signal": "A (signal)", "B_execution": "B (exécution)", "C_net": "C (net)"}
A1_SIDE_LABELS = {"events": "événements", "controls": "contrôles", "events_minus_controls": "événements − contrôles"}


def render_a1(descriptive: dict) -> list[str]:
    """A1 — the A / B / C table and the diagnostic classification as Markdown (an unsealed type only; reported, never decisive)."""
    abc = descriptive.get("abc_signal_execution_net")
    if not isinstance(abc, dict) or "A_signal" not in abc:
        return []
    lines = ["### Amendement A1 — signal (A), exécution (B), net (C) : rapporté, jamais décisif", "",
             f"Coûts du type : {json.dumps(abc.get('costs_bp'), ensure_ascii=False)} ; conditionnement : {abc.get('fill_conditioning')}.", "",
             "| ligne | côté | n instants | n instrument-instants | taux de fill | moyenne (pb) | médiane (pb) | LB90 | UB90 |", "|---|---|---|---|---|---|---|---|---|"]
    for row in ("A_signal", "B_execution", "C_net"):
        for side in ("events", "controls", "events_minus_controls"):
            r = abc[row].get(side) or {}
            if side == "events_minus_controls":
                cells = [str(r.get("n_instants")), "—", "—", _fmt(r.get("point")), _fmt(r.get("median_difference")), _fmt(r.get("lb90")), _fmt(r.get("ub90"))]
            else:
                rate = r.get("fill_rate")
                cells = [str(r.get("n_instants")), str(r.get("n_instrument_instants")), "—" if rate is None else _fmt(rate, 3, signed=False), _fmt(r.get("mean")),
                         _fmt(r.get("median")), _fmt(r.get("lb90")), _fmt(r.get("ub90"))]
            lines.append(f"| {A1_ROW_LABELS[row]} | {A1_SIDE_LABELS[side]} | " + " | ".join(cells) + " |")
    portfolio = abc["C_net"].get("portfolio")
    if portfolio:
        lines += ["", f"Lecture portefeuille (C) : {json.dumps(portfolio, ensure_ascii=False)}."]
    cls = descriptive.get("diagnostic_classification_a1")
    if isinstance(cls, dict) and "class" in cls:
        lines += ["", f"**Classification diagnostique (rapportée, jamais un verdict) : {cls['label']}** — règle : {cls['rule']} ; "
                      f"conditions : {json.dumps(cls['conditions'], ensure_ascii=False)}."]
    return lines + [""]


def render_markdown(document: dict) -> str:
    spec = document["spec"]
    rule = spec["descriptive_rule"]
    lines = [f"# G5 — `{spec['spec_id']}` ({spec['source']}) — {EVALUATOR_VERSION}", "", f"> **Rapports descriptifs** : {rule}", "",
             "> `GATES` §9.7 interdit de promouvoir un descriptif en test, de baisser un seuil ou d'allonger une fenêtre après cette lecture.", "",
             f"Généré le {document['generated_at']} ; spec sha256 `{spec['sha256']}` (statut `{spec['status']}`) ; digest du code `{document.get('code_digest')}` ; "
             f"fenêtre {spec['window'][0]} → {spec['window'][1]} (exclu) ; instruments : {len(document.get('instruments', []))} ; D_cov global : {document.get('d_cov_global')} ; "
             f"mode : {document['summary']['mode']}.", "",
             "Aucun paramètre n'a été recherché : signes, fenêtres, classifieur, contrôles, coûts et seuils viennent du spec.", "",
             "## Verdicts", "", f"| type | verdict | raisons | n instants | jours | D (pb) | LB90 | UB90 | p | Holm local | PASS_PROGRAMME immédiat | "
             f"{'inscription (résultat du Gate 1)' if spec['source'] == 'capture' else 'Gate 2'} | lecture |",
             "|---|---|---|---|---|---|---|---|---|---|---|---|---|"]
    for name, r in document["types"].items():
        sealed = "sealed" in r
        st = r.get("statistic", {})
        cells = ["scellé"] * 4 if sealed else [_fmt(st.get("D")), _fmt(st.get("lb90")), _fmt(st.get("ub90")), _fmt(st.get("p_one_sided"), 3, signed=False)]
        lines.append(f"| `{name}` | **{r['verdict_label']}**{(' (' + r['fail_annotation'] + ')') if r.get('fail_annotation') else ''} | {' ; '.join(r['verdict_reasons'])} | "
                     f"{r['n_instants']} | {r['n_days']} | {' | '.join(cells)} | {'oui' if r['holm_local_confirmed'] else 'non'} | {'oui' if r['pass_programme_immediate'] else 'non'} | "
                     f"{r.get('gate2_inscription') or (r.get('gate1_inscription') or {}).get('rule')} | {r['reading']} |")
    lines += ["", "`holm_local_confirmed` est la confirmation Holm locale (K = 2) ; `pass_programme_immediate` le test immédiat du programme (p ≤ α / m_max) : "
              "il n'a aucun effet sur l'inscription au Gate 2.", ""]
    for name, r in document["types"].items():
        lines += [f"## `{name}`", ""]
        if r.get("verdict") == "NOT_EVALUATED":
            lines += [f"Type **non évalué** : {' ; '.join(r['verdict_reasons'])} (p = 1 au Holm local).", ""]
            continue
        if "sealed" in r:
            lines += [f"Type **scellé** ({r['verdict_label']}) : D, LB90, UB90, p, moyennes, critères et descriptifs du type sont dans `{r['sealed']['file']}`, "
                      f"sha256 `{r['sealed']['sha256']}`, ouvert après {r['sealed']['open_after']}.", ""]
        lines += [f"- Rôle `{r['role']}`, coûts {json.dumps(r['costs_bp'])}, jours/an {r['days_per_year']:g}, règle de fenêtre `{r['window_rule']}`.",
                  f"- Taux de fill : {json.dumps(r.get('fill_rate'))}.",
                  f"- Validation C6 : {json.dumps(r.get('validation'), ensure_ascii=False)}.",
                  f"- Exclusions : {json.dumps({k: v for k, v in r['exclusions'].items() if k != 'by_instrument'}, ensure_ascii=False)}.", ""]
        if "sealed" not in r:
            lines += [f"- Statistique : {json.dumps(r['statistic'])}.", f"- Critères : {json.dumps(r['criteria'], ensure_ascii=False)}.",
                      f"- Lecture économique : {json.dumps(r['economic'], ensure_ascii=False)}.", ""]
            lines += render_a1(r["descriptive"])
            lines += ["### Descriptifs du type (aucune p-value, aucun verdict)", ""]
            for key, value in r["descriptive"].items():
                lines.append(f"- `{key}` : {json.dumps(value, ensure_ascii=False)[:1500]}")
            lines.append("")
    lines += ["## Descriptifs communs (aucune p-value, aucun verdict)", ""]
    for key, value in document.get("common_descriptive", {}).items():
        lines.append(f"- `{key}` : {json.dumps(value, ensure_ascii=False)[:1500]}")
    lines += ["", "## Dispositif", "", f"- Garde : {json.dumps(document.get('guard'))}.", f"- Qualité du fetch : {json.dumps(document.get('fetch_quality'), ensure_ascii=False)[:2000]}.",
              f"- Défauts globaux : {document['summary']['global_device_defects'] or 'aucun'}.", f"- Instantané : {json.dumps(document.get('instruments_snapshot'), ensure_ascii=False)}.",
              f"- Funding scellé : {json.dumps(document.get('funding_seal'), ensure_ascii=False)}.", ""]
    if spec["source"] == "capture":
        lines[-1:-1] = [f"- Résultat du Gate 1 : {json.dumps(document.get('gate1_result'), ensure_ascii=False)}.",
                        f"- Tape d'indice (P-G5-10 ii) : {json.dumps(document.get('index_tape'), ensure_ascii=False)}."]
    return "\n".join(lines)


REPO_ROOT = Path(__file__).resolve().parents[3]


def spec_summary(spec: Spec, sha: str, path: str) -> dict:
    protocol = REPO_ROOT / spec.protocol
    return {"path": path, "sha256": sha, "spec_id": spec.spec_id, "schema": SPEC_SCHEMA, "version": spec.version, "status": spec.status, "source": spec.source,
            "dataset_id": spec.dataset_id, "window": [spec.start.isoformat(), spec.end_exclusive.isoformat()], "requires": list(spec.requires),
            "descriptive_rule": spec.descriptive["rule"], "protocol": spec.protocol, "protocol_sha256": tape.sha256_of(protocol) if protocol.is_file() else None,
            "audit": spec.audit, "freeze_fill": {k: v for k, v in spec.freeze_fill.items() if k not in ("null", "absent")}, "notes": spec.notes}


# ----------------------------------------------------------------------------- command line


def add_parser(sub) -> None:
    parser = sub.add_parser("g5-eval", help="G5 Gate 1 / Gate 2 evaluation driven by the frozen g5-spec/2 JSON spec; JSON + Markdown + sealed files", allow_abbrev=False)
    parser.add_argument("--tape", required=True)
    parser.add_argument("--spec", required=True, help="the frozen machine spec (schema g5-spec/2)")
    parser.add_argument("--expect-spec-sha256", required=True, help="the sha256 of the frozen spec (LP-G5 §13): a different file gives INCONCLUSIVE without reading anything")
    parser.add_argument("--instruments-file", default="", help="the freeze snapshot (default freeze_fill.instruments_snapshot_path); its sha256 must equal the spec's")
    parser.add_argument("--expect-funding-seal-sha256", default="", help="optional: the sha256 of the sealed funding receipt (registry); the receipt must equal "
                                                                          "freeze_fill.funding_seal_receipt_sha256 in any case (refuse_run)")
    parser.add_argument("--out-json", required=True)
    parser.add_argument("--out-md", default="")
    parser.add_argument("--sealed-dir", default="", help="where sealed/<type>.json go (default: <dir of --out-json>/sealed)")
    gate2 = parser.add_argument_group("Gate 2 (source capture)")
    gate2.add_argument("--gate1-result", default="", help="the Gate 1 result JSON of g5-eval (gate2_inscription.input; never a file under sealed/)")
    gate2.add_argument("--expect-gate1-result-sha256", default="", help="its sha256 written in the register the day of the Gate 1 run")
    gate2.add_argument("--p3-counts-file", default="", help="the P3 counts selection file (sha256 = freeze_fill.p3_counts_selection_sha256)")
    gate2.add_argument("--index-tape", default="", help="P-G5-10 (ii) option b: the directory holding index_1m/<index_id> (default --tape)")
    gate2.add_argument("--expect-index-tape-sha256", default="", help="P-G5-10 (ii) option b: the register's sha256 of the index tape "
                                                                      "(g5_fetch.directory_digest of its index_1m/; required, refuse_run otherwise; CTO M3)")
    gate2.add_argument("--gate1-spec", default="", help="the FROZEN Gate 1 spec frozen with this one (default: frozen_with.spec in the repository); "
                                                       "its sha256 must equal freeze_fill.gate1_spec_sha256 (CTO M2)")
    parser.set_defaults(func=command_eval)


def _snapshot_rows(path: Path) -> dict:
    return {str(r["instId"]): r for r in xc.load_instruments_file(path) if isinstance(r, dict) and r.get("instId")}


def check_universe(spec: Spec, rows: dict) -> list[str]:
    """(B) xviii, vii — the spec's universe against the freeze snapshot: listTime, tickSz, ctVal, uly of every analysed instrument;
    the metadata rule (FUTURES, xperp, <BASE>-USD, listTime + days before the end) on the D1 list (analysed ∪ excluded)."""
    problems = []
    days_us = int(round(spec.eligibility_days * DAY_US))
    d1 = {i.inst_id for i in spec.universe} | {e["inst_id"] for e in spec.excluded}
    for meta in spec.universe:
        row = rows.get(meta.inst_id)
        if row is None:
            problems.append(f"{meta.inst_id}: absent from the snapshot")
            continue
        if xc._i(row.get("listTime"), 0) * 1000 != meta.list_time_us:
            problems.append(f"{meta.inst_id}: listTime differs")
        if xc._f(row.get("tickSz")) != meta.tick_sz or xc._f(row.get("ctVal")) != meta.ct_val:
            problems.append(f"{meta.inst_id}: tickSz / ctVal differ")
        if g5_fetch.index_id_of(row) != meta.index_id:
            problems.append(f"{meta.inst_id}: uly differs")
    for inst, row in rows.items():
        if inst not in d1:
            continue
        rule = (str(row.get("instType", "FUTURES")) == "FUTURES" and str(row.get("ruleType", "xperp")) == "xperp" and bool(xc.XPERP_RE.match(inst))
                and xc._i(row.get("listTime"), 0) * 1000 + days_us < spec.end_us)
        analysed = any(i.inst_id == inst for i in spec.universe)
        if rule != analysed:
            problems.append(f"{inst}: the metadata rule {'admits' if rule else 'refuses'} it but the spec {'analyses' if analysed else 'excludes'} it")
    return problems


def _write(path: Path, text: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(text)


def _finish(document: dict, args: argparse.Namespace, sealed_payloads: dict) -> None:
    out_json = Path(args.out_json)
    sealed_dir = Path(args.sealed_dir) if args.sealed_dir else out_json.parent / "sealed"
    for name, text in sealed_payloads.items():
        path = sealed_dir / f"{name}.json"
        _write(path, text)
        if g5_fetch.sha256_text(path.read_text()) != document["types"][name]["sealed"]["sha256"]:
            raise RuntimeError(f"sealed file {path}: sha256 differs from the one printed")
        document["types"][name]["sealed"]["path"] = str(path)
    public = _clean(document)
    _write(out_json, json.dumps(public, indent=1, sort_keys=True, ensure_ascii=False) + "\n")
    if args.out_md:
        _write(Path(args.out_md), render_markdown(public))


def command_eval(args: argparse.Namespace) -> int:
    from . import cli  # lazy: cli imports this module

    started = time.time()
    try:
        spec, spec_sha = load_spec(Path(args.spec), Path(args.gate1_spec) if getattr(args, "gate1_spec", "") else None)
    except SpecError as error:
        raise SystemExit(f"spec error: {error}") from None
    if spec.source == "capture":
        return _command_eval_capture(args, spec, spec_sha, started)
    for option in ("gate1_result", "expect_gate1_result_sha256", "p3_counts_file", "index_tape", "expect_index_tape_sha256", "gate1_spec"):
        if getattr(args, option, ""):
            raise SystemExit(f"--{option.replace('_', '-')} belongs to a capture (Gate 2) spec")
    guard = guard_window(spec)
    code_digest = cli.code_digest()
    base = {"kind": "lp-g5-eval", "evaluator": EVALUATOR_VERSION, "generated_at": tape.iso_now(), "spec": spec_summary(spec, spec_sha, str(args.spec)), "tape": str(args.tape),
            "guard": guard, "code": cli.code_identity(), "code_digest": code_digest}
    # Global device conditions decided before any market value is opened (B xviii): nothing is read when one holds.
    device = []
    if args.expect_spec_sha256.lower() != spec_sha:
        device.append(f"spec_sha256_mismatch: file {spec_sha}, expected {args.expect_spec_sha256}")
    if not spec.frozen:
        device.append(f"status_not_frozen: {spec.status} (market data are read under a FROZEN spec only)")
    if spec.freeze_fill["null"]:
        device.append(f"freeze_fill_null: {spec.freeze_fill['null']}")
    if spec.freeze_fill.get("code_digest_sha256") != code_digest:
        device.append(f"code_digest_mismatch: running {code_digest}, frozen {spec.freeze_fill.get('code_digest_sha256')}")
    if device:
        types = {t.name: {"type": t.name, "verdict": "INCONCLUSIVE", "verdict_label": spec.gate + "INCONCLUSIVE", "verdict_reasons": device, "n_instants": 0, "n_days": 0,
                          "exclusions": {}, "fill_rate": None, "validation": None, "gate2_inscription": spec.gate2_inscription["INCONCLUSIVE"], "holm_local_confirmed": False,
                          "pass_programme_immediate": False, "reading": spec.gate + "INCONCLUSIVE", "market_data_read": False} for t in spec.types}
        document = {**base, "types": types, "summary": {"verdicts": {n: r["verdict_label"] for n, r in types.items()}, "global_device_defects": device, "market_data_read": False,
                                                        "mode": "tape"}}
        check_forbidden(document, spec)
        _write(Path(args.out_json), json.dumps(_clean(document), indent=1, sort_keys=True, ensure_ascii=False) + "\n")
        print(json.dumps({"spec_sha256": spec_sha, "verdicts": document["summary"]["verdicts"], "market_data_read": False, "device": device}, ensure_ascii=False))
        return 2
    snapshot_path = Path(args.instruments_file or spec.freeze_fill["instruments_snapshot_path"])
    if not snapshot_path.exists() or tape.sha256_of(snapshot_path) != spec.freeze_fill["instruments_snapshot_sha256"]:
        raise SystemExit(f"instruments snapshot {snapshot_path}: missing or its sha256 differs from freeze_fill.instruments_snapshot_sha256 (mismatch_with_snapshot: refuse_run)")
    rows = _snapshot_rows(snapshot_path)
    problems = check_universe(spec, rows)
    if problems:
        raise SystemExit("universe against the snapshot (refuse_run): " + "; ".join(problems[:20]))
    tape_dir = Path(args.tape)
    manifest = tape.read_manifest(tape_dir)
    if manifest is None or manifest.get("mode") != "values":
        raise SystemExit(f"{tape_dir}: no values tape here")
    context: dict = {"mode": "tape", "device": []}
    seal = None
    if spec.source == "history":
        if manifest.get("kind") != g5_fetch.MANIFEST_KIND:
            raise SystemExit(f"{tape_dir}: not a {g5_fetch.MANIFEST_KIND} tape")
        src = manifest.get("source") or {}
        if src.get("dataset_id") not in (None, spec.dataset_id):
            raise SystemExit(f"{tape_dir}: dataset {src.get('dataset_id')!r} is not the spec's {spec.dataset_id!r}")
        if src.get("decision_ref") != spec.freeze_fill["decision_ref_p_g5_1"]:
            raise SystemExit(f"{tape_dir}: the fetch's decision_ref is not freeze_fill.decision_ref_p_g5_1")
        # CTO M4: the sealed funding receipt is required and must be the one frozen in freeze_fill (refuse_run, never a silent
        # INCONCLUSIVE); CTO m3: the sealed store is one archive, read in memory, its sha256, manifest and files equal to the receipt.
        receipt_path = tape_dir / g5_fetch.SEALED_RECEIPT_NAME
        archive_path = tape_dir / g5_fetch.SEALED_ARCHIVE_NAME
        if (tape_dir / g5_fetch.SEALED_DIR_NAME).exists():
            raise SystemExit(f"{tape_dir / g5_fetch.SEALED_DIR_NAME}: an open sealed store (refuse_run): {g5_fetch.SEALED_ARCHIVE_RULE}")
        if not receipt_path.exists() or not archive_path.exists():
            raise SystemExit(f"{tape_dir}: no sealed funding receipt or archive (refuse_run: freeze_fill.{FREEZE_FILL_SEAL_KEY}, P-G5-3, CTO M4)")
        receipt, receipt_sha = g5_fetch.read_receipt(receipt_path)
        if receipt_sha != spec.freeze_fill[FREEZE_FILL_SEAL_KEY]:
            raise SystemExit(f"funding receipt sha256 {receipt_sha} differs from freeze_fill.{FREEZE_FILL_SEAL_KEY} {spec.freeze_fill[FREEZE_FILL_SEAL_KEY]} (refuse_run)")
        if args.expect_funding_seal_sha256 and args.expect_funding_seal_sha256.lower() != receipt_sha:
            raise SystemExit(f"funding receipt sha256 {receipt_sha} differs from --expect-funding-seal-sha256 (refuse_run)")
        try:
            archive = g5_fetch.SealedArchive.open(archive_path)
        except (OSError, ValueError, tarfile.TarError) as error:
            raise SystemExit(f"{archive_path}: unreadable sealed archive ({error}) (refuse_run)") from None
        if (archive.sha256 != receipt.get("sealed_archive_sha256") or archive.manifest_sha256 != receipt.get("sealed_manifest_sha256")
                or archive.files_digest != receipt.get("sealed_files_digest")):
            raise SystemExit(f"{archive_path}: the sealed funding store differs from its receipt (archive, sealed manifest or files changed)")
        sealed_manifest = archive.manifest
        d_cov_global = receipt.get("coverage_start_us")
        seal = {"receipt_sha256": receipt_sha, "sealed_archive_sha256": archive.sha256, "sealed_manifest_sha256": receipt.get("sealed_manifest_sha256"),
                "coverage_start_utc": receipt.get("coverage_start_utc")}
        context["d_cov_global_us"] = d_cov_global
        states = dict(manifest.get("g5_streams") or {})
        states.update((sealed_manifest or {}).get("g5_streams") or {})
        keys = [f"{i.inst_id}:{s}" for i in spec.universe for s in ("candles_1m", "mark_1m", "funding_hist")] + sorted({f"{i.index_id}:index_1m" for i in spec.universe})
        bad = [k for k in keys if not (states.get(k) or {}).get("complete") or (states.get(k) or {}).get("end_reason") not in g5_fetch.COMPLETE_REASONS or (states.get(k) or {}).get("errors")]
        missing = [k for k in keys if (states.get(k) or {}).get("end_reason") is None]
        share = len(bad) / len(keys) if keys else 0.0
        context["fetch_quality"] = {"jobs": len(keys), "jobs_in_error": len(bad), "error_share": share, "end_reason_missing": len(missing)}
        threshold = spec.verdict["fetch_error_share_max"]
        if share > threshold:
            context["device"].append(f"fetch_job_error_share_gt: {len(bad)} of {len(keys)} jobs in error > {threshold:g} (§4.5)")
        if missing and "stream_end_reason_missing" in spec.verdict["conditions"]:
            context["device"].append(f"stream_end_reason_missing: {len(missing)} jobs without end_reason")
        series = {i.inst_id: load_history_series(tape_dir, i, spec, manifest, archive) for i in spec.universe}
    else:
        if manifest.get("kind") == g5_fetch.MANIFEST_KIND:
            raise SystemExit(f"{tape_dir}: a 1-minute history is not a capture tape")
        if tape.parse_iso_utc(str(manifest.get("not_before") or "1970-01-01T00:00:00Z")) < QUARANTINE_END_US:
            raise SystemExit(f"{tape_dir}: a capture tape without the quarantine floor")
        series = {i.inst_id: load_capture_series(tape_dir, i, spec, rows.get(i.inst_id)) for i in spec.universe}
        context["d_cov_global_us"] = spec.pad_start_us
    study = run_study(series, spec, context)
    sealed_payloads = study.pop("_sealed_payloads")
    document = {**base, **study, "instruments": [i.inst_id for i in spec.universe], "instruments_snapshot": {"path": str(snapshot_path), "sha256": tape.sha256_of(snapshot_path)},
                "funding_seal": seal, "coverage": {i: s.coverage for i, s in series.items()}, "elapsed_seconds": round(time.time() - started, 1)}
    _finish(document, args, sealed_payloads)
    print(json.dumps({"spec_sha256": spec_sha, "code_digest": code_digest, "verdicts": study["summary"]["verdicts"], "sealed_sha256": study["summary"]["sealed_sha256"],
                      "gate2_inscription": study["summary"]["gate2_inscription"], "elapsed_seconds": document["elapsed_seconds"]}, ensure_ascii=False))
    return 0


def _refused_document(base: dict, spec: Spec, device: list) -> dict:
    types = {t.name: {"type": t.name, "verdict": "INCONCLUSIVE", "verdict_label": spec.gate + "INCONCLUSIVE", "verdict_reasons": device, "n_instants": 0, "n_days": 0,
                      "exclusions": {}, "fill_rate": None, "validation": None, "holm_local_confirmed": False, "pass_programme_immediate": False,
                      "reading": spec.gate + "INCONCLUSIVE", "market_data_read": False} for t in spec.types}
    document = {**base, "types": types, "summary": {"verdicts": {n: r["verdict_label"] for n, r in types.items()}, "global_device_defects": device, "market_data_read": False,
                                                    "mode": "tape"}}
    check_forbidden(document, spec)
    return document


def _command_eval_capture(args: argparse.Namespace, spec: Spec, spec_sha: str, started: float) -> int:
    """The Gate 2 run (B xxi): global device conditions decided before any market value is read (spec, code, status, freeze_fill, the
    FROZEN Gate 1 spec frozen with this one — `--gate1-spec` or `frozen_with.spec` —, the Gate 1 result and its register sha256, its kind,
    mode and status, the capture manifest), then the snapshot, the P3 counts, the universe and, under P-G5-10 (ii) option b, the index tape
    against its register sha256 (refuse_run), then the capture. Nothing is sealed at this gate (sealing.gate2_own_verdicts)."""
    from . import cli  # lazy: cli imports this module
    from . import g5_gate2

    guard = guard_window(spec)
    code_digest = cli.code_digest()
    base = {"kind": "lp-g5-eval", "evaluator": EVALUATOR_VERSION, "generated_at": tape.iso_now(), "spec": spec_summary(spec, spec_sha, str(args.spec)), "tape": str(args.tape),
            "guard": guard, "code": cli.code_identity(), "code_digest": code_digest}
    device = []
    if args.expect_spec_sha256.lower() != spec_sha:
        device.append(f"spec_sha256_mismatch: file {spec_sha}, expected {args.expect_spec_sha256}")
    gate1_path = Path(args.gate1_spec) if getattr(args, "gate1_spec", "") else REPO_ROOT / spec.gate2["frozen_with"]["spec"]
    gate1_sha = tape.sha256_of(gate1_path) if gate1_path.is_file() else None
    if gate1_sha != spec.freeze_fill.get("gate1_spec_sha256"):
        device.append(f"spec_sha256_mismatch: the Gate 1 spec frozen with this one ({gate1_path}) is {gate1_sha}, freeze_fill.gate1_spec_sha256 {spec.freeze_fill.get('gate1_spec_sha256')}")
    if spec.gate2["frozen_with"].get("status") != "FROZEN":
        device.append(f"status_not_frozen: the Gate 1 spec read with this one is {spec.gate2['frozen_with'].get('status')} (frozen in the same freeze line, CTO M2)")
    if not spec.frozen:
        device.append(f"status_not_frozen: {spec.status} (market data are read under a FROZEN spec only)")
    if spec.freeze_fill["null"]:
        device.append(f"freeze_fill_null: {spec.freeze_fill['null']}")
    if spec.gate2["pending"]:
        device.append(f"owner_decision_pending_present: {spec.gate2['pending']}")
    if spec.freeze_fill.get("code_digest_sha256") != code_digest:
        device.append(f"code_digest_mismatch: running {code_digest}, frozen {spec.freeze_fill.get('code_digest_sha256')}")
    gate1 = None
    if not args.gate1_result:
        device.append("gate1_result_sha256_mismatch: no --gate1-result (gate2_inscription.input)")
    else:
        gate1, defects = g5_gate2.read_gate1_result(Path(args.gate1_result), args.expect_gate1_result_sha256, spec)
        device.extend(defects)
    tape_dir = Path(args.tape)
    manifest = tape.read_manifest(tape_dir)
    device.extend(g5_gate2.check_capture_manifest(manifest, spec))
    if device:
        document = _refused_document(base, spec, device)
        _write(Path(args.out_json), json.dumps(_clean(document), indent=1, sort_keys=True, ensure_ascii=False) + "\n")
        print(json.dumps({"spec_sha256": spec_sha, "verdicts": document["summary"]["verdicts"], "market_data_read": False, "device": device}, ensure_ascii=False))
        return 2
    snapshot_path = Path(args.instruments_file or spec.freeze_fill["instruments_snapshot_path"])
    if not snapshot_path.exists() or tape.sha256_of(snapshot_path) != spec.freeze_fill["instruments_snapshot_sha256"]:
        raise SystemExit(f"instruments snapshot {snapshot_path}: missing or its sha256 differs from freeze_fill.instruments_snapshot_sha256 (mismatch_with_snapshot: refuse_run)")
    if not args.p3_counts_file or not Path(args.p3_counts_file).is_file() or tape.sha256_of(Path(args.p3_counts_file)) != spec.freeze_fill["p3_counts_selection_sha256"]:
        raise SystemExit("--p3-counts-file: missing or its sha256 differs from freeze_fill.p3_counts_selection_sha256 (refuse_run)")
    rows = _snapshot_rows(snapshot_path)
    problems = g5_gate2.check_universe(spec, rows)
    if problems:
        raise SystemExit("universe against the snapshot (refuse_run): " + "; ".join(problems[:20]))
    index_dir = Path(args.index_tape) if args.index_tape else None
    index_check = None
    d10 = spec.gate2["index_choice"] == g5_gate2.INDEX_CHOICE_D10
    if spec.gate2["index_choice"] == g5_gate2.INDEX_OPTION_B or d10:
        index_check = g5_gate2.check_index_tape(index_dir or tape_dir, getattr(args, "expect_index_tape_sha256", ""))
    series = {i.inst_id: load_capture_series(tape_dir, i, spec, rows.get(i.inst_id), index_dir) for i in spec.universe}
    context = {"mode": "tape", "device": [], "gate1_verdicts": gate1["verdicts"], "d_cov_global_us": spec.pad_start_us}
    selection = sensitivity = None
    if d10:  # D10: the choice is computed and hashed on coverage and agreement only, then recorded, before any T01 evaluation
        selection = g5_gate2.apply_index_selection(series, spec)
        _write(Path(args.out_json).with_name(Path(args.out_json).stem + ".index-selection.json"), json.dumps(selection, indent=1, sort_keys=True) + "\n")
        context["index_selection"] = selection
    study = run_study(series, spec, context)
    study.pop("_sealed_payloads")
    if d10:  # the other source, reported as a sensitivity of T01, never decisive, never used to choose (selection_rule.sensitivity)
        g5_gate2.use_index_source(series, selection["sensitivity"])
        other = run_study(series, spec, {**context, "index_role": "sensitivity"})
        other.pop("_sealed_payloads")
        t01 = other["types"].get("T01_funding_8h") or {}
        sensitivity = {"source": selection["sensitivity"], "role": "sensitivity_reported_never_decisive", "selection_sha256": selection["sha256"],
                       "T01_funding_8h": {"verdict_label": t01.get("verdict_label"), "n_instants": t01.get("n_instants"), "statistic": t01.get("statistic"),
                                          "verdict_reasons": t01.get("verdict_reasons")}}
        g5_gate2.use_index_source(series, selection["primary"])
    document = {**base, **study, "instruments": [i.inst_id for i in spec.universe], "instruments_snapshot": {"path": str(snapshot_path), "sha256": tape.sha256_of(snapshot_path)},
                "gate1_result": {k: gate1[k] for k in ("path", "sha256", "market_data_read", "note")}, "index_tape": index_check,
                "index_selection": selection, "index_sensitivity": sensitivity,
                "coverage": {i: s.coverage for i, s in series.items()}, "elapsed_seconds": round(time.time() - started, 1)}
    _finish(document, args, {})
    print(json.dumps({"spec_sha256": spec_sha, "code_digest": code_digest, "verdicts": study["summary"]["verdicts"], "gate1_inscription": study["summary"]["gate1_inscription"],
                      "elapsed_seconds": document["elapsed_seconds"]}, ensure_ascii=False))
    return 0
