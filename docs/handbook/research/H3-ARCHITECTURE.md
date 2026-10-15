# H3 — Opportunity scoring & adaptive position management: architecture (DRAFT)

**Status: DRAFT architecture and research prototype, synthetic data only.** No market data was read, no model was
trained on real data, no order was placed (demo included) and no protocol is frozen. Nothing here shows an edge or
claims a profitability. Every number produced so far comes from invented data and is not evidence about any market.

## 1. Question

H3 asks whether a portfolio's net result can be improved, and shows each effect separately:

1. **Scoring (H3-A).** Does ranking opportunities by predicted quality pick better trades than a score-blind reference?
2. **Adaptive management (H3-B).** Does managing exits from what the trade shows (trailing stop, partial close)
   improve on a static exit?
3. **Combination (H3-C).** Do the two together raise the net PnL of the portfolio?
4. **Robustness.** The answer must hold net of every cost, not depend on a few best trades, and be compatible with a
   500-USDT account under the H3 limits (0.5 % risk per trade, 1 % total open risk, leverage 1x, mandatory stop).

The answer comes from a 2 x 2 factorial design on one shared candidate book: B0 (reference entries, static exits),
B1 (scored entries, static), B2 (reference entries, adaptive), B3 (scored, adaptive). The design and the statistics
are in [H3-EXPERIMENTAL-DESIGN.md](H3-EXPERIMENTAL-DESIGN.md) and
[H3-PREREGISTRATION-DRAFT.md](H3-PREREGISTRATION-DRAFT.md).

**Where it stands (2026-10-15).** The infrastructure exists and runs end to end on synthetic data. It cannot answer
the question yet, for three reasons:

