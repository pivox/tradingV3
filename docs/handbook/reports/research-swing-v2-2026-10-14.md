# Swing v2 — ranked 4h breakouts with a strength filter (2026-10-14)

## Result

**No candidate: none of the five selected configurations passes validation on the ten mid-caps
(2023-01-01 → 2026-01-01, read once, 2026-10-14 08:35–08:40Z). The 2026 holdout is not claimed: the
mid-caps' 2026 stays unread, and the count of 2026 holdout claims stays at one (`B30_2s`, 2026-10-12).**

- The training (the ten majors 2023-24, read many times before) looked strong. 96 of 288 configurations
  passed every r1/r2 gate on both cost profiles, against 9.8 expected by chance from the matched controls.
  Nearly all of them were long; the best showed a surplus of +0.42 to +0.55 R per trade over random timing.
- The validation removes the absolute result. **None of the 96 configurations passes the validation
  gates** (0.3 expected by chance). The best selected configuration, `B120-Q0.5|long|C|S3|T3|H14d`, fails on
  a single gate: the year 2024 (57 trades, −658 USDT at 10,000 USDT). Over 2023-25 it makes +0.20 R on
  149 trades, PF 1.37, 8 of 10 pairs positive, with a surplus of +0.23 R over its control (p 0.01). It is
  closed by the pre-registered rule.
- **A relative effect survives, an absolute one does not.**
  - In validation, 70 of the 96 training passers still beat their control (median surplus +0.03 R).
  - The long 20-day breakouts with a 3 R target beat it in 15 of 15 configurations (p ≤ 0.10, median
    surplus +0.14 to +0.17 R).
  - Their PnL depends on the year: the mid-caps lost in 2024 for every selected long.
  - The Chandelier exits keep no surplus, and the shorts do not beat their control in either phase.

The canonical contracts `swing@1.0.0` are unchanged; `B30_2s × S3.T5.H14d` stays rejected; nothing is
published. The forward OKX test is unaffected.

## Why

The owner asked on 2026-10-14 (relayed by the session tradingv3-db, "les deux oui") for a new
pre-registered swing cycle, run in parallel with the forward OKX test. It was framed as a new hypothesis,
not a repair of `B30_2s`. The novelties were:

- a breakout-quality filter;
- simultaneous signals ranked by that quality;
- a continuation entry and a trend-following exit;
- 1 % risk sizing;
- two separate streams;
- selection on the surplus over a matched random control.

The owner confirmed two points of the gates before the freeze (drawdown ≤ 20 R; a validation year must be
positive only with ≥ 30 trades). The owner also asked that configurations passing by chance be documented
(§ "Passed by chance").

Pre-registration `tradingV3-private/research/20261014T083000Z-swing-v2/PREREGISTRATION-swing-v2.md`, frozen
2026-10-14T08:26:11Z (body sha256 `ce57375b…`), before any 4h value of the mid-caps was read. Code
`python-orchestrator/scripts/research_swing2` (commit `c8f32296`; the package hashes recorded in both
screens are equal). Chain `run-swing-v2.sh`, outputs in the same directory.

## Protocol

- **Signals (4h, UTC).**
  - Long when the close is above the EMA200 and above the highest high of the prior `N` bars,
    `N ∈ {30, 60, 120}` (5, 10, 20 days). Short mirrored, below the EMA200.
  - Strength = distance of the close beyond the level, in ATR14(4h); kept when ≥ `Q ∈ {0, 0.25, 0.5}`.
  - Indicators are PHP-identical, on 250-bar windows (`swing.build_frame`).
- **Entries.**
  - `L`: a limit at the close, 60 minutes, maker — the B30 entry.
  - `C`: a stop-limit at the decision bar's high (low), limit ± 0.25 ATR, one 4h bar, taker. No market
    fallback.
- **Exits.**
  - Stop `S ∈ {2, 3}` ATR from the fill.
  - `T3`: a 3 R target. `CH`: a Chandelier stop (highest high since the fill − `S` × current ATR, ratcheting
    at each 4h close).
  - Holding limit `H ∈ {7, 14}` days.
- **Portfolio.**
  - Four positions, one per pair, signals ranked by strength.
  - 1 % risk per trade of a fixed 10,000 USDT, one position's notional ≤ a quarter of the equity
    (leverage 1).
  - The r1/r2 baseline and adverse cost profiles.
  - 144 configurations per side, 288 in all.
- **Control.** 100 seeds per configuration: as many decisions per pair as the configuration, drawn among
  the same-regime 4h closes, randomly ranked. Same entry, stop, exit, holding limit and portfolio. The
  continuation entry filters the control exactly as it filters the configuration (`screen.py:76` and `:84`
  pass the same `Geometry` to `engine.run_stream`; `engine.py:494–498`). The long continuation orders
  went unfilled 37 % of the time for the selected configurations and 51 % for their controls.
- **Gates (r1/r2 on both profiles).**
  - Trades: ≥ 100 in training, ≥ 50 in validation.
  - At least 5 pairs; mean net R > 0.
  - PF ≥ 1.2 baseline / ≥ 1.0 adverse.
  - Realized drawdown ≤ 20 R.
  - Years: in training, each year ≥ 30 trades and net > 0. In validation, a year with ≥ 30 trades must be
    net > 0.
