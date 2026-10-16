# H3 contracts V2 and the trial ledger (DRAFT design, prototype)

Status: **DRAFT prototype, not wired into any module.** Schema 1 (`contracts.py`) is frozen and stays the wire form
of the scoring, adaptive, validation and integration packages. Nothing here trains a model, reads market data or
changes a production component. Every example value is synthetic.

Code (all under `python-orchestrator/scripts/research_h3/`):

- `contracts_v2.py`: the V2 contracts;
- `migrate_v1_v2.py`: explicit V1 → V2 upgrades;
- `trial_ledger.py`: append-only, hash-chained trial ledger.

Tests: `tests/test_research_h3_contracts_v2.py` and `tests/test_research_h3_trial_ledger.py`.

## 1. Principles

- **V1 is untouched.** A test pins the sha256 of `contracts.py`, of `scoring/parity/golden_v1.json` and of three V1
  fingerprints, and checks that importing V2 changes none of them. The golden vectors still verify
  (`python -m scripts.research_h3.scoring.parity`).
- **Reuse, not copy.** V2 imports the V1 helpers (`Contract`, canonical JSON, `wire_decimal`, `dec`, `check_*`) and
  the V1 blocks that did not change. A V2 document can therefore nest V1 blocks with their `/1` ids (`h3.header/1`,
  `h3.clock/1`, `h3.instrument/1`, `h3.snapshot_ref/1`, `h3.policy_ref/1`, `h3.limits/1`). Only contracts whose
  content changed get an `h3.<name>/2` id.
- **V1 rules by construction.** When V2 is a superset of a V1 contract, it validates through its `to_v1()`
  projection. A V2 candidate, snapshot, score, provenance, model ref or cost model therefore obeys every V1 rule,
  including the protective stop, anti-lookahead and abstention rules. No second copy of a rule can drift.
- **No field without a consumer.** Every V2 field below names the code or the review finding that reads it. The
  candidates that were dropped are listed in section 4.
- **Explicit migrations.** A V1 value never becomes V2 with an invented value: a missing supplement is refused with
  `migration_missing.<field>`. Upgrading and then projecting back gives the V1 value with the same fingerprint, and
  the tests check this for every superset contract.

## 2. Audit of the V1 contracts (D1)