- no calibrated scoring model exists, so B1/B3 are `NOT_RUNNABLE` on any real data;
- no candidate source is both statistically powerful and compatible with the generator (the candidate-source
  dossiers CS-FUND, CS-UNLOCK and CS-XMOM, PR #513);
- no unread historical dataset exists on the execution target (`INDEPENDENT_DATA_NOT_AVAILABLE`,
  [H3-DATA-ELIGIBILITY.md](H3-DATA-ELIGIBILITY.md)), and the test procedure is not validated
  (`procedure_validated = False`).

## 2. Components

All code lives under `python-orchestrator/scripts/research_h3/`. It uses the standard library only (Decimal
arithmetic) and is tested by `python-orchestrator/tests/test_research_h3_*.py`.

| Package | Owner | Responsibility | Main interfaces | Doc |
|---|---|---|---|---|
| `contracts`, `synthetic` | integrator | Versioned shared contracts (schema 1), canonical JSON, fingerprints, H3 limits, Protocols; synthetic builders | `CandidateProvider`, `OpportunityScorer`, `ManagementPolicy`, `RiskGate`, `AuditSink` | this page |
| `scoring` | agent A | Feature catalogue `h3-scoring-features` 0.1.0, lookahead-free snapshots, `ScoringEngine` (UNESTIMATED without a calibrated model), manifests, B1/B3 readiness rule, PHP golden vectors | `features.build_snapshot`, `ScoringEngine.score/rank`, `model.scored_variant_readiness` | [H3-OPPORTUNITY-SCORING.md](H3-OPPORTUNITY-SCORING.md) |
| `adaptive` | agent B | Discrete state classifier, `StaticPolicy`, DRAFT `AdaptivePolicyV1`, position-level guards | `guards.check_action`, `policy.*.decide` | [H3-ADAPTIVE-MANAGEMENT.md](H3-ADAPTIVE-MANAGEMENT.md) |
| `validation` | agent C | Factorial harness, metrics, comparisons, guards, power studies, walk-forward, data registry | `factorial.run_factorial`, `VariantSimulator`, `compare.evaluate` | [H3-EXPERIMENTAL-DESIGN.md](H3-EXPERIMENTAL-DESIGN.md), [H3-DATA-ELIGIBILITY.md](H3-DATA-ELIGIBILITY.md), [H3-PREREGISTRATION-DRAFT.md](H3-PREREGISTRATION-DRAFT.md) |
| `integration` | agent D | Cost-inclusive risk gate, bar-replay simulator with injected failures, hash-chained audit journal, synthetic candidate provider, map to TradingCore | `H3RiskGate`, `run_variant`, `AuditJournal`, `SyntheticCandidateProvider` | [H3-RISK-AND-EXECUTION.md](H3-RISK-AND-EXECUTION.md), [H3-IMPLEMENTATION-PLAN.md](H3-IMPLEMENTATION-PLAN.md) |
| `integration.validation_adapter`, `e2e` | integrator | D's simulator behind C's `VariantSimulator`; the end-to-end synthetic example | `RunVariantSimulator`, `e2e.run_e2e` | this page |

### The D2 flow

The target flow of [H3-RISK-AND-EXECUTION.md](H3-RISK-AND-EXECUTION.md), as wired by the end-to-end example:

```
market data (Bar, Quote, FundingObservation)
  -> FeatureSnapshot per instrument and decision instant      scoring.features.build_snapshot      (A)
  -> data check: only COMPLETE snapshots go on (reason codes)   e2e.DataCheckedCandidateProvider
  -> Candidate Provider (no score)                              integration.SyntheticCandidateProvider (D)
       called once per instant by validation.factorial.build_candidate_book                      (C)
  -> Opportunity Scoring (B1/B3 only; ranked once)              scoring.ScoringEngine                (A)
  -> Risk Admission (size at 0.5 % / 1 % / 1x, cost-inclusive)  integration.H3RiskGate.admit_entry   (D)
  -> Order Plan + Fake Execution (IOC at the next open)         integration.run_variant              (D)
  -> Adaptive Management (B2/B3; static HOLD for B0/B1)         adaptive.AdaptivePolicyV1 / StaticPolicy (B)
  -> Risk Validation of every action                            H3RiskGate.validate_action + guards.check_action (D + B)
  -> Audit (hash chain per experiment and variant)              integration.AuditJournal             (D)
  -> VariantRun records -> metrics, comparisons, verdict        validation.compare.evaluate           (C)
```

A score never reaches an order without `admit_entry`; an adaptive action never changes a position without
`validate_action`. Each variant gets a fresh gate and a fresh simulator; the four portfolios share nothing but the
candidate book and the market stream, both fingerprinted and checked unchanged after every run.

## 3. Reuse, additions and what was not touched

**Reused.**

- TradingCore's canonical risk definition (`CanonicalRiskEngine`'s cost-inclusive `totalStopLoss`) is the gate's R
  unit. The PHP is mapped field by field, read-only, in `integration/tradingcore_map.py`.
- The conventions of `app/backtesting/research/portfolio_simulator.py` (contracts x contract value, separate cost
  lines, funding sign, liquidation proxy, the margin rule): four cross-tests agree with its public API.
- `scripts/research_lev/stats.py` (Holm) and `app/backtesting/research/statistics.py` (`OutcomeStatistics`) as
  cross-checks of the validation statistics, in tests only.

**Deliberately not reused.**

- `portfolio_simulator.py` is not the H3 engine. Its plans are immutable (one stop, one target, no partial close),
  so B2/B3 cannot run on it. Its rules are hard-wired and differ from H3 (5 % budget, net-R and notional filters,
  daily loss cap, 1-minute Binance universe). Its input is the PHP `research-plan.v1`. It is a certified kernel that
  14 test modules import. D found a bug in it: `finish()` after a liquidation-proxy exit raises
  `research_wallet_unreconciled` on a 1e-23 Decimal residue. The bug is reported to the owner and was not fixed (out
  of scope).
- The H2 and leverage statistics (`scripts/research_h2/stats.py`, `scripts/research_lev/stats.py`) are bound to their
  own frozen protocols: calendar-month blocks and gates G1–G7 for H2, pair-day cluster bootstrap and frozen decision
  rules for the leverage study. Both import numpy and their protocol modules. H3 needs protocol-independent,
  pure-Python statistics on weekly portfolio blocks: circular block bootstrap, self-normalised and batch-means tests,
  effective sample size, Ljung-Box and design effect. These are in `validation/stats.py` and cross-checked.

**Added.** The five packages above, 6 test modules (343 tests), 8 H3 pages and this nav group. At integration, three
changes were made to agent code, each with an unchanged default:

