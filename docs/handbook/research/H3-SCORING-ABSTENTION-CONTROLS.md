# H3-A — scoring versus abstention: matched random controls (status `DRAFT_NOT_FROZEN`)

Question (review R1-F1): can a useful score be told apart from a random score that simply refuses more trades?
Everything here is synthetic or analytical. No model was trained, no backtest was run, no market data was read, and
no number below is evidence about any market. Companion: [H3-A-STATISTICAL-VALIDATION-DRAFT.md](H3-A-STATISTICAL-VALIDATION-DRAFT.md).

## 1. The problem

With a losing common generator, B1 (scored entries) beats B0 (all entries) by refusing trades: every refused
candidate saves an expected loss and its fees. The old S1 (B1 - B0) cannot separate that from ranking skill. In the
synthetic study below, an uninformative score on a losing generator makes the B1 - B0 test reject in 43 % of panels
(p4), and a pure coin-flip score in 38 % (p5), at a nominal 5 %.

## 2. Controls

| Variant | Entries | Policy | Portfolio |
|---|---|---|---|
| B0 | every candidate, seeded lottery order | static | own |
| B1 | TRADE_CANDIDATE candidates, score order | static | own |
| BR | as many candidates as B1 per stratum, chosen by a seeded lottery, lottery order | static | own, one per seed |

BR shares with B0 and B1: universe, candidate book (same object and fingerprint), market stream, window, capital,
risk gate policy (a fresh gate per portfolio, its `policy` checked), cost model and scenario, exit policy, portfolio
limits. Its budget comes from B1's score-stage decisions (`budget_source='b1_acceptance'`), never from fills, trades
or outcomes. Seeds are pre-registered in `RandomControlSpec.seeds`, and the K draws are reported as a distribution;
no single draw is ever read.

Code: `python-orchestrator/scripts/research_h3/validation/abstention.py`.
- `RandomControlSpec`, `ActivityBudget`: the shared interface (agent D mirrors them in V2 contracts).
- `decision_item`, `activity_budget`, `b1_accepted_ids`, `select_random`, `lottery_key`: the selection. The draw
  key sees only the candidate id and the seed.
- `run_random_control`: BR through the same `VariantSimulator` path as B0/B1 (`RunVariantSimulator`, D's gate and
  simulator). Fresh gate and simulator per seed. Shared or wrong-policy gates are refused, and the shared inputs are
  fingerprint-checked after every run.
- `portfolio_activity`, `compare_b1_to_controls`, `randomisation_p`, `block_excess_series`, `block_excess_test`:
  the comparisons.
- `h3a_status`: the DRAFT H3-A reading. It never returns "validated" or "profitable".
- `spec_to_v2`, `budget_to_v2`, `spec_from_v2`, `budget_from_v2` (integration): the shared interface as D's V2
  contracts, same fingerprints both ways. Strata keys and seeds are pre-registered sorted (the key order fixes the
  stratum labels).

On the wire, the V1 `Variant` enum is frozen, so BR runs as an unscored portfolio (`Variant.B0` at the gate, which
refuses a score for it) under its own portfolio id `<experiment>-BR-<control>-s<seed>`. The label `BR` lives in
`abstention.BR_LABEL` and `ControlRun.label`.

## 3. What each matching method measures

The budget is always B1's acceptance count. Only the strata change, and the strata decide what B1 is credited
for.

| Method | Strata | B1 - BR measures | Blind spot |
|---|---|---|---|
| global budget | the whole window | ranking + timing + choice of instrument/side | timing artefacts: a score and an outcome that are both persistent but independent correlate by chance (§6: size 0.15 in p6, 0.21 at 104 weeks) |
| stratified, decision day | day | ranking within days (+ instrument/side choice) | removes the timing part of real skill. In p3, BR-day keeps 57 % of B1's gain over BR-global |
| stratified, decision instant | instant | cross-sectional ranking only | **degenerate for sparse sources**: with one candidate per instant, each stratum is (1, 0) or (1, 1), so BR = B1 exactly and the test has zero power by construction |
| stratified, instrument | instrument | ranking within instruments | removes between-instrument skill. In p9 (skill = choosing instruments), power falls from 0.65 (global) to 0.05 (size) |
| stratified, side | side | ranking within sides | removes directional skill |
| stratified, score status | estimated / unestimated / invalid | ranking among eligible candidates | none for value, but the status must stay a status, never the score |

