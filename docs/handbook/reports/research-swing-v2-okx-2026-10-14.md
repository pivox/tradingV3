# Swing V2 on OKX history — single run (2026-10-14)

## Result

**Rejected: Swing V2 is closed.** The frozen configuration `B120-Q0.5|long|C|S3|T3|H14d`, replayed once on
OKX's history with a monthly universe of 30 swaps (2023-01 → 2026-09-28), loses money and does worse than random
entries in the same regime. Under the owner's declared interpretation ("surplus not significant → Swing V2
rejected") and rule 7 ("if the OKX test fails, Swing V2 is CLOSED"), the swing breakout line ends here.

The result has three components:

- **Trades and PnL.** 360 trades on 106 series. Mean net R is −0.027 (baseline) and −0.036 (adverse). Net PnL is
  −985 and −1,686 USDT at a 10,000-USDT equity. PF is 0.95 and 0.92; drawdown is 30.7 and 36.9 R.
- **Against the matched random control** (100 seeds, about 361 trades each), the control median is +0.020 R
  (baseline). The surplus is −0.047 R (p 0.77); adverse −0.032 (p 0.72).
- **The conservative scenarios** give the same verdict. No trade was touched by a delisting, an interruption or
  the censoring.

| Decision year | Trades | Net (USDT, baseline) | Mean R | Net (adverse) |
|---|---:|---:|---:|---:|
| 2023 | 108 | +262 | +0.024 | +19 |
| 2024 | 95 | +47 | +0.003 | −122 |
| 2025 | 73 | −460 | −0.060 | −593 |
| 2026 | 84 | −834 | −0.099 | −989 |

The degradation is steady: by exit year, 2024, 2025 and 2026 are negative. 18 of 45 months are positive. The
month-block 90 % interval of the mean R is [−0.16, +0.10].

## Protocol

- **Origin.** The owner's conditional go of 2026-10-14 and his corrections A–D, applied before the freeze. The
  data readiness report (revision 2), the 193-series audit and the pre-registration live in
  `tradingV3-private/research/20261014T110000Z-swing-v2-okx/`. `PREREGISTRATION-okx.md` was frozen
  2026-10-14T17:16:24Z (sha256 `bf1ddf73…`); the claim was taken at 17:16:47Z.
- **Data.** OKX official historical files (1-minute candles, funding) and OKX public REST (daily volumes, funding
  history). See [the readiness report](okx-swing-v2-data-readiness-2026-10-14.md).
- **Series.** One per instrument or segment: re-listings and contract changes are separate series. The contract
  value is observed in the segment, never carried backwards; tick and lot are observed.
- **Universe.** On the 1st of each month, the top 30 by median reliable daily quote volume of the previous month,
  among the eligible series. Only data before the month is used; membership gates only new entries.
- **Rule.** Unchanged from swing v2: 4h, above the EMA200, close above the prior 120-bar high by at least
  0.5 ATR, continuation stop-limit entry, stop 3 ATR, target 3 R, 14 days, four slots ranked by strength, 1 %
  risk, leverage 1.
- **Events.** Interruptions are carried across; delistings settle at the last-hour mean; positions beyond the end
  of the data are censored.
- **Funding.** Real settlement instants and rates. The provision uses the cadence known at entry; missing
  settlements are imputed against the position.
- **Decisions** stop on 2026-09-28T16:00Z; the data end on 2026-10-12T16:00Z.
- **Verdict.** On each of four result sets (baseline, adverse, and their conservative versions):
  - ≥ 200 trades, ≥ 5 series;
  - mean R > 0 and net > 0;
  - PF ≥ 1.10 (baseline) / 1.00 (adverse);
  - drawdown ≤ 20 R;
  - every year with ≥ 30 trades positive;
  - surplus > 0 and empirical p ≤ 0.10.

## Details

- **Exits.** 247 stops, 74 targets, 39 at the time limit. 226 of 586 admitted continuation entries did not fill.
  The mean hold is 117 h.
- **Funding imputation.** 22 settlements were imputed against the position (intervals longer than 1.5 × the
  cadence known at entry). These are most likely cadence changes read as missing settlements; the treatment is
  conservative and disclosed.
- **Series.** The best were ZEC#2 +586, BEAT +505, APE +496, PEPE +469 and GRT +442 USDT. The worst were TRUMP
  −412, TRX −411, ETHFI −369, CFX −365 and BCH −355. 39 of 106 series were positive.
- **Budgets** (`budgets-okx.json`, baseline). −53 USDT at 500 (−10.6 %; 28 plans below the minimums), −116 at
  1,000, −258 at 2,500 and −985 at 10,000.
- **Defect, disclosed** (`DEFECT-okx-2026-10-14.md`). The first launch stopped before any output: to fit in
  memory the candles started at the 1st of the first month of membership, and a 00:00 funding settlement had no
  prior close. Commit `baf91073` keeps one more day. The run then ran once on the same claim and data.

## What the swing v2 line shows

| Step | Data | Surplus over random timing |
|---|---|---|
| Selection | majors 2023-24 | +0.46 R |
| Validation | mid-caps 2023-25, failed on 2024 | +0.23 R |
| Indicative read | mid-caps 2026 | +0.05 R (p 0.35) |
| This test | 193 OKX series 2023-26 | −0.05 R (p 0.77) |

The surplus shrank at every new set of data. The "strong 20-day breakout entered on continuation" effect does not
exist outside the pairs on which it was chosen. Swing V2 is closed; no contract is published. A favourable
result would only have opened Paper research, never real trading.