- `run_variant(entry_priority=...)`. D's simulator ordered the candidates of one instant by id, so the scorer's rank
  was lost. The total budget admits few positions at a time, so the order decides which trades happen.
- `H3RiskGate(action_guard=..., action_guard_identity=...)`. B's guard is wired into the gate (section 5).
- `contracts`: type hints are cached per class (half the run time of the example). This is not a wire change.

**Experimental (all DRAFT).** Every parameter: the feature catalogue, the scoring policy, `AdaptiveParamsV1`,
`ExecutionAssumptions`, `GuardParams` and the statistical plan with its thresholds. The fixture model is a labelled
SYNTHETIC TEST FIXTURE. The candidate generator has no economic hypothesis (`synthetic-no-economic-claim`).

**Not touched.** Production PHP (`trading-app/src`), production and trading configs (`trading-app/config`), Temporal
workers and schedules (`cron_symfony_mtf_workers`), the orchestrator runtime (`python-orchestrator/app`), requirements
files, docker and systemd, and the running forward test `fwd-okx-r1`. Nothing outside `scripts/research_h3` and its
tests imports `research_h3`. The diff against `origin/main` adds only new files under `scripts/research_h3/`,
`tests/test_research_h3_*.py`, `docs/handbook/research/H3-*.md`, plus the `mkdocs.yml` nav.

## 4. Identity fields of the shared contracts

The mission asks every decision to carry its identity. The table shows where each field lives (schema 1).

| Field | Where it lives | Not applicable / note |
|---|---|---|
| Instrument / product | `Header.instrument` (`InstrumentRef`: venue, `product`, inst_id, base/quote/settle, contract value, tick, lot, minima) on `FeatureSnapshot`, `OpportunityCandidate`, `OpportunityScore`, `PositionState`, `AdaptiveAction`, `RiskDecision`, `DecisionAudit` | `Bar`/`Quote`/`FundingObservation` carry `inst_id` only (raw market data). `PortfolioView`, `ModelManifest`, `CostModel` and `ExperimentManifest` are not per instrument. |
| Side | `Header.side`, required on candidate, score, position, action and risk decision | None only on a side-agnostic `FeatureSnapshot` (one snapshot serves both sides) |
| Observation / availability / decision time | `Header.clock` (`observed_ms <= available_ms <= decision_ms`, enforced) on every decision contract; `FeatureValue.observed_ms/available_ms`; `Bar.close_ms/available_ms`; `Quote.observed_ms/available_ms`; `FundingObservation.funding_ms/available_ms`; `PortfolioView.clock` | — |
| Provenance | `Header.provenance` (`Provenance`: source, dataset id, sha256, label, licence); `ModelManifest.training_provenance`; `ExperimentManifest.datasets`. Real data requires a sha256 and a FROZEN protocol | — |
| Model version | `ModelRef` (id, version, manifest fingerprint) on `OpportunityScore.model`, `DecisionAudit.model`, `ExperimentManifest.scoring_model`; `ModelManifest` | Policies use `PolicyRef` (id, version, fingerprint of the parameter document) on `PositionState`, `AdaptiveAction`, `RiskDecision`, `DecisionAudit`, `ExperimentManifest` |
| Protocol version | `Header.protocol` (`ProtocolRef`: id, version, DRAFT/FROZEN); `ModelManifest.protocol`; `ExperimentManifest.protocol` | The statistical plan fingerprint is not in the manifest yet (backlog) |
| Snapshot id + fingerprint | `FeatureSnapshot.snapshot_id` + `fingerprint()` -> `SnapshotRef` on candidate and score; `PositionState.ref()` -> `AdaptiveAction.state`; `DecisionAudit.subject_id/subject_fingerprint` | — |
| Missing-data status | `FeatureValue.status` (ok/missing/stale/non_finite), `FeatureSnapshot.data_status` (derived, checked), `PositionState.data_status`; `OpportunityScore.status` INVALID_INPUT and `data_quality` | — |
| Rejection reason | Stable reason codes: `OpportunityScore.reasons` (NO_TRADE needs one), `RiskDecision.reasons` (REJECT needs one), `AdaptiveAction.reasons`, `DecisionAudit.reasons`; validation `RefusalRecord` (stage + reasons) | — |
| Portfolio identity | `Header.portfolio_id`, required on `PositionState`, `AdaptiveAction`, `RiskDecision`; `PortfolioView.portfolio_id` + `variant`; `DecisionAudit.experiment_id` + `variant`; validation records carry variant and portfolio id | None on features, candidates and scores by design: the same candidates feed the four independent portfolios |