Too strict a matching on a predictive key matches the skill away: p9 shows it for instruments, p3 for timing.
Too loose a matching credits B1 with spurious timing (p6). There is no neutral choice. The method must be fixed
before any data, and the report must say what it measures. The DRAFT choice is decision-day stratification for
the primary (A2), with the global budget and the instrument stratification as secondaries.

The global budget is a count known only at the end of the window, so BR is a conditional randomisation, not a
tradable strategy. It is still free of outcome information.

## 4. Portfolio comparison (B2 of the brief)

B1 and BR free capital at different times, and nothing forces equal executed trades. `portfolio_activity` reports,
per portfolio: candidates evaluated, accepted, acceptance rate, executable orders (accepted minus risk-gate
refusals), trades executed, exposure (days), time-weighted mean open risk, mean notional at entry, net PnL, gross
PnL, costs and funding, net PnL per block, drawdown, net per exposure day and net per risk-day.
`compare_b1_to_controls` gives B1, the K-draw BR distribution (min, median, max, mean) and B0.

End-to-end demonstration on the H3 synthetic e2e market (`abstention_sim.run_e2e_random_control`, seeds 101-103,
fixture model; SYNTHETIC, 8 days, 273 candidates): B1 accepts 44 candidates. Every BR draw accepts exactly 44, per
day for the stratified control. BR executes 19-24 trades (global) or 21 (day) against B1's 21. Exposure is 4.7-6.8
days against 5.8, and B0 executes 52 trades. The randomisation p of B1's net against 3 draws is 0.5 for both
methods. With K = 3 nothing can be read: the run only proves that the path works.

## 5. Synthetic study B3

Pre-registered before running: `ACCEPTANCE-RULE.md` (sha256 `69cd11ac…`), `seeds.json`, `run_b3.py` and the module
hashes in `PRE-RUN.sha256` (2026-10-16T02:33:45Z), private directory
`tradingV3-private/research/20261016T0500Z-h3a-testability/b-abstention/`. One pass, no seed changed, 1,149
CPU-seconds, 2 processes, nice 10. Outputs: `b3-results.json`, `b3-summary.md`, `b3-decision.json`, `SHA256SUMS`.

Model (`validation/abstention_sim.py`): a light candidate-level replay, because the bar simulator costs about 1 s
per portfolio and the study needs about 40 portfolios per panel. It keeps one common candidate stream (about 10 a
week over 6 instruments, decisions every 4 h), a capacity of 2 positions at 0.5 % risk (at most one per
instrument), holds of 4-48 h, costs of 0.10 R per trade, and separate trajectories for B0, B1 and every BR draw.
It drops bars, fills, stops, funding and the collar: outcomes are net R drawn directly. B1 accepts a score above
0.5244 (about 30 %, fixed a priori). K = 19 BR draws per method and panel, alpha = 0.05 one-sided, SN test on
weekly series.

Populations (`abstention_sim.POPULATIONS`):

| | Population | Truth |
|---|---|---|
| p1 | no signal, zero net mean | null |
| p2 | weak signal, IC 0.05 | signal |
| p3 | strong signal, IC 0.15 | signal |
| p4 | losing generator (−0.15 R/trade), structured uninformative score | null |
| p5 | losing generator, i.i.d. coin-flip score (pure random selection) | null |
| p6 / p6s | persistent regime in outcomes and an independent persistent score (φ 0.8); IC 0 / 0.10 | null / signal |
| p7 / p7s | instant-level common factors (correlated instruments); IC 0 / 0.10 | null / signal |
| p8 | IC +0.15 then −0.15 (regime instability); 0 on average | null |
| p9 | skill only between instruments | signal |
| p10 | strong signal on a losing generator | signal |

Methods: `naive_b1_vs_b0` (old S1), `perm_global` / `perm_day` / `perm_inst` (randomisation p of B1's total net
against K BR draws), `block_global` / `block_day` (per-week B1 − mean BR, SN test), `rank_ic` (per-week Spearman on
capacity-free labels, SN test), `kept_minus_refused`, `h3a_primary` (rank_ic AND block_day AND IC > 0 in both
halves), and `b1_absolute` (B1 net > 0, not part of H3-A).

## 6. Results (rejection rates; SYNTHETIC)

52 weeks, 400 panels per cell (binomial SE 0.011 at a rate of 0.05; the Wilson intervals are in the JSON):

