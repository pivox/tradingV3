# H3 — draft pre-registration (status `DRAFT_NOT_FROZEN`)

**This draft is not frozen and authorises nothing.** It has no sha256. No real backtest, no training on real data and
no prospective capture may start from it. It can be frozen only after:

1. a candidate generator with an explicit, defended economic hypothesis is named (§2);
2. the owner arbitrates every item of §10;
3. a frozen version is committed with its sha256 before any new performance is read.

It was written on 2026-10-15 without reading any price, return, trade or event of any market. Every number below is
DRAFT: chosen a priori, with its reason given, to be confirmed or changed by the owner. No threshold was chosen from
a known performance. The only numbers produced so far are synthetic (§8).

Design: `H3-EXPERIMENTAL-DESIGN.md`. Data: `H3-DATA-ELIGIBILITY.md`. Code: `python-orchestrator/scripts/research_h3/`.

## 1. Question

« Peut-on améliorer durablement l'espérance nette d'un portefeuille en classant les opportunités selon leur qualité
prédite et en adaptant les sorties selon l'évolution observable du marché ? » (owner). No statistical edge is
demonstrated for H3. The question has three parts: H3-A (scoring), H3-B (adaptive management) and H3-C
(combination). They are tested through the B0/B1/B2/B3 factorial (`H3-EXPERIMENTAL-DESIGN.md` §2).

H3 must not be used to recover H1, H2 or any of the families already closed. Data already read are development data
only, never independent confirmation.

## 2. Candidate generator: OPEN (blocks the freeze)

The scoring engine creates no opportunity. Every variant draws from one common generator (`contracts.CandidateProvider`)
whose economic hypothesis is explicit. The protocol is **generator-agnostic**: nothing below depends on which
generator is chosen. It nevertheless **refuses to freeze without a named, defended generator**:

- `ExperimentManifest.candidate_generator_id`, `candidate_generator_version` and `hypothesis_id` must name it;
- a written economic mechanism must exist, together with its difference from the invalidated families (gate M4,
  `RESEARCH-ELIGIBILITY-GATES.md`), its expected event frequency, its side(s) and products, and its data needs
  checked against `H3-DATA-ELIGIBILITY.md`;
- the generator is fixed before any score or management policy is evaluated on its candidates.

The candidate-source dossiers CS-FUND (funding carry), CS-UNLOCK (token unlocks) and CS-XMOM (cross-sectional
momentum) are being prepared elsewhere and are not part of this draft. If none yields a defensible mechanism, H3 stops
before the freeze with the finding "no defensible candidate generator". Scoring or adaptive management are never run
on a generator chosen for convenience.

The generator's event frequency and its expected per-trade effect decide whether a prospective confirmation is
feasible at all. The synthetic study (§8.3) shows that a six-month window detects only large effects. At 2 trades per
week over 26 weeks, a gain of 0.5 R per trade is detected in 62 % of `iid_normal` panels and 30 % in the worst
scenario (`sn_gaussian`).

## 3. Variants and components (fixed at the freeze)

