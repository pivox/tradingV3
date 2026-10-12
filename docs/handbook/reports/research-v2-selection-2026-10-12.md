# Research cycle v2 — candidate selection from zero (2026-10-12)

## Result

**No eligible candidate.** Of 436 pre-registered configurations screened on 2023–2024, eight
pass every training gate, all of them 4-hour long trend-following setups (Donchian 55 and
60-bar momentum with 5 R targets, Ichimoku). Their four selected variants were run once on
2025 and all four lose:

| Validated configuration | Training 2023–24: trades / mean R / PF | 2025: trades / net (baseline, adverse) / mean R / PF | 2025 control median |
|---|---|---|---|
| `A1.N55\|4h\|long\|S3.T5\|E1` | 299 / +0.350 / 1.73 | 146 / −277 / −318 / +0.006 / 0.83 | +0.055 |
| `A2.N60.K1\|4h\|long\|S3.T5\|E3` | 462 / +0.282 / 1.65 | 199 / −622 / −678 / −0.042 / 0.74 | +0.039 |
| `A2.N60.K2\|4h\|long\|S3.T5\|E3` | 441 / +0.279 / 1.63 | 187 / −656 / −709 / −0.064 / 0.72 | +0.031 |
| `D3\|4h\|long\|S2.T5\|E1` | 261 / +0.373 / 1.67 | 126 / −125 / −158 / +0.044 / 0.88 | −0.080 |

The training result is explained by selection among market exposure, not by timing: in the
4h long trend family, the best of 54 regime-matched random-timing variants earns +0.30 R per
trade in 2023–2024 (per-seed maximum, median 0.302, 95th percentile 0.364), as much as the
survivors (+0.27 to +0.32 R). 2025 removes the bull-market exposure and the survivors fall to
zero or below, while random timing inside the same regime stays slightly positive.

Intraday is worse: not one of the 228 fifteen-minute configurations has a positive mean net R
under the baseline costs; their random controls sit at −0.10 to −0.12 R, the cost of a 14 bp
round trip against 15-minute moves. Shorts at 4h have a median of −0.05 R.

Per the mandate, the next branch opens: scalping and micro-scalping on 1-minute data, under
their own pre-registration (drafted, to be frozen before any computation), with the fill and
cost caveats stated up front. The 2026 window stays untouched (one use so far, by the swing
campaign).

## Why this cycle

The swing candidate `B30_2s × S3.T5.H14d` passed the canonical training and validation gates
and failed the one-shot 2026 holdout (net PF 1.01 baseline, 0.97 adverse;
[report](research-swing-campaign-2026-10-12.md)). The owner's mandate of 2026-10-12 asked, in
that case, for a fresh candidate selection by an expert trader across the indicator families,
both sides, 15-minute and 4-hour cadences, with every simulation also reported for budgets from
500 USDT, and 2026 never looked at during selection.

## Method (pre-registered, frozen before any PnL computation)

`tradingV3-private/research/20261012T003000Z-explore-v2/PREREGISTRATION.md`, frozen
2026-10-12T00:53:28Z, body sha256 `4e6fdb79…`. Before the freeze only signal counts were
inspected (BTC and ADA), never outcomes.

- **Data.** Ten Binance USD-M pairs, 1-minute candles and funding from the official monthly
  archives (sha256 verified against the dataset manifest), parsed with volumes and cut at
  2026-01-01. Training 2023–2024, validation 2025.
- **Setups.** 24 setup rows in four families: trend and momentum (Donchian 20/55, ROC momentum,
  EMA stack with ADX and pullback, MACD turn, Supertrend flip), mean reversion (RSI2, RSI14,
  stochastic, Bollinger touch, ATR stretch, VWAP deviation), volatility (Bollinger squeeze, NR7,
  ATR compression breakout), multi-timeframe and structure (EMA20 pullback and RSI pullback with
  a 4h trend, Ichimoku, opening-range breakouts at 00:00 and 13:30 UTC, Fibonacci pullback of the
  last fractal impulse). Long and short, 15m and 4h where defined.