## 5. Conventions at the seams

Each agent wrote against the contracts alone, and some conventions were assumed on both sides. The integration
checked them, translated them or recorded them. The adapter is `integration/validation_adapter.py`. The checks are
in `tests/test_research_h3_e2e.py`.

| Convention | Status |
|---|---|
| Costs single-sourced | The adapter refuses a gate whose `CostModel` is not the spec's. The simulator charges the costs the gate sized for. |
| `pessimistic_multiplier` | Applied under `CostScenario.PESSIMISTIC` through `ExecutionAssumptions.pessimistic`, with the scenario cross-checked. **Divergence:** D scales spread and slippage only (fees are contractual), while C's harness docstring says "every cost component". Backlog. |
| Mark-to-market at block edges | D marks every bar boundary, so every block edge on the bar grid is a mark (tested). The last point is the wallet after the end-of-data closes. |
| One gate and one simulator per variant | Enforced by `run_factorial`. The adapter also refuses being run twice. |
| MFE/MAE post-fill | Holds: D fills at a bar open, so the entry bar is entirely post-fill. Clarified in the `PositionState` docstring. |
| `actions_applied` | Lists only applied ids; a failed or late action is not listed (tested). Clarified in the docstring. |
| Entry priority | Was lost (D ordered by id). Fixed with `entry_priority`; rank order at the gate is tested. |
| Funding sign, gap excess, R | Translated: C's `funding` (paid > 0) = -D's cash flow; `gap_excess` = D's `loss_beyond_nominal`; `initial_risk` = D's cost-inclusive nominal risk. |
| Scorer costs vs simulator costs | **Divergence, documented.** A's `cost_r` assumes taker on both legs plus the observed quote spread. D charges a maker fee on a resting target and a flat half-spread (`ExecutionAssumptions`). In the example the scorer's cost is the higher of the two (synthetic). A's training label is GROSS R over the gross stop distance (`validation_adapter.gross_r_label`), not C's `r_multiple` (net over cost-inclusive risk). |
| B's guard in D's gate | Wired as defence in depth: `guards.check_action` is injected, D's checks are kept, and violations are added as `guard.<code>`. The guard identity is part of the risk `PolicyRef`. One semantic difference: with the guard, a kill switch admits only HOLD/FULL_CLOSE (B, mirroring `OkxDemoWriteGate`), while D alone also admits TIGHTEN/PARTIAL. The stricter rule wins; the owner decides. |
| Intrabar exit time | **Finding.** D stamps an intrabar exit at the bar's `close_ms`. C's fill-realism check assigns that instant to the next bar, which gives false `fill_exit_outside_bar_range` findings. Backlog. |
| Execution timing | **Finding.** An entry decided after a boundary (latency > 0) fills at the next bar open, a full 1-hour bar after its reference price. In the example the 10 bp IOC collar refuses about 40 % of fills (synthetic). This needs a protocol decision (sub-bar execution, or a reference price at the fill). |

## 6. End-to-end synthetic example

`python -m scripts.research_h3.e2e` (from `python-orchestrator`). The example runs in about 12 s and uses the
following synthetic setup:

- six invented USDT-settled instruments, 216 hourly bars each, quotes and 8-hourly funding;
- one portfolio of 500 USDT per variant;
- one candidate generator with no economic hypothesis, deciding every four hours.

Two defects are planted:

- one instrument has no funding settlement during the first two days, which gives PARTIAL snapshots;
- one quote is ten minutes old, which gives a STALE snapshot.

These snapshots are refused before generation with reason codes (`snapshot_data_partial`,
`feature_missing.funding_rate_hourly`, `snapshot_data_stale`, `feature_stale.spread_bp`). The refusals are written
to a variant-independent journal.

