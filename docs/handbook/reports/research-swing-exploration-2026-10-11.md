# Historical research — swing exploration (2026-10-11)

## Result

The owner chose a longer horizon after the day_trading family closed negative ([day trading exploration](research-day-trading-entry-exploration-2026-10-11.md)). This screen holds long positions for days instead of hours. Signals are evaluated at every 4h close on the same ten Binance USD-M pairs, and the campaign protocol is unchanged: training 2023–2024, validation 2025, both cost profiles, and a 2026 holdout that stays closed.

**No configuration of the pre-specified grid is protocol-eligible.** The grid has 270 configurations (10 signal policies × 27 exit geometries) with at most four active plans. Each runs under both cost profiles in both phases.

- Training looks strong: 243 configurations reach 100 baseline trades, 88 % of them have a positive mean net R (median +0.13 R), and 153 pass the training gates under both profiles.
- Validation removes all of them. On 2025 the median mean net R is +0.01 R, the profit factor stays below 1.2 (baseline) in 152 of the 153 training passes, and annual 2025 PnL is negative in most.
- Random entries explain most of the training result: random entries taken only when the close is above the 4h EMA200 earn +0.32 R per trade on the best geometry in 2023–2024 (setups: +0.40 R at best), then −0.10 R in 2025.

Breakout entries carry a small but consistent edge over random entries in the same regime, in both periods. Pullback, oversold and EMA-cross entries do not.

A sensitivity run, chosen after the primary grid, allows one active plan per pair (ten) instead of four. Then 19 configurations pass every gate, all breakouts (14 on the 20-day high, 5 on the 5-day high). They are not a robust candidate:

- 18 of the 19 lose money in 2025 once July 2025 is removed; one month carries the validation result.
- The 10-day breakout, between the two that pass, fails validation.
- With four active plans, none of them passes.