- **Exits.** ATR stops (2 or 3 ATR at 4h, 1.5 or 2.5 at 15m); targets 3 R and 5 R or a 3-ATR
  Chandelier trailing stop for the trend families; indicator exit at the mean or a 2 R target for
  mean reversion; time stops of 14 days / 24 h (trend) and 5 days / 8 h (reversion).
- **Execution.** The swing campaign's conventions: limit at the decision close resting one bar,
  1-minute fills, stop first on ties, both cost profiles, observed funding, 250 USDT per plan,
  one plan per pair.
- **Grid.** 436 configurations (A 216, B 88, C 60, D 72; 4h 208, 15m 228).
- **Controls.** Regime-matched random timing with the same exits and admission rule, matched to
  each configuration's trade count per pair and year, 10 seeds.
- **Gates (training, both cost profiles).** Trades ≥ 200 (4h) / 400 (15m) with yearly minima;
  net > 0 in 2023 and 2024; mean net R ≥ 0.15 (4h) / 0.10 (15m) baseline with a positive
  bootstrap 90 % lower bound and > 0 adverse; PF ≥ 1.25 baseline / 1.10 adverse; above the 95th
  percentile of the control and ≥ +0.10 R over its median; ≥ 6 of 10 pairs positive; positive
  without the best month; drawdown ≤ 6,000 USDT.
- **Selection.** At most three survivors per family and cadence, ranked by the bootstrap lower
  bound; second pass with the funding, session, weekday and volume filters on the survivors only;
  one validation pass on 2025 (trades ≥ 60 / 150, net > 0, PF ≥ 1.10 / 1.00, mean net R > 0 and
  at least half the training mean).
- **Budgets.** 500, 1,000, 2,000, 5,000, 10,000 and 100,000 USDT: risk 1 % of equity per trade,
  open notional ≤ 2 × equity, exchange step and minimum notional per pair.

Tooling: `python-orchestrator/scripts/research_v2/` ([runbook](../runbooks/research-v2-screen.md)).

## Training screen

436 configurations, 1,268 s on three workers, ten control seeds each
(`screen-training.json`, code `ce79e0a2fcab…`).

| Cadence, side | Configurations | Median mean R (baseline) | Share with mean R > 0 | Control median | Above the control's p95 | Pass all gates |
|---|---:|---:|---:|---:|---:|---:|
| 4h long | 104 | +0.080 | 78 % | +0.054 | 36 | 8 |
| 4h short | 104 | −0.049 | 21 % | −0.057 | 21 | 0 |
| 15m long | 114 | −0.117 | 0 % | −0.121 | 35 | 0 |
| 15m short | 114 | −0.109 | 0 % | −0.102 | 28 | 0 |

| Family | Configurations | Median mean R | Share > 0 | Pass |
|---|---:|---:|---:|---:|
| A trend and momentum | 216 | −0.069 | 28 % | 7 |
| B mean reversion | 88 | −0.092 | 6 % | 0 |
| C volatility | 60 | −0.054 | 40 % | 0 |
| D multi-timeframe and structure | 72 | −0.089 | 19 % | 1 |

Gate failures (a configuration can fail several): G1 trades 32, G2 a losing year 347, G3 mean R
380, G4 profit factor 367, G5 control 387, G6 breadth 359, G7 drawdown 57.

The eight passing configurations (baseline costs; control = regime-matched random timing):

