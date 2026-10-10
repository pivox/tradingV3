# Historical research — day trading entry and geometry exploration (2026-10-11)

## Result

The frozen `day_trading.trend_continuation.long@1.1.0` setup produced **no accepted entry** on ten Binance USD-M pairs: 0 of 701,760 training evaluations (2023–2024) and 0 of 350,400 validation evaluations (2025). Every variant of the October 9 geometry catalogue is therefore a zero-trade attempt by construction, and no candidate can satisfy the campaign protocol.

An exploratory screen then relaxed the entry filters and the plan geometry: 1,360 configurations, each under the baseline and adverse cost profiles (2,720 replays). **No configuration shows a robust edge.** Of the 482 configurations with at least 100 training trades, the median mean realized net R is **−0.13** and only 2.9 % are positive. Three configurations pass the protocol's training gates; none passes validation. The research loop on this setup family is closed with a negative result, not a selected candidate. The 2026 holdout stays closed.

| Hypothesis (training 2023–2024, baseline costs) | Signals | Trades | Win rate | Mean net R | Profit factor |
|---|---:|---:|---:|---:|---:|
| Published 1.1.0 filters, canonical geometry | 0 | 0 | — | — | — |
| All four sections, no filter, canonical geometry | 7,425 | 168 | 23 % | −0.38 | 0.52 |
| Published filters minus the two global pullback filters | 346 | 15 | 7 % | −0.83 | 0.00 |
| No 5m/1m confirmations, 15m RSI/ADX/anti-extension | 26,064 | 757 | 29 % | −0.25 | 0.63 |
| Same, EMA20 5m anchor and wider zone (largest sample) | 26,064 | 3,838 | 36 % | −0.10 | 0.84 |

| Training-eligible configuration | Training trades / mean net R / PF | Validation 2025 trades / mean net R / PF | Verdict |
|---|---|---|---|
| No confirmations, published filters minus pullbacks, zone EMA20 15m, stop 1.5 × ATR 15m, target 1.5 R | 123 / +0.24 / 1.69 | 31 / +0.12 / 1.38 | Inconclusive: below the 50-trade validation minimum |
| Same, zone EMA20 5m 0.5 × ATR | 286 / +0.14 / 1.31 | 53 / −0.03 / 0.95 | Rejected |
| Same as the first, stop 2.0 × ATR 15m | 290 / +0.06 / 1.25 | 83 / −0.05 / 0.88 | Rejected |

With 1,360 configurations tested, three training passes are what selection on noise produces. The validation results confirm it.

## Why the published setup never trades

The setup's five long filters include two `global` filters, which the canonical rule engine evaluates on every available snapshot (`1m`, `5m`, `15m`, `1h`, `4h`):

- `pullback_confirmed_ma9_21` needs a **fresh** EMA9-over-EMA21 cross on the current bar of all five timeframes at once.
- `pullback_confirmed_vwap` needs every timeframe's close within 0.15 % of its VWAP.

Neither filter passes when all four sections are ready (7,425 training evaluations). On those evaluations the global `price_lte_ma21_plus_k_atr` filter passes 10 % of the time, `rsi_lt_70` 94 % and `adx_min_for_trend_1h` 60 %. This is a restrictive conjunction faithfully compiled from `validations.regular.yaml`, not an engine defect.

When filters are relaxed, the plan stage becomes the binding constraint. `ResearchPlanBuilder` requires the last 15m close to lie inside the entry zone around the anchor: by default the 5m VWAP ± max(0.3 × ATR 5m, 0.05 %). It also requires ≥ 1.3 R after costs: with a 2 R target and baseline costs, the stop distance must be at least about 0.46 % of price, which a 1.5 × ATR 5m stop often misses (61 % of zone-accepted plans in the unfiltered baseline). About 90 % of relaxed signals fail the zone check. Larger zones, EMA20 anchors and 15m/1h ATR stops raise the trade count, but the trades keep a negative expectancy.

## State of the October 9 campaign

