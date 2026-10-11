# Historical research — day trading entry and geometry exploration (2026-10-11)

## Result

The frozen `day_trading.trend_continuation.long@1.1.0` setup produced **no accepted entry** on ten Binance USD-M pairs: 0 of 701,760 training evaluations (2023–2024) and 0 of 350,400 validation evaluations (2025). Every variant of the October 9 geometry catalogue is therefore a zero-trade attempt by construction, and no candidate can satisfy the campaign protocol.

An exploratory screen then relaxed the entry filters and the plan geometry: 1,312 distinct configurations, each replayed under the baseline and adverse cost profiles for training and validation (5,248 replays). The screen applies the campaign protocol's screening (`experiments.screening_reasons`) to both cost profiles. **No configuration is protocol-eligible, and none passes the training gates under both cost profiles.**

- Configurations with at least 100 baseline training trades: 468.
- Median mean realized net R of those configurations: **−0.13**.
- Share with a positive mean net R: 3.0 %.

The research loop on this setup family is closed with a negative result, not a selected candidate. The 2026 holdout stays closed.

| Hypothesis (training 2023–2024, baseline costs) | Signals | Trades | Win rate | Mean net R (± SE) | Profit factor |
|---|---:|---:|---:|---:|---:|
| Published 1.1.0 filters, canonical geometry | 0 | 0 | — | — | — |
| All four sections, no filter, canonical geometry | 7,425 | 168 | 23 % | −0.38 (± 0.08) | 0.52 |
| Published filters minus the two global pullback filters | 346 | 15 | 7 % | −0.83 (± 0.08) | 0.00 |
| No 5m/1m confirmations, 15m RSI/ADX/anti-extension | 26,064 | 757 | 29 % | −0.25 (± 0.04) | 0.63 |
| Same, EMA20 5m anchor and wider zone (largest sample) | 26,064 | 3,838 | 36 % | −0.10 (± 0.02) | 0.84 |

Three configurations pass the baseline-profile training gates. All three use no 5m/1m confirmations, the published filters minus the global pullback filters, a 15m ATR stop and a 1.5 R target. Each fails the adverse profile and, in 2025, is below the 50-trade minimum or negative:

| Zone and stop | Training, baseline: trades / mean net R (± SE) / PF | Training, adverse: trades | Validation 2025, baseline: trades / mean net R / PF |
|---|---|---|---|
| EMA20 15m, stop 1.5 × ATR 15m | 123 / +0.24 (± 0.09) / 1.69 | 3 | 31 / +0.12 / 1.38 |
| EMA20 5m ± 0.5 × ATR, stop 1.5 × ATR 15m | 286 / +0.14 (± 0.06) / 1.31 | 3 | 53 / −0.03 / 0.95 |
| EMA20 15m, stop 2.0 × ATR 15m | 290 / +0.06 (± 0.06) / 1.25 | 22 | 83 / −0.05 / 0.88 |

The adverse profile's cost gate leaves almost no plan. With 1,312 configurations tested, a few baseline training passes are expected from noise, and the 2025 results do not confirm them.

### Random-entry control

The control sends a deterministic 5 % sample of all 15m evaluations through the same plan, fill and portfolio rules. It ignores every section and filter: sha256(`SYMBOL:ms`) mod 20 = 0. Standard errors use the per-trade net R deviation of about 1.0 R.

| Geometry (baseline costs) | Training: random entries | Training: setup signals | 2025: random entries | 2025: setup signals |
|---|---|---|---|---|
| Canonical baseline | 566 trades, −0.21 (± 0.05) | All sections: 168 trades, −0.38 (± 0.08) | 308 trades, −0.05 (± 0.07) | All sections: 38 trades, −0.37 (± 0.18) |
| EMA20 15m, stop 1.5 × ATR 15m, 1.5 R | 227 trades, +0.15 (± 0.07) | No confirmations: 123 trades, +0.24 (± 0.09) | 106 trades, +0.02 (± 0.10) | 31 trades, +0.12 (± 0.19) |
| EMA20 5m ± 0.5 × ATR, same stop and target | 716 trades, −0.04 (± 0.04) | No confirmations: 286 trades, +0.14 (± 0.06) | 299 trades, +0.01 (± 0.06) | 53 trades, −0.03 (± 0.15) |