| Population | naive B1−B0 | perm global | perm day | block global | block day | perm inst | rank IC | kept−refused | **H3-A primary** | B1 absolute |
|---|---|---|---|---|---|---|---|---|---|---|
| p1 null | 0.070 | 0.075 | 0.060 | 0.062 | 0.062 | 0.048 | 0.062 | 0.060 | **0.007** | 0.107 |
| p2 IC .05 | 0.177 | 0.212 | 0.138 | 0.172 | 0.140 | — | 0.225 | 0.163 | **0.048** | 0.138 |
| p3 IC .15 | 0.318 | 0.647 | 0.410 | 0.575 | 0.347 | 0.632 | 0.635 | 0.535 | **0.275** | 0.425 |
| p4 losing, null | **0.427** | 0.087 | 0.052 | 0.052 | 0.048 | — | 0.060 | 0.062 | **0.005** | 0.020 |
| p5 coin flip | **0.378** | 0.050 | 0.048 | 0.052 | 0.037 | — | 0.050 | 0.055 | **0.007** | 0.010 |
| p6 dependent null | 0.140 | **0.152** | 0.055 | 0.045 | 0.055 | — | 0.070 | 0.068 | **0.018** | 0.060 |
| p6s dependent IC .10 | 0.163 | 0.435 | 0.182 | 0.203 | 0.158 | — | 0.407 | 0.287 | **0.122** | 0.113 |
| p7 correlated null | 0.120 | 0.085 | 0.070 | 0.070 | 0.062 | — | **0.077** | 0.080 | **0.015** | 0.098 |
| p7s correlated IC .10 | 0.223 | 0.420 | 0.245 | 0.378 | 0.215 | — | 0.417 | 0.362 | **0.130** | 0.285 |
| p8 IC flip | 0.062 | 0.068 | 0.045 | 0.005 | 0.015 | — | 0.005 | 0.010 | **0.000** | 0.060 |
| p9 between instruments | 0.455 | 0.650 | 0.455 | 0.570 | 0.443 | **0.052** | 0.718 | 0.598 | **0.390** | 0.535 |
| p10 IC .15 on losing gen. | 0.640 | 0.662 | 0.417 | 0.605 | 0.343 | — | 0.652 | 0.542 | **0.280** | 0.107 |

104 weeks, 150 panels (SE 0.018): H3-A primary at 0.007 (p1), 0.040 (p6) and 0.013 (p7) under the null, and power
0.067 (p2), **0.553 (p3)** and 0.193 (p6s). Rank IC alone has power 0.927 in p3, with size 0.073 / 0.100 / 0.127
in p1 / p6 / p7. 26 weeks, 200 panels: H3-A primary power 0.145 in p3, size 0.005 in p6.

Mean net R per panel (52 weeks): in p4, B0 −55.9, B1 −21.8, BR-day −21.7 and BR-global −22.3. B1's "gain" over B0
is all abstention, and BR earns the same. In p10, B1 +3.1 against BR-day −6.9 and B0 −52.0: B1 beats BR yet is
near zero in absolute terms.

Pre-registered verdict (`b3-decision.json`): **`NO_VALID_PROCEDURE_AT_52`, reason: power**. The H3-A primary is
SIZE_VALID at 52 weeks in every null population, including p4 and p5 (rule 4: no positive conclusion from fee
savings). Its power in p3 is 0.275, far below the 0.80 required. The four recorded expectations were all MET:
E1 (naive rejects p4, 0.43), E2 (perm global oversized in p6, 0.15), E3 (perm inst has power 0.05 against 0.65 for
global in p9) and E4 (weak-signal power 0.05).

Readings:

1. **Abstention is separated from ranking.** Every BR-based method and the rank IC stay near 5 % in p4 and p5,
   where B1 − B0 rejects 38-43 %.
2. **The randomisation p is unsafe under dependence.** `perm_global` reaches 0.15 (52 weeks) and 0.21 (104 weeks)
   in p6. It is conditional on the realised market and does not see that a persistent score and a persistent
   regime correlate by chance. Day stratification (`perm_day`, `block_day`) brings it back near nominal at 52
   weeks. At 104 weeks `block_day` shows 0.080 in p6 (SE 0.018, not significant).
3. **The block-level tests drift upward with n in some nulls.** At 104 weeks, rank IC reaches 0.100 (p6) and 0.127
   (p7), kept−refused 0.087 (p1), and B1 absolute 0.15 (p1). The likely cause (a hypothesis, not tested here): with
   6 fixed instruments, the chance correlation between the score's and the outcome's instrument effects is a
   per-panel constant, so a test over weeks grows more confident in it as weeks accumulate. A small fixed universe
   makes the instrument a cluster that time-blocking does not cover. Remedies to study (pre-registered first):
   within-instrument IC, inference clustered by instrument, a larger universe.
