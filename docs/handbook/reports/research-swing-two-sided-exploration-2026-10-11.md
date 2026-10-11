# Historical research — two-sided swing breakouts (2026-10-11)

## Result

The long-only swing screen ([swing exploration](research-swing-exploration-2026-10-11.md)) found one signal that beats random timing, breakouts, and lost money in 2025 through long-only market exposure. This iteration tests the same breakouts in both directions, with short entries on breakdowns below the 4h EMA200.

The grid was fixed before any run: 10 setup policies × the same 27 geometries, four active plans as the primary rule, ten as a pre-declared sensitivity, and three random-timing controls. The campaign protocol is unchanged: training 2023–2024, validation 2025, both cost profiles, the 2026 holdout closed.

**With four active plans, 19 configurations pass every protocol gate**, all of them two-sided:

| Policy | Eligible geometries (of 27) |
|---|---:|
| `B120_2s` | 9 |
| `B60_any_2s` | 4 |
| `B30_2s` | 3 |
| `B60_2s` | 2 |
| `TR_2s_adx25` | 1 |

No short-only policy passes, and no random-timing control passes (0 of 81).

This is the first family to pass the protocol on a pre-registered grid, and it is more robust than the long-only result:

- every eligible configuration stays positive in 2025 without its best month, and without its best pair;
- the two sides complement each other: longs carry 2023–2024, shorts carry 2025;
- each configuration is profitable in 2023, 2024 and 2025 under both cost profiles, with 38–50 % winning trades.

It is still not a candidate for the holdout:

- **No configuration passes under both portfolio rules.** With ten active plans, 3 configurations pass, and none of them is among the 19. With ten plans, most of the 19 fall to a 2025 profit factor between 0.91 and 1.19, and 8 of them lose money in 2025. With four plans, simultaneous signals are admitted in the fixed symbol order (BTC, ETH, BNB, XRP first), as in the canonical simulator, so the subset of trades taken depends on that order.
- **In 2025 the edge is the regime, not the breakout timing.** Random entries that go long above the 4h EMA200 and short below it do almost as well in 2025: the breakout policies beat them by −0.03 to +0.04 R per trade on average. In 2023–2024 they beat the same control by 0.10 to 0.15 R with four plans, and by 0.06 to 0.10 R with ten. The regime control alone never passes, because it is flat in 2023–2024.
- Four iterations of swing grids (1,080 configurations) have now been evaluated on 2025.

## Eligible with four active plans

Each cell gives trades / mean net R (± SE) / net profit factor / win rate. The last column replays the same configuration with ten active plans.

