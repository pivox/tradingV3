# Research landscape — what TradingV3 has really tested (2026-10)

## Summary

TradingV3 ran about twenty pre-registered protocols between 2026-10-09 and 2026-10-14, covering over 3,600
configurations across five horizon classes. None produced a strategy whose net expectancy held on data not
used to select it.

Three problems recur:

1. **Selection on regime exposure, not on timing.** Every family that looked good in training held long
   exposure in 2023–24, a bull market. Random entries in the same regime earned almost as much (swing r1, v2
   selection, swing v2, regime-r2). Matched controls caught this each time. The surplus over random timing
   shrank at every new set of data:

   | Swing v2 phase | Surplus over random timing |
   |---|---|
   | Training | +0.46 R |
   | Validation | +0.23 R |
   | 2026 read | +0.05 R |
   | OKX | −0.05 R |

2. **Costs against short horizons.** At holds of 15 minutes or less, fees and spreads are larger than any
   gross edge. No price, flow, depth, queue or microprice family survived. Most were negative even before
   fees (r1–r2, r8–r9, candle scalping).

3. **Fragile edges: a few windows, pairs or tail trades.** The only rule that kept its sign across several
   windows was reversion to the 15-minute EMA with confirming flow (r3–r8b, Q3). It always depended on a
   changing handful of pairs or on the top 5 % of trades. It failed when a window had a strong trend
   (December 2025 for r7, November 2024 for Q3 V2).

What has **not** been tested is just as important. All studies used price-path signals on established,
continuously listed pairs: indicator states, breakouts, bands, book and flow states. Instrument life-cycle
events (listings, delistings) and the cross-sectional structure across many coins were excluded by
construction. Universes required 42 to 200 days of history, and every signal was computed per pair against
its own past.

The last section lists the open questions.

## Matrix of the mechanisms explored

Status codes:

- `INVALIDATED`: failed under a valid protocol.
- `INCONCLUSIVE`: insufficient to conclude.
- `TECHNICAL_BLOCKER`: could not be tested properly.
- `NOT_TESTED`: never evaluated.

Numbers are as written in the reports. R is net R per trade; bp is basis points per trade net of fees.