The swing family is therefore closed with a negative result for long-only entries. The data suggests a next hypothesis rather than a candidate: breakouts in both directions (see [Next options](#next-options)). The 2026 holdout is untouched.

## Primary grid

At most four active plans, pending entries included. Mean net R with its standard error, and PF the net profit factor. For each policy, the table shows the geometry with the best training mean among those with at least 100 baseline trades.

| Policy | Geometry | Training, baseline: trades / mean net R (± SE) / PF | Training, adverse PF | Validation 2025, baseline | Validation, adverse PF | 2025 net, baseline |
|---|---|---|---:|---|---:|---:|
| `B30_up200` | `S3.T5.H14d` | 238 / +0.33 (± 0.11) / 1.72 | 1.74 | 95 / −0.00 (± 0.14) / 0.86 | 0.86 | −138 |
| `B60_up200` | `S4.T5.H14d` | 173 / +0.38 (± 0.12) / 1.95 | 1.91 | 74 / −0.00 (± 0.16) / 0.77 | 0.71 | −228 |
| `B120_up200` | `S4.T5.H14d` | 132 / +0.37 (± 0.14) / 1.87 | 1.84 | 50 / +0.19 (± 0.22) / 1.04 | 1.02 | +25 |
| `B60_any` | `S2.T5.H7d` | 315 / +0.30 (± 0.10) / 1.49 | 1.43 | 118 / +0.03 (± 0.15) / 0.84 | 0.81 | −147 |
| `PB20_stack` | `S4.T5.H7d` | 230 / +0.15 (± 0.08) / 1.50 | 1.45 | 85 / +0.11 (± 0.13) / 0.95 | 0.93 | −38 |
| `PB50_stack` | `S4.T5.H14d` | 188 / +0.23 (± 0.10) / 1.69 | 1.65 | 74 / +0.14 (± 0.18) / 0.96 | 0.94 | −46 |
| `OS30_up200` | — | at most 42 trades | | | | |
| `OS40_up200` | `S4.T5.H14d` | 133 / −0.00 (± 0.10) / 1.06 | 1.03 | 48 / −0.01 (± 0.18) / 0.93 | 0.90 | −47 |
| `X921_up200` | `S3.T2.H7d` | 263 / +0.15 (± 0.07) / 1.38 | 1.29 | 109 / −0.10 (± 0.10) / 0.67 | 0.42 | −353 |
| `TR_stack_adx25` | `S4.T5.H14d` | 161 / +0.40 (± 0.12) / 2.31 | 2.26 | 65 / +0.23 (± 0.20) / 1.00 | 0.98 | +1 |
| `R_all` (control) | `S3.T5.H14d` | 341 / +0.20 (± 0.08) / 1.62 | 1.53 | 183 / −0.06 (± 0.09) / 0.81 | 0.77 | −432 |
| `R_up200` (control) | `S4.T5.H14d` | 218 / +0.32 (± 0.10) / 1.81 | 1.75 | 100 / −0.10 (± 0.12) / 0.68 | 0.67 | −454 |

Across the 243 configurations with at least 100 training trades and 30 validation trades, the training and validation mean net R correlate at +0.60: the ranking carries over, the level does not. Among the 153 configurations that pass training under both profiles, validation fails on:

- the baseline profit factor in 152;
- the adverse profit factor in 131;
- a negative 2025 PnL in 130 (adverse) and 120 (baseline);
- a non-positive mean net R in 83 (adverse) and 48 (baseline).

### Edge over random entries

`R_up200` sends a deterministic quarter of the 4h decisions whose close is above the 4h EMA200 through the same plans, fills, exits and portfolio rules: sha256(`SYMBOL:ms`) mod 4 = 0. The table gives, over the 27 geometries, the mean difference of mean net R between the setup and this control at the same geometry. The median z uses both standard errors.

| Policy | Training: mean difference (median z) | Validation: mean difference (median z) | Geometries where the setup beats the control in 2025 |
|---|---:|---:|---:|
| `B30_up200` | +0.10 R (+1.3) | +0.04 R (+0.3) | 21 / 27 |
| `B60_up200` | +0.11 R (+1.4) | +0.01 R (+0.1) | 17 / 27 |
| `B120_up200` | +0.14 R (+1.7) | +0.10 R (+0.7) | 21 / 27 |
| `B60_any` | +0.08 R (+1.1) | +0.02 R (+0.1) | 16 / 27 |
| `PB20_stack` | −0.01 R (+0.1) | −0.05 R (−0.8) | 9 / 27 |
| `PB50_stack` | −0.03 R (+0.0) | +0.04 R (+0.2) | 15 / 27 |
| `OS30_up200` | −0.04 R (−0.0) | too few trades | — |
| `OS40_up200` | −0.18 R (−2.1) | −0.11 R (−0.9) | 3 / 27 |
| `X921_up200` | −0.01 R (+0.4) | −0.07 R (−0.6) | 5 / 27 |
| `TR_stack_adx25` | +0.09 R (+1.2) | +0.05 R (+0.5) | 21 / 27 |

- Breakouts and trend continuation beat regime-matched random timing in both periods, by about 0.1 R in training and less in 2025. No single geometry is significant once the 27 tries per policy are accounted for, but the sign holds across geometries and phases.
- Pullback, oversold and EMA-cross entries do no better than random timing.
- In 2025 the regime-matched random control loses money at all 27 geometries (profit factor below 1): the long-only exposure itself lost money, and the breakout edge is too small to offset it under four active plans.

## Sensitivity: one active plan per pair

The same 270 configurations with ten active plans (one per pair) yield 19 protocol-eligible configurations: 14 on `B120_up200` and 5 on `B30_up200`. The last column removes the cashflows of trades exiting in July 2025.

| Policy | Geometry | Training, baseline: trades / mean net R (± SE) / PF | Validation 2025, baseline | Validation, adverse | 2025 net without July, baseline |
|---|---|---|---|---|---:|
| `B120_up200` | `S2.T3.H3d` | 422 / +0.15 (± 0.07) / 1.27 | 141 / +0.20 (± 0.12) / 1.29 | 140 / +0.14 (± 0.10) / 1.24 | −13 |
| `B120_up200` | `S2.T5.H7d` | 336 / +0.22 (± 0.10) / 1.38 | 106 / +0.30 (± 0.17) / 1.29 | 106 / +0.16 (± 0.12) / 1.21 | −96 |
| `B120_up200` | `S3.T3.H3d` | 365 / +0.21 (± 0.06) / 1.58 | 127 / +0.15 (± 0.10) / 1.25 | 127 / +0.12 (± 0.09) / 1.20 | −76 |
| `B120_up200` | `S3.T3.H7d` | 308 / +0.23 (± 0.08) / 1.48 | 100 / +0.28 (± 0.13) / 1.31 | 92 / +0.20 (± 0.11) / 1.29 | −125 |
| `B120_up200` | `S3.T5.H14d` | 247 / +0.33 (± 0.11) / 1.65 | 83 / +0.39 (± 0.19) / 1.31 | 82 / +0.20 (± 0.13) / 1.24 | −178 |
| `B120_up200` | `S3.T5.H7d` | 289 / +0.29 (± 0.10) / 1.55 | 91 / +0.31 (± 0.15) / 1.35 | 91 / +0.21 (± 0.12) / 1.30 | −148 |
| `B120_up200` | `S4.T2.H3d` | 372 / +0.19 (± 0.05) / 1.70 | 129 / +0.13 (± 0.08) / 1.26 | 126 / +0.10 (± 0.07) / 1.22 | −8 |
| `B120_up200` | `S4.T2.H7d` | 304 / +0.26 (± 0.06) / 1.77 | 101 / +0.24 (± 0.11) / 1.39 | 72 / +0.16 (± 0.11) / 1.32 | −48 |
| `B120_up200` | `S4.T3.H14d` | 235 / +0.33 (± 0.09) / 1.95 | 85 / +0.26 (± 0.15) / 1.20 | 73 / +0.12 (± 0.12) / 1.08 | −303 |
| `B120_up200` | `S4.T3.H3d` | 354 / +0.19 (± 0.05) / 1.70 | 123 / +0.12 (± 0.08) / 1.20 | 123 / +0.09 (± 0.07) / 1.16 | −83 |
| `B120_up200` | `S4.T3.H7d` | 281 / +0.28 (± 0.08) / 1.76 | 92 / +0.24 (± 0.12) / 1.25 | 92 / +0.17 (± 0.10) / 1.22 | −161 |
| `B120_up200` | `S4.T5.H14d` | 218 / +0.42 (± 0.11) / 2.10 | 76 / +0.33 (± 0.19) / 1.29 | 76 / +0.21 (± 0.14) / 1.26 | −235 |
| `B120_up200` | `S4.T5.H3d` | 349 / +0.19 (± 0.05) / 1.67 | 119 / +0.12 (± 0.08) / 1.21 | 119 / +0.09 (± 0.08) / 1.16 | −39 |
| `B120_up200` | `S4.T5.H7d` | 265 / +0.32 (± 0.09) / 1.89 | 86 / +0.24 (± 0.13) / 1.28 | 86 / +0.17 (± 0.11) / 1.24 | −164 |
| `B30_up200` | `S2.T3.H3d` | 699 / +0.12 (± 0.05) / 1.22 | 279 / +0.16 (± 0.08) / 1.25 | 275 / +0.11 (± 0.07) / 1.21 | +138 |
| `B30_up200` | `S2.T5.H14d` | 529 / +0.19 (± 0.08) / 1.34 | 196 / +0.22 (± 0.12) / 1.27 | 167 / +0.12 (± 0.09) / 1.25 | −24 |
| `B30_up200` | `S2.T5.H7d` | 565 / +0.23 (± 0.08) / 1.37 | 215 / +0.26 (± 0.12) / 1.32 | 213 / +0.16 (± 0.09) / 1.27 | −20 |
| `B30_up200` | `S3.T5.H7d` | 484 / +0.29 (± 0.07) / 1.58 | 189 / +0.22 (± 0.11) / 1.25 | 189 / +0.14 (± 0.08) / 1.21 | −91 |
| `B30_up200` | `S4.T5.H7d` | 448 / +0.29 (± 0.06) / 1.82 | 179 / +0.15 (± 0.09) / 1.22 | 179 / +0.11 (± 0.08) / 1.18 | −113 |
| `B60_up200` (not eligible) | `S3.T3.H7d` | 394 / +0.23 (± 0.07) / 1.50 | 167 / +0.05 (± 0.10) / 0.92 | 151 / +0.00 (± 0.08) / 0.88 | |
| `R_up200` (control) | `S3.T3.H7d` | 803 / +0.10 (± 0.04) / 1.28 | 304 / −0.01 (± 0.07) / 0.81 | 285 / −0.05 (± 0.06) / 0.77 | |

- The 2025 gains come from July 2025: for `B120_up200 S3.T3.H7d`, July adds +374 quote to a year that ends at +249.
- The breakouts lose much less than random timing in the weak first quarter: −95 quote in January–March 2025 for `B120_up200 S3.T3.H7d`, against −688 for `R_up200` at the same geometry.
- Profits are spread over several pairs, not one: removing the best pair (ETH) leaves `B120_up200 S3.T3.H7d` at +157 quote.
- With 19 passes out of 270 configurations, on a rule changed after the primary grid, and a result that disappears without one month, this does not justify opening the holdout.

## Method

`app.backtesting.research.swing` adds the swing family to the exploration engine of PR #461 and reuses its plan arithmetic, fill, exit, cost and funding rules.

- **Data.** The same verified Binance USD-M acquisition is read through `select_sources`/`iter_verified_candles`, from 2022-11-01. The two months before 2023 only warm up the indicators. Funding (`FundingReader`) and the frozen instrument and cost assumptions are the same as in the day_trading screen. Scoring and every simulated position stay in 2023–2025.
- **Bars and indicators.** 4h bars are aggregated from 1m candles in complete UTC buckets, like `ResearchRollingWindows`. Indicators are computed on the last 250 closed bars with the operation order of `CanonicalPhpIndicatorCalculator`: EMA 9/20/21/50/200 and their previous values, RSI 14, ATR 14, ADX 14 and MA 21. Sums run left to right like PHP's `array_sum`, because Python 3.12's `sum()` compensates and can differ in the last bit.
- **Decisions.** 65,760 decisions, one per 4h close and pair: 43,860 in training and 21,900 in validation. Each policy is a long-only rule on the bar that just closed.

| Policy | Rule at the 4h close |
|---|---|
| `B30_up200`, `B60_up200`, `B120_up200` | Close above the highest high of the previous 30, 60 or 120 bars (5, 10, 20 days), and above the EMA200 |
| `B60_any` | Close above the 10-day high, no trend filter |
| `PB20_stack` | EMA20 > EMA50 > EMA200 and close above EMA50; close back above the EMA20 after a close below it |
| `PB50_stack` | Same trend; a low of the last day touched the EMA50, close above it |
| `OS30_up200`, `OS40_up200` | Above the EMA200 with RSI 14 below 30 or 40 |
| `X921_up200` | Fresh EMA9-over-EMA21 cross above the EMA200 |
| `TR_stack_adx25` | EMA20 > EMA50 > EMA200, close above EMA50, ADX 14 at least 25 |
| `R_all`, `R_up200` | Controls: random quarter of all decisions, or of those above the EMA200 |

- **Plans.** The entry is a limit at the 4h close rounded up to the tick, resting 60 minutes. The stop is the entry minus 2, 3 or 4 times the 4h ATR. The target is 2, 3 or 5 R. The holding deadline is 3, 7 or 14 days. Geometry ids read `S<stop>.T<target>.H<days>d`.
- **Costs and sizing.** Sizing, costs and the net-R ≥ 1.3 gate are the canonical ones (`protect_and_size`), except that the funding provision covers every 8h interval of the horizon (9, 21 or 42), as `NetRCostMath` does for its `fundingIntervals` argument. Under the adverse profile a 14-day provision reserves 4.2 % of notional: with a 2 R target, the gate rejects all 1,193 training signals of `B60_up200` at a 2 ATR stop, and 1,000 of them at a 4 ATR stop.
- **Simulation.** A position exits at its stop, its target or the deadline. The stop wins when one minute reaches both. On the fill minute, a target is ignored and the trade is flagged ambiguous. A deadline exit settles at the strictly prior close. Funding follows `adverse_possible_credit_certain.v1`. An hour/day index of 1m extremes finds the exit in a few hundred steps.
- **Portfolio policy** (research assumption; a swing mode contract would fix it):
  - at most four active plans, pending entries included, one per pair;
  - 250 quote notional at leverage 1;
  - no daily-loss admission rule, because one wide swing stop can exceed the day_trading 30 quote cap;
  - a position still open at the end of its phase is closed there at the last known close.
- **Statistics.** The statistics and the protocol screen are `exploration.summarize` and `exploration.screening_reasons`, so the gates are identical to the day_trading screen. An exit exactly at the phase end belongs to the next year, as in `statistics.py`.

### Parity evidence

| Layer | Reference | Evidence |
|---|---|---|
| Indicator formulas | PHP 8.4 `Ema`, `Rsi`, `AtrCalculator`, `Adx`, `Sma` | 14 reference values on a synthetic 250-bar window, bit-equal (CI test) |
| 4h indicators on real data | Every retained PHP 4h context of the October 9 signal runs | 65,760 decisions × 16 fields (EMA 9/20/21/50/200 and previous values, RSI, ATR, ADX, MA21, MA21 + 1.3 ATR, close): 0 mismatches |
| 4h and 1h aggregation | Retained compact contexts of the random-timing sample | 105,236 contexts × 11 fields: 0 mismatches |
| Plan arithmetic | `EntryZonePriceMath` (tick rounding), `ProtectionPriceMath`, `NetRCostMath` with 9, 21 and 42 funding intervals, run by `php` | 394,560 plans over six geometry × profile cases: status, prices, quantity, net risk and net R equal |
| Fill, exit, cost and funding rules | `exploration.simulate` (checked against `PortfolioSimulator` in PR #461) and a minute-by-minute scan | 244,348 comparisons on real candles and funding, both profiles and phases: 0 differences |

The canonical `PortfolioSimulator` cannot replay these plans: it only accepts plans that end within 8 hours and before the next UTC midnight. Its 30 quote daily cap and single funding interval are also those of day_trading, and it is long-only. The kinematics are therefore checked against the exploration kernel, whose 90 s / 8 h behaviour was proven equal to the canonical simulator.

About 16 % of the trades are flagged ambiguous. Funding is charged at 00:00, 08:00 and 16:00 UTC, often a few milliseconds late, and these are 4h decision times: the funding instant then falls inside the fill minute. The conservative policy books such a charge and drops such a credit; the amount is one funding period at most.

Private artifacts are under `tradingV3-private/research/20261009T060000Z-swing-r1/`: prepared data, frames, grids, sensitivity runs and parity logs.

Reproduce:

```bash
cd python-orchestrator
M="python -m app.backtesting.research.swing"
$M prepare --root ROOT --dataset-root DATASET --funding-supplement-root SUPPLEMENT \
  --instrument-path INSTRUMENTS --cost-path COSTS
$M features --root ROOT
$M parity --root ROOT --rows CONTROL/training CONTROL/validation --results <*.results.ndjson>
$M grid --root ROOT --phase training --output training.json          # setup policies; and validation
$M grid --root ROOT --phase training --policies R_all,R_up200 --output training-controls.json
$M grid --root ROOT --phase training --max-active 10 --output training-10.json
python -m app.backtesting.research.exploration screen training.json validation.json
python scripts/research_exploration_parity.py swing-plan-math --root ROOT --phase training --cases S4.T5.H14d:baseline
python scripts/research_exploration_parity.py swing-kernel --root ROOT --phase training
```

`CONTROL` is the random-timing sample of the day_trading screen (`20261009T060000Z-explore-entry-r2/control`). `grid` runs the ten setup policies by default; the controls run separately so the screen counts setups only. `screen` keeps rows with different `--max-active` apart and rejects duplicate rows.

## Limits

- This is an exploratory screen, not a certified campaign, and it grants no execution authority. A canonical swing campaign would need:
  - a published swing mode and setup;
  - a `PortfolioSimulator` parameterized by mode (lifetime, daily-loss rule, funding provision);
  - PHP signal generation for the new setup.
- The data is Binance USD-M OHLCV. Fills are closed-candle proxies with no order book. A limit at the close misses breakouts that never trade back down within the hour. Instrument metadata is current, and the ten pairs were chosen in 2026, so survivorship bias remains.
- 540 configurations (primary and sensitivity) have now been evaluated on 2025. Validation has informed the analysis, so any later selection must be confirmed on the guarded 2026 holdout, once.
- There is no daily-loss rule, and the portfolio limit changes the result. The concurrency rule a swing mode would adopt is an owner decision.

## Next options

1. **Breakouts in both directions.** Breakout entries beat random timing in both periods, while long-only exposure lost in 2025. Short entries on breakdowns would test whether trend persistence pays in falling markets too. This needs short-side plans and exits in the exploration engine; a canonical campaign would also need a short-capable kernel.
2. **Exits that let trends run**, such as a trailing stop or a longer horizon, and a BTC trend filter for the other pairs.
3. **The canonical path for the 20-day breakout long**: swing mode contract, kernel support for multi-day plans, PHP signals. Given the July dependence, this is not recommended before option 1.