The example runs two paths:

- **(a) Reference null model.** Every score is UNESTIMATED and carries no number. `scored_variant_readiness` returns
  `model_reference_null`, `model_uncalibrated`. B1/B3 are reported `NOT_RUNNABLE`, only B0 and B2 run, and no
  comparison verdict is computed.
- **(b) A's SYNTHETIC TEST FIXTURE manifest.** The full factorial runs under base and pessimistic costs, and
  `compare.evaluate` returns the evidence label `synthetic_not_evidence`. The verdict is `INSUFFICIENT_EVIDENCE`
  (8 daily blocks are below the DRAFT minimum of 26).

The tests assert technical properties only:

- the four variants get the same candidates and the same market;
- portfolios are independent;
- the H3 caps hold on every admitted entry, and no admitted action adds risk;
- stops are never widened, and only B2/B3 move them;
- every audit chain verifies and tampering is detected;
- two runs are byte-identical;
- bad data is refused;
- every convention in section 5 holds.

No test asserts or reports a profitability. The example's PnL figures are labelled `SYNTHETIC_NOT_EVIDENCE`.

## 7. Schema v2 backlog

Wire forms were **not** changed at integration: A's golden vectors and every fingerprint still verify. Schema 1 got
docstring-only clarifications:

- MFE/MAE are built from post-fill observations;
- the unit of `volatility` is not fixed (None);
- Quote sizes are informational;
- `actions_applied` lists applied ids only;
- cost rates, the scope of `pessimistic_multiplier`, and a cost-inclusive `risk_amount`.

Everything else waits for a schema 2.