| Policy | Geometry | Training, baseline | Validation 2025, baseline | 2025 net: long / short | 2025 net without its best month | Adverse PF: training / 2025 | Ten plans: 2025 PF / net |
|---|---|---|---|---:|---:|---:|---:|
| `B120_2s` | `S3.T3.H14d` | 273 / +0.16 (± 0.08) / 1.30 / 41% | 129 / +0.20 (± 0.11) / 1.22 / 40% | +53 / +233 | +118 | 1.19 / 1.26 | 0.95 / −115 |
| `B120_2s` | `S3.T3.H7d` | 333 / +0.17 (± 0.07) / 1.34 / 44% | 143 / +0.18 (± 0.10) / 1.30 / 45% | +74 / +276 | +141 | 1.30 / 1.33 | 0.96 / −86 |
| `B120_2s` | `S3.T5.H14d` | 250 / +0.18 (± 0.10) / 1.30 / 38% | 110 / +0.22 (± 0.14) / 1.31 / 39% | +133 / +200 | +103 | 1.26 / 1.32 | 1.08 / +153 |
| `B120_2s` | `S3.T5.H7d` | 311 / +0.20 (± 0.08) / 1.42 / 42% | 128 / +0.20 (± 0.12) / 1.40 / 48% | +107 / +284 | +156 | 1.37 / 1.36 | 1.02 / +39 |
| `B120_2s` | `S4.T2.H7d` | 323 / +0.16 (± 0.06) / 1.47 / 48% | 143 / +0.12 (± 0.08) / 1.23 / 48% | +1 / +292 | +64 | 1.54 / 1.40 | 1.01 / +27 |
| `B120_2s` | `S4.T3.H14d` | 239 / +0.15 (± 0.08) / 1.36 / 43% | 110 / +0.17 (± 0.11) / 1.28 / 45% | +83 / +247 | +38 | 1.14 / 1.27 | 0.97 / −61 |
| `B120_2s` | `S4.T3.H7d` | 299 / +0.20 (± 0.07) / 1.51 / 48% | 127 / +0.17 (± 0.09) / 1.42 / 50% | +135 / +286 | +138 | 1.51 / 1.37 | 0.91 / −186 |
| `B120_2s` | `S4.T5.H14d` | 215 / +0.17 (± 0.09) / 1.36 / 43% | 100 / +0.24 (± 0.13) / 1.48 / 46% | +142 / +326 | +166 | 1.32 / 1.44 | 1.12 / +234 |
| `B120_2s` | `S4.T5.H7d` | 286 / +0.20 (± 0.07) / 1.59 / 48% | 121 / +0.19 (± 0.10) / 1.46 / 50% | +175 / +242 | +176 | 1.55 / 1.41 | 0.93 / −137 |
| `B30_2s` | `S3.T3.H7d` | 492 / +0.15 (± 0.05) / 1.28 / 45% | 224 / +0.12 (± 0.08) / 1.25 / 45% | −26 / +448 | +167 | 1.24 / 1.07 | 1.13 / +487 |
| `B30_2s` | `S3.T5.H7d` | 462 / +0.23 (± 0.07) / 1.50 / 45% | 220 / +0.12 (± 0.09) / 1.22 / 43% | −78 / +449 | +120 | 1.45 / 1.17 | 1.19 / +665 |
| `B30_2s` | `S4.T2.H7d` | 478 / +0.14 (± 0.05) / 1.39 / 48% | 217 / +0.09 (± 0.07) / 1.25 / 47% | +1 / +432 | +189 | 1.45 / 1.08 | 1.10 / +383 |
| `B60_2s` | `S3.T3.H7d` | 420 / +0.14 (± 0.06) / 1.33 / 42% | 184 / +0.13 (± 0.09) / 1.27 / 45% | −13 / +392 | +100 | 1.25 / 1.13 | 0.97 / −103 |
| `B60_2s` | `S3.T5.H7d` | 393 / +0.15 (± 0.07) / 1.40 / 42% | 174 / +0.16 (± 0.10) / 1.29 / 44% | −42 / +461 | +173 | 1.36 / 1.25 | 1.10 / +256 |
| `B60_any_2s` | `S3.T3.H7d` | 432 / +0.13 (± 0.06) / 1.30 / 42% | 189 / +0.14 (± 0.09) / 1.28 / 46% | −30 / +435 | +141 | 1.24 / 1.19 | 0.96 / −138 |
| `B60_any_2s` | `S3.T5.H7d` | 410 / +0.14 (± 0.07) / 1.34 / 42% | 179 / +0.17 (± 0.10) / 1.31 / 45% | −52 / +505 | +206 | 1.30 / 1.27 | 1.08 / +222 |
| `B60_any_2s` | `S4.T3.H7d` | 391 / +0.13 (± 0.06) / 1.37 / 45% | 175 / +0.11 (± 0.08) / 1.23 / 46% | +12 / +322 | +47 | 1.32 / 1.13 | 1.00 / −10 |
| `B60_any_2s` | `S4.T5.H7d` | 375 / +0.13 (± 0.06) / 1.38 / 45% | 168 / +0.16 (± 0.09) / 1.29 / 46% | +77 / +330 | +161 | 1.33 / 1.25 | 1.05 / +152 |
| `TR_2s_adx25` | `S4.T5.H14d` | 273 / +0.15 (± 0.07) / 1.41 / 46% | 126 / +0.25 (± 0.12) / 1.35 / 48% | +228 / +246 | +130 | 1.35 / 1.32 | 1.01 / +30 |

## Eligible with ten active plans

The last column replays the same configuration with four active plans.

| Policy | Geometry | Training, baseline | Validation 2025, baseline | 2025 net: long / short | Adverse PF: training / 2025 | Four plans: 2025 PF / net |
|---|---|---|---|---:|---:|---:|
| `B30_2s` | `S3.T5.H14d` | 682 / +0.14 (± 0.06) / 1.21 / 34% | 319 / +0.16 (± 0.08) / 1.24 / 40% | +86 / +654 | 1.16 / 1.20 | 1.20 / +280 |
| `B30_2s` | `S4.T5.H14d` | 579 / +0.18 (± 0.06) / 1.38 / 42% | 291 / +0.14 (± 0.08) / 1.25 / 43% | +101 / +687 | 1.33 / 1.22 | 1.02 / +23 |
| `B60_2s` | `S4.T5.H14d` | 478 / +0.18 (± 0.06) / 1.44 / 41% | 242 / +0.15 (± 0.08) / 1.23 / 44% | +118 / +492 | 1.40 / 1.20 | 0.89 / −161 |