- On the canonical geometry, the setup's signals do worse than random timing.
- On the two selected geometries, the training advantage over random entries is +0.09 R and +0.18 R. That is about 0.8 and 2.5 standard errors. The second is the size of edge expected from the best of 1,312 tries.
- Random entries on the best geometry are already profitable in 2023–2024 and flat in 2025. The training-period gains therefore reflect the market and the geometry more than the entry rules, and they do not persist in 2025.

## Why the published setup never trades

The setup's five long filters include two `global` filters, which the canonical rule engine evaluates on every available snapshot (`1m`, `5m`, `15m`, `1h`, `4h`):

- `pullback_confirmed_ma9_21` needs a **fresh** EMA9-over-EMA21 cross on the current bar of all five timeframes at once.
- `pullback_confirmed_vwap` needs every timeframe's close within 0.15 % of its VWAP.

Neither filter passes when all four sections are ready (7,425 training evaluations). On those evaluations, the global `price_lte_ma21_plus_k_atr` filter passes 10 % of the time, `rsi_lt_70` 94 % and `adx_min_for_trend_1h` 60 %. This is a restrictive conjunction faithfully compiled from `validations.regular.yaml`, not an engine defect.

When filters are relaxed, the plan stage becomes the binding constraint. `ResearchPlanBuilder` requires the last 15m close to lie inside the entry zone around the anchor: by default the 5m VWAP ± max(0.3 × ATR 5m, 0.05 %). It also requires ≥ 1.3 R after costs. With a 2 R target and baseline costs, the stop distance must then be at least about 0.46 % of price. A 1.5 × ATR 5m stop often misses that (61 % of zone-accepted plans in the unfiltered baseline). About 90 % of relaxed signals fail the zone check. Larger zones, EMA20 anchors and 15m/1h ATR stops raise the trade count, but the trades keep a negative expectancy.

## State of the October 9 campaign

