# H3 — experimental design: separating scoring from adaptive management (B0/B1/B2/B3)

Status: **DRAFT design.** Nothing was run on real data. Every number this framework has produced so far comes from
synthetic panels and is not evidence about any market. The statistical plan and its DRAFT thresholds are in
`H3-PREREGISTRATION-DRAFT.md`; data eligibility is in `H3-DATA-ELIGIBILITY.md`.

Code: `python-orchestrator/scripts/research_h3/validation/` (pure Python, Decimal accounting, no numpy). Tests:
`python-orchestrator/tests/test_research_h3_validation.py` (synthetic only).

## 1. What the experiment must separate

The owner's question: can a portfolio's net expectancy be improved durably by ranking opportunities by predicted
quality and by adapting exits to what the market shows during the trade? It has three parts:

- **H3-A (scoring):** do features observable at T separate candidates with a favourable net expectancy, in a way
  that improves the portfolio?
- **H3-B (adaptive management):** does managing a position with information available during the trade only
  improve the net result against a static exit?
- **H3-C (combination):** does combining both add value beyond each engine alone?

A scoring engine creates no opportunity. All four variants therefore draw from **one common candidate generator**,
built on an explicit economic hypothesis and blind to any score. Which generator is still open (candidate-source
dossiers CS-FUND, CS-UNLOCK, CS-XMOM); the design is generator-agnostic, and the protocol cannot be frozen without one
(`H3-PREREGISTRATION-DRAFT.md` §2).

## 2. The 2 × 2 factorial

| | Static management (HOLD only; exits by stop, target, end of validity) | Adaptive management (policy of agent B, through the risk gate) |
|---|---|---|
| **Reference entries** (every candidate, fixed score-blind order) | **B0** | **B2** |
| **Scored entries** (TRADE_CANDIDATE in rank order; NO_TRADE is a refusal) | **B1** | **B3** |

- **Reference order.** Within a decision instant, B0 and B2 offer every candidate to the risk gate in an order set
  by a seeded lottery on the candidate id: sha256(seed, candidate id) (`factorial.reference_order`). It ignores
  scores and features. Under the shared limits (0.5 % risk per trade, 1 % total, 1x leverage) capacity binds, so the
  order matters. B1 - B0 then measures what scoring adds over a score-blind choice made under the same capacity.
- **Scored entries.** The scorer is called once per candidate, and each instant is ranked once
  (`factorial.score_book`). B1 and B3 receive the same ranked scores. NO_TRADE candidates become refusals at the
  `score` stage, with the scorer's reasons.
- **Static policy.** It always returns HOLD; exits come only from the stop, the target or the end of validity.
  **Adaptive policy.** Each non-HOLD action passes the risk gate. The contracts forbid widening the stop or raising
  open risk or notional.

## 3. Identical inputs and independent portfolios (C1)

The owner requires that all four variants receive the same initial candidates and the same market stream, while
their portfolios evolve independently. The harness (`factorial.run_factorial`) secures this in three ways.

**By construction:**
- The generator runs once per decision instant, before any variant and before any score. Its output is one frozen
  `CandidateBook`. Each candidate must come from a snapshot of its own instant, carry no portfolio, and, when a
  point-in-time universe is given, belong to it.
- One frozen `MarketStream` (bars, funding, quotes, fingerprinted at construction) is passed, as the same object, to
  all four simulator runs.
- Each variant gets a fresh simulator and a fresh risk gate from their factories; reusing an instance is refused. It
  also gets its own portfolio id.
- The four variants share capital, cost model, cost scenario, H3 limits and period.

**By check, after every variant run:**
- the run echoes the book and market fingerprints, the candidate ids, and its own variant and portfolio id;
- recomputed fingerprints of the shared objects are unchanged;
- every score-stage refusal is kept;
- every record carries the run's portfolio id (`records.VariantRun`).

**By test:**
- the generator is called once per instant and the scorer once per candidate;
- B0 and B2 receive identical entries, and so do B1 and B3;
- running the variants in reverse order gives byte-identical runs;
- flipping the scorer changes B1 and B3 and leaves B0 and B2 byte-identical;
- a simulator that lies about its inputs is refused.