| # | Family (mechanism) | Data / universe / periods | Entry / exit / hold | Size | Best result and validation | Status | Why it stopped | Sources |
|---|---|---|---|---|---|---|---|---|
| 1 | **Day-trading trend continuation** (platform 1.1.0 setup, MTF pullback) | Binance 1m→15m, 10 majors; train 2023-24, valid 2025 | MTF filters, limit entry, ATR stop / R target, ≤ 8 h | 1,312 configs | Published filters: 0 trades. Best relaxed: +0.24 R on 123 trades (train), +0.12 R on 31 trades in 2025. Median −0.13 R. Random 15m entries: −0.21 R | INVALIDATED | No timing content beyond geometry; costs 0.1–0.2 R | `docs/handbook/reports/research-day-trading-entry-exploration-2026-10-11.md`, `tradingV3-private/research/20261009T060000Z-explore-entry-r2/` |
| 2 | **Swing long, 4h indicator setups** (breakout, pullback, oversold, EMA cross, trend stack) | 10 majors 4h; train 2023-24, valid 2025 | Limit at close, ATR stop, R target, 3–14 d | 270 configs | Breakouts beat regime-matched random by about 0.1 R; nothing survives 2025 (median +0.01 R). Random entries above the EMA200: +0.32 R in train, −0.10 R in 2025 | INVALIDATED | Training gains = bull exposure | `research-swing-exploration-2026-10-11.md` |
| 3 | **Swing two-sided breakouts** (B30/60/120, EMA200 regime) | same | same, both sides | 270 + controls | 19 eligible with 4 slots, none with 10 slots. In 2025 the edge is the regime (−0.03 to +0.04 R over random) | INVALIDATED | See row 4 | `research-swing-two-sided-exploration-2026-10-11.md` |
| 4 | **Canonical campaign, B30_2s × S3.T5.H14d** | majors; 2026 holdout (claim 1) | PHP/kernel parity | 1 | Train +0.14 R, valid +0.16 R. Holdout 2026: PF 1.01 / 0.97, 267 trades | INVALIDATED | Failed the one-shot holdout | `research-swing-campaign-2026-10-12.md` |
| 5 | **v2 selection, 24 setups** (trend, mean reversion, squeeze / NR7 / ATR compression, Ichimoku, opening range, Fibonacci), 15m and 4h, both sides | majors 2023-24 / 2025 | ATR stops, R targets or Chandelier | 436 | 8 pass training (all 4h long trend). All 4 selected lose in 2025 (PF 0.72–0.88). 15m: no positive config. Volatility-compression rows: nothing | INVALIDATED | Regime exposure; costs at 15m | `research-v2-selection-2026-10-12.md` |
| 6 | **Candle scalping** (5m/1m: VWAP, opening drives, …) | majors 2023-24 | taker exits, minutes | 40 | 0/40; medians −0.30 R (5m), −0.62 R (1m). Gross −30k against 168k of costs | INVALIDATED | Costs | `research-scalping-2026-10-12.md` |
| 7 | **Passive-order mark-outs** (maker edge) | OKX run14 / HL run15, 24 h | — | — | No unconditional maker edge; a passive fill costs 3.1–3.5 bp vs 5 bp taker; adverse selection 1.1–1.5 bp | INVALIDATED (as an edge) | — | `research-execution-tick-2026-10-12.md` |
| 8 | **Tick scalping r1/r2** (flow bursts, momentum, VWAP / band, 1–60 min) | Binance aggTrades 2025 weeks, majors | E1 passive through-rule; time / σ exits | 368 + 304 + 152 | 0 passes; medians −6 to −11 bp; controls equal | INVALIDATED | Costs; zero gross edge | same report |
| 9 | **Reversion to the 15-min EMA band + flow** (S7, r3–r7) | aggTrades, majors then mid-caps, 9–10 weeks of 2025 | passive entry, 60-min exit | 64 → 2 | Beats random by 15–30 bp in every training set. r5 on 9 windows: +2.5 bp. r7 mid-caps: Nov +12.8 bp, then Dec −20.9 bp | INVALIDATED (as a strategy) | Sign follows the window; pair dispersion | `research-execution-tick-2026-10-12.md`, `research-midcap-tick-2026-10-13.md` |
| 10 | **Depth families r8** (depth momentum, contrarian, build-up; Q3 = band + flow + bid depth) | Binance bookDepth 2025, 20 pairs | idem | 192 | Pure depth −7 to −9 bp. Q3 +14 bp, 60 % of series. r8b long: Jan 2025 +18.6 bp (151 trades), Dec 2024 +6.5 bp but 9/20 pairs | INVALIDATED (consistency gate) | Pair dispersion | `research-book-families-2026-10-13.md` |
| 11 | **Queue / microprice r9** | bookTicker 2023-24, 18 pairs | queue fill rule | 256 | −2.1 bp before fees; equal to control | INVALIDATED | No gross edge | same |
| 12 | **Platform scalping 1.1.0 / 1.2.0 on ticks** (r10, r10x) | aggTrades, 4 weeks of 2024, 20 pairs | platform stacks | 96 | 0/96; the core stacks have zero gross edge; the confirmation layer barely trades | INVALIDATED | — | `research-scalping-120-2026-10-13.md` |
| 13 | **VWAP entry zone ± time exit** (r11, r12) | idem; valid Sept 2024 mid-caps | zone, 2 h exit | 12 + 3 | r11: 0/12 vs zone control. r12 valid: −10.4 bp (below control) | INVALIDATED | The zone, not the stacks, carried training; it failed out of sample | `research-zone-time-2026-10-13.md`, `research-zone-only-2026-10-13.md` |
| 14 | **Open-interest positioning** (O1 flush reversal, O2 build-up continuation) | Binance metrics, 2024 weeks; valid Oct 2024 mid-caps | 1.5 σ / 15-min, 1 h exit | 8 → 2 | O1 negative. O2 +11.4 bp (train); validation −11.7 bp below control | INVALIDATED | Out of sample | `research-oi-positioning-2026-10-13.md`, `research-oi-buildup-2026-10-13.md` |
| 15 | **Q3 ablation (q3x)** | 9 weeks of 2025, 20 pairs | Q3 rule | 5 variants | Flow carries the edge (+9.6 bp), depth +0.5 bp. Negative without the top 5 % of trades (−1.2 bp). 27 % of trades go below −100 bp MAE | (exploratory) | Tail-dependent | `research-q3x-2026-10-14.md` |
| 16 | **Q3 V2 with a 2 % stop, 500-USDT portfolio** (scalp-q3-r2) | same 9 weeks; valid Nov 2024 | stop-market 2 %, 2 slots | 8 | Train C4-SL +13.0 bp; stop costs 2.8–7.5 bp. All negative without the top 5 %. Valid Nov 2024: −20.7 bp, PF 0.71, 5/20 pairs, −20.22 USDT. C2-SL statistically blocked (control mismatch) | INVALIDATED (C4-SL); C2-SL TECHNICAL_BLOCKER | Out of sample; loss before fees | PR #498/#499 branches: `research-scalp-q3-r2-*.md`, `python-orchestrator/scripts/research_exec/prereg/scalp-q3-r2/` |
| 17 | **Market-regime detectors** (D1–D6: SMA200, dual momentum, breadth, contrarian funding, vol filter, combos), daily, top-50 universe | Binance spot / perp daily 2018-22 (training only) | 14-tranche regime portfolio | 6 | D5 passes Q1 (prediction; surplus CI [+16 ; +613] bp) and fails Q2 (+9.8 % vs random +27 %). None passes both; validation / holdout never read | INVALIDATED (as exploitable); D5 prediction INCONCLUSIVE | No exploitable gain after costs; edge concentrated in 2020-21 | `research-regime-r2-2026-10-14.md`, `python-orchestrator/scripts/research_regime/` |
| 18 | **Swing v2: ranked breakouts with ATR strength, continuation entries, Chandelier** | majors 2023-24, mid-caps 2023-25, mid-caps 2026 (post hoc), OKX 193 series 2023-26 | 4 slots, 1 % risk | 288 | Train 96/288 pass (9.8 expected by chance). Valid 0/96. Post-hoc 2026 +0.17 R but surplus +0.05 (p 0.35). OKX: −0.027 R, surplus −0.047 (p 0.77) | INVALIDATED, closed by rule 7 | Surplus decays with each new dataset | `research-swing-v2-2026-10-14.md`; PR #500: `research-swing-v2-okx-2026-10-14.md` |
| 19 | **Forward OKX r8b** (long Q3 rule, live public data) | OKX, 20 pairs, W1 / W2 Oct 2026 | — | 1 | Running; reserved, not read here | (pending) | — | `research-forward-okx-2026-10.md` (plan only) |