| Variant | Entries | Management |
|---|---|---|
| B0 | every candidate, seeded score-blind order (`factorial.reference_order`, seed = manifest seed) | static (HOLD; stop, target, end of validity) |
| B1 | scored: TRADE_CANDIDATE in rank order | static |
| B2 | as B0 | adaptive (agent B's policy) |
| B3 | as B1 | adaptive |

Fingerprinted at the freeze, in the `ExperimentManifest`:
- the generator;
- the scoring `ModelManifest`, calibrated on development data only and with its trial count disclosed;
- the static and adaptive `PolicyRef`s;
- the risk policy;
- the `CostModel`.

Fixed for every variant:
- the H3 limits: risk 0.5 % per trade, 1 % total, leverage at most 1x, stop mandatory, adaptation never increases
  risk;
- capital of 500 USDT-equivalent;
- a single shared candidate book and market stream.

## 4. Data plan

- **Development** (designing the generator, fitting the scoring model, tuning the adaptive policy) uses synthetic
  data. Consumed historical data may be used only after the owner's explicit consent (`registry.check_use`); the
  consumed X-Perp-equivalent history to 2026-10-12 is one example.
- Every development trial is recorded in the `TrialLedger`.
- **Confirmation: prospective only** (`INDEPENDENT_DATA_NOT_AVAILABLE` for historical data).
  - X-Perp 1m candles, funding and top-of-book quotes, captured after the freeze, from 2026-11-02 at the earliest
    (DRAFT), never inside 2026-10-14 → 2026-10-31.
  - The duration is fixed before the start: at least 26 weekly blocks (DRAFT floor, §7). §8 suggests that one to two
    years may be needed for level control under persistent dependence and for useful power; the independent review
    (R1-F4, R1-F5, §12) found that the studies cannot yet support a window choice in either direction. With a 2026-11-02 start,
    26 weeks run to early May 2027 and 104 weeks to late October 2028.
  - Nothing is fitted on prospective data.
- **The four variants replay the captured data in simulation.** No order is placed, demo included.

## 5. Metrics (C3), reported for every variant

Computed by `validation/metrics.py` and published together; per-trade performance alone is never enough.

- **Net result:**
  - net PnL;
  - return on initial capital;
  - mean and median net R (R = nominal loss at the initial stop);
  - profit factor;
  - win rate.
- **Equity drawdown** (marked to market): maximum depth, as a fraction of the peak, and the longest underwater span.
- **Costs:**
  - execution costs (fees, spread, slippage) and net funding paid, with the number of funding events;
  - a re-costed sensitivity at 2x costs (a bound only: the pessimistic scenario is a full replay).
- **Activity:**
  - number of trades;
  - refusals by stage (score, risk, execution, expired) and reason, and the refusal rate;
  - exit reasons.
- **Extreme losses:**
  - worst trade in R;
  - expected shortfall of R at 5 %;
  - trades below -1 R;
  - total gap excess.
- **Temporal stability:** sums of the block series over four chronological periods, the share of positive blocks,
  and the worst and best block.
- **Concentration:** share of gains carried by the top 5 % of trades; net PnL without them; net PnL without the top
  instrument; Herfindahl index over instruments.
- **Result under pessimistic costs** (full replay).
- **Dependence:** effective blocks, lag-1 autocorrelation, Ljung-Box, ICC of trade R within blocks, design effect,
  effective trades.

## 6. Statistical procedure

- **Unit.** One weekly block (DRAFT: 7 days, from the period start; the last block may be shorter). A block's
  result is the change in marked-to-market equity, as a fraction of the initial capital.
- **Test: NOT VALIDATED (blocks the freeze).** One-sided test of "mean > 0" on the block series by the plan's
  `test_procedure` (`stats.one_sided_test`). Three procedures are implemented and none is validated (§8):
  - `cbb_percentile`, circular block bootstrap (B = 9,999, block length round(n^(1/3))), was proposed first and
    rejected by synthetic study 1;
  - `sn_gaussian`, the self-normalised test, is the current placeholder (the tie-break preference declared before
    study 2);
  - `batch_t`, the batch-means t-test.

  `StatisticalPlan.procedure_validated` is False, and the code refuses a FROZEN plan until the owner chooses a
  procedure and a new synthetic rule validates it. Seeds derive from the plan seed (20261015, DRAFT) and the
  comparison id. The null is rejected when p ≤ α.
- **Primary criterion (single).** The intersection-union of:
  - P1, `global_vs_reference`: mean per-block (B3 - B0) > 0, paired by block;
  - P2, `absolute_vs_cash`: mean per-block B3 > 0.

  Each component is tested at α = 0.05 one-sided (DRAFT); both must reject. By the intersection-union principle the
  composite has level α. P1 alone is not the criterion: an improvement that leaves the portfolio losing money is not
  success.
- **Verdict** (`compare.evaluate`):
  - `INSUFFICIENT_EVIDENCE` if the minimum-information guard fails;
  - otherwise `CRITERION_NOT_MET` if the primary criterion is not met;
  - otherwise `CRITERION_MET_GUARDS_FAILED` if any other guard fails;
  - otherwise `CRITERION_MET`.

  No verdict means "profitable", "validated" or "go". The evidence label is `synthetic_not_evidence`,
  `development_not_confirmatory`, `protocol_not_frozen_not_confirmatory` or `confirmatory` (only with a frozen
  protocol and prospective data).
- **Secondary hierarchy.** One Holm family at α = 0.05 (DRAFT), confirmatory only if the verdict is `CRITERION_MET`
  (serial gatekeeping):
  - S1 `scoring` (B1 - B0): H3-A;
  - S2 `adaptation` (B2 - B0): H3-B;
  - S3 `adaptation_after_scoring` (B3 - B1);
  - S4 `scoring_with_adaptation` (B3 - B2).

  H3-C (combination) is supported only if S3 and S4 both reject. Otherwise the secondaries are published as
  exploratory.
- **Descriptive only:** the interaction (B3 - B2) - (B1 - B0); B0, B1 and B2 against cash; B3 against the passive
  exposure where meaningful. Each gets a two-sided 90 % interval from the plan's procedure
  (`stats.two_sided_interval`).
- **Scoring checks (C4) and adaptive checks (C5).** Run and published as specified in `H3-EXPERIMENTAL-DESIGN.md`
  §7-§8. `scoring_verdict` cannot report scoring value without S1. An oracle-exit, fill-realism or funding-coverage
  finding on the adaptive replay invalidates the run (the result is not read until the replay is fixed and re-run in
  full).
- **Multiplicity outside the confirmatory family.** Development trials are counted and disclosed (`TrialLedger`,
  with the Šidák level for the count). The report shows all five comparisons in a fixed order; none is selected by its
  result.

## 7. Success criteria (C8): rules, DRAFT values and reasons

| Criterion | Rule | DRAFT value | A-priori reason |
|---|---|---|---|
| Absolute net profitability | P2 rejects: per-block B3 net > 0 | α = 0.05 one-sided | Keeping the capital is always available. A strategy must beat it net of every cost. |
| Net improvement over B0 | P1 rejects: per-block B3 - B0 > 0 | α = 0.05 one-sided | The engines must add value to the same opportunities, not only ride the generator. |
| Incremental value of each engine | S1-S4 after Holm, gatekept | family α = 0.05 | Answers H3-A, H3-B and H3-C separately, without inflating the primary error. |
| Confidence intervals | one-sided 95 % lower bounds for P1, P2 and S1-S4; two-sided 90 % for descriptives | the plan's procedure | The bound, not the point estimate, carries the decision. |
| Stability | mean B3 - B0 > 0 in ≥ 3 of 4 chronological quarters of the period | 3 of 4 | "Durablement": an effect confined to one quarter is an episode, not a durable improvement. |
| Pessimistic costs | full replay at `pessimistic_multiplier` (fees, spread, slippage, adverse funding × 2); point estimates of B3 - B0 and of B3 net both > 0 | multiplier 2 | The X-Perp cost model is unverified (fee tier, book depth). A result that disappears when costs double is fragile. Since review R1-F10 the simulator scales all four components as written here (before, fees were not scaled). |
| Extreme losses | ES5 % of B3 trade R ≥ ES5 % of B0 - 0.25 R; no B3 trade below -2 R | 0.25 R; -2 R | Adaptation and selection must not buy expectancy with tail risk. At 1x leverage with a protective stop, a loss beyond 2 R points to a gap, fill or model failure that must be examined. |
| Concentration | B3 - B0 > 0 without its top-contributing instrument; B3 net > 0 without its top 5 % trades | 5 % | Same rule as H1/H2 (G7): no dependence on a few trades or one instrument. |
| Minimum independent observations | ≥ 26 weekly blocks; effective blocks of B3 - B0 ≥ 13; ≥ 100 B3 trades | 26; 13; 100 | 26 weekly blocks (half a year) is an a-priori floor, not a sufficient size: §8 found no procedure that passes the acceptance rule under persistent regime dependence, but that rule is itself too noisy to say how many blocks are needed (§12, R1-F5). Since review R1-F9 the effective-block floor also applies to B3 against cash (P2), and a secondary with fewer effective blocks is not confirmatory. 13 effective blocks allows at most a halving of information by autocorrelation. 100 trades is the floor already used across the campaign. |
| Economic threshold | none beyond "> 0 net of pessimistic costs" | — | No a-priori effect size can be defended without a generator. The owner may set a minimum economic effect (e.g. covering operating costs) before the freeze; §8 shows which effects the design can detect. |

The guards can only turn a met primary criterion into a not-met verdict, so they add no false positive. They are
implemented in `compare.guards` and tested on synthetic runs.

## 8. Synthetic power of the primary criterion

**Status: no primary test procedure is validated; the freeze is blocked on this point.** Two synthetic studies were
run, each under an acceptance rule written and hashed before any code or simulation of what it governs (same
discipline as the H2 power study). Every number below is synthetic: it describes the test procedure on invented
panels, not any market.

**Generator** (`validation/power.py`, `simulate_panel`). Outcomes are in R, with 1 R = 0.5 % of capital.

- A weekly regime factor follows an AR(1) with coefficient φ.
- Each week has Poisson(λ) candidates, traded by both B0 and B3.
- B0 outcome: R0 = μ0 + σ (√ρ f + √(1-ρ) e).
- B3 - B0 difference: D = δ + κ f + τ u, with heavy-tailed shocks in two scenarios.
- P1 tests the weekly sum of D; P2 tests the weekly sum of R0 + D.

Scenarios (τ = 0.75 R, σ = 1 R and ρ = 0.2 in all):

| Scenario | κ (R) | φ | Shocks |
|---|---:|---:|---|
| `iid_normal` | 0 | 0 | normal |
| `regime_normal` | 0.3 | 0.5 | normal |
| `regime_t3` | 0.3 | 0.5 | Student t3 |
| `strong_cluster_t3` | 0.6 | 0.7 | Student t3 |

Grid: 400 panels per cell; λ ∈ {2, 5, 15} trades per week; n ∈ {13, 26, 52} weeks (study 2 adds 104); α = 0.05 one-sided per component. With 400 panels, a rate near 0.05 has a Monte Carlo standard error of about 0.011, so the validity limit 0.075 sits about 2.3 standard errors above nominal.

### 8.1 Study 1: percentile circular block bootstrap, REJECT

The rule (`ACCEPTANCE-RULE.md`, sha256 `83f28cca47ca063f9c21f0886374aeb02a46f7a788e45c46440804b9b61d639d`, hashed
2026-10-15T20:59:38Z) required a size of at most 0.075 at δ = 0 in every scenario × λ cell. The verdict was
**REJECT**: no n in {13, 26, 52} qualifies (`power-results.json` sha256 `12bb1dc1…131a`, `decision.json`
`d6e554da…87d4`).

| Scenario | Max false-positive rate of P1, block bootstrap (all λ, n) | Max false-positive rate, naive trade-level test |
|---|---:|---:|
| `iid_normal` | 0.115 | 0.070 |
| `regime_normal` | 0.140 | 0.253 |
| `regime_t3` | 0.158 | 0.260 |
| `strong_cluster_t3` | 0.238 | 0.393 |

Reading:

- The percentile bootstrap is anti-conservative at these sample sizes, even without dependence. Its short blocks
  understate the long-run variance when weeks are persistent.
- Treating correlated trades as independent observations (the naive test) produces up to 39 % false positives at a
  nominal 5 %. This is the synthetic demonstration of why the unit is the time block.

### 8.2 Study 2: dependence-robust candidates, NO_VALID_CANDIDATE

The rule (`ACCEPTANCE-RULE-2.md`, sha256 `48fde3681930034513ec8dc29ef6bc352a66c5c52b58371a09f75b15fdd9a76f`, hashed
2026-10-15T21:08:23Z, before any code for these procedures) compared two candidates. Study 1's verdict was not
revised.

- **`sn_gaussian`**, the self-normalised test: Lobato 2001 and Shao 2010, calibrated on the Gaussian i.i.d. null of
  the same length. Its simulated 95 % quantile is 5.40 / 5.36 / 5.35 / 5.22 at n = 13 / 26 / 52 / 104, against a
  large-n literature value of 5.32.
- **`batch_t`**, the batch-means t-test with max(3, round(√n)) batches.

The verdict was **NO_VALID_CANDIDATE** (`power-results-2.json` sha256 `50e4083a…d5ae3`, `decision-2.json`
`cd4dc3e6…0f2`). The six study-1 cells re-run with the modified module reproduced study 1 exactly.

| Procedure | n = 13 | n = 26 | n = 52 | n = 104 |
|---|---:|---:|---:|---:|
| `sn_gaussian`: max P1 rate at δ = 0, all scenarios | 0.135 | 0.110 | 0.100 | 0.072 |
| `sn_gaussian`: same, without `strong_cluster_t3` | 0.087 | 0.072 | 0.075 | 0.072 |
| `sn_gaussian`: max IUT rate at the IUT null (δ 0.3, μ0 -0.3) | 0.125 | 0.105 | 0.102 | 0.080 |
| `batch_t`: max P1 rate at δ = 0, all scenarios | 0.125 | 0.110 | 0.105 | 0.075 |
| `batch_t`: same, without `strong_cluster_t3` | 0.083 | 0.068 | 0.080 | 0.072 |
| `batch_t`: max IUT rate at the IUT null | 0.113 | 0.115 | 0.102 | 0.080 |

Reading:

- Both candidates are far better behaved than the bootstrap. Outside `strong_cluster_t3` they stay close to the
  limit from n = 26.
- Under strong, persistent clustering they exceed it until about two years of weekly blocks. Even at 104 weeks the
  intersection-union null gives 0.080 for both, against a limit of 0.075. **Caveat (review R1-F5):** 0.080 is 32
  rejections out of 400 panels, 95 % interval about [0.053, 0.107]; and the rule takes the maximum over 12 to 36
  cells, so a procedure of exact size 0.05 fails it in 13 to 34 % of studies. These figures do not show that about
  104 weeks are needed; the study must be re-run with Monte Carlo precision (about 4,000 panels per cell, or a
  multiplicity-adjusted upper confidence bound) and the realistic DGP of §12 before any window is chosen.
- The rule therefore leaves the procedure unvalidated. **Owner decision:** choose a procedure, and either accept a
  longer window or rule `strong_cluster_t3` implausible on stated grounds. A new rule is then written and run; this
  study does not make that choice.

### 8.3 Power (study 2): what a prospective window could detect

| | 26 weeks | 52 weeks | 104 weeks |
|---|---:|---:|---:|
| IUT power, `iid_normal`, 5 trades/week, δ = 0.3 R/trade (SN / batch) | 0.51 / 0.52 | 0.76 / 0.82 | 0.96 / 0.98 |
| IUT power, `iid_normal`, 15 trades/week, δ = 0.3 (SN / batch) | 0.71 / 0.73 | 0.88 / 0.95 | 0.99 / 0.99 |
| IUT power, `iid_normal`, 5 trades/week, δ = 0.1 (SN / batch) | 0.07 / 0.07 | 0.17 / 0.18 | 0.34 / 0.40 |
| P1 alone, `iid_normal`, 5 trades/week, δ = 0.1 (SN / batch) | 0.35 / 0.35 | 0.59 / 0.59 | 0.78 / 0.88 |
| IUT power, worst scenario, 15 trades/week, δ = 0.3 (SN / batch) | 0.24 / 0.24 | 0.24 / 0.28 | 0.29 / 0.37 |

Reading:

- **P2 binds the primary criterion.** B3's absolute result carries the whole market regime, which pairing removes
  from P1. "Better than B0" is far easier to show than "better than cash".
- **A six-month prospective window detects only large effects.** Even in the easiest scenario, a gain of 0.3 R per
  trade at 15 trades per week is detected about 70 % of the time.
- **Small effects are out of reach.** For scale only, and not as a threshold: the closed H1 Gate 2 measured +0.027 R
  per trade (`RESEARCH-CAMPAIGN-STATUS.md`). Effects of that order are undetectable by this design within two years.
- **Under strong clustering**, power at δ = 0.3 stays below 0.4 even at 104 weeks.

Consequence for the owner (not a decision): a prospective confirmation of H3 needs a generator with a large expected
per-trade effect and a high trade frequency, a window of one to two years, or an accepted lower power. Each of these
should be chosen before the freeze, on these synthetic figures and not on any performance.

Artifacts: `tradingV3-private/research/20261015T2230Z-h3-architecture/c-validation/power/` contains
`ACCEPTANCE-RULE.md`, `PRE-RUN.sha256`, `power_run.py`, `power-results.json`, `summary.md`, `decision.json`,
`SHA256SUMS` and `study1-module/`. Its subdirectory `study2/` contains `ACCEPTANCE-RULE-2.md`, `PRE-RUN-2.sha256`,
`power_run_2.py`, `power-results-2.json`, `summary-2.md`, `decision-2.json` and `SHA256SUMS`.

## 9. Stop rules

- **Before the freeze:**
  - stop if no defensible generator exists (§2);
  - stop if gate M1 or M2 fails for the account (API access to X-Perps, fee tier, minimum sizes);
  - stop if the capture pipeline or the data licence cannot be established.
- **During the prospective window:**
  - no interim look at performance and no early stop for efficacy or futility;
  - interim checks are limited to data quality (gaps, stale feeds, listing records) and to the oracle and
    fill-realism detectors on the replay mechanics;
  - the window is never extended or shortened after it starts.
- **Data quality.** If more than 10 % of the planned blocks (DRAFT) fail the quality checks, the verdict is
  `INSUFFICIENT_EVIDENCE`. The window is not prolonged to compensate.
- **One final analysis** at the pre-declared end date. If a bug is found afterwards, it is fixed, the whole analysis
  is re-run unchanged on the same data, and both results are reported.
- **No consequence for trading.** A `CRITERION_MET` authorises nothing: any trading decision is the owner's,
  separately, demo flags first (M5).

## 10. Decisions left to the owner (before the freeze)

1. The candidate generator and its economic hypothesis (§2).
2. α for the primary components and the secondary family (DRAFT 0.05 one-sided each).
3. **The primary test procedure** (no procedure is validated, §8). Choose among `sn_gaussian`, `batch_t` or another
   procedure. Then either accept a longer window, or rule `strong_cluster_t3` implausible on stated grounds. A new
   synthetic rule, with stated Monte Carlo precision and the realistic DGP of §12, must then validate the choice
   before the freeze.
4. The block length (DRAFT one week) and the minimum number of blocks (DRAFT floor 26; how many blocks validity
   needs under persistent dependence is not established, §12 R1-F5).
5. The guard values (§7): stability 3 of 4, pessimistic multiplier 2, tail tolerance 0.25 R and floor -2 R, top 5 %,
   effective blocks 13, 100 trades.
6. Whether to add a minimum economic effect to P1 or P2 (none by default).
7. The prospective window: start (≥ 2026-11-02, DRAFT) and duration (≥ 26 weeks; see §8.3 for power).
8. Whether consumed historical data may be used for development (consent per dataset, `H3-DATA-ELIGIBILITY.md`).
9. ~~The label replay for scoring checks: a capacity-free B0-policy replay (recommended) or B0's portfolio trades.~~
   **No longer a choice (review R1-F3):** labels for training and for the C4 checks come only from the capacity-free
   replay (`integration.label_replay`, implemented); B0's portfolio trades serve the economic translation only.
10. Bootstrap resamples (DRAFT 9,999; only for `cbb_percentile`) and seed (DRAFT 20261015).

## 11. Freeze checklist

The checklist covers the generator, the engines and fingerprints, the data, the runtime and the hash:

- [ ] Generator named, mechanism written, M4 difference stated, event frequency estimated without reading
      confirmation data.
- [ ] `ModelManifest`, static and adaptive `PolicyRef`, risk `PolicyRef` and `CostModel` fingerprinted; development
      `TrialLedger` summary attached.
- [ ] Every dataset checked with `registry.check_use`.
- [ ] The prospective window and the capture pipeline are declared.
- [ ] A primary test procedure validated by a new synthetic acceptance rule (`procedure_validated = True`).
- [ ] `ExperimentManifest` built with `ProtocolStatus.FROZEN`, and the `StatisticalPlan` set to FROZEN with the
      owner's values; its fingerprint is written here.
- [ ] Code digest of `scripts/research_h3/` recorded; the synthetic test suite is green.
- [ ] This document is renamed to `PREREGISTRATION-H3.md`, then hashed and committed before any prospective data are
      read.

## 12. Amendments after the independent reviews (2026-10-16)

Two independent reviews (quantitative methodology R1, CTO / risk / security R2) were answered in
[H3-INDEPENDENT-REVIEWS.md](H3-INDEPENDENT-REVIEWS.md). The points below change or qualify this DRAFT. None is
frozen; each item is a condition of any freeze.

- **Attribution needs matched controls (R1-F1, blocker).** S1 (B1 - B0) mixes ranking skill with abstention: under
  a negative-mean generator a coin-flip scorer that abstains 60 % of the time is reported significant in most
  synthetic panels. The protocol must add a pre-declared random-abstention reference B0m (per decision instant, a
  seeded random subset of the book with B1's acceptance count, over K lottery seeds) and define S1 = B1 - B0m; and an
  exposure-matched static control for S2-S4. Until then `compare.evaluate` reports every hypothesis as
  `rejects_null_attribution_not_identified` at best, never `supported`.
- **Separate answers need their own alpha (R1-F2, blocker).** Serial gatekeeping on B3 makes H3-A and H3-B
  unconfirmable on their own. The owner chooses a multiplicity procedure that gives them their own alpha (e.g. a
  graphical or fallback procedure, or Holm over {S1, S2, B3 vs cash}), and keeps the intersection-union test with P2
  as the gate of any economic decision.
- **Labels (R1-F3, fixed).** Capacity-free replay, see §10 item 9.
- **Power and size must be re-studied (R1-F4, R1-F5, blockers).** The power DGP assumed that B0 and B3 trade the same
  candidates; in the example they share 11 of 51. The re-run must go through the real mechanics (simulator, gate,
  capacity, abstention), with a negative μ0, partial overlap, at most one candidate per week and multi-week holds,
  and report size and power of P1, P2, the IUT and S1-S4, with Monte Carlo precision.
- **Execution timing (R1-F6, R2-F5, blocker).** At hourly bars with latency, entries and adaptive actions run one full
  bar after their decision; the 10 bp collar then refuses about 43-45 % of entry attempts (synthetic), selecting trades on the
  price path after the decision, and every B2/B3 gap exit in the example is action-induced. The owner chooses the
  rule (sub-bar fills at the decision, or re-anchoring the reference at the fill) before any label exists. Refusal
  reasons and action-induced gap exits are published per variant meanwhile.
- **Pessimistic costs (R1-F10, fixed).** Fees, spread, slippage and adverse funding are all scaled.
- **Cash comparator (R1-F10).** A 0 % cash comparator is lenient towards B3, not conservative. The owner pre-declares
  a cash yield, or B3 is reported against a stated risk-free rate descriptively.
- **Measured adaptive policy (R2-F9).** The simulator provides no liquidity status (UNKNOWN), and `AdaptivePolicyV1`
  defers partial closes unless liquidity is NORMAL: the policy measured by B2/B3 is **trail and target only**. A
  result may not be described as testing partial closes unless a liquidity status is pre-declared and fed.
- **Stop-replacement sensitivity (R2-F12).** A DRAFT sensitivity scenario is added: a per-modification failure rate
  of the cancel-then-place replacement (`REPLACE_CANCELLED_PLACE_FAILED`, the position unprotected for one bar), the
  rate to be set by the owner a priori.
- **Data independence (R1-F7).** The cross-venue quarantine and consumed-equivalence of `H3-DATA-ELIGIBILITY.md`;
  the (underlying x time) overlap rule waits for schema v2 (blocker).
- **Development multiplicity (R1-F12, blocker for a freeze).** The `TrialLedger` must become an append-only,
  hash-chained file whose head is fingerprinted in the manifest, and B0/B2 must be evaluated over K pre-declared
  lottery seeds.
- **Candidate source (R1-F8, blocker).** No source on the table supports the factorial; the feature catalogue must
  be versioned to the chosen generator, and its cross-sectional relative-return features (the closed CS-XMOM family)
  dropped or justified against gate M4.


### 12.1 Amendment note H3A-1 (H3-A testability integration, 2026-10-16; DRAFT, not frozen)

This note amends the wording of the R1-F1 item above; the item itself is kept as written for the record. It changes
nothing frozen, because nothing here is frozen.

- **BR replaces B0m.** The random-abstention reference is BR, a portfolio of its own (own trajectory, own risk gate;
  same candidates, market, costs, exit policy and limits as B0 and B1). Its budget is B1's acceptance count per
  stratum, taken from B1's score-stage decisions (`budget_source = b1_acceptance`), never from outcomes; it runs over
  K pre-registered seeds, every seed reported. **The primary stratification is the decision day**, not the decision
  instant: per-instant matching is degenerate for sparse sources (one candidate per instant gives BR = B1, zero
  power), and the global budget is oversized under dependence. Global budget and instrument stratification are
  secondaries. See [H3-SCORING-ABSTENTION-CONTROLS.md](H3-SCORING-ABSTENTION-CONTROLS.md) §3.
- **H3-A has its own DRAFT criterion**, outside the B3 gatekeeping: rank IC on the reference label over the
  technically eligible population, AND B1 minus the mean BR-day portfolio, AND the halves guard
  ([H3-A-STATISTICAL-VALIDATION-DRAFT.md](H3-A-STATISTICAL-VALIDATION-DRAFT.md) §2-§3). S1 (B1 - B0) stays a
  secondary labelled "abstention-confounded".
- **Code.** `validation.abstention` implements BR; `contracts_v2` carries `VariantV2.BR`, `RandomControlSpec` and
  `ActivityBudget` with the same strata keys; `compare.evaluate` accepts the BR comparison as H3-A attribution
  evidence and still never reports a hypothesis `supported`.