| # | Change | Requested by | Reason |
|---|---|---|---|
| 1 | `ModelManifest.feature_spec_fingerprint` and optional `side` | A | Bind a model to the exact catalogue content, not only its id and version; allow side-specific models |
| 2 | `FeatureSnapshot.feature_spec_fingerprint` | A | Same, on the snapshot side |
| 3 | Unit of `Quote.bid_size/ask_size` | A | Sizes are in venue units; needed before any depth feature |
| 4 | Unit of `PositionState.volatility` | B | Today producers set None; the state classifier can only use "known or not" |
| 5 | Protection status (live / replacing / absent) on `PositionState` | B | A stop replacement on OKX is cancel-then-place (`TpSlTwoTargetsService`): the policy must see an unprotected window; D tracks it privately |
| 6 | Failed-attempt count / retry budget on `PositionState` | B | A failed modification is invisible to the policy today |
| 7 | Numeric half-spread and funding provision in `CostModel` (CR-1), and one rule for `pessimistic_multiplier` (fees scaled or not) | D (+ integrator) | Today in `ExecutionAssumptions`; the scorer and the simulator do not share one spread source; D and C state different multiplier scopes |
| 8 | `PortfolioView.open_instruments` (CR-2) | D | Occupancy is passed beside the view; without it the gate fails closed |
| 9 | Order type and limit price on `RiskDecision` (CR-3) | D | The IOC collar price is carried as `price_reference` |
| 10 | Portfolio-level audit header (CR-4) | D | Kill-switch and data-refusal records borrow an instrument header |
| 11 | Validation records (`TradeRecord`, `RefusalRecord`, `EquityPoint`, `VariantRun`) in `contracts` | C | They cross the validation/integration seam (the adapter) |
| 12 | `ExperimentManifest.statistical_plan_fingerprint` and the prospective window | C | Bind an experiment to its plan and to its data window |
| 13 | `ExitReason` values for fail-safe close, gap and liquidation proxy | integrator | `fail_safe_unprotected` maps to KILL_SWITCH; stop, gap and liquidation share STOP |
| 14 | One intrabar exit-time convention (or an `exit_bar_open_ms` field) | integrator | Section 5: false fill-realism findings |
| 15 | An execution-timing rule (sub-bar fill or a reference price at fill time) | integrator | Section 5: next-bar-open fills against a 10 bp collar |
| 16 | Kill-switch action semantics (D: risk-reducing; B: HOLD/FULL_CLOSE) | integrator | Two rules exist; the guard makes the stricter win |
| 17 | Gross stop distance on the trade record (the scorer's label unit) | integrator | A's label is gross R; C's `r_multiple` is net over cost-inclusive risk |
| 18 | Per-settlement funding lines on D's `Trade` | integrator | `funding_events` is recomputed with C's rule; D's boundary handling (adverse-possible charged) is not visible |

## 8. Parallel organisation

The mission ran as four agents that really worked concurrently, as four background agents, each in its own worktree
and branch. All four branches were cut from the contracts commit `31bda7e8`. The workflow tool was not used for them,
because on this 4-CPU machine it caps a workflow at 2 concurrent agents.

| Agent | Branch (commit) | Files |
|---|---|---|
| integrator | `claude/h3-opportunity-architecture` (contracts `e23f418a`, `31bda7e8`) | `research_h3/{__init__,contracts,synthetic}.py`, `test_research_h3_contracts.py`; at integration `e2e.py`, `integration/validation_adapter.py`, `test_research_h3_e2e.py`, this page, `mkdocs.yml` |
| A — scoring | `claude/h3-a-scoring` (`5de71c7a`) | `research_h3/scoring/**` (incl. `parity/golden_v1.json`), `test_research_h3_scoring.py`, `H3-OPPORTUNITY-SCORING.md` |
| B — adaptive | `claude/h3-b-adaptive` (`93206e1a`) | `research_h3/adaptive/**`, `test_research_h3_adaptive.py`, `H3-ADAPTIVE-MANAGEMENT.md` |
| C — validation | `claude/h3-c-validation` (`8a00e015`) | `research_h3/validation/**`, `test_research_h3_validation.py`, `H3-EXPERIMENTAL-DESIGN.md`, `H3-DATA-ELIGIBILITY.md`, `H3-PREREGISTRATION-DRAFT.md` |
| D — integration / risk | `claude/h3-d-integration` (`3ffb7b11`) | `research_h3/integration/**`, `test_research_h3_integration.py`, `H3-RISK-AND-EXECUTION.md`, `H3-IMPLEMENTATION-PLAN.md` |

Write scopes were exclusive, and a needed contract change was requested in the agent's report, not made. There were
**no merge conflicts**. The branches were integrated by sequential `--no-ff` merges in the order A, B, C, D (merge
commits `0104e0c2`, `e816d26b`, `26967d31`, `801f53fd`).

One label clash existed. The previous evening's desk dossiers used H3-A/B/C for funding carry, token unlocks and
cross-sectional momentum, while this mission uses H3-A/B/C for scoring, adaptation and interaction. The clash was
resolved by renaming the dossiers to the candidate-source dossiers **CS-FUND, CS-UNLOCK and CS-XMOM** (PR #513).
They answer "which explicit economic hypothesis feeds the common candidate generator"; they are not H3
sub-hypotheses.

The independent reviews and the decision record are separate pages, to be added by a later step.

## 9. Code digest

**Method.** Take every file under `python-orchestrator/scripts/research_h3/`, excluding `__pycache__` and `*.pyc`.
Build the sorted list of `[path relative to python-orchestrator, sha256 hex of the file bytes]`, serialise it as
canonical JSON (keys sorted, no whitespace), and take `"sha256:" + sha256(that JSON)`. The function is
`scripts.research_h3.e2e.code_digest()`; the example's `ExperimentManifest.code_digest` is that value. The digest
covers 39 files.

**Value at the integration commit:**
`sha256:ba4b13227413643843d6a0b9acd2b96c6f2d56ddbf4deb9062329aec79855a36`.

## 10. Checks run at integration

- **Tests.** `tests/test_research_h3_*.py`: 343 passed, 0 failed (Python 3.12.3; CI runs 3.11). This includes 19
  e2e tests.
- **Python 3.11 grammar.** Every file under `research_h3` and every H3 test module parses with
  `feature_version=(3, 11)` (44 files).
- **Golden vectors.** `python -m scripts.research_h3.scoring.parity` prints `golden vectors: OK`.
- **Docs.** `mkdocs build --strict` passes.
- **Runtime untouched.** The diff against `origin/main` lists only new H3 files and the `mkdocs.yml` nav. No runtime
  path imports `research_h3`. No requirement changed.
