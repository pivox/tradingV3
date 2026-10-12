# Swing canonical campaign — results (2026-10-12)

## Result

**The candidate fails the 2026 holdout and is closed.** The guarded holdout ran once (claim 2026-10-12 00:06:53 CEST, status `complete`, both units independently verified, candle coverage and cost evidence complete). Over 2026-01-01 → 2026-10-09T06:00Z it closes:

| Costs | Trades | Win rate | Net (USDT) | Mean net R | Net PF | Marked drawdown (USDT) |
|---|---:|---:|---:|---:|---:|---:|
| baseline | 267 | 34.8 % | +31.36 | +0.078 | 1.01 | 1,545 |
| adverse | 262 | 34.7 % | −64.38 | +0.019 | 0.97 | 1,563 |

Against the protocol's gates (net profit factor ≥ 1.2 baseline and ≥ 1.0 adverse, net strictly positive), the holdout fails under both cost profiles. Against the decision rule the owner's mandate fixed before the result was known (PF ≥ 1.1 baseline, ≥ 1.0 adverse, net > 0, mean net R > 0, at least 50 trades, drawdown ≤ 6,000 USDT), it fails as well.

The result is flat rather than negative: +31 USDT is 0.12 USDT per trade on 250 USDT positions, within the noise of one nine-month regime. The training and validation edge (+0.14 and +0.16 R per trade) does not carry into 2026 (+0.08 R baseline, +0.02 R adverse). The holdout claim of campaign `swing-2026-10-11` is consumed and will not be rerun. `B30_2s × S3.T5.H14d` is not transferred to Paper and not forwarded to any exchange path.

Holdout claims used on the 2026 window so far: **1** (this campaign). Every later candidate evaluated on 2026 must count and disclose its claim, because each use leaves the period less untouched.

Next, per the owner's mandate of 2026-10-12: a new pre-registered candidate-selection cycle (training 2023–2024, validation 2025, 2026 untouched during selection), each simulation also reported for several budgets from 500 USDT; then, if nothing survives, scalping and micro-scalping.

## Candidate and protocol

The owner fixed one candidate on 2026-10-11 (plan `docs/superpowers/plans/2026-10-11-swing-canonical-campaign.md`), the exploration's `B30_2s × S3.T5.H14d`:

- **Long** when the 4h close is above the 4h EMA200 and above the highest high of the previous 30 closed 4h bars. **Short** when it is below the EMA200 and below the lowest low of the previous 30 bars.
- **Entry:** a limit at the 4h close, resting 60 minutes.
- **Exits:** stop at 3 × ATR14(4h), one target at 5 R, 14-day holding horizon. Funding is provisioned for 42 intervals of 8 h.
- **Portfolio:** one active plan per pair, pending entries included, so ten plans at most. Each position is 250 USDT notional on a shared 100,000 USDT wallet per unit.
- **Pairs:** Binance USD-M BTC, ETH, BNB, XRP, ADA, DOGE, SOL, LTC, LINK and AVAX, from 1-minute candles.
- **Periods:**
  - training 2023-01-01 → 2025-01-01;
  - validation 2025;
  - holdout 2026-01-01 → 2026-10-09T06:00Z, run once after a `selected` freeze.
- **Costs:** the frozen baseline and adverse profiles (fees, spread, slippage, observed funding).
- **Gates**, per phase and cost profile:
  - at least 100 training trades, 30 per year, and 50 validation trades;
  - at least five pairs;
  - net PnL strictly positive each year;
  - mean net R strictly positive;
  - net profit factor ≥ 1.2 (baseline) and ≥ 1.0 (adverse);
  - marked drawdown ≤ 6,000 USDT.

Every number below comes from the canonical pipeline:

- PHP signal workers for each side;
- PHP plan workers;
- the swing kernel (`PortfolioSimulator`, profile `swing.v1`);
- independently replayed statistics.

R is the net PnL divided by the plan's total stop-loss risk, costs included. A stop therefore costs about −0.84 R net under the baseline costs, and a target earns about +3.9 R.

## Code identity and runs