| Configuration | Trades | Win | Net | Mean R | LB90 | PF base / adv | Control median / p95 | Pairs + | Months + |
|---|---:|---:|---:|---:|---:|---|---|---:|---:|
| `A1.N55\|4h\|long\|S2.T5` | 409 | 28 % | +1,766 | 0.268 | 0.151 | 1.50 / 1.46 | 0.073 / 0.158 | 8/10 | 11/24 |
| `A1.N55\|4h\|long\|S3.T5` | 334 | 35 % | +2,096 | 0.297 | 0.174 | 1.57 / 1.54 | 0.179 / 0.269 | 9/10 | 10/25 |
| `A2.N60.K1\|4h\|long\|S2.T5` | 653 | 30 % | +2,668 | 0.234 | 0.147 | 1.49 / 1.44 | 0.085 / 0.114 | 9/10 | 11/25 |
| `A2.N60.K1\|4h\|long\|S3.T5` | 481 | 36 % | +2,951 | 0.269 | 0.174 | 1.60 / 1.57 | 0.165 / 0.226 | 9/10 | 13/25 |
| `A2.N60.K2\|4h\|long\|S2.T5` | 608 | 30 % | +2,516 | 0.237 | 0.145 | 1.48 / 1.44 | 0.106 / 0.153 | 9/10 | 13/25 |
| `A2.N60.K2\|4h\|long\|S3.T3` | 508 | 39 % | +2,702 | 0.203 | 0.123 | 1.53 / 1.50 | 0.101 / 0.155 | 9/10 | 11/25 |
| `A2.N60.K2\|4h\|long\|S3.T5` | 451 | 37 % | +2,783 | 0.268 | 0.171 | 1.61 / 1.57 | 0.154 / 0.211 | 10/10 | 12/25 |
| `D3\|4h\|long\|S2.T5` | 298 | 31 % | +1,265 | 0.316 | 0.190 | 1.52 / 1.48 | 0.167 / 0.232 | 9/10 | 15/24 |

Reading. These are one idea seen through seven lenses: be long a major above its 4h EMA200
after a new high, with a wide stop and a far target, in 2023–2024. Each beats its own control's
95th percentile, but the family-wise null (the best of the family's 54 random-timing variants,
+0.30 R) says that choosing the best of many such exposures produces the same numbers. The best
short (`A5|4h|short|S2.TR3`, +0.17 R on 239 trades) fails only on a losing year; the best
mean-reversion configuration (`B1.RSI2|4h|long|S3.T2`, +0.06 R on 597 trades) fails the mean R,
profit factor and control gates; the best 15-minute configuration (`A5|15m|short|S2.5.T5`) is
at −0.01 R with a profit factor of 0.96.

## Second pass and validation

Selection (at most three per family and cadence, by the bootstrap lower bound):
`A1.N55|4h|long|S3.T5`, `A2.N60.K1|4h|long|S3.T5`, `A2.N60.K2|4h|long|S3.T5` (family A, out of
seven) and `D3|4h|long|S2.T5` (family D). The second pass applied the five filters to each
(`screen-second-pass.json`): 11 of 20 filtered variants pass the gates, and for every survivor
one of them has a higher lower bound, so the validation list is the filtered variant: the
funding filter E1 for A1.N55 (lower bound 0.174 → 0.230) and D3 (0.190 → 0.236), the weekday
filter E3 for both A2.N60 (0.174 → 0.181 and 0.171 → 0.181). This is more selection pressure,
pre-registered and disclosed.

Validation 2025 was run once (`screen-validation.json`, `validation-verdict.json`):

| Configuration | Trades | Win | Net base / adverse | Mean R base / adverse | PF base / adverse | Pairs + | Positive months | Gate failures |
|---|---:|---:|---|---|---|---:|---:|---|
| `A1.N55\|4h\|long\|S3.T5\|E1` | 146 | 28 % | −277 / −318 | +0.006 / −0.027 | 0.83 / 0.81 | 4/10 | 4/12 | net, PF, retention |
| `A2.N60.K1\|4h\|long\|S3.T5\|E3` | 199 | 29 % | −622 / −678 | −0.042 / −0.062 | 0.74 / 0.73 | 2/10 | 4/12 | net, PF, mean R, retention |
| `A2.N60.K2\|4h\|long\|S3.T5\|E3` | 187 | 26 % | −656 / −709 | −0.064 / −0.076 | 0.72 / 0.70 | 1/10 | 4/12 | net, PF, mean R, retention |
| `D3\|4h\|long\|S2.T5\|E1` | 126 | 26 % | −125 / −158 | +0.044 / −0.010 | 0.88 / 0.86 | 5/10 | 4/11 | net, PF, retention |