4. **Power is the binding constraint.** At about 10 candidates and about 2.6 B1 trades a week, a strong IC of 0.15
   gives the H3-A primary 0.28 power at one year and 0.55 at two. The limiting component is A2 (block day: 0.35 /
   0.57); rank IC alone reaches 0.64 / 0.93. A weak IC of 0.05 is undetectable (0.05 / 0.07).
5. **Beating BR is not profit.** p10 rejects A2 in 34 % of panels while B1 averages +3.1 R on a −52 R generator.
6. **Instability is caught.** p8 (IC sign flip) gives 0.000 for the primary, thanks to the halves guard and the
   averaging.

## 7. Limits

- Light model: no bars, fills, stops, collar or funding. The real mechanics (R1-F4, R1-F6) can only lower power and
  add selection. A re-study through the bar simulator is still required.
- One fixed acceptance rule (top 30 %), one capacity (2 positions), one frequency (about 10 candidates a week), 6
  instruments. Sparser sources (CS-UNLOCK at most about 40 trades a year) are far below this, see the n_eff table
  in the companion §5.
- SN test only. The batch-means t-test was not run here.
- K = 19 makes the randomisation p coarse (minimum 0.05). K = 99 would refine it at five times the cost.
- The acceptance check has its own multiplicity: about a 6.5 % chance per method of one false size failure across
  6 null cells.
- The fixed-instrument mechanism of reading 3 is a hypothesis.

**Independent reviews (2026-10-16).**

- Provenance (CTO-09): the B3 numbers come from agent B's commit 6622a0c3; the CTO reviewer reproduced cell
  G3/p6/26 bit-identically at the integration commit cbff09de. The decision script `summarize_b3.py` was written
  19 s after the results and is not in `PRE-RUN.sha256` (its logic matches rules 1-6); `run_b3.py` submits every cell
  up front and only stops recording past the CPU budget (no cell exceeded it, 1148 CPU-s); `PRE-RUN.sha256` hashes
  code by absolute path in another worktree. Any later study pre-hashes its decision script, submits cells lazily
  against the budget and pins code by commit plus repository-relative paths.
- Transcription (QR-13): the p10 primary rate is 0.28 in `b3-results.json`; the table above said 0.275 and is
  corrected.
- Coverage (QR-12, QR-13): B3's outcomes ignore side, its candidate rate has no seasonality, its universe is 6
  fixed instruments, capacity barely binds and there is no eligibility or executability. The R1-F4 re-study must use
  a CS-EARN-like process (seasonal, side-signed market factor, about 44 instruments with listing churn, stop-bounded
  R with gaps, binding capacity, a non-scorable share) and include a capacity-binding hold and executability
  confound cell, with an exposure-matched excess (net per risk-day) next to d_b. The quant reviewer's probe on such
  a stream kept sizes acceptable but found the power far lower (H3-A-STATISTICAL-VALIDATION-DRAFT.md §6).
- Budget validation (CTO-08): the harness `ActivityBudget` now refuses what its V2 mirror refuses (unknown or unsorted
  keys, labels that are not the keys' labels, float or bool counts) instead of truncating; `budget_to_v2` takes the
  B1 run fingerprint the V2 readiness check requires.

## 8. Answer

Can a useful score be told apart from a random score that simply refuses more trades? **Yes in principle, not at the
frequencies H3 can reach.**
- In principle, yes. A matched random-abstention control stratified by decision day, plus a rank-IC test on
  capacity-free labels, both kept the false-positive rate at or below nominal in every synthetic null tried,
  including the R1-F1 case (a losing generator with an uninformative or coin-flip score), where the old B1 − B0
  test is wrong 38-43 % of the time.
- In practice, no, at the sizes H3 can reach. The combined DRAFT criterion has 0.28 power for a strong IC (0.15) at
  one year of about 10 candidates a week, and 0.55 at two years. Weak signals are undetectable. Under dependence,
  the cheapest control (global budget, randomisation p) is oversized. At 104 weeks some block tests drift above
  nominal, plausibly because the universe is small and fixed.
- Pre-registered verdict: `NO_VALID_PROCEDURE_AT_52` (reason: power). No threshold is final. The owner's choices
  (source frequency, minimum useful IC, procedure, strata, K, alpha split) are listed in the companion §7.