- Signal generation completed for both phases (`training-signals-g1-r3`, `training-signals-g2-r2`, `validation-signals-g1-r2`, `validation-signals-g2-r2`), with complete reports and no errors.
- The experiment registry `20261009T060000Z-experiments-r2` completed three of 26 training attempts: baseline under both cost profiles, and `ema20_5m` under baseline. All three have 0 plans and 0 trades. The fourth attempt (`ema20_5m`, adverse) was interrupted on 2026-10-10 at 09:38:44 UTC, when the host shut down and stopped the supervisor unit after 11 h 50 min of CPU time.
- The remaining attempts are determined: all variants share the same signal files, which contain no passed rule, so every attempt yields 0 plans. Resuming would spend about 30 CPU-hours to confirm zeros. The campaign is closed as **no eligible candidate**, and the guarded holdout path (PR #460) stays unused.

## Method and parity evidence

`app.backtesting.research.exploration` replays the retained B1 rows one plan at a time instead of one minute of the whole universe at a time. Each replay takes about 0.1 s, against about 80 min per canonical attempt. Every element it does not take verbatim from retained PHP output is proven equal to the canonical implementation:

| Layer | Reference | Evidence |
|---|---|---|
| Section verdicts | Retained PHP verdicts | Used as is |
| Five 1.1.0 filters | Retained PHP filter verdicts | 0 mismatches on 1,052,160 evaluations (every training and validation row) |
| Zone, stop, target, quantity, net risk, ≥ 1.3 R gate | `EntryZonePriceMath`, `ProtectionPriceMath`, `NetRCostMath`, 250 quote notional cap | 0 mismatches on 659,221 cases evaluated by the PHP classes |
| Fill, exits, costs, funding, daily-loss and concurrency admission | `PortfolioSimulator` | 0 differences on 404 trades over 7 real months, including 99 daily-loss rejections; CI tests compare both kernels on synthetic days and are mutation-checked |

Inputs are the verified Binance acquisition `20261009T060000Z-10pairs-20221101` read through `select_sources`/`iter_verified_candles` and `FundingReader`, plus the frozen instrument (`sha256:0e1f545b…`) and cost (`sha256:c48a228b…`) assumptions. Materialization stops at 2026-01-01: holdout data is never read. Private artifacts and parity logs are under `tradingV3-private/research/20261009T060000Z-explore-entry-r1/`.

Reproduce a grid:

```bash
cd python-orchestrator
python -m app.backtesting.research.exploration extract --output ROOT/training <training *.results.ndjson>
python -m app.backtesting.research.exploration prepare --root ROOT --dataset-root DATASET \
  --funding-supplement-root SUPPLEMENT --instrument-path INSTRUMENTS --cost-path COSTS
python -m app.backtesting.research.exploration grid --root ROOT --phase training --geometries all --output grid.json
```

## Limits

- This is an exploratory screen, not a certified campaign. A selected hypothesis would still need a published setup version, the canonical campaign, the guarded holdout, and then Paper transfer. Nothing here grants execution authority.
- The data is Binance USD-M OHLCV. Fills are conservative closed-candle proxies with no order book or queue model. The instrument metadata is current, not historical. The ten-pair universe was chosen in 2026, so survivorship bias remains.
- Ranking uses realized-equity drawdown; the canonical campaign reports marked-to-market drawdown.
- The extended geometries (zone on ATR 15m, stops on ATR 15m or 1h) are not in the canonical `ResearchVariant` catalogue. Running them canonically would need a new setup version and catalogue support.

## Next options

The data does not support further tuning of this trend-continuation family. A next iteration needs a different hypothesis family, chosen explicitly by the owner, for example:

1. Pullback entries that rest a limit order at the anchor instead of requiring the close inside the zone. This changes the execution model.
2. The short setup or regime-conditional sides.
3. A longer horizon than the 8 h / UTC-day boundary of `day_trading`.

Each family needs its own signal generation. The exploration engine then screens it in minutes, and only a survivor goes to the canonical campaign.