A run is accounted for in exact Decimal (`records`):
- net = gross - fees - spread - slippage - funding;
- every candidate ends as exactly one trade or one refusal;
- final equity = initial equity + Σ net, with every position closed by the end;
- a stop is never widened.

## 4. Comparators: absolute and incremental improvement

| Question | Comparison | Role |
|---|---|---|
| Does the full system improve the reference? | B3 - B0 | primary component P1 |
| Does it earn net of every cost? | B3 against cash (no trading, capital kept) | primary component P2 |
| Scoring alone (H3-A) | B1 - B0 | secondary S1 |
| Adaptive management alone (H3-B) | B2 - B0 | secondary S2 |
| Adaptation once entries are scored | B3 - B1 | secondary S3 |
| Scoring once exits are adaptive | B3 - B2 | secondary S4 |
| Synergy | (B3 - B2) - (B1 - B0) | descriptive |
| Each variant's absolute result | B0, B1, B2 against cash | descriptive |
| Directional market exposure | B3 against a passive exposure | descriptive, only when meaningful |

- **Absolute vs incremental.** The primary criterion is the intersection-union of P1 and P2: H3 must improve the
  reference **and** gain net of costs. An improvement that only turns a loss into a smaller loss fails P2; a gain
  that the reference already had fails P1. A synthetic test covers both cases. Under the intersection-union
  principle, the composite has level α without adjustment: both components must reject at α.
- **H3-C.** It is supported only if S3 and S4 both reject: the combination beats each engine alone. The interaction
  contrast is published with its interval but is never a claim, because super-additivity is not the owner's
  question.
- **Cash.** Interest on idle capital is not modelled. **Correction (review R1-F10):** a 0 % comparator is easier to
  beat than a yield-bearing one, so this choice is lenient towards B3, not conservative. The owner pre-declares a cash
  yield before the freeze, or B3 is reported against a stated risk-free rate descriptively.
- **Attribution (review R1-F1, R1-F2).** A rejected S1-S4 is not an attribution: S1 mixes ranking skill with
  abstention and S2-S4 mix the exit rule with exposure. Without matched controls `compare.evaluate` reports a
  rejected hypothesis as `rejects_null_attribution_not_identified`, never `supported`
  ([H3-INDEPENDENT-REVIEWS.md](H3-INDEPENDENT-REVIEWS.md)).
- **Passive exposure.** It holds the universe's instruments in the generator's direction at 1x for the whole period,
  with the same fees and funding (`comparators.passive_pnl_path`, `basket_pnl_path`). It is computed only when every
  candidate has the same side and the product can be held continuously. A two-sided or market-neutral generator has
  no passive twin, so the comparison is reported `NOT_APPLICABLE`. It stays descriptive: a directional generator
  that only harvests beta would show up here without inflating the confirmatory family.

## 5. Unit of analysis and dependence (C6)

Trades are not independent. Trades in the same week share market shocks, positions overlap, and a regime can last
several weeks. The unit of analysis is therefore the **time block** (DRAFT: one calendar week):

- **Block result.** Each variant's per-block net PnL is its marked-to-market equity change between block edges,
  as a fraction of the initial capital. The fixed denominator avoids a compounding confound between portfolios that
  diverge. Comparisons pair the two variants block by block.
- **Inference.** The plan's `test_procedure` is a one-sided test on the block series (`stats.one_sided_test`):
  - `cbb_percentile`: circular block bootstrap, block length round(n^(1/3));
  - `sn_gaussian`: self-normalised;
  - `batch_t`: batch-means t.

  The synthetic power studies rejected the bootstrap: it is anti-conservative at 13-52 weeks. They found no
  procedure that keeps its level in every scenario below about two years of weekly blocks. **The primary procedure
  is therefore not validated, and the code refuses to freeze the plan** (`H3-PREREGISTRATION-DRAFT.md` §8). Each
  comparison's seed derives from the plan seed and the comparison id, so results do not depend on call order
  (tested).