- **Selection.** All gates, a surplus > 0 on both profiles and empirical p ≤ 0.10, at most three per side
  by surplus. The validation decision is limited to those; every other training passer is replayed for
  information and declared not selectable.
- **Engine check before the freeze.** In fixed symbol order with the B30 geometry, the engine reproduces
  the 339 training paths of `B30_2s × S3.T5.H14d` of the swing r2 screen exactly.
- **Disclosed before the freeze.** A speed check of three configurations on the majors' training window,
  with figures published in the pre-registration.

## Training: the ten majors 2023-24

`screen-training.json` (288 configurations × 100 seeds, 528 s, 2 workers).

| Stream | Configurations | Pass all gates | Expected by chance | Selectable | Expected by chance | Median mean R | Median control | Median surplus |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| Long | 144 | 94 | 8.4 | 93 | 3.4 | +0.20 | +0.04 | +0.14 |
| Short | 144 | 2 | 1.4 | 2 | 1.4 | −0.00 | −0.05 | +0.04 |
| All | 288 | 96 | 9.8 | 95 | 4.8 | | | |

"Expected by chance" applies the same gates to each control seed as a pseudo-configuration. The control
pass rate is 5.8 % for longs and 1.0 % for shorts. The smallest Benjamini-Hochberg q over the 288 is 0.023:
most long passers sit at the floor of the empirical p (1/101). The shorts' two passers are what chance
predicts.

Selected (decision set):

| Configuration | Trades | Mean R (base / adverse) | PF (base / adverse) | Drawdown | Control median | Surplus | p | Exits stop / target / time / trailing |
|---|---:|---|---|---:|---:|---:|---:|---|
| B120-Q0.5\|long\|C\|S2\|T3\|H14d | 151 | +0.50 / +0.31 | 2.12 / 1.98 | 15.2 R | +0.02 | +0.48 | 0.01 | 83 / 64 / 4 / 0 |
| B120-Q0.5\|long\|C\|S3\|T3\|H14d | 118 | +0.55 / +0.37 | 2.31 / 2.20 | 10.8 R | +0.10 | +0.46 | 0.01 | 57 / 44 / 17 / 0 |
| B120-Q0.5\|long\|C\|S2\|T3\|H7d | 155 | +0.47 / +0.32 | 1.94 / 1.81 | 16.7 R | +0.04 | +0.42 | 0.01 | 79 / 54 / 22 / 0 |
| B30-Q0.5\|short\|L\|S3\|CH\|H14d | 164 | +0.10 / +0.06 | 1.41 / 1.32 | 8.6 R | −0.03 | +0.12 | 0.01 | 1 / 0 / 1 / 162 |
| B30-Q0.5\|short\|L\|S3\|CH\|H7d | 167 | +0.10 / +0.07 | 1.38 / 1.29 | 9.4 R | −0.02 | +0.12 | 0.01 | 1 / 0 / 8 / 158 |

The three longs are near-variants of one signal: a 20-day breakout by at least 0.5 ATR, entered on
continuation, with a 3 R target.

**Unfilled entries** (all configurations, training):

| Lookback | Long L | Long C | Short L | Short C |
|---|---:|---:|---:|---:|
| 30 bars | 0.4 % | 37.0 % | 0.4 % | 55.1 % |
| 60 bars | 0 % | 37.3 % | 0 % | 57.5 % |
| 120 bars | 0 % | 36.0 % | 0 % | 59.0 % |

A limit at the breakout close almost always fills within the hour. The continuation stop-limit fills
for about two long breakouts in three and fewer than half of the short ones.

## Validation: the ten mid-caps 2023-2025

`screen-validation.json` (96 configurations × 100 seeds, 245 s; the code hashes are equal to training).
The candles were acquired after the freeze. There are 350 monthly 1m archives and 350 funding archives,
with no failure and no missing minute. They start from 2022-11 or the listing month (ARB 2023-03, SUI
2023-05, SEI 2023-08, TIA 2023-10).

Decision set (`verdict-validation.json`: `no_eligible_candidate`):