| V1 contract | Adequate for | Gap found | Source |
|---|---|---|---|
| `OpportunityCandidate` | Stop mandatory and protective, side, clock, snapshot ref | The generator is named by id and version only. Two parameterisations of one generator cannot be told apart, so the ledger cannot count them as distinct trials | R1-F8, R1-F12 |
| `FeatureSnapshot` | Anti-lookahead per feature; missing/stale status derived and checked | The catalogue is bound by id and version, not by content | backlog #2 |
| `OpportunityScore` | No number without a model; INVALID_INPUT abstains; NO_TRADE needs a reason | Not bound to the exact candidate (id only). The calibration status and model availability time are not visible, so a walk-forward score could use a model trained after its decision without the contract noticing | backlog #1, R1-F3 |
| `ModelManifest` | Calibration needs training; real training needs a FROZEN protocol | Has no catalogue content, no side, no label identity, no training window and no standardisation cutoff. It can claim training on HOLDOUT data | #1, #20, R1-F7 |
| `Provenance` | Synthetic ⇔ `source=synthetic`; real data needs a sha256 | Independence is checked by dataset id only. There is no underlying set, venue or time window, so the (underlying × time) overlap rule cannot be expressed | #20, R1-F7 |
| `ExperimentManifest` | One FROZEN protocol for real data, H3 limits, shared costs | The factorial `B0..B3` is hard-wired, so the BR control cannot be expressed. It has one `seed` where R1-F12 wants K pre-declared ones, and no plan fingerprint (#12), no pessimistic risk policy (#21), no target population, no label identity and no ledger head | R1-F1, R1-F12, #12, #21 |
| `StatisticalPlan` (validation/plan.py) | Has its own fingerprint | That fingerprint is not referenced by any manifest | #12 |
| `DecisionAudit` | Hash chain per (experiment, variant) | `variant` is the V1 enum, so a BR journal would have to mislabel itself | R1-F1 |
| `CostModel` | Fees, slippage, pessimistic multiplier | The numeric half-spread and funding provision live in `ExecutionAssumptions`, so the scorer and the gate do not share one cost source | #7, CR-1 |
| `TrialLedger` (validation/walkforward.py) | Refuses trials on confirmatory data | It is in memory and voluntary: no file, no chain, no results, no attempt counts, no publication link | R1-F12 |

## 3. V2 contracts and the consumer of each field (D2)

| V2 contract (`schema`) | Field(s) beyond V1 | Consumer | Ref |
|---|---|---|---|
| `VariantV2` (enum) | `BR` | BR portfolio, `comparison_readiness`, `DecisionAuditV2`, `VariantRunRef` | R1-F1, #22 |
| `ProvenanceV2` (`h3.provenance/2`) | `venue`, `product`, `underlyings`, `window_start_ms`, `window_end_ms` (`[start, end)`) | `overlaps()` → `ExperimentManifestV2` (no cross-label overlap), `check_model_for_experiment` (training vs evaluation data), `ModelManifestV2` (training window inside the data) | R1-F7, #20 |
| `GeneratorRef` (`h3.generator_ref/2`) | `generator_fingerprint` (full parameter document) | Candidate identity, manifest, ledger `generator_fingerprint` | R1-F8, R1-F12 |
| `FeatureCatalogueRef` (`h3.feature_catalogue_ref/2`) | `catalogue_fingerprint` | `FeatureSnapshotV2`, `ModelManifestV2`, manifest, `check_model_for_experiment` (`model_catalogue_mismatch`) | #1, #2 |
| `LabelSpecRef` (`h3.label_spec_ref/2`) | `label_id/version`, `unit` (gross_r / net_r), `label_fingerprint` | `LabelOutcome`, `ModelManifestV2` (calibration), manifest; settles the unit ambiguity | #17, R1-F3 |
| `ModelRefV2` (`h3.model_ref/2`) | `calibration`, `available_ms` | `OpportunityScoreV2` (ESTIMATED needs CALIBRATED and `available_ms <= decision_ms`), manifest (model exists before the manifest) | #1, walk-forward anti-lookahead |
| `FeatureSnapshotV2` (`h3.feature_snapshot/2`) | `catalogue` replaces `feature_spec_id/version` | Scorer: refuses a snapshot from another catalogue content | #2 |
| `OpportunityCandidateV2` (`h3.opportunity_candidate/2`) | `generator: GeneratorRef` replaces 3 strings | Ledger, manifest, `score.scores(candidate)` | R1-F12 |
| `OpportunityScoreV2` (`h3.opportunity_score/2`) | `candidate_fingerprint`, `model: ModelRefV2` | Joins scores to labels, gate idempotency, model anti-lookahead | R1-F3 |
| `LabelOutcome` (`h3.label_outcome/2`) | `status` LABELLED/ABSENT, `value` in the spec's unit, `stopped`, `available_ms` (> decision), `absence_reasons` | Label/population representativeness (LABELABLE vs evaluated); `available_ms` is the effective availability date of the label for training cutoffs. Built from `label_replay.CandidateLabel` | R1-F3 |
| `RandomControlSpec` (`h3.random_control_spec/2`) | `control_id`, `method`, `strata_keys` (decision-time whitelist), `seeds` (sorted, unique, ≥ 1), `budget_source` = `b1_acceptance` | Manifest (BR ⇔ spec), `comparison_readiness` (every seed, no more) | R1-F1, shared interface |
| `ActivityBudget` + `StratumCount` | `budget_id`, per-stratum `(candidate_count, accepted_count)`, `derived_from_variant` = B1 | BR draw, `VariantRunRef.activity_budget_fingerprint`, `comparison_readiness` | R1-F1, shared interface |
| `CostModelV2` (`h3.cost_model/2`) | `half_spread_rate`, `funding_provision_rate`, `funding_interval_ms` | Risk gate (via `execution_assumptions`), scorer cost, simulator: one cost source | #7, CR-1 |
| `ModelManifestV2` (`h3.model_manifest/2`) | `catalogue`, `side`, `label_spec`, `training_start/end_ms`, `standardisation_cutoff_ms`, `available_ms` | Calibration rules, `check_model_for_experiment`, `ref()` → `ModelRefV2` | #1, #20 |
| `ExperimentManifestV2` (`h3.experiment_manifest/2`) | `statistical_plan_fingerprint`, `variants` (3 designs), `generator`, `catalogue`, `scoring_model: ModelRefV2`, `label_spec`, `target_population`, `random_control`, `reference_lottery_seeds`, `adaptive_policy` (optional), `pessimistic_risk_policy`, `cost_model: CostModelV2`, `datasets: ProvenanceV2`, `trial_ledger_head` | Pre-registration of B0/BR/B1 and B0-B3; `comparison_readiness`; freeze conditions | #12, #21, R1-F1, R1-F12 |
| `VariantRunRef` (`h3.variant_run_ref/2`) | Run identity: manifest fingerprint, variant, portfolio, cost scenario, seed, book/market fingerprints, risk policy, budget fingerprint | `comparison_readiness` | R1-F1, R1-F12, R2-F3 |
| `DecisionAuditV2` (`h3.decision_audit/2`) | `variant: VariantV2`, `model: ModelRefV2` | BR journal | R1-F1 |

**Mission field list → where it lives.**

| Mission field | Where it lives |
|---|---|
| Generator identity | `GeneratorRef` |
| Catalogue version | `FeatureCatalogueRef` (id, version, content) |
| Model identity | `ModelRefV2` / `ModelManifestV2` |
| Calibration status | `ModelRefV2.calibration` |
| Instrument and product provenance | `ProvenanceV2.venue`, `product`, `underlyings`; the instrument itself stays in `Header.instrument` (V1) |
| Observation windows | `ProvenanceV2.window_*` and `ModelManifestV2.training_*`; per-feature lookbacks are catalogue content, fingerprinted |
| Effective availability date | `Clock.available_ms` (V1), `ModelRefV2.available_ms`, `LabelOutcome.available_ms` |
| Label identity | `LabelSpecRef` |
| Label absence reasons | `LabelOutcome.absence_reasons` |
| Target population | `ExperimentManifestV2.target_population` (`Population` enum; the choice belongs to the labels audit) |
| Random control reference | `RandomControlSpec`, `VariantRunRef` |
| Randomisation seeds | `RandomControlSpec.seeds`, `ExperimentManifestV2.reference_lottery_seeds` |
| Activity budget identity | `ActivityBudget`, `VariantRunRef.activity_budget_fingerprint` |
| Cost model reference | `CostModelV2` (embedded, fingerprinted with the manifest) |
| Risk policy | `risk_policy`, `pessimistic_risk_policy`, `limits` = `H3_LIMITS`, `initial_equity` = 500 |
| Provenance fingerprints | `ProvenanceV2.dataset_sha256`, every `*_fingerprint`, the manifest fingerprint, `trial_ledger_head` |

**Manifest rules beyond V1.**

- `variants` must be one of `(B0, BR, B1)`, `(B0, B1, B2, B3)` or `(B0, BR, B1, B2, B3)`.
- BR is present if and only if `random_control` is set. B2 is present if and only if `adaptive_policy` is set.
- `initial_equity` must be the H3 reference equity (500), under `H3_LIMITS`.
- `pessimistic_risk_policy` must differ from `risk_policy`.
- Lottery seeds are sorted, unique and non-empty.
- A FROZEN protocol needs `statistical_plan_fingerprint` and `trial_ledger_head`. Real data needs a FROZEN protocol.
- A calibrated model needs `label_spec` and must have been available before the manifest's `created_ms`.
- Two non-synthetic datasets with different labels may not overlap in (underlying × time), across venues.

## 4. Considered and not added (no consumer in this mission)

| Item | Why it is not in V2 now |
|---|---|
| Quote size unit (#3) | No depth feature exists or is planned |
| `PositionState` volatility unit, protection status, retry budget, unresolved-order status (#4, #5, #6, #19) | These belong to adaptive management (H3-B) and a live port; H3-A testability does not read them |
| `PortfolioView.open_instruments` (#8), order type on `RiskDecision` (#9), portfolio audit header (#10) | Risk-gate interface changes; kept as change requests (section 10). `RiskDecision` stays V1: it already refuses an ADMIT that breaks the H3 limits |
| Validation records in contracts (#11), `ExitReason` values (#13) | Validation-internal; their run identity is covered by `VariantRunRef` |
| Exposure-matched static control (#22, second half) | Not yet defined by a protocol; adding a variant before its definition would be a field without a consumer |
| Uncertainty and feature definitions | Owned by the labels/features audit (`scoring/uncertainty.py`, `catalogue_v2.py`); V2 binds them only by `FeatureCatalogueRef` and `ModelRefV2` |

## 5. Controlled comparison: B0/BR/B1 now, B0-B3 later

`comparison_readiness(manifest, runs, budget)` returns reason codes. An empty tuple means the runs form the
pre-registered comparison:

- every run carries the manifest's fingerprint;
- all runs share one candidate book and one market fingerprint;
- portfolios are distinct per scenario;
- every run uses the risk policy the manifest anchors for its cost scenario (base or pessimistic);
- per scenario, every declared variant is present: B0 (and B2) once per lottery seed, BR once per control seed, B1
  (and B3) exactly once;
- every BR run names the fingerprint of the given B1 `ActivityBudget`.

Publishing only the best BR seed, or adding an undeclared seed, gives `control_seeds_not_exactly_preregistered`
(tested).

BR is fed to the existing V1 risk gate through `migrate_v1_v2.gate_variant(BR) = B0`. The only thing borrowed is the
gate's "no score expected" rule. BR keeps its own portfolio id and its own gate instance. The factorial
`(B0, B1, B2, B3)` and the five-variant design are already valid manifests, so B2/B3 need no further contract
change.

## 6. Trial ledger (D4)

`trial_ledger.py` writes JSONL files named `*.h3trials.jsonl`. Each line holds one canonical JSON entry
`{seq, prev_hash, kind, recorded_ms, body, entry_hash, schema='h3.trial_ledger_entry/2'}`, where
`entry_hash = sha256(canonical JSON of everything but entry_hash)`.

**Entry kinds.**

- **GENESIS**: the ledger id. It is always line 0.
- **ATTEMPT**: written before any result exists. It holds:
  - the trial id and kind;
  - the `ProtocolRef`;
  - the attempt number in that protocol, assigned by the ledger;
  - the complete parameter document and its fingerprint (no floats: decimals are strings);
  - the datasets (id, sha256, label) and the most confirmatory label;
  - the model, generator and catalogue fingerprints;
  - the planned tests;
  - the code digest.
- **RESULT**: names its attempt by sequence and hash. It holds the status (`completed` / `failed` / `abandoned`), a
  reason code unless completed, and the result document with its fingerprint. A completed result must carry its
  document.

**Rules enforced on write and on `verify()`.**

- Sequence, chain, entry hash and monotonic time must all hold. Any rewrite, truncation, reordering, deletion,
  replay or non-canonical line is refused.
- An attempt on VALIDATION, HOLDOUT or PROSPECTIVE data needs a FROZEN protocol. It is allowed once per (protocol id,
  dataset id) and can never be `abandoned`, so a bad confirmatory read cannot be withdrawn.
- A result needs an earlier attempt, and an attempt gets at most one result.
- The writer re-verifies the file before each append and compares it with the chain it already knows. A clean
  truncation, or a whole chain rewritten with recomputed hashes, is therefore detected (`ledger_changed_on_disk`).
- The ledger only creates new files (`open(..., 'x')`) and only appends to files whose first line is its own GENESIS
  entry. It cannot write into an existing registry, and the tests write only under `tmp_path`.

**Publication.** `publication_claim(trial_id)` returns the ledger head, the protocol's attempt count, its results by
status and the number still pending. `verify_publication(path, claim)` refuses a claim in four cases:

- the head is not in the chain;
- the trial's completed result comes after the head;
- the claim understates the attempts;
- the claim names the wrong ledger.

A team that tries hundreds of configurations must therefore disclose all of them to publish one. The manifest's
`trial_ledger_head` binds a FROZEN experiment to the ledger state at pre-registration.

**Limit.** Hash chaining detects edits of the file. It cannot detect a ledger that was never written: a team could
keep two ledgers. The defence is procedural: the ledger id and head are pre-registered with the protocol, and only one
ledger per protocol is accepted. The walk-forward `TrialLedger` is not replaced; migrating its callers is a
coordinator decision (section 10).

## 7. Risk compatibility (D3)

**Unchanged.** None of the following was modified:

- `CanonicalRiskPolicy`, `CanonicalRiskEngine` and `CanonicalPortfolioAdmissionEngine`;
- production parameters, live adapters, Temporal and forward workflows;
- `H3RiskGate` and `RiskDecision`.

**Python tests (synthetic).** V2 candidates go through `to_v1()` into the existing `H3RiskGate`, configured from
`CostModelV2` through `execution_assumptions`. The cost rates are realistic synthetic values:

- taker fee 5 bp, maker fee 2 bp;
- slippage 5 bp, half-spread 2 bp;
- funding provision 1 bp per 8 h.

The grid covers both sides, base and pessimistic (×2) costs, and four invented instruments: a fine lot, a coarse
lot of 0.1 at 3000, a low price with `min_notional`, and a large contract value. Stops are at 0.3 %, 1 %, 3 % and
8 %, rounded away on the tick grid.

For every ADMIT the tests check:

- the quantity is on the lot grid and ≥ min size;
- the stop is present and protective;
- risk ≤ 0.5 % × 500 = 2.5 and open risk ≤ 1 % × 500 = 5;
- leverage ≤ 1x and notional ≤ equity;
- the gate's `risk_amount` equals an **independent recomputation** from the V2 cost numbers (gross loss at the
  stop, plus fees, spread and slippage on both legs, plus the funding provision);
- one more lot would break a cap.

Other tests check that:

- the total cap binds with 4 USDT of open risk (`cap_total_risk`, risk ≤ 1);
- BR is refused a score (`score_not_expected`);
- a V2 candidate cannot be built without a protective stop.

**PHP canonical engines (static reading only, no PHP run).** Field-level compatibility of the V2 additions:

| V2 field | PHP counterpart | Status |
|---|---|---|
| `H3Limits.per_trade_risk_max` 0.005 | `CanonicalRiskPolicy::$riskRate` (budget = equity × riskRate) | mapped |
| `H3Limits.leverage_max` 1 | `CanonicalRiskPolicy::$modeLeverageCap`; `CanonicalRiskDecision::$finalLeverage` is `int`, `max(1, …)` | mapped (1x is expressible) |
| `H3Limits.total_risk_max` 0.01 | No per-position open-risk sum. `CanonicalPortfolioPolicy` has `dailyLossRate`, `maxConcurrentPositions` and `modeExposureRate` | gap (H3-only rule, as in V1) |
| `CostModelV2.maker/taker_fee_rate` | `CanonicalRiskPolicy::$makerFeeRate/$takerFeeRate` | mapped |
| `CostModelV2.half_spread_rate` | `CanonicalCostSnapshot::$entrySpreadRate/$stopSpreadRate` | derived (same rate on both legs) |
| `CostModelV2.slippage_rate` | `CanonicalCostSnapshot::$entrySlippageRate/$stopSlippageRate` | derived (same rate on both legs) |
| `CostModelV2.funding_provision_rate`, `funding_interval_ms` | `CanonicalCostSnapshot::$fundingRate`, `$fundingIntervals` (a count) | derived (`provision_intervals(max_holding_ms, interval)`) |
| `CostModelV2.pessimistic_multiplier` | none | gap (research scenario only) |
| Cost-inclusive `risk_amount` | `CanonicalRiskDecision::$totalStopLoss` | mapped (same components, as in the V1 map) |
| `risk_policy.policy_fingerprint` | `CanonicalRiskPolicy::$configHash` | divergent (different documents hashed) |
| `ProvenanceV2.venue/product` | `CanonicalRiskPolicy::$exchange`, request `marketType` | divergent for X-Perps (GAP-XPERP in `tradingcore_map.py`) |
| `quote_ccy` (`USDC`) | request `quoteCurrency` regex `[A-Z][A-Z0-9]{2,11}` | compatible format; the USD/USDC split remains the V1 divergence |
| `GeneratorRef`, `FeatureCatalogueRef`, `LabelSpecRef`, `ModelRefV2`, `RandomControlSpec`, `ActivityBudget`, `VariantRunRef`, `LabelOutcome`, ledger | none | research_only |

## 8. Tests (D5)

| Area | Tests |
|---|---|
| V1 frozen | sha256 of `contracts.py` and `golden_v1.json`; V1 fingerprints after importing V2; V1 schema ids `/1` |
| Serialisation and hashes | Round trip and identical fingerprint over 9 builders; floats, unknown fields and cross-version documents refused; decimal scale invariance |
| Identities | A different generator fingerprint gives a different candidate, while V1 cannot tell them apart; `score.scores(candidate)` |
| Migrations | Lossless for candidate, snapshot, score, cost, provenance, variants; model ref, model manifest and experiment upgrades; 5 missing-supplement refusals; BR → V1 refused; inconsistent inputs refused |
| Abstention and missing data | V1 abstention rules hold in V2; ESTIMATED needs a calibrated model available at the decision; PARTIAL snapshot; label absent needs a reason; labelled needs `available_ms > decision` |
| Random control | Method/strata consistency; non-decision-time keys refused; seed rules; budget mapping round trip, counts, B1 only |
| Manifest | 12 invalid-manifest refusals; three designs; cross-venue (underlying × time) overlap; training on evaluation data refused |
| Controlled comparison | Ready with all seeds (base + pessimistic); missing, extra or cherry-picked seed, budget mismatch or missing, unshared book, unanchored policy, wrong manifest and missing variant refused |
| Risk | Section 7 (both sides × both cost scenarios × 16 cells, total cap, BR score-blind) |
| Runtime independence | AST: stdlib and relative imports only, parse with Python 3.11; a subprocess import loads no `app`, `temporalio`, `fastapi`, `httpx`, `requests`, `sqlalchemy`, `psycopg`, `numpy` or `redis`, nor `research_h3.integration` |
| Trial ledger | 14 tests: chain, numbering, reopen; complete identities; result rules; confirmatory rules; 6 tampering cases; forged chain; foreign/existing files untouched; publication disclosure; byte reproducibility |

## 9. What changed V1 → V2, what must migrate, which tests pass

**Changed (new V2 ids).**

- `provenance/2`;
- `model_ref/2` and `model_manifest/2`;
- `feature_snapshot/2`, `opportunity_candidate/2` and `opportunity_score/2`;
- `cost_model/2`;
- `experiment_manifest/2`;
- `decision_audit/2`.

**New.**

- `generator_ref/2`, `feature_catalogue_ref/2` and `label_spec_ref/2`;
- `label_outcome/2`;
- `random_control_spec/2`, `stratum_count/2` and `activity_budget/2`;
- `variant_run_ref/2`;
- the `VariantV2`, `Population`, `LabelUnit`, `LabelStatus`, `ControlMethod`, `BudgetSource` and `CostScenarioV2`
  enums;
- `trial_ledger_entry/2`.

**Unchanged and reused as V1.**

- `Header`, `Clock`, `InstrumentRef`, `ProtocolRef`, `PolicyRef`, `SnapshotRef`, `FeatureValue`, `NamedValue`,
  `H3Limits`;
- `Bar`, `Quote`, `FundingObservation`;
- `PortfolioView`, `PositionState`, `AdaptiveAction`, `RiskDecision`.

**Must migrate before any FROZEN H3-A protocol** (none is done here):

1. The validation harness must accept `ExperimentManifestV2` and produce `VariantRunRef`s, so that
   `comparison_readiness` gates `compare.evaluate`.
2. The abstention module's `RandomControlSpec` / `ActivityBudget` must be serialised as the V2 contracts. The field
   names are identical; the per-stratum mapping becomes `strata` (`from_mapping` / `as_mapping`).
3. The labels module must emit `LabelOutcome` with a `LabelSpecRef`. Exit time must be added to `CandidateLabel`
   for `available_ms`.
4. The scorer must emit `OpportunityScoreV2` from a `ModelManifestV2`, and the catalogue must publish its
   `catalogue_fingerprint`.
5. The common generator must publish its parameter document and `generator_fingerprint`.
6. Development trials must be recorded in a `*.h3trials.jsonl` ledger, with its id and head pre-registered.

**Can stay V1 for now.** Adaptive management, the simulator and `H3RiskGate` (BR uses `gate_variant`), and market
data.

**Tests.** `tests/test_research_h3_*.py`: 425 passed, of which 56 are new (42 contracts V2 + 14 ledger). Parity:
`golden vectors: OK`.

## 10. Change requests and limits

- **CR-V2-1 (gate).** `H3RiskGate` and `PortfolioView` should accept `VariantV2`, so BR stops borrowing B0's score
  rule; also add `open_instruments` (#8).
- **CR-V2-2 (walk-forward).** Replace or wrap `validation.walkforward.TrialLedger` with `trial_ledger.TrialLedger`;
  map `Trial.kind`, which is a subset of `TRIAL_KINDS`.
- **CR-V2-3 (labels).** Add the exit instant to `integration.label_replay.CandidateLabel`.
- **CR-V2-4 (registry).** `validation.registry.check_use` should read `ProvenanceV2.overlaps` for the
  (underlying × time) rule, which is currently conservative by dataset id.
- **Limit.** `ModelRefV2.available_ms` for a model migrated from V1 is the V1 `created_ms`: this is a stated
  derivation, not an observed availability.
- **Limit.** No PHP code was run; section 7's PHP table is a static reading.