- **Diagnostics, published with every result:**
  - the effective number of blocks (initial-positive-sequence autocorrelation sum);
  - the lag-1 autocorrelation and a Ljung-Box test of the difference series;
  - for trades, the intraclass correlation of R within blocks, the Kish design effect and the effective number of
    trades. A thousand trades with a design effect of 10 carry about a hundred trades' worth of information.
- **Size check.** The synthetic studies measure the size of each block-level procedure under dependence and heavy
  tails, and how far the forbidden naive trade-level test departs from its nominal level: up to 39 % false
  positives at a nominal 5 % (`H3-PREREGISTRATION-DRAFT.md` §8).

## 6. Multiplicity

- **One primary criterion, declared before any read.** It is the intersection-union of P1 and P2 and needs no
  adjustment.
- **Four secondaries** in one Holm family at α = 0.05 (DRAFT). They are confirmatory only when the verdict is
  `CRITERION_MET`; this serial gatekeeping preserves the family-wise error. They are always computed and published,
  and otherwise labelled `secondary_exploratory_only_primary_not_met`.
- **Descriptive comparisons** carry no claim.
- **Development trials.** Every configuration tried before the freeze (generator variants, models, policies,
  thresholds, feature sets) is recorded in a `TrialLedger`, together with the Šidák level that would cover the count,
  and the count is disclosed with the result. The ledger refuses trials on validation, holdout or prospective data.
  The confirmatory run uses exactly one frozen configuration per engine.
- **No selection after the fact.** The report always contains all five comparisons, in the fixed order. The verdict
  depends only on the primary rule and the guards, and no comparison is chosen by its result.

## 7. Verification of the scoring engine (C4)

`validation/scoring_checks.py`:

- **Labels.** A candidate's label is its net R under the static reference management (the B0 policy), from a
  consistent replay of that candidate. It is never the best exit seen afterwards.
  - Candidates that B0 did not trade (capacity) are `unlabelled` and counted. Since review R1-F3 the capacity-free
    label replay exists (`integration.label_replay.capacity_free_labels`, joined by
    `scoring_checks.join_scores_labels`): every candidate is replayed alone, so its label does not depend on capacity.
    It is the only label source for training and for these checks; B0's trades serve the economic translation only.
  - A score decided after its trade's entry is refused.
- **Calibration.**
  - Predicted vs realised net R per bucket.
  - OLS slope and intercept (a calibrated model has 1 and 0).
  - The Brier score of `p_stop` against stop exits, with a reliability table.
- **Ranking.**
  - Pooled Spearman (pure Python, average ranks for ties).
  - Cross-sectional rank correlation per decision instant.
  - Its uncertainty, by a block bootstrap of the per-block mean.
- **Score groups.** Bucket edges are either fixed a priori in the protocol or fitted on training rows only
  (`walkforward.FrozenEdges`). Applying training-fitted edges to in-sample rows is refused.
- **Abstention.** Compares the reference outcomes of the candidates the scorer kept with those it refused.
- **Robustness.**
  - Spearman by period and by instrument, with the share of positive groups.
  - Population stability index of features (train vs test).
  - Sign stability of model parameters across walk-forward folds.
- **Economic translation.** `scoring_verdict` combines the ranking test with S1 (B1 - B0). A good ranking without
  portfolio improvement reads `scoring_ranking_without_economic_value`, never a success.

## 8. Verification of the adaptive manager (C5)

`validation/adaptive_checks.py` pairs a static run with the adaptive run that has the same entry rule (B0 with B2, B1
with B3). Both see the same candidates and market under the same costs.

`paired_exits` reports:
- the per-candidate ΔR and a cross-table of exit reasons;
- holding time, funding paid, partial exits and gap excess;
- the candidates only one of the two runs traded (capacity diverges once exits differ). These differences are why
  the decisive comparison is at portfolio level (S2, S3).

**The counterfactual comes from one consistent replay, never from picking the best future exit.** The simulator
contract (§10) requires:
- the full price path;
- stops before targets inside a bar when both are touched;
- the end of validity (`max_holding_ms`);
- partial exits;
- fills no better than the stop or the bar range;
- a liquidity cap;
- funding over the real holding time.