| Configuration | Trades | Mean R (base / adverse) | PF (base / adverse) | Drawdown | Pairs + | Control median | Surplus (p) | 2023 / 2024 / 2025 (trades, USDT) | Fails |
|---|---:|---|---|---:|---:|---:|---|---|---|
| B120-Q0.5\|long\|C\|S2\|T3\|H14d | 181 | +0.035 / +0.008 | 1.06 / 1.01 | 26.0 R | 4/10 | −0.08 | +0.11 (0.06) | 69 +1,897 / 75 −1,864 / 37 +606 | PF, drawdown, 2024 |
| B120-Q0.5\|long\|C\|S3\|T3\|H14d | 149 | +0.20 / +0.14 | 1.37 / 1.33 | 18.0 R | 8/10 | −0.03 | +0.23 (0.01) | 60 +3,530 / 57 −658 / 32 +177 | 2024 only |
| B120-Q0.5\|long\|C\|S2\|T3\|H7d | 182 | +0.08 / +0.05 | 1.14 / 1.08 | 27.8 R | 6/10 | −0.09 | +0.17 (0.07) | 69 +2,652 / 76 −1,696 / 37 +525 | PF, drawdown, 2024 |
| B30-Q0.5\|short\|L\|S3\|CH\|H14d | 289 | +0.05 / +0.02 | 1.18 / 1.13 | 9.6 R | 6/10 | +0.06 | −0.01 (0.68) | 73 +272 / 96 +951 / 117 +155 | PF (baseline), surplus |
| B30-Q0.5\|short\|L\|S3\|CH\|H7d | 295 | +0.05 / +0.03 | 1.18 / 1.12 | 10.9 R | 7/10 | +0.06 | −0.01 (0.70) | 74 +387 / 97 +1,036 / 121 +3 | PF, 2025 (adverse), surplus |

- **The closest miss.** `B120-Q0.5|long|C|S3|T3|H14d` passes every other check on both profiles: trades,
  pairs, mean R, PF, drawdown, surplus. By pair it nets +1,017 on INJ, +676 SEI, +465 ATOM, +418 SUI,
  +323 FIL, +228 OP, +210 NEAR and +54 ARB, against −62 APT and −281 TIA. The rule makes no exception for
  a single year.
- **Exits.** The selected longs exit on the stop in 60–72 % of trades and on the target in 23–27 %. The
  Chandelier shorts exit by trailing in 94–99 % of trades.
- **Unfilled continuation entries.** Fewer than in training: 31–33 % for the longs.
- **Boundary convention.** Positions still open at the end of the phase close at 2026-01-01T00:00Z at the
  last 2025 close. Three short trades are therefore attributed to "2026" by exit date; no 2026 value was
  read (the loader cuts the candles and funding at 2026-01-01).
- **Pooled long + short** (information). The same parameters as the best long make +0.08 R on 246
  trades; the longs make +0.20 R and the shorts −0.10 R.

### Budgets

`budgets-validation.json` and `budgets-training.json`: the same trades re-sized at fixed equity, 1 % risk.
No plan fell below the instrument minimums in validation, even at 500 USDT; in training at 500 USDT, 2 to
18 of the majors' plans did.

| Configuration | Phase | 500 USDT | 1,000 | 2,500 | 10,000 | Drawdown at 500 |
|---|---|---:|---:|---:|---:|---:|
| B120-Q0.5\|long\|C\|S3\|T3\|H14d | validation 2023-25 | +155.6 (+31 %) | +307.6 | +762.8 | +3,047.9 | 87.4 |
| B120-Q0.5\|long\|C\|S2\|T3\|H14d | validation 2023-25 | +33.4 (+7 %) | +64.1 | +160.3 | +638.9 | 128.1 |
| B120-Q0.5\|long\|C\|S2\|T3\|H7d | validation 2023-25 | +74.0 (+15 %) | +147.2 | +370.6 | +1,481.7 | 138.1 |
| B30-Q0.5\|short\|L\|S3\|CH\|H14d | validation 2023-25 | +68.3 (+14 %) | +136.8 | +341.3 | +1,364.9 | 46.4 |
| B30-Q0.5\|short\|L\|S3\|CH\|H7d | validation 2023-25 | +70.0 (+14 %) | +141.0 | +354.1 | +1,412.7 | 52.7 |
| B120-Q0.5\|long\|C\|S3\|T3\|H14d | training 2023-24 | +290.8 (+58 %) | | | +6,427.5 | |

These are baseline-cost figures over three years. None of them is a candidate.

## Passed by chance

These are the owner's four points, as pre-registered.

1. **All 96 training passers** are listed below with their training surplus, empirical p and BH q, and
   their validation result. Every gate of both profiles is in the screen files.
2. **Chance baseline.** Treating every control seed as a pseudo-configuration, 9.8 of 288 configurations
   would pass the training gates by chance (4.8 would be "selectable"). In validation, 0.3 of the 96 would
   pass by chance.
3. **Validation of all passers, for information.** **0 of 96** pass the validation gates. None can become
   a candidate in this cycle in any case.
4. **Observed against expected.** 96 passed training against 9.8 expected, and 96 of 96 failed
   validation. On absolute PnL, the training excess was not reproducible on another universe and period.
   On the surplus over random timing, 70 of 96 stay positive, and the long 20-day breakouts with a 3 R
   target keep p ≤ 0.10 in 15 of 15 configurations.

| Training family (passers) | Count | Validation: pass gates | Validation: median surplus | Validation: p ≤ 0.10 |
|---|---:|---:|---:|---:|
| long B120, T3 (L and C) | 15 | 0 | +0.17 / +0.14 | 15 |
| long B30, T3 | 15 | 0 | +0.06 / +0.10 | 10 |
| long B60, T3 | 16 | 0 | +0.07 / +0.05 | 5 |
| long, Chandelier (all lookbacks) | 48 | 0 | −0.02 … +0.03 | 4 |
| short B30, Chandelier | 2 | 0 | −0.01 | 0 |