| Step | Identity | When (CEST) |
|---|---|---|
| Frozen commit X | `6dfb43b3` (main with the swing PRs 1–5b, #464–#471), detached checkout plus vendor copy | 2026-10-11 20:00 |
| Training B1, four roots (2 sides × 2 groups of five pairs) | from X | 20:02 → 21:33 |
| Orchestration (`--family swing`): validation B1, protocol, four units, freeze, reports | from X | 21:33 → 23:50 (8,199 s) |
| Freeze | `selected`, `guarded_selected_identity_only` | 23:48 |
| Pin (PR 7, #473): frozen-verifier inventory of X, `SWING_FROZEN_COMMIT = X` | merged as `b06fb38b` | 2026-10-12 00:03 |
| Runner checkout | `b06fb38b`; its `app/` differs from X only by the pin | 00:04 |
| Authority install, real `HOME` | `20a14c05…` | 00:04 |
| Holdout claim (single use) | contract and claim under `registry.c2b-holdout/` | 00:06:53 |
| Holdout B1 long, ten pairs | runner `b06fb38b`, pinned frozen verifier | 00:07 → 00:38 |
| Holdout B1 short, ten pairs | same | 00:41 → 01:09 |
| Holdout unit baseline / baseline (plans, kernel, independent replay) | `holdout/units/baseline/baseline/` | 01:19 → 01:40 |
| Holdout unit baseline / adverse | `holdout/units/baseline/adverse/` | 01:50 → 02:11 |
| Holdout report `complete`, then `--verify-retained` (read-only replay, exit 0) | `holdout/report.{json,csv,fr.md}`, `status.json` | 02:12 → 02:21 |

Artifacts: `tradingV3-private/research/20261009T060000Z-swing-campaign-r1/`.

## Signals (B1)

Each decision is one closed 4h bar of one pair and one side.

| Phase | Side | Decisions | Passed |
|---|---|---:|---:|
| Training 2023–2024 | long | 43,860 | 1,544 |
| Training 2023–2024 | short | 43,860 | 775 |
| Validation 2025 | long | 21,900 | 713 |
| Validation 2025 | short | 21,900 | 669 |
| Holdout 2026 (to 10-09) | long | 16,880 | 486 |
| Holdout 2026 (to 10-09) | short | 16,880 | 428 |

Most passed signals are not traded: a breakout usually repeats on the following 4h bars while the pair already holds a plan. In training (baseline costs), 2,319 passes give 688 plans. The planner rejects the rest:

- 1,626 because the pair already has an active plan (`research_portfolio_symbol_active`);
- 5 because the 5 R short target would be at or below zero (`canonical_protection_target_polarity_invalid`).

Under the adverse costs, 42 (training) and 46 (validation) more passes also fail the 1.3 minimum net R.

In the holdout, 914 passes give 269 plans (baseline): 645 are rejected because the pair already holds a plan. Under the adverse costs, 21 more fail the minimum net R, leaving 264 plans.

## Training and validation

| Phase | Costs | Trades | Win rate | Net (USDT) | Mean net R | Net PF | Marked drawdown (USDT) |
|---|---|---:|---:|---:|---:|---:|---:|
| Training 2023–2024 | baseline | 682 | 34.0 % | +1,547.50 | +0.136 | 1.21 | 1,177 |
| Training 2023–2024 | adverse | 671 | 33.4 % | +1,209.99 | +0.066 | 1.16 | 1,268 |
| Validation 2025 | baseline | 319 | 40.1 % | +740.55 | +0.158 | 1.24 | 595 |
| Validation 2025 | adverse | 314 | 40.4 % | +634.49 | +0.095 | 1.20 | 618 |

All four units pass every gate.

- **Closest gate.** The training profit factor under baseline costs is 1.21, against a 1.2 gate.
- **Returns are small.** Ten positions of 250 USDT never commit more than 2,500 USDT of the 100,000 USDT wallet. The 2025 net is +0.74 % of the wallet (baseline costs).
- **By year** (baseline costs; a trade counts in the year it exits):

  | Year | Trades | Net (USDT) | Mean net R | Net PF |
  |---|---:|---:|---:|---:|
  | 2023 | 324 | +1,031.65 | +0.229 | 1.33 |
  | 2024 | 350 | +351.78 | +0.038 | 1.08 |
  | 2025 (validation) | 315 | +739.20 | +0.161 | 1.24 |

  Each training unit also has 8 trades that exit in January 2025 (+164 USDT), and each validation unit has 4 that exit in January 2026.

- **By side,** the result changes sign between periods:

  | Phase (baseline costs) | Long: trades / net / PF | Short: trades / net / PF |
  |---|---|---|
  | Training 2023–2024 | 399 / +2,272.83 / 1.52 | 283 / −725.33 / 0.76 |
  | Validation 2025 | 155 / +86.37 / 1.05 | 164 / +654.18 / 1.44 |

- **Concentration in training.** November 2024 alone earns +1,024 of the +1,548 USDT. Without it, the training net is +523 USDT (baseline) and +193 USDT (adverse). 2025 is spread more evenly: without its best month (July), it stays at +367 USDT; without its best pair (DOGE), at +474 USDT. 13 of 25 training months and 7 of 13 validation months are positive.
- **Pairs.**
  - Losing in training: BNB, ETH and LTC.
  - Losing in 2025: BTC and LTC; under adverse costs, XRP as well.
  - Every pair trades in every unit.

## Canonical pipeline against the exploration

`scripts/research_swing_compare.py` matched every canonical trade with the exploration (`swing.run_swing`) by pair and 4h decision, and compared each trade field by field.

| Unit | Costs | Canonical trades | Exploration trades | Long / short | Open at the window end | Other differences |
|---|---|---:|---:|---|---:|---:|
| Training | baseline | 682 | 682 | 399 / 283 | 8 | 0 |
| Training | adverse | 671 | 671 | 393 / 278 | 8 | 0 |
| Validation | baseline | 319 | 319 | 155 / 164 | 4 | 0 |
| Validation | adverse | 314 | 314 | 151 / 163 | 4 | 0 |

The canonical pipeline reproduces the exploration exactly. The only differences are the trades still open at a window's end, which the two engines settle by different conventions (see the [rehearsal](research-swing-rehearsal-2026-10-11.md)). They change each unit's net by less than 0.6 USDT. The exploratory evidence in the plan (682 / +0.14 R / PF 1.21 and 319 / +0.16 R / PF 1.24) is therefore also the canonical result.

## Holdout 2026

The holdout is the guarded one-shot path of the runbook: authority installed in the real `HOME`, frozen verifier subprocess over the pinned inventory of X, single-use claim, then the two units on their own 100,000 USDT wallets, an independent replay of each unit, the report, and a read-only `--verify-retained` pass. Scored window 2026-01-01 → 2026-10-09T06:00Z (exclusive), warm-up from 2025-11-01. Exit codes: holdout 0, verify 0. Status `complete`, `execution_authority: none`, `paper_transfer: distinct_pending`.

| Costs | Trades | Win rate | Gross (USDT) | Fees | Funding (signed) | Spread | Slippage | Net (USDT) | Mean net R | Net PF | Marked drawdown | Max exposure |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| baseline | 267 | 34.8 % | +149.76 | 45.72 | −30.67 | 12.00 | 30.00 | +31.36 | +0.078 | 1.01 | 1,545 | 2,485 |
| adverse | 262 | 34.7 % | +129.56 | 44.81 | −30.78 | 59.17 | 59.17 | −64.38 | +0.019 | 0.97 | 1,563 | 2,485 |

The adverse mean net R stays slightly positive while the net is negative: R is normalised by each plan's stop risk, and the losing plans of 2026 carry more stop risk than the winning ones.

- **Trade frequency is unchanged:** 29 trades per month, against 28 in training and 27 in validation. The signal still fires; the trades no longer pay.
- **By side** (baseline costs): long 139 trades, −148.14 USDT, PF 0.88, mean R +0.05; short 128 trades, +179.50 USDT, PF 1.17, mean R +0.11. As in 2025, the shorts carry the result and the longs lose. Under the adverse costs: long −220.53 (PF 0.83), short +156.15 (PF 1.14).
- **By exit** (baseline costs): 157 stops (−2,242 USDT, −0.81 R each), 21 targets (+1,064 USDT, +3.65 R each), 80 holding-deadline exits (+1,196 USDT, 84 % winners) and 9 trades still open at the window end (+13 USDT). Only 7.9 % of the trades reach 5 R, against about 9 % needed to break even with this stop rate.
- **By month** (baseline costs, by exit month): Jan +406, Feb +109, Mar −491, Apr −209, May −186, Jun +299, Jul −246, Aug +307, Sep −46, Oct (nine days) +89. Five of ten months are positive. Without January the holdout nets −375 USDT; without SOL, −173 USDT.
- **By pair** (baseline costs): SOL +204, ETH +102, XRP +63, BNB +55, BTC +19, DOGE +4; AVAX −189, ADA −169, LTC −43, LINK −16. Under the adverse costs BTC (−30) and DOGE (−3) turn negative too, so only four pairs of ten earn anything.
- **Independent verification:** each unit's `verified-result.json` replays the ledgers and equals the kernel (`independently_verified: true`); the B1 counters of the report equal the signal reports (16,880 decisions and 486 / 428 passes per side).

### Reading

Training and validation showed about +0.1 R over regime-matched random timing (the [two-sided exploration](research-swing-two-sided-exploration-2026-10-11.md)), and already a sign change between the sides across periods. The holdout returns the same geometry (34 % winners, −0.8 R stops, +3.7 R targets) without the surplus: mean net R +0.08 before and +0.02 after the adverse costs, that is zero within the noise of 267 trades (the standard error of the mean R is about 0.09 R). Nothing in the 2026 data contradicts the hypothesis that the 2023–2025 surplus was regime and selection (1,080 configurations evaluated on 2025) rather than a persistent timing edge.

## Limits

- **Simulated fills.** Fills come from 1-minute OHLC candles. Candles prove neither the path inside a bar nor queue priority for passive orders. Marked equity uses close prices as a proxy.
- **Costs and metadata.**
  - Fees are those of the Fake/local profile; spreads and slippage are hypotheses.
  - Funding is observed only, and is not certified by an exchange.
  - Instrument metadata is current, not historical.
- **Selection bias.** The ten pairs were chosen in 2026 (survivorship). Four swing iterations evaluated 1,080 configurations on 2025 before this candidate was fixed, so 2025 is not out of sample.
- **Holdout scope.** The 2026 holdout was the only untouched period. It is one regime of nine months, and it has now been used once; every later use must be counted.
- **No execution authority.** The campaign has none: transfer to Paper is a separate step that needs its own evidence.