## Common causes of failure

| Cause | Where it showed | Kind |
|---|---|---|
| Training gain = market exposure (bull 2023-24) | rows 2, 3, 5, 17, 18 | validation / signal |
| Edge too small for the costs at ≤ 15 min | rows 6, 7, 8, 11, 12 | execution |
| Sign follows the window or regime | rows 9, 10, 13, 14, 16 | signal |
| Dependence on a few pairs | rows 9, 10, 16, 18 | signal |
| Dependence on the top 5 % of trades | rows 15, 16 | signal / risk |
| Stop destroys part of the expectancy (−2.8 to −7.5 bp) | row 16 | risk |
| Mis-matched control (twice the trades) | row 16 (Amendment 2) | validation |
| Fill realism (bid/ask reconstructed from prints) | rows 8–16 | execution |
| Reuse of the same windows (9 training weeks of 2025 used by r5–Q3 V2; majors 2023-24 by every candle study) | rows 1–18 | validation |
| Multiplicity: 3,600+ configurations, each family selected from many | rows 1, 5, 18 | validation |

**Signal problems dominate.** Except for the cost-bound short horizons, the signals had no timing content
beyond their regime. The protocols worked: matched controls, out-of-sample stages and one-shot holdouts caught
every case. The weak points of method were:

- the amended control written after the training result (Q3 V2);
- the repeated reuse of the same windows;
- the absence of event-level independence when many pairs move together.

## Limits of what was tested

- **Universes.** Always the same 10 majors and 10 mid-caps, except regime-r2 (top-50 daily) and swing v2 OKX
  (monthly top 30). Every universe excluded young instruments (warm-up 42–200 days).
- **Signal type.** Always time-series on one pair against its own history. No cross-sectional or
  market-relative signal was tested; v2 mentions cross-sectional momentum as out of scope (§ "What remains",
  `research-v2-selection-2026-10-12.md`).
- **Events.** No instrument life-cycle event (listing, delisting announcement, contract change) and no
  calendar event was used as a trigger.
- **Execution.** Fills on candles or prints; real OKX bid/ask only in the 24-h captures and the running
  forward test.