(The two surplus values are the L and C entries.)

### All 96 training passers

Sorted by training surplus. Training columns are baseline; "Valid." columns are the validation replay
(information for the 91 not selected). Gate abbreviations: PF, DD (drawdown), R≤0, a year = that year net ≤ 0
with ≥ 30 trades.

| # | Configuration | Train trades | Train R | Train surplus | p | BH q | Valid. trades | Valid. R | Valid. adverse R | Valid. surplus | Valid. p | Gates failed in validation (baseline / adverse) |
|---:|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 1 | B120-Q0.5\|long\|C\|S2\|T3\|H14d (selected) | 151 | +0.50 | +0.48 | 0.01 | 0.023 | 181 | +0.04 | +0.01 | +0.11 | 0.06 | PF, DD, 2024 / DD, 2024 |
| 2 | B120-Q0.5\|long\|C\|S3\|T3\|H14d (selected) | 118 | +0.55 | +0.46 | 0.01 | 0.023 | 149 | +0.20 | +0.14 | +0.23 | 0.01 | 2024 / 2024 |
| 3 | B120-Q0.5\|long\|C\|S2\|T3\|H7d (selected) | 155 | +0.46 | +0.42 | 0.01 | 0.023 | 182 | +0.08 | +0.05 | +0.17 | 0.07 | PF, DD, 2024 / DD, 2024 |
| 4 | B120-Q0.5\|long\|C\|S3\|T3\|H7d | 127 | +0.48 | +0.38 | 0.01 | 0.023 | 159 | +0.18 | +0.14 | +0.19 | 0.01 | 2024 / 2024 |
| 5 | B60-Q0.5\|long\|C\|S2\|T3\|H14d | 192 | +0.39 | +0.37 | 0.01 | 0.023 | 246 | -0.06 | -0.06 | +0.04 | 0.26 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 6 | B30-Q0.5\|long\|C\|S2\|T3\|H7d | 246 | +0.37 | +0.34 | 0.01 | 0.023 | 310 | +0.07 | +0.03 | +0.15 | 0.05 | PF, DD, 2024 / DD, 2024 |
| 7 | B120-Q0.25\|long\|C\|S3\|T3\|H14d | 139 | +0.44 | +0.34 | 0.01 | 0.023 | 161 | +0.10 | +0.06 | +0.13 | 0.04 | PF, DD, 2024 / DD, 2024 |
| 8 | B30-Q0.5\|long\|C\|S2\|T3\|H14d | 239 | +0.31 | +0.30 | 0.01 | 0.023 | 308 | +0.01 | -0.01 | +0.10 | 0.12 | PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 9 | B120-Q0.25\|long\|C\|S3\|T3\|H7d | 161 | +0.41 | +0.30 | 0.01 | 0.023 | 174 | +0.14 | +0.10 | +0.15 | 0.01 | 2024 / DD, 2024 |
| 10 | B30-Q0.25\|long\|C\|S2\|T3\|H7d | 301 | +0.27 | +0.27 | 0.01 | 0.023 | 362 | +0.03 | -0.00 | +0.09 | 0.05 | PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 11 | B120-Q0\|long\|C\|S3\|T3\|H7d | 180 | +0.37 | +0.27 | 0.01 | 0.023 | 188 | +0.13 | +0.09 | +0.14 | 0.02 | 2024 / DD, 2024 |
| 12 | B30-Q0.25\|long\|C\|S3\|T3\|H7d | 238 | +0.35 | +0.26 | 0.01 | 0.023 | 298 | +0.09 | +0.05 | +0.09 | 0.06 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 13 | B60-Q0.5\|long\|L\|S2\|T3\|H14d | 252 | +0.29 | +0.26 | 0.01 | 0.023 | 308 | +0.00 | -0.01 | +0.06 | 0.16 | PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 14 | B120-Q0.5\|long\|L\|S3\|T3\|H14d | 148 | +0.38 | +0.26 | 0.01 | 0.023 | 172 | +0.15 | +0.11 | +0.15 | 0.01 | DD, 2024 / DD, 2024 |
| 15 | B30-Q0.5\|long\|L\|S2\|T3\|H7d | 317 | +0.30 | +0.25 | 0.01 | 0.023 | 388 | +0.06 | +0.04 | +0.11 | 0.02 | PF, DD, 2024 / DD, 2024 |
| 16 | B60-Q0.5\|long\|L\|S2\|T3\|H7d | 262 | +0.28 | +0.25 | 0.01 | 0.023 | 316 | +0.03 | +0.01 | +0.08 | 0.08 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 17 | B60-Q0.25\|long\|C\|S3\|T3\|H7d | 200 | +0.33 | +0.24 | 0.01 | 0.023 | 246 | +0.05 | +0.02 | +0.06 | 0.20 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 18 | B120-Q0.25\|long\|L\|S3\|T3\|H14d | 158 | +0.37 | +0.24 | 0.01 | 0.023 | 186 | +0.12 | +0.09 | +0.10 | 0.03 | DD, 2024, 2025 / DD, 2024, 2025 |
| 19 | B120-Q0.5\|long\|C\|S3\|CH\|H7d | 138 | +0.28 | +0.24 | 0.01 | 0.023 | 166 | -0.01 | -0.03 | +0.06 | 0.14 | R≤0, PF, 2024 / R≤0, PF, 2024 |
| 20 | B30-Q0.5\|long\|C\|S3\|T3\|H7d | 203 | +0.33 | +0.23 | 0.01 | 0.023 | 265 | +0.06 | +0.03 | +0.08 | 0.11 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 21 | B60-Q0.5\|long\|C\|S3\|T3\|H14d | 151 | +0.32 | +0.23 | 0.01 | 0.023 | 198 | +0.04 | +0.02 | +0.08 | 0.19 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 22 | B120-Q0.5\|long\|C\|S3\|CH\|H14d | 134 | +0.26 | +0.23 | 0.01 | 0.023 | 163 | -0.00 | -0.02 | +0.07 | 0.09 | R≤0, PF, 2024 / R≤0, PF, 2024 |
| 23 | B120-Q0.25\|long\|C\|S3\|CH\|H7d | 174 | +0.26 | +0.23 | 0.01 | 0.023 | 201 | -0.08 | -0.09 | +0.01 | 0.47 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 24 | B120-Q0.25\|long\|C\|S3\|CH\|H14d | 169 | +0.26 | +0.22 | 0.01 | 0.023 | 197 | -0.07 | -0.07 | -0.00 | 0.52 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 25 | B60-Q0.5\|long\|C\|S3\|T3\|H7d | 162 | +0.32 | +0.22 | 0.01 | 0.023 | 213 | +0.05 | +0.02 | +0.06 | 0.21 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 26 | B30-Q0.5\|long\|L\|S2\|T3\|H14d | 305 | +0.26 | +0.22 | 0.01 | 0.023 | 378 | +0.01 | +0.00 | +0.06 | 0.09 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 27 | B120-Q0.25\|long\|L\|S3\|T3\|H7d | 193 | +0.32 | +0.22 | 0.01 | 0.023 | 211 | +0.17 | +0.14 | +0.18 | 0.02 | 2024 / 2024 |
| 28 | B120-Q0.5\|long\|L\|S2\|T3\|H14d | 202 | +0.26 | +0.21 | 0.02 | 0.040 | 224 | +0.17 | +0.11 | +0.23 | 0.01 | DD, 2024 / DD, 2024 |
| 29 | B120-Q0\|long\|C\|S3\|CH\|H7d | 195 | +0.25 | +0.21 | 0.01 | 0.023 | 218 | -0.03 | -0.04 | +0.03 | 0.24 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2024 |
| 30 | B120-Q0\|long\|C\|S3\|T3\|H14d | 157 | +0.32 | +0.21 | 0.01 | 0.023 | 167 | +0.11 | +0.07 | +0.13 | 0.01 | DD, 2024 / DD, 2024 |
| 31 | B30-Q0\|long\|C\|S3\|T3\|H7d | 264 | +0.30 | +0.21 | 0.01 | 0.023 | 314 | +0.14 | +0.09 | +0.12 | 0.01 | 2025 / 2025 |
| 32 | B30-Q0\|long\|C\|S2\|T3\|H14d | 312 | +0.22 | +0.21 | 0.01 | 0.023 | 377 | +0.01 | -0.01 | +0.06 | 0.13 | PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 33 | B120-Q0\|long\|C\|S3\|CH\|H14d | 190 | +0.23 | +0.20 | 0.01 | 0.023 | 215 | -0.03 | -0.04 | +0.03 | 0.22 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2024 |
| 34 | B60-Q0.25\|long\|L\|S2\|T3\|H7d | 309 | +0.25 | +0.20 | 0.01 | 0.023 | 362 | +0.09 | +0.06 | +0.14 | 0.01 | PF, DD, 2024 / DD, 2024 |
| 35 | B120-Q0.5\|long\|L\|S3\|T3\|H7d | 173 | +0.30 | +0.20 | 0.01 | 0.023 | 196 | +0.20 | +0.16 | +0.19 | 0.01 | 2024 / 2024 |
| 36 | B120-Q0.5\|long\|L\|S3\|CH\|H7d | 183 | +0.25 | +0.19 | 0.01 | 0.023 | 207 | +0.01 | -0.01 | +0.06 | 0.14 | PF, 2024 / R≤0, PF, DD, 2024 |
| 37 | B30-Q0.5\|long\|C\|S3\|CH\|H7d | 219 | +0.21 | +0.18 | 0.01 | 0.023 | 283 | -0.02 | -0.04 | +0.06 | 0.11 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2023, 2024 |
| 38 | B60-Q0.25\|long\|C\|S3\|CH\|H14d | 209 | +0.21 | +0.18 | 0.01 | 0.023 | 275 | -0.10 | -0.09 | -0.02 | 0.69 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2023, 2024, 2025 |
| 39 | B60-Q0.25\|long\|C\|S3\|CH\|H7d | 215 | +0.21 | +0.18 | 0.01 | 0.023 | 279 | -0.10 | -0.11 | -0.03 | 0.80 | R≤0, PF, DD, 2023, 2024, 2025 / R≤0, PF, DD, 2023, 2024, 2025 |
| 40 | B60-Q0\|long\|C\|S3\|T3\|H7d | 224 | +0.28 | +0.17 | 0.01 | 0.023 | 265 | +0.04 | +0.01 | +0.04 | 0.20 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 41 | B120-Q0.5\|long\|L\|S3\|CH\|H14d | 178 | +0.22 | +0.17 | 0.01 | 0.023 | 202 | +0.02 | +0.00 | +0.07 | 0.04 | PF, 2024 / 2024 |
| 42 | B60-Q0\|long\|L\|S2\|T3\|H14d | 325 | +0.23 | +0.17 | 0.01 | 0.023 | 387 | -0.02 | -0.03 | -0.00 | 0.52 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2024, 2025 |
| 43 | B120-Q0.25\|long\|L\|S3\|CH\|H7d | 218 | +0.22 | +0.16 | 0.01 | 0.023 | 238 | +0.00 | -0.01 | +0.05 | 0.08 | PF, DD, 2024 / R≤0, PF, DD, 2023, 2024 |
| 44 | B60-Q0.5\|long\|C\|S3\|CH\|H7d | 177 | +0.18 | +0.16 | 0.01 | 0.023 | 232 | -0.08 | -0.09 | +0.00 | 0.50 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 45 | B30-Q0.5\|long\|C\|S3\|CH\|H14d | 214 | +0.20 | +0.16 | 0.01 | 0.023 | 279 | -0.02 | -0.04 | +0.05 | 0.15 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2023, 2024 |
| 46 | B120-Q0.25\|long\|L\|S3\|CH\|H14d | 212 | +0.20 | +0.16 | 0.01 | 0.023 | 231 | +0.01 | -0.00 | +0.05 | 0.07 | PF, 2024 / R≤0, PF, 2024 |
| 47 | B60-Q0.25\|long\|L\|S2\|T3\|H14d | 295 | +0.20 | +0.15 | 0.01 | 0.023 | 346 | +0.03 | +0.01 | +0.06 | 0.10 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 48 | B30-Q0.25\|long\|C\|S3\|CH\|H14d | 258 | +0.17 | +0.15 | 0.01 | 0.023 | 332 | -0.05 | -0.06 | +0.00 | 0.46 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024, 2025 |
| 49 | B60-Q0\|long\|C\|S3\|CH\|H7d | 245 | +0.18 | +0.15 | 0.01 | 0.023 | 303 | -0.05 | -0.06 | +0.01 | 0.34 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 50 | B60-Q0.5\|long\|C\|S3\|CH\|H14d | 172 | +0.18 | +0.15 | 0.02 | 0.040 | 228 | -0.08 | -0.08 | -0.00 | 0.55 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2023, 2024, 2025 |
| 51 | B120-Q0.5\|long\|L\|S2\|CH\|H7d | 240 | +0.13 | +0.14 | 0.01 | 0.023 | 266 | -0.07 | -0.08 | -0.02 | 0.74 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 52 | B30-Q0\|long\|C\|S3\|T3\|H14d | 227 | +0.26 | +0.14 | 0.03 | 0.056 | 273 | +0.11 | +0.07 | +0.11 | 0.02 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 53 | B60-Q0\|long\|C\|S3\|CH\|H14d | 238 | +0.17 | +0.14 | 0.01 | 0.023 | 301 | -0.06 | -0.06 | -0.00 | 0.56 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 54 | B30-Q0.25\|long\|C\|S3\|CH\|H7d | 263 | +0.18 | +0.14 | 0.01 | 0.023 | 338 | -0.06 | -0.07 | +0.00 | 0.45 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024, 2025 |
| 55 | B30-Q0.5\|long\|L\|S3\|CH\|H7d | 287 | +0.19 | +0.14 | 0.01 | 0.023 | 349 | -0.04 | -0.05 | -0.00 | 0.53 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024, 2025 |
| 56 | B120-Q0\|long\|L\|S3\|T3\|H14d | 175 | +0.27 | +0.13 | 0.01 | 0.023 | 200 | +0.09 | +0.07 | +0.08 | 0.08 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 57 | B60-Q0.25\|long\|L\|S3\|CH\|H7d | 279 | +0.18 | +0.13 | 0.01 | 0.023 | 329 | -0.03 | -0.04 | +0.01 | 0.40 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 58 | B60-Q0\|long\|C\|S3\|T3\|H14d | 195 | +0.25 | +0.13 | 0.01 | 0.023 | 243 | +0.03 | +0.01 | +0.03 | 0.31 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 59 | B30-Q0\|long\|C\|S3\|CH\|H7d | 298 | +0.17 | +0.13 | 0.01 | 0.023 | 363 | -0.03 | -0.05 | +0.02 | 0.21 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2023, 2024 |
| 60 | B120-Q0.5\|long\|L\|S2\|CH\|H14d | 240 | +0.12 | +0.13 | 0.01 | 0.023 | 266 | -0.07 | -0.06 | -0.03 | 0.76 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 61 | B30-Q0\|long\|C\|S3\|CH\|H14d | 290 | +0.16 | +0.13 | 0.01 | 0.023 | 357 | -0.03 | -0.04 | +0.02 | 0.23 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 62 | B60-Q0.5\|long\|L\|S3\|CH\|H7d | 238 | +0.16 | +0.13 | 0.01 | 0.023 | 285 | -0.05 | -0.05 | -0.00 | 0.53 | R≤0, PF, DD, 2023, 2024, 2025 / R≤0, PF, DD, 2023, 2024, 2025 |
| 63 | B30-Q0.5\|long\|L\|S2\|CH\|H7d | 378 | +0.11 | +0.12 | 0.01 | 0.023 | 446 | -0.05 | -0.06 | -0.00 | 0.51 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 64 | B60-Q0.25\|long\|L\|S3\|CH\|H14d | 272 | +0.16 | +0.12 | 0.01 | 0.023 | 321 | -0.02 | -0.03 | +0.02 | 0.28 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 65 | B30-Q0.5\|short\|L\|S3\|CH\|H14d (selected) | 164 | +0.10 | +0.12 | 0.01 | 0.023 | 289 | +0.05 | +0.02 | -0.01 | 0.68 | PF / none |
| 66 | B30-Q0.5\|short\|L\|S3\|CH\|H7d (selected) | 167 | +0.10 | +0.12 | 0.01 | 0.023 | 295 | +0.05 | +0.03 | -0.01 | 0.70 | PF / 2025 |
| 67 | B60-Q0.25\|long\|L\|S3\|T3\|H7d | 242 | +0.23 | +0.12 | 0.01 | 0.023 | 279 | +0.14 | +0.11 | +0.13 | 0.01 | 2025 / 2025 |
| 68 | B30-Q0.5\|long\|L\|S3\|CH\|H14d | 279 | +0.18 | +0.12 | 0.01 | 0.023 | 341 | -0.03 | -0.03 | +0.01 | 0.40 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2024, 2025 |
| 69 | B30-Q0.5\|long\|C\|S2\|CH\|H7d | 282 | +0.08 | +0.12 | 0.01 | 0.023 | 342 | -0.04 | -0.06 | +0.02 | 0.28 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 70 | B30-Q0.5\|long\|L\|S3\|T3\|H7d | 256 | +0.23 | +0.12 | 0.01 | 0.023 | 308 | +0.12 | +0.09 | +0.10 | 0.02 | 2025 / 2025 |
| 71 | B120-Q0.25\|long\|C\|S2\|CH\|H7d | 238 | +0.08 | +0.12 | 0.02 | 0.040 | 251 | -0.12 | -0.12 | -0.05 | 0.82 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 72 | B120-Q0.25\|long\|L\|S2\|CH\|H7d | 308 | +0.10 | +0.12 | 0.01 | 0.023 | 334 | -0.09 | -0.09 | -0.04 | 0.94 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 73 | B60-Q0.5\|long\|L\|S3\|T3\|H7d | 212 | +0.22 | +0.12 | 0.02 | 0.040 | 259 | +0.12 | +0.10 | +0.13 | 0.01 | 2025 / 2024, 2025 |
| 74 | B120-Q0\|long\|L\|S3\|T3\|H7d | 214 | +0.24 | +0.11 | 0.01 | 0.023 | 225 | +0.19 | +0.15 | +0.17 | 0.01 | 2025 / 2025 |
| 75 | B30-Q0.5\|long\|C\|S2\|CH\|H14d | 282 | +0.07 | +0.11 | 0.02 | 0.040 | 342 | -0.03 | -0.05 | +0.02 | 0.33 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 76 | B60-Q0.5\|long\|L\|S2\|CH\|H7d | 311 | +0.09 | +0.11 | 0.01 | 0.023 | 363 | -0.08 | -0.08 | -0.02 | 0.77 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 77 | B30-Q0.5\|long\|L\|S2\|CH\|H14d | 378 | +0.10 | +0.11 | 0.01 | 0.023 | 446 | -0.05 | -0.05 | -0.01 | 0.67 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 78 | B60-Q0\|long\|L\|S3\|CH\|H7d | 316 | +0.16 | +0.11 | 0.01 | 0.023 | 370 | -0.06 | -0.07 | -0.03 | 0.93 | R≤0, PF, DD, 2023, 2024, 2025 / R≤0, PF, DD, 2023, 2024, 2025 |
| 79 | B120-Q0.25\|long\|C\|S2\|CH\|H14d | 238 | +0.07 | +0.11 | 0.01 | 0.023 | 251 | -0.11 | -0.10 | -0.06 | 0.84 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 80 | B60-Q0.5\|long\|L\|S3\|T3\|H14d | 193 | +0.22 | +0.11 | 0.06 | 0.097 | 233 | +0.08 | +0.05 | +0.07 | 0.12 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 81 | B120-Q0.25\|long\|L\|S2\|CH\|H14d | 308 | +0.09 | +0.11 | 0.01 | 0.023 | 334 | -0.09 | -0.08 | -0.05 | 0.97 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 82 | B60-Q0.5\|long\|L\|S3\|CH\|H14d | 231 | +0.15 | +0.11 | 0.01 | 0.023 | 280 | -0.04 | -0.04 | +0.01 | 0.36 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2024, 2025 |
| 83 | B60-Q0\|long\|L\|S3\|CH\|H14d | 306 | +0.15 | +0.10 | 0.01 | 0.023 | 362 | -0.05 | -0.05 | -0.02 | 0.87 | R≤0, PF, DD, 2024, 2025 / R≤0, PF, DD, 2023, 2024, 2025 |
| 84 | B60-Q0.5\|long\|L\|S2\|CH\|H14d | 311 | +0.08 | +0.10 | 0.01 | 0.023 | 363 | -0.07 | -0.07 | -0.03 | 0.81 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 85 | B30-Q0\|long\|L\|S3\|CH\|H14d | 363 | +0.14 | +0.09 | 0.01 | 0.023 | 428 | -0.03 | -0.04 | -0.01 | 0.70 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2024, 2025 |
| 86 | B30-Q0\|long\|L\|S3\|CH\|H7d | 377 | +0.14 | +0.09 | 0.01 | 0.023 | 441 | -0.04 | -0.05 | -0.02 | 0.78 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2023, 2024, 2025 |
| 87 | B60-Q0.25\|long\|L\|S2\|CH\|H14d | 391 | +0.07 | +0.09 | 0.02 | 0.040 | 460 | -0.08 | -0.08 | -0.04 | 0.97 | R≤0, PF, DD, 2023, 2024 / R≤0, PF, DD, 2023, 2024 |
| 88 | B120-Q0\|long\|L\|S3\|CH\|H14d | 245 | +0.14 | +0.08 | 0.01 | 0.023 | 253 | -0.00 | -0.01 | +0.03 | 0.17 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2024 |
| 89 | B30-Q0.25\|long\|L\|S3\|T3\|H7d | 298 | +0.20 | +0.08 | 0.04 | 0.071 | 329 | +0.17 | +0.14 | +0.16 | 0.01 | 2025 / 2025 |
| 90 | B30-Q0\|long\|L\|S3\|T3\|H7d | 313 | +0.20 | +0.08 | 0.05 | 0.083 | 361 | +0.09 | +0.07 | +0.05 | 0.09 | PF, DD, 2025 / DD, 2025 |
| 91 | B30-Q0\|long\|L\|S3\|T3\|H14d | 265 | +0.20 | +0.08 | 0.04 | 0.071 | 309 | +0.05 | +0.03 | +0.01 | 0.40 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 92 | B30-Q0.25\|long\|L\|S3\|CH\|H7d | 346 | +0.11 | +0.07 | 0.03 | 0.056 | 396 | -0.02 | -0.03 | +0.01 | 0.30 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2023, 2024 |
| 93 | B60-Q0\|long\|L\|S3\|T3\|H7d | 262 | +0.18 | +0.07 | 0.05 | 0.083 | 309 | +0.05 | +0.03 | +0.03 | 0.28 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 94 | B60-Q0\|long\|L\|S3\|T3\|H14d | 224 | +0.20 | +0.06 | 0.07 | 0.111 | 272 | +0.03 | +0.02 | -0.00 | 0.52 | PF, DD, 2024, 2025 / DD, 2024, 2025 |
| 95 | B30-Q0.25\|long\|L\|S3\|CH\|H14d | 338 | +0.11 | +0.06 | 0.01 | 0.023 | 387 | -0.01 | -0.02 | +0.01 | 0.36 | R≤0, PF, DD, 2024 / R≤0, PF, DD, 2024 |
| 96 | B30-Q0.5\|long\|L\|S3\|T3\|H14d | 228 | +0.16 | +0.04 | 0.23 | 0.309 | 277 | +0.03 | +0.02 | +0.02 | 0.33 | PF, DD, 2024, 2025 / DD, 2024, 2025 |

## Limits

- **Training.** The majors' 2023-24 had been read by every earlier swing study; training is
  information only.
- **Lineage of the mid-caps.** They were never used at 4h. Their 2024-03 … 2024-09 1m klines and several
  tick windows (2023-2025) had been read by other families (r6–r14).
- **Validation window.** One universe and three years, in which 2024 was negative for every selected long.
- **Instruments.** Current Binance metadata (not historical). Costs come from the r1/r2 profiles, not
  from historical quotes.
- **Exit bars.** On bars that reach both bounds, the stop is assumed first. The Chandelier is updated at
  4h closes only.
- **Control matching.** The control matches the configuration's decision count per pair, not its trade
  count.

## What is left

No configuration of this cycle can be retained. The only thread is a description, not a selection:
"long 20-day breakouts by ≥ 0.5 ATR, 3 R target" beats random timing on the majors 2023-24 and on the
mid-caps 2023-25, but its absolute result depends on the year. Following it would need a new
pre-registration on unread data (for example a forward test). It is the owner's call; nothing is launched.
