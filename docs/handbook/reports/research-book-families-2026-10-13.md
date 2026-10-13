# Book families on Binance depth and top-of-book archives (r8, r9, 2026-10-13)

## Result

_Pending: r8 (depth families, bookDepth 2025) and r9 (queue and microprice families, bookTicker
2023-24) were pre-registered and launched on 2026-10-13; this section is written from their
outputs once the chains end._

## Why

After seven protocols on twenty pairs ([2026-10-12](research-execution-tick-2026-10-12.md),
[mid-caps](research-midcap-tick-2026-10-13.md)) the price and flow families lose the fees at
every hold of fifteen minutes or less, and the only rule with content at hourly holds has a sign
that follows the month. The families of the owner's list that remained untested at scale are the
**book families**: depth imbalance and the pullback confirmed by the book (r8), queue imbalance
and microprice (r9). The captures had tested them on two symbols, one day, two venues.

Two public archives make the test possible without new captures:

| Archive | Content | Coverage | Per symbol-day |
|---|---|---|---|
| `bookDepth` | cumulative notional within ±1, 2, 3, 4, 5 % of the mid, one snapshot every 30 s on average | 2023 → today | 0.5 MB |
| `bookTicker` | every change of the best bid or ask, with the displayed quantities | 2023-05-16 → 2024-03-30 | 25–200 MB |

Pre-registrations: `PREREGISTRATION-r8.md` (frozen 2026-10-13T06:18:52Z, sha256 `16fc120a…`) and
`PREREGISTRATION-r9.md` (frozen 2026-10-13T06:30:51Z, sha256 `807bc3a6…`), both in
`tradingV3-private/research/20261012T060000Z-execution-r1/`, both before any archive was read
for its content (one file of each kind had been opened to read the column layout; disclosed in
the texts).

## r8 — depth families at the platform's cadence (bookDepth, 2025)

- **Universe:** the ten majors and the ten mid-caps of r6 (twenty pairs). **Training:** the nine
  2025 windows already on disk (February to October, 180 series). **Validation:** 2025-01-13..17.
  **Holdout:** 2024-12-09..13, separate directory. Neither had been read by anything.
- **Features:** `di_b` = bid-minus-ask share of the notional within `b` % (1 and 2) of the last
  snapshot stamped at or before the bar (NaN when older than 120 s); `ddi_b` = its change since
  the last snapshot at least 300 s earlier; the flow and band features of r1–r7.
- **Rules (28):** Q1 depth momentum (`sgn · di_b > t`), Q4 depth contrarian (`sgn · di_b < −t`),
  both with and without flow confirmation, `t` ∈ {0.25, 0.4}; Q2 build-up (`sgn · ddi_b > d`,
  `d` ∈ {0.15, 0.3}); Q3 band reversion with depth support (the S7 condition and `sgn · di_1 > t`,
  `t` ∈ {0.1, 0.25}, EMA 300 / 900 s, 1.5 / 2 σ).
- **Policies:** entry E1 at the touch (through rule); exits Y1 taker at 15 min, Y3 bracket
  1.5 / 3 σ_300 (60 min cap), Z3 passive at 60 min then taker at 65 min. The 5-minute taker exit
  was dropped before the run (it lost in every protocol so far). Long and short streams for every
  rule, the pooled two-sided stream for Q3: **192 configurations**.
- **Gates, controls, selection:** as r6 (G1 ≥ 300 trades … G8 fill ≥ 20 %; regime-matched random
  timing, 10 seeds; at most three per family to validation; validation and holdout checks
  trades ≥ 100, net > 0, PF ≥ 1.05, above the control median, ≥ 50 % of series positive).

### r8 training: 0 of 192 pass; the pure depth families have no content

180 series (twenty pairs × nine windows), 1,865 s with the series-by-series driver, no missing
day. Every configuration trades (median 1,539 trades, 145 of 192 above 300), so the gates judge
content, not sample size:

| Family | Configurations | Median trades | Median mean net bp | Best configuration (mean net, trades, LB90) | Gates failed (typical) |
|---|---:|---:|---:|---|---|
| Q1 depth momentum | 48 | 3,789 | −7.6 | `Q1-B1-T0.4\|short\|E1\|Y3` −5.4 bp (n = 3,765, LB −6.7) | G2–G7, all 48 |
| Q4 depth contrarian | 48 | 3,971 | −9.4 | `Q4-B2-T0.4\|short\|E1\|Y3` −4.7 bp (n = 2,780, LB −6.4) | G2–G7, 44 of 48 |
| Q2 depth build-up | 24 | 2,502 | −8.5 | `Q2-B1-D0.3\|long\|E1\|Z3` −0.6 bp (n = 1,463, LB −5.2) | G2–G7, 21 of 24 |
| Q3 band reversion with depth support | 72 | 145 | +3.6 | `Q3-W900-K1.5-T0.25\|short\|E1\|Z3` +30.5 bp (n = 137, LB +21.1) | G1 or G5 |