The 2025 pattern is the same for all four: losses from January to March, gains in April–May and
July, losses again from August. The regime-matched 2025 controls of the three A configurations
sit at +0.03 to +0.06 R: random entries above the EMA200 did slightly better than the setups'
timing. Outcome of the cycle: `no_eligible_candidate`. Nothing was tuned on 2025; no second
validation pass was run.

## Budgets

Every configuration carries the budget study (1 % of equity risked per trade, open notional
≤ 2 × equity, exchange step and minimum notional per pair). For the four validated
configurations:

| Configuration | Budget (USDT) | Training 2023–24: tradable / ROI / max DD | 2025: tradable / ROI / max DD |
|---|---:|---|---|
| `A1.N55\|…\|E1` | 500 | 298/299 / +130 % / 15 % | 139/146 / −7 % / 19 % |
| | 1,000 | 299/299 / +134 % / 15 % | 146/146 / −5 % / 18 % |
| | 10,000 | 299/299 / +137 % / 15 % | 146/146 / −6 % / 19 % |
| | 100,000 | 299/299 / +138 % / 15 % | 146/146 / −6 % / 19 % |
| `A2.N60.K1\|…\|E3` | 500 | 459/462 / +153 % / 28 % | 183/199 / −16 % / 27 % |
| | 1,000 | 462/462 / +165 % / 28 % | 199/199 / −15 % / 27 % |
| | 100,000 | 462/462 / +170 % / 28 % | 199/199 / −17 % / 28 % |
| `A2.N60.K2\|…\|E3` | 500 | 439/441 / +153 % / 23 % | 175/187 / −17 % / 31 % |
| | 100,000 | 441/441 / +154 % / 23 % | 187/187 / −19 % / 33 % |
| `D3\|…\|E1` | 500 | 261/261 / +132 % / 10 % | 125/126 / +2 % / 15 % |
| | 100,000 | 261/261 / +133 % / 10 % | 126/126 / +2 % / 16 % |

What the budget dimension says, independently of the verdict:

- **4-hour setups are feasible from 500 USDT.** With 2–3 ATR stops (5–10 % of price) a 1 %
  risk is 50–100 USDT of notional, above every pair's minimum (BTC 50, ETH/LTC/LINK 20, others
  5 USDT); 97–100 % of the trades are tradable and ten positions fit under the 2 × leverage cap.
  Returns scale linearly with the budget; only the rounding to the quantity step differs.
- **15-minute setups are not, at 500 USDT.** With 1.5 ATR stops (0.3–0.5 % of price) a 1 % risk
  asks for 1,000–1,700 USDT of notional per position, so the 2 × leverage cap admits one position
  at a time and skips the rest: on the RSI(2) 15m setup, 30 % of the trades were skipped at
  500 USDT and 2 % at 100,000 USDT. Small accounts would have to risk less than 1 % or trade one
  pair.
- The drawdowns (10–33 % of equity over two years at 1 % risk) are the figure to compare with a
  trader's tolerance; they do not change the verdict.

## Limits

- Fills come from 1-minute candles: no intra-candle path, no queue position for the resting
  limit, no order book. Spreads and slippage are the frozen hypotheses, fees the Fake/local
  policy, funding observed only, instrument metadata current rather than historical.
- The ten pairs were chosen in 2026 (survivorship). 2025 was used by the earlier cycles'
  explorations (1,080 swing configurations), so it is a weaker validation than it was.
- The 2026 window has been used once (swing holdout). Any v2 survivor tested on 2026 would be
  the second use, and the report would have to say so.
- Survivors that need a trailing stop, an indicator exit or a 15-minute cadence require kernel
  and contract work before any canonical campaign.