## Questions that are really open

1. **Instrument life cycle.** Do newly listed perpetuals behave differently in their first weeks? Is there a
   drift once supply arrives and short selling becomes possible? `NOT_TESTED`: every universe excluded the
   first 42–200 days after listing.
2. **Market-relative (idiosyncratic) moves.** Does a coin's move net of the market revert or persist over
   days? `NOT_TESTED`: every reversion study was absolute and intraday.
3. **Rejected breakouts** (penetration of a multi-day level, then a close back inside). `NOT_TESTED` as such.
   It is close to rows 2–5 and 9.
4. **Regime prediction without exploitation** (D5). `INCONCLUSIVE`, on training only.
5. **Q3 C2-SL.** `TECHNICAL_BLOCKER`; not to be reopened (owner: the campaign is closed).

## Sufficiently explored (do not repeat)

- Indicator grids at 15m / 4h.
- Breakouts and trend stacks at 4h.
- Short-EMA reversion with flow or depth, any stop.
- Pure book and queue states.
- Funding contrarian (D4).
- Market making.
- Volatility compression / squeeze.
- Platform 1.1.0 / 1.2.0 stacks.

## Data already consumed (Phase E)

Source of the detailed per-protocol inventory:
`tradingV3-private/research/20261014T1200Z-scalp-q3-r2/DATA-EXPOSURE-scalp-q3-r2.md`, cross-checked with the
reports and manifests. "Consumed" means read for results; consumed windows may serve development, never an
independent validation.

| Window | Universe | Level | Studies | Role |
|---|---|---|---|---|
| 2023-01 → 2024-12 | 10 majors | Binance 1m→4h + funding | day trading, swing r1/r2, campaign, v2, candle scalping, swing v2 | training (read many times) |
| 2025 | 10 majors | 1m→4h | day trading, swing, campaign, v2 | validation |
| 2026-01-01 → 2026-10-09T06Z | 10 majors | 4h + funding | swing campaign | holdout, claim 1 |
| 2023-01 → 2026-01-01 | 10 mid-caps | 1m→4h + funding | swing v2 | validation |
| 2026-01-01 → 2026-10-09T06Z | 10 mid-caps | 4h + funding | swing v2 post-hoc read | holdout, claim 2 |
| 9 weeks of 2025 (02-10…10-17) | 20 pairs | aggTrades + bookDepth | r5–r8, q3x, Q3 V2 | training |
| 2025-11, 2025-12 weeks | majors / mid-caps | aggTrades | r3 valid; r7 valid / holdout | validation / holdout |
| 2025-01-13…17; 2024-12-09…13 | 20 pairs | aggTrades + bookDepth | r8b | validation; holdout |
| 2023-07, 09, 11; 2024-01 weeks | 18 pairs | bookTicker | r9 | training |
| 2024-04, 05, 06, 08 weeks | 20 pairs | aggTrades + metrics | r10–r13 | training |
| 2024-09; 2024-10 | mid-caps | aggTrades (+ metrics) | r12; r14 | validation |
| 2024-11-04…29 | 20 pairs | aggTrades + bookDepth + funding | Q3 V2 (PR #499) | validation (VALIDATION_FAIL) |
| 2023-01 → 2026-10-12T16Z | 193 OKX series, days ≥ 42 after each series' start | OKX 1m + funding | swing v2 OKX (PR #500) | single test |
| spot 2017-08 → 2023-01; perps 2020-01 → 2023-01 | Binance top-50 daily | daily | regime-r2 | training |
| 2026-10-02/03, 24 h | BTC, ETH | OKX run14 / HL run15 | execution r1 | exploratory |
| W0 / W1 / W2 (2026-10-14…16, 19…23, 26…30) | 20 pairs on OKX | live public data | forward fwd-okx-r1 | **reserved, never to be recycled** |

**Never read for results** (data independence available):

- **Binance intraday data of any symbol outside the 20 programme pairs.** regime-r2's reserved daily data for
  2023–2026 was never downloaded.
- **The first 42 days of every OKX series.** These were excluded from swing v2 OKX trading, but the candles are
  on disk and were loaded.
- **Programme-pair ticks outside the listed weeks:** 2026, most 2025 weeks, 2023, much of 2024. Their candles,
  however, are consumed.
- **Open-interest metrics outside r13 / r14.**
- **All future data.**
