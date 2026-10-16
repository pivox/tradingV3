# H3-A — statistical validation of the scoring engine (status `DRAFT_NOT_FROZEN`)

Scope: the H3-A question only ("does the opportunity score rank candidates better than chance, and does selecting
by it beat a random selection of the same size?"). H3-B (adaptive management) and H3-C (combination) keep their own
criteria in [H3-PREREGISTRATION-DRAFT.md](H3-PREREGISTRATION-DRAFT.md). Nothing here is frozen, no threshold is
final, no model was trained, and every number below is synthetic or analytical. Companion:
[H3-SCORING-ABSTENTION-CONTROLS.md](H3-SCORING-ABSTENTION-CONTROLS.md) (controls, synthetic study B3).

## 1. Why H3-A needs its own criterion

The DRAFT protocol tested H3-A as S1 (B1 - B0), a secondary gatekept behind the B3 primary. Two reviews showed that
this cannot answer H3-A:

- R1-F1: B1 - B0 mixes ranking skill with abstention. With a losing generator, any scorer that refuses trades
  "wins". The synthetic study B3 reproduces it: the naive B1 - B0 test rejects in most panels of a losing generator
  with an uninformative score (see the companion, §6).
- R1-F2: serial gatekeeping on B3 makes H3-A unconfirmable unless B3 is profitable.

H3-A is a statement about information in the score, not about the profitability of a strategy. Its criterion must
therefore (a) not depend on B3's success, (b) compare against a control that abstains as much as B1, and (c) test the
ranking on labels that do not depend on the portfolio's capacity.

## 2. Primary criterion (DRAFT)

H3-A is supported (DRAFT reading `h3a_ranking_and_selection_beyond_abstention_draft`, `abstention.h3a_status`) only
if all three hold on the confirmation sample:

| Component | Statistic | Test (DRAFT) |
|---|---|---|
| A1 ranking | per-block mean Spearman(score, capacity-free net R label) over the target population (agent C) | one-sided, mean > 0, dependence-robust procedure on the block series |
| A2 selection beyond abstention | per-block d_b = B1 net PnL - mean of K matched BR portfolios (stratified by decision day) | one-sided, mean d_b > 0, same procedure |
| A3 stability guard | mean block IC > 0 in each chronological half | rule, no test |

A1 AND A2 is an intersection-union test: level alpha if each component has level alpha. A3 can only remove a
rejection.

Why two components: A1 alone can be positive while the score carries no money (the ranking lives where the policy
never trades, or the gain is smaller than the costs it changes); A2 alone can be positive from timing between strata
or from a few lucky trades. The synthetic study shows that A2 has little power at realistic frequencies, so A1
carries most of the information and A2 protects the economic reading.

What the primary does NOT include: B1 or B3 net profitability (absolute performance) and B1 - B0. Both are reported
(secondaries, §3); neither can support H3-A.

## 3. Secondaries (descriptive unless frozen into a family)

1. B1 vs BR, global budget (randomisation p over K draws and the block test): ranking plus timing.
2. Kept minus refused: per-block mean label of TRADE_CANDIDATE minus NO_TRADE candidates (abstention quality on
   capacity-free labels).
3. Calibration: predicted vs realised net R per frozen score bucket (`scoring_checks.calibration_table`,
   `calibration_regression`), Brier score of p_stop.
4. Net expectancy per frozen score group (`scoring_checks.bucket_returns`), monotonicity.
5. Absolute: B1 per-block net > 0 (capital preservation vs. performance). Reported, never part of H3-A.
6. B1 - B0 (old S1), labelled "abstention-confounded".
7. Over-matching diagnostic: B1 vs BR stratified by instrument (and by side).

Multiplicity: the primary is one intersection-union test (no correction needed). The secondaries 1-4 form one Holm
family at alpha 0.05 if the owner wants any of them confirmatory; otherwise they are exploratory and labelled so.
H3-A's alpha is separate from H3-B's and from the B3 economic gate (owner decision R1-F2: graphical or fallback
procedure, or Holm over {H3-A primary, H3-B primary, B3 vs cash}).

## 4. Dependence handling

- Unit: the block (DRAFT one week). Labels inside a block share regime and instant shocks; the test is on the block
  series, never on candidates as if independent.
- Procedure: the same choice as for the B3 primary (`sn_gaussian` or `batch_t`); neither is validated (C's study 2:
  both exceed the size limit under strong clustering until about 104 blocks). The B3 study here adds its own size
  numbers for A1 and A2 (companion §6). The primary stays at or below 0.04 under every synthetic null tried, but
  its components drift upward at 104 weeks (rank IC 0.10-0.13 in the dependent and correlated nulls).
- Instrument as a cluster: with a small fixed universe, a chance correlation between instrument-level score and
  outcome effects is constant over the sample, and time blocks do not cover it (hypothesis, companion §6 reading 3).
  Candidate remedies to pre-register: within-instrument IC, inference clustered by instrument, a larger universe.
- The randomisation p-value of B1 against BR is exact only conditionally on the realised market and under
  exchangeability of B1's selection; it ignores dependence between the score process and the outcome process (two
  persistent but independent series correlate by chance). It is a secondary, not the primary.
