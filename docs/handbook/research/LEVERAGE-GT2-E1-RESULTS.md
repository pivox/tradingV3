# Leverage > 2x — E1 replay and analysis (run once, 2026-10-15)

**Verdict of the protocol (§9): the primary contrast is NOT_INFORMATIVE, and so are all nine secondaries S1–S9
(Holm-adjusted p = 1 for each).** E0a triggered no studied band under L_eng, so E1 replayed nothing for the primary
definition. The only testable contrast, S4 (Δ ]2,3] − ]1,2] under L_stop), has 240 distinct filled trades in the
]2,3] arm. That is under N_min = 400, so S4 is NOT_INFORMATIVE whatever its p-value. **The study closes
NOT_INFORMATIVE.** Both replayed arms have a negative net expectancy in R, and that sign holds at every point of
the slippage grid. This is descriptive only.

Exploratory, post hoc, on consumed 2024 Binance windows. Nothing was chosen from outcomes and no criterion was
relaxed. Every number below is copied from the frozen outputs (`e1-manifest.json`, `e1-analysis.json`); the full
analysis document is in [`LEVERAGE-GT2-E1-RESULTS.json`](LEVERAGE-GT2-E1-RESULTS.json)
(`analysis_document_verbatim`).

## Provenance

| Item | Value |
|---|---|
| Protocol | [`PREREGISTRATION-leverage-gt2.md`](PREREGISTRATION-leverage-gt2.md), FROZEN 2026-10-15T10:07:48Z, body sha256 `10eaf6cb…7f37d97`, code digest `6e20c4eb…e17ddeb` (unchanged at every claim) |
| E0a | [census](LEVERAGE-GT2-E0A-CENSUS.md); trigger: L_eng `[]`, L_stop `[1-2, 2-3]` |
| E1 | claimed 10:20:35Z, consumed 10:20:39Z, complete 10:23:12Z (152.8 s, `nice -n 15`, 2 workers); 76 series, 30 configurations, arms `stop:1-2`, `stop:2-3`; no guard abort, no resumption used |
| Analysis | claimed 10:23:23Z, run once 10:23:23Z → 10:23:24Z |
| Outputs (private) | `e1-replay.npz` `1090b5f1…`, `e1-manifest.json` `06de67bd…`, `e1-analysis.json` `69707e3d…`, all equal to the hashes recorded in the consumed files |

## Admission counters (nominal, every configuration copy, `counters_total`)

| Arm | Signals | Excluded (band) | Busy | Conflict | Orders | Crossed | Unfilled | Filled | Indeterminable (all reasons) | No exit |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| L_stop ]1,2] | 69,688 | 42,110 | 22,328 | 2 | 5,246 | 51 | 1,453 | 3,742 | 0 | 0 |
| L_stop ]2,3] | 69,688 | 65,865 | 2,982 | 0 | 841 | 7 | 360 | 474 | 0 | 0 |

## Per arm (decision population: distinct trades, D1)

| Metric | L_stop ]1,2] (reference) | L_stop ]2,3] |
|---|---:|---:|
| Admitted orders (distinct) | 2,806 | 418 |
| Filled distinct / nominal | **1,983** / 3,742 | **240** / 474 |
| Fill rate | 70.7 % | 57.4 % |
| Duplicates across configurations | 1,759 | 234 |
| Win rate | 30.4 % | 29.2 % |
| Mean net R | **−0.063** | **−0.158** |
| Median net R | −0.933 | −0.881 |
| Mean gross R / mean cost R | +0.085 / 0.148 | +0.068 / 0.226 |
| Worst R / best R | −2.75 / +16.40 | −2.44 / +7.80 |
| Share below −2 R | 0.30 % | 1.25 % |
| Slippage grid mean R (0 / 1 / 2 / 5 ticks) | −0.063 / −0.080 / −0.097 / −0.148 | −0.158 / −0.189 / −0.221 / −0.314 |
| Grid label | SIGN_STABLE | SIGN_STABLE |
| Stop share | 62.9 % | 61.3 % |
| Same-second stop share | 0 | 0 |
| Stop slippage beyond the stop, R (p50 / p90 / max) | 0.010 / 0.064 / 1.78 | 0.000 / 0.093 / 1.44 |
| Realistic − frozen stop execution, mean R | −0.048 | −0.074 |
| Crossing a funding settlement (8 h grid, approx.) | 7.5 % | 2.9 % |
| Median hold | 2,969 s | 2,391 s |
| d / c (bp, p50); c/(d + c) p50 | 39.4 / 9.0; 0.189 | 22.8 / 9.4; 0.297 |
| L_eng (min / mean / max) | 0.75 / 1.06 / 1.56 | 1.37 / 1.61 / 2.10 |
| L_stop (min / mean / max) | 1.00 / 1.33 / 2.00 | 2.00 / 2.29 / 3.00 |
| Floor share (two-tick floor) | 0 | 0 |
| Max pair share | 8.8 % (BNB) | 24.2 % (LTC) |
| Max configuration share (nominal) | 20.2 % (`P3-A3-K0|short`) | 23.2 % (`P3-A3-K0|short`) |

**Floor strata.** No filled trade sits at the two-tick floor in either arm, so results "with" and "without" the
floor are identical (]1,2] 1,983 trades, −0.063 R; ]2,3] 240 trades, −0.158 R).

