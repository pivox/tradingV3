# H3-A — statistical validation of the scoring engine (status `DRAFT_NOT_FROZEN`)

Scope: the H3-A question only ("does the opportunity score rank candidates better than chance, and does selecting
by it beat a random selection of the same size?"). H3-B (adaptive management) and H3-C (combination) keep their own
criteria in [H3-PREREGISTRATION-DRAFT.md](H3-PREREGISTRATION-DRAFT.md). Nothing here is frozen, no threshold is
final, no model was trained, and every number below is synthetic or analytical.

Written by agent B, consolidated at integration (2026-10-16) with the labels audit (agent C) and the source
arithmetic (agent A). Companions, not repeated here:

- controls and the synthetic study B3: [H3-SCORING-ABSTENTION-CONTROLS.md](H3-SCORING-ABSTENTION-CONTROLS.md);
- populations, reference label, features, uncertainty: [H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md](H3-LABELS-FEATURES-UNCERTAINTY-AUDIT.md);
- candidate sources and their frequencies: [H3-CANDIDATE-SOURCE-QUALIFICATION.md](H3-CANDIDATE-SOURCE-QUALIFICATION.md);
- V2 contracts (BR variant, label identity, target population, trial ledger): [H3-CONTRACTS-V2-DESIGN.md](H3-CONTRACTS-V2-DESIGN.md).

## 1. Why H3-A needs its own criterion

The DRAFT protocol tested H3-A as S1 (B1 - B0), a secondary gatekept behind the B3 primary. Two reviews showed that
this cannot answer H3-A:

- R1-F1: B1 - B0 mixes ranking skill with abstention. With a losing generator, any scorer that refuses trades
  "wins". The synthetic study B3 reproduces it: the naive B1 - B0 test rejects in most panels of a losing generator
  with an uninformative score (companion, §6).
- R1-F2: serial gatekeeping on B3 makes H3-A unconfirmable unless B3 is profitable.

H3-A is a statement about information in the score, not about the profitability of a strategy. Its criterion must
therefore (a) not depend on B3's success, (b) compare against a control that abstains as much as B1, and (c) test the
ranking on labels that depend neither on the portfolio's capacity nor on executability.

## 2. Population and label (from the labels audit)

| Item | DRAFT choice | Why |
|---|---|---|
| Target population | `TECHNICALLY_ELIGIBLE` (`scoring.labels.TARGET_POPULATION`; `ExperimentManifestV2.target_population`) | The scorer is applied at T to every candidate that passes the checks decidable at T, before anything about its fill is known |
| Ranking label | `h3.reference_static_exit_label/1`, gross R at the reference price (`scoring.labels.reference_label`) | Independent of capacity, collar and fill; outcome-independent exclusions only (missing bars, calendar horizon) |
| Rows used by A1 | `LABELABLE` ⊂ `TECHNICALLY_ELIGIBLE`, accepted only if the census (`labels.population_census`, `e2e.label_check(census=True)`) shows no outcome-dependent exclusion at the label step | Otherwise the labelled rows do not represent the target |
| Not usable for training or A1 | The capacity-free replay labels (`integration.label_replay`) | Executability-conditioned: in the synthetic example all 124 unlabelled candidates are `fill_beyond_collar`, an outcome-dependent selection |
| Calibration unit | `net_reference_r` (reference label minus the engine's ex-ante `cost_r` and `funding_cost_r`; unit `net_gross_r`) | The unit of `expected_net_r`. Never `net_r`, which is about 0.81 × in magnitude on the example |

Synthetic e2e census: 273 candidates, 261 technically eligible (12 fail the calendar horizon), 261 labelable, 142
executable. This is a property of the synthetic example, not of any market.

**Rank statistic (integration note, open).** `scoring_checks.rank_ic_test` ranks by `utility` (net of the
deterministic `cost_r`) when present. Against a net label, the cost term, known ex ante, sits in both the score and
the label and can produce a positive rank IC with no predictive information. The DRAFT is therefore: A1 ranks by the
score's gross prediction (the engine's `gross_r` head, in its trace; equivalently `expected_net_r` + `cost_r` +
`funding_cost_r`, since the V1 score does not carry it) against the gross reference label; a net-utility IC is reported only next to its cost-only
baseline (the IC of `-cost_r` alone). To be fixed in the protocol before any freeze (integration blocker table).

## 3. Primary criterion (DRAFT)

H3-A is supported (DRAFT reading `h3a_ranking_and_selection_beyond_abstention_draft`, `abstention.h3a_status`) only
if all three hold on the confirmation sample:

| Component | Statistic | Test (DRAFT) |
|---|---|---|
| A1 ranking | per-block mean Spearman(score's gross prediction, reference gross R) over the rows of §2 | one-sided, mean > 0, dependence-robust procedure on the block series |
| A2 selection beyond abstention | per-block d_b = B1 net PnL - mean of K matched BR portfolios (stratified by decision day) | one-sided, mean d_b > 0, same procedure |
| A3 stability guard | mean block IC > 0 in each chronological half | rule, no test |

A1 AND A2 is an intersection-union test: level alpha if each component has level alpha. A3 can only remove a
rejection.

Why two components: A1 alone can be positive while the score carries no money (the ranking lives where the policy
never trades, or the gain is smaller than the costs it changes); A2 alone can be positive from timing between strata
or from a few lucky trades. The synthetic study shows that A2 has little power at realistic frequencies, so A1
carries most of the information and A2 protects the economic reading. A2 is a portfolio comparison in net PnL:
costs, capacity and executability enter there, never in A1's label.

What the primary does NOT include: B1 or B3 net profitability (absolute performance) and B1 - B0. Both are reported
(secondaries, §4); neither can support H3-A.

**In code (integration).** `compare.evaluate(..., random_control=compare_b1_to_controls(...))` accepts the A2
comparison as H3-A attribution evidence and reports it under `h3a_random_control`. Its H3-A reading says whether
abstention is excluded (`rejects_null_beyond_random_abstention_criterion_not_frozen` or
`rejects_null_abstention_not_excluded`); it can never say `supported`, because A1 lives outside the factorial
comparison, the criterion is not frozen and the H3-A alpha is not allocated
(`compare.H3A_BLOCKERS_WITH_RANDOM_CONTROL`).

## 4. Secondaries (descriptive unless frozen into a family)

1. B1 vs BR, global budget (randomisation p over K draws and the block test): ranking plus timing.
2. Kept minus refused: per-block mean reference label of TRADE_CANDIDATE minus NO_TRADE candidates (abstention
   quality on reference labels).
3. Calibration: predicted vs realised `net_reference_r` per frozen score bucket (`scoring_checks.calibration_table`,
   `calibration_regression`), Brier score of p_stop.
4. Net expectancy per frozen score group (`scoring_checks.bucket_returns`), monotonicity.
5. Absolute: B1 per-block net > 0 (capital preservation vs. performance). Reported, never part of H3-A.
6. B1 - B0 (old S1), labelled "abstention-confounded".
7. Over-matching diagnostic: B1 vs BR stratified by instrument (and by side).
8. Executability: share of the target population that the replay fills, per score bucket (a fill probability under
   the timing rule, never a training filter).

Multiplicity: the primary is one intersection-union test (no correction needed). The secondaries 1-4 form one Holm
family at alpha 0.05 if the owner wants any of them confirmatory; otherwise they are exploratory and labelled so.
H3-A's alpha is separate from H3-B's and from the B3 economic gate (owner decision R1-F2: graphical or fallback
procedure, or Holm over {H3-A primary, H3-B primary, B3 vs cash}).

## 5. Dependence handling

- Unit: the block (DRAFT one week). Labels inside a block share regime and instant shocks; the test is on the block
  series, never on candidates as if independent.
- Procedure: the same choice as for the B3 primary (`sn_gaussian` or `batch_t`); neither is validated (both exceed
  the size limit under strong clustering until about 104 blocks). The B3 study adds its own size numbers for A1 and
  A2 (companion §6). The primary stays at or below 0.04 under every synthetic null tried, but its components drift
  upward at 104 weeks (rank IC 0.10-0.13 in the dependent and correlated nulls).
- Instrument as a cluster: with a small fixed universe, a chance correlation between instrument-level score and
  outcome effects is constant over the sample, and time blocks do not cover it (hypothesis, companion §6 reading 3).
  Candidate remedies to pre-register: within-instrument IC, inference clustered by instrument, a larger universe.
  This matters for CS-EARN (an equity X-Perp universe of about 50 issuers, each with about 4 events a year).
- Seasonal clustering: earnings events bunch in reporting seasons, so weekly blocks are very unequal (several
  events a week in season, close to none out of season). The block length and the minimum events per block must be
  pre-declared for a seasonal source.
- The randomisation p-value of B1 against BR is exact only conditionally on the realised market and under
  exchangeability of B1's selection; it ignores dependence between the score process and the outcome process. It is
  a secondary, not the primary.
- Effective sample size: `stats.effective_sample_size` on the A1 and A2 block series; minimum floors as in the
  DRAFT protocol (26 blocks, 13 effective), to be re-studied (R1-F5, R1-F9).

## 6. Required effective sample size: one table for agents A and B

Agents A and B computed the same quantities with different simplifications. The common table below uses one set
of formulas; the differences are explained after it.

Notation: z = z_0.95 + z_0.80 = 2.486 (one-sided alpha 0.05, power 0.80); rho the true rank IC between score and
label; a the share of candidates B1 accepts; lambda(a) = phi(z_a) / a the selection differential of a top-a
threshold rule under a unit-variance score (0.798 at a = 0.5, 1.159 at a = 0.3); sigma the per-trade sd of net R
(about 1 R); K = 19 BR draws.

- A1 (ranking), independent labelled candidates: n_A1 = ((z / atanh rho)^2 + 3) / 0.912 (Fisher z, Spearman
  efficiency 0.912 under normality).
- A2 (selection beyond abstention), accepted B1 entries: m_A2 = z^2 (1 - a) (1 + 1/K) / (rho lambda)^2. The
  (1 - a) term is the finite-pool covariance between B1's and BR's subsets of the same book; the (1 + 1/K) term is
  the noise of the mean of K draws. Candidates needed: m_A2 / a.

| rho (true IC) | A1: labelled candidates | A2, a = 0.5: accepted / candidates | A2, a = 0.3: accepted / candidates |
|---:|---:|---:|---:|
| 0.05 | about 2,710 | about 2,045 / 4,090 | about 1,360 / 4,520 |
| 0.10 | about 680 | about 510 / 1,020 | about 340 / 1,130 |
| 0.15 | about 300 | about 225 / 455 | about 150 / 500 |
| 0.20 | about 170 | about 130 / 255 | about 85 / 285 |

Reconciliation:

- A1: agent A's n = (z / rho)^2 + 1 (2,475 / 620 / 276 / 156) is the same Fisher approximation without the
  Spearman efficiency; it is about 9 % lower. The table keeps the conservative one.
- A2: agent A counted candidates in the pool without the 1/K term (3,885 / 972 / 432 / 243 at a = 0.5, 5 % below
  the table). Agent B's first version counted accepted trades without the (1 - a) term (1,900 / 490 / 215 / 120 at
  a = 0.3), which overstates the need by 1 / (1 - a). Both are superseded by the table.
- All of these are floors. They ignore capacity (B1 executes fewer trades than it accepts), dependence (n_eff = n /
  DEFF, DEFF = 1 + (m - 1) ICC, `stats.design_effect`) and the cost of the dependence-robust block test. The
  synthetic study at about 10 candidates a week (companion §6) reached power 0.15 / 0.28 / 0.55 for the primary at
  26 / 52 / 104 weeks with rho = 0.15: 52 weeks give about 525 labelled candidates, above the analytical 300, yet A1
  alone reached only 0.64. Weekly blocking and the self-normalised test cost roughly a factor of two.

**Years of prospective collection** for the only conditionally qualified source, CS-EARN (176-208 events a year
from the issuer count, not verified against announcement dates; agent A, §3): A1 at rho = 0.15 needs 1.4-1.7 years
analytically and about 2.9-3.4 years with the factor of two; at rho = 0.10, 3.3-3.9 and about 6.5-7.7 years. A2
at rho = 0.15 and a = 0.3 needs 2.4-2.9 years of candidates analytically, about 5 with the factor of two, before
capacity. CS-UNLOCK (at most about 60 a year) is out of reach at any useful IC (agent A, §2.2). These are
upper-end frequencies (every event a technically eligible candidate); the eligible share is UNKNOWN.

## 7. What would falsify H3-A (pre-declared)

On a frozen protocol and an independent sample with enough effective information (§6 at the owner's minimum
effect):
- A1 does not reject, or its 95 % lower bound excludes the minimum effect the owner declared useful: no ranking
  information of that size;
- A1 rejects but A2 does not, with A2's power at the observed IC above 0.8: ranking without selection value
  (`h3a_ranking_without_selection_value`);
- the stability guard fails (IC sign differs between halves): no stable ranking;
- B1 beats B0 but not BR: abstention only (`h3a_abstention_only_no_skill_shown`), never read as skill.

If the available sample cannot reach the n_eff of §6 for the owner's minimum effect, H3-A is not falsifiable on it and
the honest status is "untestable on this source", not "rejected" and not "supported".

## 8. What remains for the owner

1. The candidate source (R1-F8) and hence the candidate frequency: §6 turns it into a detectable IC.
2. The minimum useful IC (or the minimum excess per trade over BR) that the test must be able to detect.
3. The dependence-robust procedure (shared with the B3 primary; none is validated).
4. The block length and the confirmation window (seasonal sources need a rule, §5).
5. The stratification of the primary BR control (decision day is the DRAFT; per-instant matching is degenerate for
   sparse sources, companion §3) and K (19 DRAFT; 99 for a finer randomisation p).
6. The alpha allocation between H3-A, H3-B and the economic gate (R1-F2).
7. Whether the secondaries form a confirmatory Holm family.
8. The execution-timing rule (R1-F6), which the reference label must follow as a new version, and the rank statistic
   of A1 (§2).

Thresholds are not fixed until a power study through the real mechanics (R1-F4) validates them.
