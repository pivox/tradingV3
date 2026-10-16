# H3-A — independent reviews of the scoring-testability work (2026-10-16)

**Status: DRAFT research prototype, synthetic data only.** Two independent reviewers examined the H3-A integration
commit `cbff09de` on branch `claude/h3a-scoring-testability` (PR #514 head + PR #513 + agents A-D). This page records
their recommended verdicts, every finding, what was verified and what was done. The owner's rule applies: every
finding is corrected, documented as blocking, or rejected with a verified reason. None was dropped.

No reviewer and no response read market data, opened a price, return or trade file, downloaded anything, called an
exchange, trained a model, ran a backtest or placed an order. Every number below is synthetic or analytical and is
not evidence about any market.

## 1. Recommended verdicts

| Reviewer | Scope | Recommended verdict |
|---|---|---|
| Quant (QR) | Methodology and statistics, adversarial, read-only, with a pre-registered synthetic probe | **SCORING_UNTESTABLE** at a reasonable horizon, for now |
| CTO / risk (CTO) | Code, contracts, risk, traceability, read-only | **SCORING_CONDITIONAL**, with explicit fall-through rules to UNTESTABLE |

**Do the reviewers agree on the verdict?** No. They agree that the bar for SCORING_TESTABLE is not met, that the
integration's 12-row blocker table is accurate, and on the code and documentation defects. They disagree on whether
the remaining obstacles are measurable prerequisites (CTO) or a structural horizon and training obstacle for the only
remaining source (QR). The CTO reviewer named fall-through rules: switch to UNTESTABLE if M1 or M2 fails, if the
owner's minimum useful IC is below about 0.12, or if the accepted collection horizon is under about 3 years. The
CTO review did not have a CS-EARN-like power study; the quant probe, reproduced here, shows that even A1 alone needs
about 4.7 years of collection at IC 0.15 before any training period. The decision is in
[H3-A-NEXT-DECISION.md](H3-A-NEXT-DECISION.md).

**Quant review (summary).** Integration verified (496 tests, golden vectors OK). Pre-registered probe
(`PREDECLARED.md` sha256 `3fbf28ef…`, results sha256 `4cbb3392…`, 7 cells × 200 panels, about 5 CPU-minutes) through
the unchanged `abstention_sim.evaluate_panel` on a CS-EARN-like stream (176 events a year bunched in reporting weeks,
side-signed market factor, 5-session holds, 2 slots). Three reasons against CONDITIONAL: (1) CS-EARN has no
venue-specific mechanism inside its trade window (the band and off-hours argument act before T; after T the X-Perp
tracks a large-cap stock where post-earnings drift is reported absent; the side rule mirrors H2); (2) the primary
A1∧A2∧A3 has power 0.155 at 2 years and 0.23 at 4 years at IC 0.15 (A1 alone 0.49 / 0.745, A2 0.23 / 0.30), so
about 5 years for A1 and about 20 for A2; (3) no admissible training data exists, and every quoted horizon leaves
out training. Fixable defects listed below. Where the agents are right: BR removes the R1-F1 confound in synthetic
nulls (side-signed seasonal null at 208 weeks: A1 0.075, primary 0.005), and the capacity/hold confound was not
demonstrated in the B3 stream. Reopening conditions: admitted underlying-stock history for development, or H3-A as
A1 only on a pre-specified score at an owner IC of at least 0.15, or a higher-frequency source with a mechanism.

**CTO / risk review (summary).** Confirmed: 496 tests pass (without conftest), golden vectors OK, V1 files
byte-identical to the #514 head, diff scope limited to H3 research paths and `mkdocs.yml`, nothing in `app/` imports
`research_h3`, agent A's one public instrument snapshot holds no prices, pre-registration order and hashes verify,
B3 cell G3/p6/26 reproduced bit-identically, B3 within its CPU budget, Python 3.11 grammar and `math.fsum` respected,
risk limits never breached per trade on the synthetic e2e, BR refused a score at the gate, deterministic reruns.
Fixes needed before any freeze: trial-ledger truncation and fork detection, replay labels still convertible to
LABELLED V2 training outcomes, forgeable BR evidence, plus smaller items. Verdict CONDITIONAL because the
prerequisites are measurable and cheap and the architecture holds together.

**Responder verification.** The responder reproduced the quant probe's cell E15_104 once, after pre-registering the
rule (private `responder/REPRO-PREREG.md`, sha256 `1abebd47…`): all rates and means bit-identical (22 CPU-s,
`REPRO-RESULT.json` sha256 `454b2328…`). Every code finding was checked against the source before acting.

## 2. Findings and responses

Status: **corrected** (fixed in this branch, with tests where code changed), **blocking** (verified, cannot be fixed
inside this mission's scope; it is a prerequisite with an owner), **rejected** (with a verified reason). A finding
can be partly corrected and partly blocking.

| ID | Sev. | Finding (short) | Evidence verified | Decision | Status |
|---|---|---|---|---|---|
| QR-1 | blocker | Horizon of the full primary on a CS-EARN-like stream is far beyond 1.4-3.4 years | Probe hashes match; cell E15_104 reproduced bit-identically; noncentrality extrapolation checked (A1 0.49 → 0.745 is a √2 ratio; 0.8 at about 4.7 yr; A2 0.30 at 4 yr → about 20 yr) | DRAFT §6 now states the full-primary horizon next to the A1-only one | **blocking** (structural for CS-EARN; drives the verdict) |
| QR-2 | blocker | No admissible training data; horizons omit training | `engine.py` rules 2/4: no or uncalibrated model gives UNESTIMATED, NO_TRADE; H3-DATA-ELIGIBILITY forbids fitting on prospective data | DRAFT §6 and §8 item 9 list the three options (admitted history, two windows, pre-specified A1-only score) | **blocking** (owner decision) |
| QR-3 | major | CS-EARN has no venue-specific mechanism after T; qualification criterion skips M4 | Source doc §3.1: T is the day-1 close, band and off-hours act before T; side rule declared the mirror of H2 | Source doc §3.1 gets an M4 requirement and the side-rule classification | **blocking** (M4 criterion before any capture) |
| QR-4 | major | A1 exists as three different statistics; e2e A1 runs on replay labels | `scoring_checks.rank_ic_test` is per instant, ≥ 3 candidates; `abstention_sim._block_ic` per-week pooled; `e2e.py` joins scores with `label_mapping(labels)` (replay) | DRAFT §2 documents it and the single function to build; power study to re-run on it | **blocking** (protocol design; part of blocker 5) |
| QR-5 | major | `compare.evaluate` gates the H3-A reading behind the B3 primary and S1 | `supported()` returned `not_confirmatory` before reading BR | H3-A reading now A2-only and independent of B3 and S1 (`a2_rejects_null_…` / `a2_does_not_reject_…`); S1 reported as abstention-confounded; `abstention.h3a_status` declared the authoritative reader | **corrected** (`test_evaluate_reads_a2_even_when_the_b3_primary_fails`) |
| QR-6 | major | Intersection-union level fails in the least-favourable null | B3 measured the primary only where both nulls hold; A1 above 0.075 at 104 weeks; `utility` subtracts `cost_r` | "Primary ≤ 0.04" reassurance removed from DRAFT §5; caveat added to §3 with component-wise size validation and a cost-only control for A2 | **corrected** (doc) / **blocking** (component-wise size study) |
| QR-7 | major | BR does not match score status or eligibility; can credit closed families | `STRATA_KEYS` includes `score_status`; DRAFT §12.1 primary is `decision_day` only; engine never accepts non-ESTIMATED | DRAFT §8 item 10: stratify BR by (`decision_day`, `score_status`) or draw only among scorable candidates; add a "scorable" census step | **blocking** (owner choice of the primary control, then re-study) |
| QR-8 | major | Census blind to data-coverage selection; label includes a non-capturable first bar | `outcome_dependent()` is False for `DATA_COVERAGE`; `price_path_gap` excludes the candidate | Census now reports `target_label_data_coverage_exclusions_untested` and `target_label_outcome_independence_established` (False when any coverage exclusion exists); doc notes in DRAFT §2 and labels audit | **corrected** (reporting, `test_census_never_calls_a_data_coverage_exclusion_outcome_independent`) / **blocking** (gap-tolerant label, re-anchoring under R1-F6) |
| QR-9 | major | No admissible feature set for CS-EARN; H3-A collapses into the source hypothesis | `catalogue_v2` is structure only; V1 catalogue is 24 h crypto features | Labels audit records the closed-family overlap of each plausible CS-EARN feature | **blocking** (catalogue to draft and fingerprint before any capture) |
| QR-10 | minor | `h3a_status` mislabels an unstable ranking; non-rejection statuses ignore power; BR evidence not pinned to the plan | One-liner confirmed: both reject + halves False gave `h3a_abstention_only…` | New statuses `h3a_unstable_ranking`, `h3a_non_rejection_underpowered` (needs `powered=True`); `check_random_control` pins spec fingerprint, all seeds once, `decision_day` stratification, plan alpha, procedure and block length | **corrected** (3 tests) |
| QR-11 | minor | With K = 19 a randomisation p can never survive a Holm family of four | min p = 1/20 = 0.05, Holm × 4 = 0.20 | DRAFT §4: K = 99 when a randomisation p enters a confirmatory family (K ≥ 79), or block test only | **corrected** (doc) |
| QR-12 | minor | A2 matches acceptances, not executed exposure; capacity-binding case untested | Probe H_NULL: block_day 0.055; B1 executes 53 % of acceptances on the CS-EARN-like stream | Re-study cell and exposure-matched guard listed in the abstention doc §7 | **blocking** (part of the R1-F4 re-study, blocker 3) |
| QR-13 | minor | B3 does not cover the source's dependence structure; p10 transcription error | `b3-results.json` p10 primary 0.28 vs table 0.275 | Table corrected to 0.280; DGP requirements for the re-study listed | **corrected** (typo) / **blocking** (re-study, blocker 3) |
| QR-14 | minor | B1's acceptance share rests on a heuristic uncertainty with a declared `n_train` | `engine.py`: se² = sd²(1+q)/n_train | DRAFT §8 item 11 and labels audit: fixed top-quantile acceptance for the H3-A test; uncertainty descriptive until a versioned engine | **corrected** (DRAFT rule) / engine version stays blocker 8 |
| QR-15 | minor | V2 `ActivityBudget` has no link to B1's run | `comparison_readiness` checked only budget fingerprint and strata | `ActivityBudget.source_run_fingerprint`; readiness refuses an unbound budget or one from another B1 run | **corrected** (`test_controlled_comparison_is_ready_only_with_every_preregistered_seed`) |
| CTO-01 | major | Trial ledger: clean truncation, fork, rewrite undetected by `verify()`; confirmatory-once easy to get around | Docstring and V2 doc claimed "any truncation refused"; existing test itself notes a clean truncation verifies | Confirmatory-once now keyed by `dataset_sha256` across protocols, ids and labels, plus `ledger_confirmatory_data_already_used`; module docstring and V2 doc corrected | **corrected** (keying, docs; `test_confirmatory_once_is_keyed_by_dataset_content_across_protocols`) / **blocking** (external head anchor, GENESIS nonce, append lock, time bound) |
| CTO-02 | major | Replay labels still become LABELLED V2 training outcomes; population not in `LabelSpecRef` | Probe path reproduced in code reading: `label_to_v2` ignored `horizon_covered` and gaps | `LabelSpecRef.population` (required); CALIBRATED model and experiment refuse `EXECUTABLE`; `label_to_v2` requires an `EXECUTABLE` spec and returns ABSENT for truncated or gappy labels; test fixture renamed; source doc §3.1 aligned. Sub-recommendation "restrict `training_target` to `reference_gross_r`" **rejected**: unit and population are orthogonal, the V2 population rule is the binding one, and the C/D unit-parity test relies on `gross_r` naming a unit | **corrected** (2 tests) |
| CTO-03 | major | BR evidence in `compare.evaluate` can be forged; two fingerprint schemes | Hand-built dict was accepted | Evidence pinned (see QR-10); budget bound to the B1 run in V2 (see QR-15) | **corrected** (partly) / **blocking** (typed `RandomControlResult`, control pre-registered in the manifest, one fingerprint scheme: harness migration, blocker 10) |
| CTO-04 | minor | `comparison_readiness` accepts a base-only run set | Test asserted `== ()` with BASE only | Both cost scenarios required (`cost_scenario_missing`); tests updated | **corrected** |
| CTO-05 | minor | PHP mapping overstates the 1 % total-cap gap | Static reading of `CanonicalPortfolioAdmissionEngine.php` lines 79-92: `reservedRiskQuote` enters the daily-loss cap | V2 doc row reworded "partially mapped", semantics stated; parity test kept in blocker 12 | **corrected** (doc) |
| CTO-06 | minor | "xs_rel_ret_lb EXCLUDED by default" overstated | `catalogue_v2.draft_from_v1` sets PENDING for every predictive feature | Labels audit and next-decision doc use the exact wording: PENDING (freeze blocked), EXCLUDED recommended at binding, still active in V1 | **corrected** (wording) |
| CTO-07 | minor | 3.11 check blind to PEP 701 f-strings; CI-style run with conftest not reproduced | `ast.parse(..., feature_version=(3,11))` on 3.12 accepts nested-quote f-strings | New test scans `research_h3` and the H3 tests for 3.12-only f-strings (self-tested on a positive case) | **corrected** (check) / open: CI must confirm the suite with the repo conftest (local interpreters lack sqlalchemy and httpx) |
| CTO-08 | minor | Abstention-side `ActivityBudget` validates much less than V2 | Labels not matching keys, unsorted keys, float and bool counts accepted (floats truncated) | Same checks as V2; floats and bools refused | **corrected** (`test_abstention_budget_validates_like_its_v2_mirror`) |
| CTO-09 | minor | B3 traceability gaps | `summarize_b3.py` mtime 19 s after results, not in `PRE-RUN.sha256`; absolute paths; eager pool submission | History cannot be rewritten; provenance (6622a0c3, reproduced at cbff09de) and process rules for later studies recorded in the abstention doc §7 | **corrected** (documented; rule for later studies) |
| CTO-10 | minor | 1 % total cap holds only at admission | Reviewer's risk probe; `risk_gate.py` caps at admission against current equity | Stated in H3-RISK-AND-EXECUTION and the V2 doc; running cap is an owner decision for the PHP parity test | **corrected** (doc) / owner decision (execution-side, blocker 12) |

## 3. What changed in this response (code)

All changes are in H3 research code and tests; V1 `contracts.py`, the golden vectors, `synthetic.py`, the engine,
production configuration and PHP are untouched.

- `contracts_v2.py`: `LabelSpecRef.population`; `h3_model_label_population_outcome_selected`,
  `h3_experiment_label_population_outcome_selected`; `ActivityBudget.source_run_fingerprint`;
  `comparison_readiness` reasons `cost_scenario_missing`, `activity_budget_not_bound_to_b1_run`,
  `activity_budget_not_from_this_b1_run`.
- `migrate_v1_v2.py`: replay labels need an `EXECUTABLE` spec; truncated or gappy ones are ABSENT.
- `trial_ledger.py`: confirmatory-once by dataset content; corrected docstring.
- `validation/abstention.py`: stricter `ActivityBudget`; `budget_to_v2(..., source_run_fingerprint)`;
  `h3a_status(..., powered=)` with two new statuses; `compare_b1_to_controls` reports `block_ms` and `seeds_run`.
- `validation/compare.py`: pinned BR evidence; A2 reading independent of B3 and S1.
- `scoring/labels.py`: census reports data-coverage exclusions and an outcome-independence flag.
- Tests: `test_research_h3_a_interfaces.py`, `test_research_h3_contracts_v2.py`, `test_research_h3_abstention.py`,
  `test_research_h3_labels.py`, `test_research_h3_trial_ledger.py`.