**L_eng of the L_stop ]2,3] arm.** The trades are at their canonical size, and their L_eng is 1.37–2.10. Most of
the "L_stop > 2" trades are ≤ 2x under the primary definition. None of them is levered up (sizing guard).

**By week (distinct trades, mean R).** ]1,2]: w2404 106 / −0.176, w2405 718 / +0.004, w2406 696 / −0.022,
w2408 463 / −0.204. ]2,3]: w2405 105 / −0.238, w2406 115 / −0.184, w2408 20 / +0.409 (no w2404 trade).

**By pair, ]2,3] (distinct trades, mean R).** LTC 58 / −0.135, XRP 49 / −0.442, ETH 43 / −0.006, BNB 33 / −0.179,
ATOM 16 / −0.413, ADA 13 / −0.681, DOGE 8 / +0.668, ARB 4 / +1.483, LINK 4 / −0.975, SOL 4 / +1.194, FIL 3 / −0.160,
APT 2 / −0.995, AVAX 1, NEAR 1, OP 1. The per-pair split of ]1,2] (19 pairs, 28–174 trades each) is in the JSON.

## Applied leverage and liquidation (analytic, §10)

For both arms, every rule (A_ceil, A_full), A_max ∈ {2, 10} and mid-cap MMR ∈ {0.005, 0.01, 0.02} gives **0
liquidations before exit**. Margin refusals under isolated margin: 0 in ]1,2]; **9 of 240** in ]2,3] when
A_max = 2 (the trades with L_eng > 2); 0 when A_max = 10. The sizing guard (`assert_not_levered_up`) passed for
every trade.

## Inference (§9)

| Test | Δ (R) | 95 % CI | p | p Holm | n band / ref | Verdict |
|---|---:|---|---:|---:|---|---|
| Primary Δ ]2,3] L_eng | — | — | — | — | 0 / 0 | NOT_INFORMATIVE (no arm replayed) |
| S1 Δ ]3,5] L_eng | — | — | — | 1 | 0 / 0 | NOT_INFORMATIVE |
| S2 Δ ]5,10] L_eng | — | — | — | 1 | 0 / 0 | NOT_INFORMATIVE |
| S3 Δ ]2,3] L_eng, intention to trade | — | — | — | 1 | 0 / 0 | NOT_INFORMATIVE |
| **S4 Δ ]2,3] L_stop** | **−0.095** | [−0.300, +0.126] | 0.385 | 1 | **240** / 1,983 (269 clusters) | **NOT_INFORMATIVE (240 < N_min)** |
| S5 Δ ]3,5] L_stop | — | — | — | 1 | 0 / 1,983 | NOT_INFORMATIVE (not triggered) |
| S6 Δ ]5,10] L_stop | — | — | — | 1 | 0 / 1,983 | NOT_INFORMATIVE (not triggered) |
| S7 C1 in ]2,3] L_eng | — | — | — | 1 | 0 | NOT_INFORMATIVE |
| S8 C2 stratified ]2,3] L_eng | — | — | — | 1 | 0 / 0 | NOT_INFORMATIVE |
| S9 ]2,3] L_eng without floor | — | — | — | 1 | 0 / 0 | NOT_INFORMATIVE |

S4: both band labels are SIGN_STABLE. Δ across the grid is −0.095 / −0.109 / −0.123 / −0.166 R
(SIGN_STABLE). Without the floor (descriptive): identical, since there are no floor trades. Because S4 is under
N_min, its raw p is not used: the frozen rule keeps p = 1 for S4 in the Holm family. The cumulative groups G3, G5
and G10 (L_eng) are empty. The robustness and multiplicity diagnostics of the primary are not produced, because
the primary has no rows.

## What the frozen outputs do not answer

These were not computed, per the reading guide (§11), which forbids new metrics after the read:

- Net expectancy in bp: R only.
- Profit factor and payoff ratio.
- Concentration in the top 5 % of trades.
- Per-day splits.
- Drawdown and capital in use.

E0a census × 0.3 predicted 414 fills for L_stop ]2,3]. E1 produced 240 distinct fills: one position per pair,
the 45 s TTL and the through-fill rule cut more than the frozen f_min assumed. This is a statement about the
trigger's assumption, not a reason to re-run anything.

## Answers to the owner's questions, within the frozen scope

- **A (selection):** above 2x, opportunities are rare under L_eng (238 distinct, 0.69 %, on three majors). Under
  L_stop, 1,596 distinct signals (4.6 %) gave 240 distinct fills in ]2,3]. That is not enough for the frozen
  N_min.
- **B (performance):** no frozen test is informative. Descriptively, the L_stop ]2,3] arm has a lower net R than
  ]1,2] (−0.158 vs −0.063). Its gross R is also lower (+0.068 vs +0.085), and its cost share is higher (0.226 vs
  0.148 R). Both arms are net negative at every slippage point. Funding is not charged; it appears only as
  settlement crossings.
- **C (risk):** 0 analytic liquidations in every scenario. 9 margin refusals at A_max = 2. Worst trades are
  −2.75 R and −2.44 R. Drawdown is not measured.
- **D (robustness):** max pair share is 24.2 % (LTC) in ]2,3], and that arm's trades come from 3 of the 4 weeks.
  No frozen per-day or top-5 % metric exists. Post hoc nature: §0 and §11 apply, and nothing here is a validation.