- Signal generation completed for both phases (`training-signals-g1-r3`, `training-signals-g2-r2`, `validation-signals-g1-r2`, `validation-signals-g2-r2`), with complete reports and no errors.
- The experiment registry `20261009T060000Z-experiments-r2` completed three of 26 training attempts: baseline under both cost profiles, and `ema20_5m` under baseline. All three have 0 plans and 0 trades.
- The fourth attempt (`ema20_5m`, adverse) was interrupted on 2026-10-10 at 09:38:44 UTC, when the host shut down and stopped the supervisor unit after 11 h 50 min of CPU time.
- The remaining attempts are determined: all variants share the same signal files, which contain no passed rule, so every attempt yields 0 plans. Resuming would spend about 30 CPU-hours to confirm zeros. The campaign is closed as **no eligible candidate**, and the guarded holdout path (PR #460) stays unused.

## Method and parity evidence

`app.backtesting.research.exploration` replays the retained B1 rows one plan at a time, instead of one minute of the whole universe at a time. A configuration takes about 0.1 s to 1 s, against about 80 min per canonical attempt. Each layer it does not take verbatim from retained PHP output was compared with the canonical implementation:

| Layer | Reference | Evidence |
|---|---|---|
| Section verdicts | Retained PHP verdicts | Used as is |
| Five 1.1.0 filters | Retained PHP filter verdicts | Re-checked by every `extract`: 0 mismatches on all 1,052,160 training and validation rows |
| Zone, stop, target, net risk, ≥ 1.3 R gate | `EntryZonePriceMath`, `ProtectionPriceMath`, `NetRCostMath` run by `php` | Exact decimal arithmetic. Agrees on all 843,801 sampled cases (14 geometry × profile × phase sets), including bit-equal net risk and net R. The harness applies the 250 quote quantity cap itself instead of calling `CanonicalRiskEngine`. |
| Fill, exits, costs, funding, ambiguity, daily-loss and concurrency admission | `PortfolioSimulator` through `canonical_replay` | 0 differences on 408 trades over 8 real windows, comparing trade set, boundaries, reason, price, net, funding, ambiguity and every rejection count (including 99 daily-loss rejections). The canonical side receives the exploration's plans. |

The real funding archives carry no observed marks, so observed-mark funding is covered only by the synthetic CI tests, which compare both kernels on multi-day synthetic data. Nine local mutation runs each made the test suite fail; no mutation artifact is kept. The mutations changed fees, removed funding, raised the daily-loss cap, ignored unrealized loss, dropped two ambiguity rules, counted midnight cash in the new day, and moved the holding-window check before admission.

Statistics follow `statistics.py`: trades are counted in their exit year and annual net PnL by cashflow timestamp. Drawdown is a realized-equity proxy, whereas the canonical campaign reports a marked-to-market drawdown.

Inputs are read through the repository's verified readers: `select_sources`/`iter_verified_candles` and `FundingReader` over the acquisition `20261009T060000Z-10pairs-20221101`. The frozen instrument (`sha256:0e1f545b…`) and cost (`sha256:c48a228b…`) assumptions are hash-checked.

**Holdout:** no value at or after 2026-01-01 enters any input or output. Like the campaign, the funding inventory opens the next monthly archive only to attest continuity at the window end, and it reads a source-complement status file that also holds later observations.

Private artifacts and parity logs are under `tradingV3-private/research/20261009T060000Z-explore-entry-r2/`.

Reproduce:

```bash
cd python-orchestrator
M="python -m app.backtesting.research.exploration"
$M prepare --root ROOT --dataset-root DATASET --funding-supplement-root SUPPLEMENT \
  --instrument-path INSTRUMENTS --cost-path COSTS
$M extract --output ROOT/training <training *.results.ndjson>   # and ROOT/validation
$M extract --control-modulus 20 --output ROOT/control/training <training *.results.ndjson>
$M grid --root ROOT --phase training --geometries all --output training.json    # and validation
$M grid --root ROOT/control --phase training --policies R_random_control --geometries all --output control.json
$M screen training.json validation.json
python scripts/research_exploration_parity.py kernel --root ROOT --policy P --geometry G --start 2024-03-01 --end 2024-04-01
python scripts/research_exploration_parity.py plan-math --root ROOT --phase training --cases baseline:baseline
```

`ROOT/control` reuses the root's candles, funding and assumption files.

## Limits

- This is an exploratory screen, not a certified campaign. A selected hypothesis would still need a published setup version, the canonical campaign, the guarded holdout, and then Paper transfer. Nothing here grants execution authority.
- The data is Binance USD-M OHLCV. Fills are conservative closed-candle proxies with no order book or queue model. The instrument metadata is current, not historical. The ten-pair universe was chosen in 2026, so survivorship bias remains.
- The extended geometries (zone on ATR 15m, stops on ATR 15m or 1h) are not in the canonical `ResearchVariant` catalogue. Running them canonically would need a new setup version and catalogue support.
- The random control is a single 5 % sample. Its standard errors describe trade-level noise only.

## Next options

The data does not support further tuning of this trend-continuation family. A next iteration needs a different hypothesis family, chosen explicitly by the owner, for example:

1. Pullback entries that rest a limit order at the anchor instead of requiring the close inside the zone. This changes the execution model.
2. The short setup or regime-conditional sides.
3. A longer horizon than the 8 h / UTC-day boundary of `day_trading`.

Each family needs its own signal generation. The exploration engine then screens it in minutes, and only a survivor goes to the canonical campaign.