`B30_2s S3.T5.H14d` misses the four-plan screen only on the baseline profit factor of 2025, which is just below 1.2.

## Edge over random timing

Each cell gives the mean difference of mean net R between the setup and the regime-matched control, over the 27 geometries. The median z, in parentheses, uses both standard errors. `R_regime` goes long above the 4h EMA200 and short below it, at random 4h closes. `R_dn200` only shorts below it.

| Policy | Control | Four plans, training | Four plans, 2025 | Ten plans, training | Ten plans, 2025 |
|---|---|---:|---:|---:|---:|
| `B30_2s` | `R_regime` | +0.14 R (+2.1) | +0.00 R (+0.1) | +0.08 R (+1.8) | +0.04 R (+0.4) |
| `B60_2s` | `R_regime` | +0.12 R (+1.8) | −0.01 R (−0.1) | +0.06 R (+1.2) | −0.02 R (−0.3) |
| `B120_2s` | `R_regime` | +0.15 R (+2.0) | +0.03 R (+0.2) | +0.10 R (+1.7) | −0.01 R (−0.2) |
| `B60_any_2s` | `R_regime` | +0.11 R (+1.6) | −0.01 R (−0.1) | +0.06 R (+1.2) | −0.03 R (−0.4) |
| `TR_2s_adx25` | `R_regime` | +0.10 R (+1.5) | −0.02 R (−0.3) | +0.06 R (+1.3) | −0.03 R (−0.3) |
| `S30_dn200` | `R_dn200` | +0.08 R (+1.1) | +0.04 R (+0.2) | +0.04 R (+0.8) | −0.03 R (−0.5) |
| `S60_dn200` | `R_dn200` | +0.01 R (+0.1) | +0.02 R (+0.1) | −0.04 R (−0.5) | −0.08 R (−1.0) |
| `S120_dn200` | `R_dn200` | +0.03 R (+0.4) | −0.04 R (−0.3) | +0.01 R (+0.2) | −0.20 R (−2.0) |
| `S60_any` | `R_dn200` | +0.04 R (+0.4) | +0.03 R (+0.1) | −0.02 R (−0.4) | −0.09 R (−1.0) |
| `TRS_stack_adx25` | `R_dn200` | +0.04 R (+0.4) | −0.07 R (−0.7) | +0.02 R (+0.4) | −0.12 R (−1.4) |

The controls show where the money came from: the direction of the regime.

| Control | Active plans | Training: median mean net R / geometries losing money | 2025: median mean net R / geometries losing money |
|---|---:|---:|---:|
| `R_up200` | 4 | +0.07 R / 0 of 27 | +0.01 R / 27 of 27 |
| `R_up200` | 10 | +0.08 R / 0 of 27 | +0.01 R / 27 of 27 |
| `R_dn200` | 4 | −0.05 R / 27 of 27 | +0.11 R / 0 of 27 |
| `R_dn200` | 10 | −0.04 R / 27 of 27 | +0.07 R / 0 of 27 |
| `R_regime` | 4 | +0.00 R / 13 of 27 | +0.05 R / 6 of 27 |
| `R_regime` | 10 | +0.01 R / 4 of 27 | +0.03 R / 15 of 27 |

- Random longs above the EMA200 win in 2023–2024 and lose in 2025. Random shorts below it do the opposite.
- Following the regime in both directions breaks even in training and earns a little in 2025.
- The breakout trigger adds about 0.1 R in training on top of that, and almost nothing in 2025.
- Shorts alone add nothing over random shorts in the same regime; they matter only as the second half of a two-sided rule.

## Method

The engine is the swing screen of PR #462, extended to shorts. The data, indicators, plans, costs and portfolio rules are those of that screen unless stated.

- **Short plans** follow the PHP value classes for `short`:
  - the entry is the 4h close rounded down to the tick;
  - the stop is the entry plus the ATR multiple, rounded up;
  - the target is the entry minus the R multiple, rounded up, and must stay positive.

  `NetRCostMath` reserves only the funding rate adverse to the side. To reserve the same funding provision as a long, research passes it the negated provision rate for a short.
- **Short simulation:**
  - a sell limit fills when a minute's high reaches it;
  - the stop triggers on a high and exits at the worse of the stop and the open;
  - the target triggers on a low.

  The fill-minute and both-bounds ambiguity rules mirror the long ones. A short receives funding when the rate is positive. An uncertain funding instant books a charge (negative rate) and never a credit.
- **Policies.** The rules are evaluated at each 4h close.