By exit the medians are −9.5 bp (Y1, taker at 15 min), −6.4 (Y3, bracket) and −5.7 (Z3,
passive at 60 min). The three pure depth families lose at the control's level on both sides
and at every hold: a heavy bid side within 1 or 2 % of the mid, its build-up over five
minutes, or the fade of a heavy side, predict nothing at 15–60 minutes that the fees do not
eat. The thresholds were set before the data; on these books the ±1 % imbalance is a
narrow quantity (ARBUSDT, February: median +0.05, 95th percentile +0.13), so the rules at
0.25 fire on about one second in a thousand, yet still on thousands of occasions over
450 symbol-days; the result does not change with the sample.

Q3 is the exception, and it is the r6 exception with one more filter. The rows that fail a
single gate:

| Configuration | Trades | Fill | Mean net bp | LB90 | PF | Control median / p95 | Series positive | Gate failed |
|---|---:|---:|---:|---:|---:|---|---:|---|
| `Q3-W900-K1.5-T0.1\|both\|E1\|Z3` | 1,462 | 75 % | +14.0 | +10.3 | 1.44 | −13.1 / −10.0 | 60 % of 168 | G5 |
| `Q3-W900-K1.5-T0.1\|short\|E1\|Z3` | 332 | 78 % | +18.3 | +10.1 | 1.61 | −4.4 / +6.0 | 64 % of 99 | G5 |
| `Q3-W900-K1.5-T0.25\|both\|E1\|Z3` | 558 | 77 % | +14.5 | +8.7 | 1.45 | −9.6 / −6.3 | 62 % of 119 | G5 |
| `Q3-W900-K1.5-T0.1\|long\|E1\|Z3` | 1,130 | 75 % | +12.7 | +8.6 | 1.40 | −16.1 / −11.8 | 59 % of 167 | G5 |
| `Q3-W900-K1.5-T0.1\|short\|E1\|Y3` | 379 | 77 % | +12.3 | +6.6 | 1.46 | −4.6 / −2.1 | 59 % of 99 | G5 |
| `Q3-W900-K1.5-T0.1\|short\|E1\|Y1` | 342 | 78 % | +9.1 | +4.6 | 1.52 | −11.4 / −7.0 | 63 % of 99 | G5 |

The depth filter (bid-side share within 1 % above 0.1 for a long, mirrored for a short) removes
more than half of the band-reversion trades (1,462 on 180 series here against 1,698 on the 90
mid-cap series of r6) and raises the mean from +10 bp (r6, mid-caps alone) to +13–18 bp per
trade on the twenty pairs, with the same 60 % of series positive. Under
the pre-registered rule nothing was selected and the January 2025 and December 2024 windows
stayed unread by r8 (`part-b/binance-r8/`).

### r8b: the depth-supported reversion out of sample, no candidate by one check

Ninth-bis pre-registration (`PREREGISTRATION-r8b.md`, frozen 2026-10-13T07:15:29Z, sha256
`9d080133…`, after the training result and before the two windows were read): the two Q3
configurations with enough training trades to reach 100 in a five-day window, judged on
January 2025 (validation) then December 2024 (holdout), with the r8 checks and no gate relaxed.

| Configuration | Phase | Trades | Fill | Mean net bp | Median | PF | Win | LB90 | Control median / p95 | Pairs positive | Result |
|---|---|---:|---:|---:|---:|---:|---:|---:|---|---:|---|
| `Q3-W900-K1.5-T0.1\|both\|E1\|Z3` | training (r8) | 1,462 | 75 % | +14.0 | — | 1.44 | — | +10.3 | −13.1 / −10.0 | 60 % of 168 | one gate short |
| `Q3-W900-K1.5-T0.1\|both\|E1\|Z3` | validation, January 2025 | 239 | 82 % | **−3.6** | +4.6 | 0.92 | 54 % | −13.9 | −5.4 / +7.6 | 9 of 20 | fails; holdout not read |
| `Q3-W900-K1.5-T0.1\|long\|E1\|Z3` | training (r8) | 1,130 | 75 % | +12.7 | — | 1.40 | — | +8.6 | −16.1 / −11.8 | 59 % of 167 | one gate short |
| `Q3-W900-K1.5-T0.1\|long\|E1\|Z3` | validation, January 2025 | 151 | 77 % | **+18.6** | +23.2 | 1.62 | 61 % | +7.0 | +5.5 / +16.1 | **14 of 20** | passes |
| `Q3-W900-K1.5-T0.1\|long\|E1\|Z3` | holdout, December 2024 | 155 | 84 % | **+6.5** | +3.3 | 1.13 | 50 % | −7.1 | −26.4 / −17.3 | **9 of 20** | fails the series check only |