- Effective sample size: `stats.effective_sample_size` on the A1 and A2 block series; minimum floors as in the
  DRAFT protocol (26 blocks, 13 effective), to be re-studied (R1-F5, R1-F9).

## 5. Required effective sample size (analytical, as functions of n_effective)

Notation: z = z_0.95 + z_0.80 = 2.486 (one-sided alpha 0.05, power 0.80); rho the true rank IC between score and
net label; lambda(q) the inverse Mills ratio of the acceptance threshold (lambda = 1.159 for q = 30 % accepted under
a unit-variance score); sigma the per-trade sd of net R (about 1 R).

- A1 (ranking), independent labelled candidates: n_eff_IC ≈ ((z / atanh rho)^2 + 3) / 0.912 (Fisher z, Spearman
  efficiency 0.912 under normality).
- A2 (selection beyond abstention), executed B1 trades: N_eff_trades ≈ (z sigma / (rho lambda))^2 x (1 + 1/K)
  (the selection differential of a threshold rule is rho lambda sigma per accepted trade; K BR draws add 1/K of
  variance). This ignores capacity (B1 executes fewer trades than it accepts) and the efficiency loss of the
  dependence-robust procedure, so it is a lower bound.
- With clustering: n_eff = n / DEFF, DEFF = 1 + (m - 1) ICC for clusters of size m (`stats.design_effect`).

| rho (true IC) | A1: independent labelled candidates | A2: executed B1 trades (K = 19) |
|---:|---:|---:|
| 0.03 | about 7,500 | about 5,400 |
| 0.05 | about 2,700 | about 1,900 |
| 0.10 | about 680 | about 490 |
| 0.15 | about 300 | about 215 |
| 0.20 | about 170 | about 120 |

Reading, to be compared with agent A's source frequencies: a source giving 40 candidates a year (CS-UNLOCK's upper
estimate) needs about 7 years of independent candidates to detect rho = 0.15 at 80 % power, before any clustering,
and more than 60 years for rho = 0.05. A2 needs executed trades, which the 1 % total-risk cap and 500 USDT make
scarcer than candidates. These are lower bounds; the companion's simulation gives the size and power actually
reached by the DRAFT procedure at 26, 52 and 104 weekly blocks.

Synthetic check (companion §6, about 10 candidates and 2.6 B1 trades a week): at rho = 0.15 the primary reaches
power 0.15 / 0.28 / 0.55 at 26 / 52 / 104 weeks. 52 weeks give about 525 labelled candidates, above the analytical
300, yet A1 alone reaches only 0.64 power. The weekly blocking and the self-normalised test cost roughly a factor
of two in information over the independent-pairs formula, so the table above is a floor.

## 6. What would falsify H3-A (pre-declared)

On a frozen protocol and an independent sample with enough effective information (§5 at the owner's minimum
effect):
- A1 does not reject, or its 95 % lower bound excludes the minimum effect the owner declared useful: no ranking
  information of that size;
- A1 rejects but A2 does not, with A2's power at the observed IC above 0.8: ranking without selection value
  (`h3a_ranking_without_selection_value`);
- the stability guard fails (IC sign differs between halves): no stable ranking;
- B1 beats B0 but not BR: abstention only (`h3a_abstention_only_no_skill_shown`), never read as skill.

If the available sample cannot reach the n_eff of §5 for the owner's minimum effect, H3-A is not falsifiable on it and
the honest status is "untestable on this source", not "rejected" and not "supported".

## 7. What remains for the owner

1. The candidate source (R1-F8) and hence the candidate frequency: §5 turns it into a detectable IC.
2. The minimum useful IC (or the minimum excess per trade over BR) that the test must be able to detect.
3. The dependence-robust procedure (shared with the B3 primary; none is validated).
4. The block length and the confirmation window.
5. The stratification of the primary BR control (decision day is the DRAFT; per-instant matching is degenerate for
   sparse sources, see the companion §4) and K (19 DRAFT; 99 for a finer randomisation p).
6. The alpha allocation between H3-A, H3-B and the economic gate (R1-F2).
7. Whether the secondaries form a confirmatory Holm family.

Thresholds are not fixed until a power study through the real mechanics (R1-F4) validates them.