The checks look for a replay that breaks this:
- `oracle_exit_findings`: the share of adaptive closes priced at the best price of the candidate's **whole validity
  window**, including bars after the exit (DRAFT alarm at 50 %), plus exits outside the traded range. A synthetic
  hindsight policy is caught at 100 %.
- `fill_realism_findings`: stop fills better than the stop, prices outside their bar, and entry size above 1 % of
  the bar's traded value (DRAFT).
- `funding_coverage_findings`: funding settlements between entry and exit that the trade did not count.

## 9. Chronology, lookahead and survivorship (C6)

`validation/walkforward.py`:

- **Walk-forward.** Chronological, non-overlapping test periods, expanding or rolling training.
  `test_start = train_end + embargo`.
- **Purge.** A model fitted at `train_end` may only use labels realised by then. A training label whose horizon ends
  after `train_end` is purged. Test labels that cross `test_end` are flagged, and dropped on request.
  `check_assignment` re-verifies an assignment produced elsewhere.
- **Train-only transforms.** Scalers and quantile edges refuse, at fit time, any row available at or after their
  cutoff, and record the cutoff.
- **Point-in-time universe.**
  - An instrument is eligible only if it is listed, its listing is known, and it is not delisted at the decision.
  - Candidates outside that universe are refused by `build_candidate_book`.
  - `survivorship_findings` detects a universe built from today's live list, which silently drops delisted
    instruments.
- **Contract-level rules.** The shared contracts already refuse features available after the decision, unclosed or
  unpublished bars, and real-data training under a DRAFT protocol. `MarketStream.bars_visible_at` gives the
  decision-time view.

## 10. Simulator contract (for the integrator and agent D)

`factorial.VariantSimulator` has one method, `run(spec: VariantSpec, market: MarketStream) -> VariantRun`. The
simulator must:

- **Inputs.** Replay `market` only, decide on observable values only, and give the policy `PositionState` values
  built at bar closes that are already available.
- **Entries.** Offer `spec.entries` in priority order within each decision instant, and call `spec.risk_gate` for
  every entry. A REJECT becomes a `RefusalRecord` at stage `risk`; failures to fill or size become stage
  `execution`; candidates whose validity ended while waiting become stage `expired`. It keeps `spec.pre_refusals`.
- **Management.** Call `spec.policy.decide` for open positions. Every non-HOLD action goes through
  `spec.risk_gate.validate_action`, and only ADMIT actions are applied.
- **Costs.** Apply `spec.cost_model`. Under `CostScenario.PESSIMISTIC`, every cost component (fees, spread,
  slippage, adverse funding) is multiplied by `cost_model.pessimistic_multiplier`.
- **Closing.** Close everything by `spec.end_ms` (`END_OF_DATA`), mark equity to market at least at every block
  edge, and fill `TradeRecord.gap_excess` with the realised loss beyond the stop.
- **Output.** Return a `VariantRun` echoing the spec's identity fields. The harness refuses any mismatch.

The test file contains a minimal hourly stub simulator that satisfies this contract. It is test scaffolding only;
agent D's bar-replay simulator is the real one.

## 11. What is tested, and what is not

Tested, on synthetic data only:
- the accounting identities;
- input identity and portfolio independence;
- order invariance;
- the primary and secondary logic, including the intersection-union failure modes and the guards;
- the bootstrap, Holm (cross-checked with `scripts/research_lev/stats.py`), Spearman, the χ² and normal tails
  (against known values), the effective sample size, Ljung-Box and the ICC;
- metrics (cross-checked with `app/backtesting/research/statistics.py::OutcomeStatistics`);
- purge and embargo, train-only fits, point-in-time universe and survivorship;
- the oracle-exit and fill-realism detectors;
- the passive comparator;
- the data registry, kept in sync with `H3-DATA-ELIGIBILITY.md`.

Not tested: anything on real data; agent D's simulator; agent A's and B's engines (stubs only); the capture pipeline
a prospective test needs.