The two-sided configuration loses in January (its short stream gives back what the long
stream earns) and December stays unread for it. The long stream passes January on fourteen
pairs and keeps a positive mean in December (+6.5 bp gross +10.6, fees 4.1, 33 bp above
regime-matched random timing, which lost −26.4 bp in that falling week) but on nine pairs of
twenty: ARB +576 bp on 13 trades, SEI +673 on 11, OP +480 on 5, APT +449 on 7, ATOM +289 on 8
against ETH −365 on 6, FIL −361 on 8, SUI −354 on 7, LTC −172, INJ −152, SOL −143. The
pre-registered check asks ten; outcome `no_eligible_candidate`
(`part-b/binance-r8b/verdict-holdout.json`). January 2025 and December 2024 have now been read
once each, for these two configurations.

What survives is a statement, not a candidate: the long-only reversion to the 15-minute EMA
with confirming flow and bid-side depth keeps a positive mean on three independent sets of
windows (training +12.7 bp on 1,130 trades, January +18.6 on 151, December +6.5 on 155) and
beats random timing in each, and its result is carried by a handful of pairs that change from
window to window. At the holdout rate (31 trades a day across the twenty pairs, one position per
pair) a 500 USDT notional per trade earns +0.3 USDT per trade and +10 USDT a day at the December
mean, +29 at the January mean, for 0.2 USDT of fees per round trip; the figure scales with the
notional and needs twenty positions' worth of margin to be realised.

## r9 — queue and microprice families on event-level top of book (bookTicker, 2023-24)

- **Universe:** eighteen pairs (the ten majors and ARB, OP, APT, SUI, NEAR, INJ, ATOM, FIL; TIA
  and SEI were listed after the first window). **Training:** 2023-07-10..14, 2023-09-11..15,
  2023-11-13..17, 2024-01-15..19 (72 series). **Validation:** 2024-02-12..16. **Holdout:**
  2024-03-25..29, the last full week of the archive, separate directory.
- **Data:** the bookTicker stream reduced to one record per second (the last update at or before
  the end of the second with its displayed quantities); the bars' quotes are these records, not
  the print proxies of r1–r8; the aggTrades of the same days give fills and flow.
- **Features:** `qi` queue imbalance of the bar's quote and `qi_5` its 5-second mean; microprice
  `mp = (ask · bq + bid · aq) / (bq + aq)`, offset `mpo = (mp − mid) / spread`, momentum
  `dmp_W = ln(mp_s / mp_{s−W})` for 5 and 30 s; `fi_10`, `fi_60`, `a_60`, `sigma_60`.
- **Rules (16):** S3 queue imbalance (`sgn · qi_5 > q`, `q` ∈ {0.3, 0.6}, with and without
  `sgn · fi_10 ≥ 0`); M1 microprice offset (`sgn · mpo > t`, `t` ∈ {0.2, 0.35}, with and without
  flow); M2 microprice momentum (`sgn · dmp_W > k · sigma_60 · sqrt(W / 60)` and `a_60 ≥ 1`,
  `k` ∈ {1, 2}); M3 queue persistence (`sgn · qi > q` on each of the last `n` bars, `q` ∈ {0.3, 0.5},
  `n` ∈ {3, 10}).
- **Entries:** E1 at the touch with the through rule; **E3 at the touch with the queue rule**:
  the order joins the queue behind the displayed quantity of its side at the decision second
  (own size 250 USDT / price) and fills when the prints at its price taken by the opposite side
  since activation exhaust that quantity plus its own, or when the price trades through.
- **Exits:** X1 taker at 60 s, X3 bracket 1 / 1.5 σ_60 (300 s cap), X4 passive at 60 s then taker
  at 90 s, Y1 taker at 15 min. **256 configurations.** Gates, controls and checks as r8.
- Stated before the result: a signal at 1–60 s is not executable by the platform's minute loop;
  a positive r9 calls for a dedicated execution path, a negative r9 closes the owner's list of
  families on fill-capable data.

### r9 training

_Pending._

### r9 validation and holdout

_Pending._

## Budgets

_Pending._

## Limits

- Binance archives only; a candidate would need confirmation on the OKX and Hyperliquid books.
- bookDepth: the ±1 % band is wide for the majors (100 bp against ticks of 0.01–0.4 bp); the
  snapshot cadence (30 s) bounds the reaction time.
- bookTicker: the per-second reduction keeps the last state of each second; the queue rule
  ignores cancellations ahead of the order (pessimistic) and queue priority inside the second
  (optimistic); 2026 tick sizes and the 2026 fee policy applied to 2023–24 data.
- Exit fills keep the through rule in both protocols.