| Policy | Long rule | Short rule |
|---|---|---|
| `B30_2s`, `B60_2s`, `B120_2s` | Close above the high of the previous 30, 60 or 120 bars (5, 10, 20 days) and above the EMA200 | Close below the low of the previous 30, 60 or 120 bars and below the EMA200 |
| `B60_any_2s` | Close above the 10-day high | Close below the 10-day low |
| `TR_2s_adx25` | EMA20 > EMA50 > EMA200, close above EMA50, ADX 14 ≥ 25 | EMA20 < EMA50 < EMA200, close below EMA50, ADX 14 ≥ 25 |
| `S30_dn200`, `S60_dn200`, `S120_dn200`, `S60_any`, `TRS_stack_adx25` | — | The short rules above |
| `R_regime`, `R_dn200`, `R_up200` | Random quarter of the decisions above the EMA200 | Random quarter of the decisions below the EMA200 |

- **Portfolio rules.** One active plan per pair, whatever its side. Pending entries count against the active-plan limit, and plans are admitted in the fixed symbol order when signals arrive together. The summaries split trades, mean net R and net PnL by side.

### Parity evidence

| Layer | Reference | Evidence |
|---|---|---|
| 4h indicators | Retained PHP contexts | The r1 indicator columns are unchanged, and their exhaustive check (65,760 decisions × 16 fields) applies. The compact check was rerun on r2: 105,236 contexts, 0 mismatches |
| Short plan arithmetic | `EntryZonePriceMath`, `ProtectionPriceMath`, `NetRCostMath` with `side = short` and 9, 21 or 42 funding intervals, run by `php` | 460,320 plans over six short and one long geometry × profile cases: status, prices, quantity, net risk and net R equal, including 2,227 rejected targets at or below zero. Two short reference vectors are CI tests |
| Short simulation | A long on the price series reflected around a pivot (prices `pivot − p`, funding rates negated) | 34,274 real short plans: fills, exits, reasons, ambiguity, funding instants and reflected exit prices all equal. The long side is checked against `exploration.simulate` (244,348 comparisons, 0 differences), itself proven against `PortfolioSimulator` |

The 32 synthetic tests of `test_research_swing.py` cover both sides, with 100 % line and branch coverage of `swing.py`. They include the PHP vectors, the funding sign rules and the reflection property.

Private artifacts: `tradingV3-private/research/20261009T060000Z-swing-r2/`. They include `PREREGISTRATION.md`, written before the runs, plus grids, screens and parity logs. The candles are those of r1.

Reproduce:

```bash
cd python-orchestrator
M="python -m app.backtesting.research.swing"
$M features --root ROOT
$M grid --root ROOT --phase training --policies two-sided,short --output training-setups.json      # and validation
$M grid --root ROOT --phase training --policies R_regime,R_dn200,R_up200 --output training-controls.json
$M grid --root ROOT --phase training --policies two-sided,short --max-active 10 --output training-10.json
python -m app.backtesting.research.exploration screen training-setups.json validation-setups.json
python scripts/research_exploration_parity.py swing-plan-math --root ROOT --phase training --cases S3.T3.H7d:adverse:short
python scripts/research_exploration_parity.py swing-kernel --root ROOT --phase training
```

## Limits

- This is an exploratory screen, and it grants no execution authority. The canonical `PortfolioSimulator` is long-only and intraday, so none of these plans can run in a canonical campaign yet.
- The portfolio rule decides the verdict, and the swing portfolio rule is an owner decision that has not been taken. The canonical admission order (fixed symbol order) is arbitrary for simultaneous signals.
- Validation 2025 has now informed four swing iterations, and the 19 configurations were picked from 270. Any selection still has to be confirmed once, on the guarded 2026 holdout, by the canonical pipeline.
- The data, fill-proxy and survivorship limits of the previous reports still apply. The research shorts assume perpetual shorting is always available at the frozen costs.

## Next options

1. **Fix the swing portfolio rule first, then choose one candidate, then build the canonical path.** The canonical path needs a swing mode and setup contracts, multi-day and short support in `PortfolioSimulator` and `ResearchPlanBuilder`, and PHP signal generation; it ends with the guarded 2026 holdout, run once. The most natural rule is one active plan per pair, with no arbitrary priority. Under it, `B30_2s S3.T5.H14d` passes and is borderline with four plans. This is a multi-day engineering effort.
2. **One more cheap exploratory step to reduce the fragility before choosing.** For example, rank simultaneous signals by breakout strength instead of symbol order, or test exits that trail the trend. It costs little, but it uses 2025 again.
3. **Stop the swing loop here** and keep the result as a documented hypothesis.
