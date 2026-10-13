# Scalping setup 1.2.0 — cost-attacking geometry on the platform's own setups (r10, 2026-10-13)

## Result

**No candidate: 0 of 96 configurations pass training (2026-10-13, 12:30–12:36Z); validation,
holdout and track F are not run; no 1.2.0 contract version is published.**

- **The full 1.1.0 stacks barely decide.** With their 5m and 1m confirmation layer the three
  setups decide 17, 92 and 208 times in 400 pair-days (pullback long, trend continuation long,
  trend momentum short), i.e. 12, 73 and 166 trades — under the 300-trade gate — and lose
  13–37 bp per trade at the 1.1.0 geometry. The certification's "0 trades per cell" is the same
  fact seen on one day.
- **The core stacks (regime + context + trigger, no confirmations) have no directional content
  at the 2-hour horizon.** 317, 1,230 and 3,080 trades; gross expectancy before fees +1.0, −5.5
  and −4.2 bp; net −6.0, −12.5 and −11.2 bp at the 1.1.0 geometry, against random-timing
  controls at −6.5, −7.3 and −3.8 bp: the stacks equal random timing in the same hours. The
  win rate is 30–38 % where the 1.5 ATR stop / 2.7 ATR target geometry needs 36 % before fees.
- **The 1.2.0 geometry does not change the sign.** The maker take-profit (B2) adds about 3 bp
  per trade over the taker one (the fee difference on the 30 % of trades that reach the target);
  the 3 R target (B3) is equal or worse; the time-only exit (B4) adds 2–4 bp on the two trend
  setups and loses on the pullback; the cost gate removes trades without improving them — at
  k = 6 the means are the worst (−0.6, −13.9, −17.3 bp on B2) because the surviving decisions
  carry wider ATR stops (median net −40 → −80 bp) and the same hit rate. Best row of the
  screen: `P1-A3-K6|long|E1|B2`, −0.6 bp on 100 trades, PF 0.99, 36 % of series positive.
- **The 1.1.0 entry zone would reject most of these entries anyway.** Only 1–5 % of the core
  decisions (0–9 % of the full-stack ones) have the price inside VWAP ± 0.22 ATR at the
  5-minute close; the research entry at the touch is the generous case.

Programme total since 2026-10-12: eleven pre-registered protocols (A, r1 twice, r2–r10, r8b),
1,846 configurations, twenty pairs. The cost-attacking geometry closes negative: a geometry can
save a few basis points of fees, not create expectancy where the signal has none. The
September and November 2024 mid-cap windows stay unread; the forward OKX test is unaffected.

## Why

The three scalping setups of the platform (`scalping.pullback.long`, `scalping.trend_continuation.long`,
`scalping.trend_momentum.short`, contracts 1.1.0) produced 0 trades per certification cell on
the 24 h captures and had never been tested on historical fill-capable data. Their execution
geometry (maker entry, stop 1.5 × ATR14(5m), one taker target at 1.8 R, 2 h time stop) pays 7 bp
of fees per round trip plus the spread against targets of a few tens of basis points. The
owner's 1.2.0 proposal attacks the costs without touching the indicator thresholds: a
volatility-to-cost gate, a wider target or a time-only exit, a maker take-profit. On 2026-10-13
~12:00Z the owner ordered the test in parallel with the forward OKX test
(plan `docs/superpowers/plans/2026-10-13-research-forward-okx.md`), which stays untouched.

Pre-registration `tradingV3-private/research/20261013T121000Z-scalping-120-r10/PREREGISTRATION-r10.md`,
frozen 2026-10-13T12:15:50Z (sha256 `fa24489d…`) before any 2024 archive of April–November or
any klines archive was downloaded; the r10 code was written after the freeze and tested on
synthetic data ([runbook](../runbooks/research-execution-study.md)).

## Protocol

- **Condition stacks.** A numpy port of the 1.1.0 contracts (`platform_setups.py`): indicators
  on closed candles with the canonical conventions (recursive EMA, MACD 12/26/9, Wilder RSI, ATR
  and ADX, VWAP cumulated over the canonical 250-candle window, volume ratio against the previous
  twenty candles, pullback age since the last EMA9/EMA21 cross or VWAP touch), the catalog 1.1.0
  conditions as the compiled evaluator applies them, the four layers (1h regime, 15m context
  and trigger, 5m and 1m confirmations) evaluated at every 5-minute close on the Binance 1-minute
  klines (5m, 15m, 1h by UTC aggregation, one warm-up month). `A4` is the full 1.1.0 stack, `A3`
  the same without the 5m/1m confirmation layer (a pre-registered structural ablation, not a
  threshold change). The 1.1.0 entry zone (VWAP ± 0.22 ATR) is not enforced; the share of
  decisions whose price lies inside it is reported.
- **Execution** on the aggTrades prints (quotes proxied as in r1–r8): entry E1 at the touch at
  the last second of the 5-minute candle, 250 ms latency, the contract's 45 s TTL, through rule,
  2 bp maker; **cost gate** `ATR14(5m) / close ≥ k × (7 bp + spread)`, `k` ∈ {0, 2, 4, 6}
  (0 = the 1.1.0 behaviour); exits **B1** 1.8 R taker (= 1.1.0), **B2** 1.8 R maker, **B3** 3 R
  maker, **B4** time-only 2 h — all with the 1.5 × ATR stop (taker), the 2 h hold and a taker exit
  no later than 23:59:00 UTC. 24 rules × 4 exits = **96 configurations**; regime-matched random
  controls (10 seeds); gates G1–G8 of r8 unchanged; at most three survivors per family.
- **Universe and windows.** Training: twenty pairs (ten majors, ten mid-caps), four 2024 windows
  (04-15..19, 05-13..17, 06-10..14, 08-12..16), 80 series. Validation: the ten mid-caps on
  2024-09-09..13 and 09-16..20; holdout: the ten mid-caps on 2024-11-11..15 and 11-18..22, each
  read once. The majors have no 2024 month unread at every level (the candle studies trained on
  2023–2024), the mid-caps were never part of a candle study: hence the split, and **track F**,
  the training survivors replayed on the forward OKX weeks W1 and W2 after their planned readings
  (not before 2026-10-24 and 2026-10-31), which conditions any 1.2.0 contract publication.

## Training: 0 of 96 pass

`part-b/binance-r10/screen-training.json` (80 series, 96 configurations, 352 s, 3 workers,
code a0fea3b3, pre-registration sha256 recorded). Mean net bp per trade at k = 0 (no cost gate),
by setup, layer and exit; `gross` is the mean before fees at B1; `control` the median of the ten
regime-matched random controls; `series+` the share of the 80 series positive (best exit).

| Setup | Layer | Decisions / trades | B1 1.8 R taker (= 1.1.0) | B2 1.8 R maker | B3 3 R maker | B4 time 2 h | gross (B1) | control | PF (best) | series+ |
|---|---|---|---|---|---|---|---|---|---|---|
| P1 pullback long | A4 full 1.1.0 | 17 / 12 | −37.5 | −36.0 | −21.6 | +0.8 | −30.5 | −5.7 | 1.01 | 22 % |
| P1 pullback long | A3 core | 1,783 / 317 | −6.0 | −3.0 | −4.9 | −6.1 | +1.0 | −6.5 | 0.92 | 36 % |
| P2 trend continuation long | A4 full 1.1.0 | 92 / 73 | −12.6 | −8.9 | −5.9 | −5.9 | −5.6 | −4.7 | 0.85 | 33 % |
| P2 trend continuation long | A3 core | 8,857 / 1,230 | −12.5 | −9.9 | −11.2 | −8.0 | −5.5 | −7.3 | 0.82 | 27 % |
| P3 trend momentum short | A4 full 1.1.0 | 208 / 166 | −18.5 | −15.6 | −17.6 | −18.3 | −11.5 | −3.4 | 0.67 | 31 % |
| P3 trend momentum short | A3 core | 21,887 / 3,080 | −11.2 | −8.4 | −9.5 | −10.3 | −4.2 | −3.8 | 0.80 | 25 % |

- **Gates.** Every core row passes G1 (trades) and G8 (fill rate 75–94 % with the 45 s TTL) and
  fails G2–G7 (mean, PF, control, series, best day, fees × 1.5); every full-stack row fails G1.
  The four rows with a positive mean (P1-A4 and P2-A4 at B4 or B3, k ≥ 2) hold 3 to 37 trades.
- **Cost gate.** On B2: P1 −3.0 (k = 0, 317 trades) → −2.6 (k = 2, 290) → −2.6 (k = 4, 206) →
  −0.6 (k = 6, 100); P2 −9.9 → −10.2 → −12.9 → −13.9; P3 −8.4 → −8.5 → −14.2 → −17.3. The gate
  keeps the decisions taken when the 5-minute ATR is large; their stops are wider in basis
  points, their hit rate is not better.
- **Exit reasons** (P3-A3-K0, B1): stop 58 %, target 31 %, time 11 %; median hold 27 minutes.
  Win rates 38 % (P1), 30 % (P2), 37 % (P3).
- **Majors against mid-caps, windows.** `P1-A3-K0|B2`: majors −4.9 bp on 168 trades, mid-caps
  −0.8 on 149; positive in August only (+1.3 bp on 144). `P3-A3-K0|B2`: majors −9.4 on 1,546,
  mid-caps −7.3 on 1,523; every window negative (April −23.0, May −1.8, June −3.0, August −8.2).
  `P2-A3-K0|B4`: majors −5.1 on 443, mid-caps −10.9 on 458; positive in May only (+3.8 on 508).
- **Entry zone diagnostic.** Share of decisions whose mid lies inside VWAP ± 0.22 ATR
  (clamped to 4–65 bp): 1.2–5.1 % for the core stacks, 0–9 % for the full ones.

## Validation and holdout: not run

No configuration passes training, so by the pre-registered stop rule the validation
(mid-caps, September 2024) and the holdout (mid-caps, November 2024) are not run and their
archives were not downloaded; the holdout directory is empty.

## Track F (forward OKX weeks): nothing to replay

Track F replays the training survivors on the forward weeks after their planned readings;
there is no survivor. fwd-okx-r1 continues untouched.

## Budgets

`budgets-training.json` (the three rows with the highest LB90, all negative): at 500 USDT and
1× leverage, `P3-A3-K0|short|E1|B2` makes 7.7 trades a day and loses 3.2 USDT a day;
`P1-A3-K0|long|E1|B2` makes 0.8 trade a day and loses 0.12 USDT a day; fees per round trip
0.30 USDT (6.1 bp). No budget from 500 to 100,000 USDT turns these rows positive: the sign is
in the expectancy, not in the size.

## Limits

- The stacks are a research port, not the PHP evaluation: indicators are seeded a month back
  instead of 250 candles back; candles are Binance's, prints are Binance's; the hysteresis gaps of
  the MACD cross conditions are in price units as in the platform (a sign flip on BTC, nearly
  unreachable on sub-dollar pairs).
- The entry zone of 1.1.0 is not enforced (diagnostic only); the platform's zone and deviation
  guards would reject part of these entries.
- Fills follow the through rule on prints; the book is not seen (no bookTicker after 2024-03-30).
- The training windows overlap the earlier candle studies' training years for the majors
  (other families); the mid-cap validation and holdout windows, unread, remain available to a
  future protocol. The run's code tree was clean at the start (`dirty=0`) and carried only
  documentation edits at the end; the package hashes are in the screen document.
- What the result says, and does not: the three condition stacks have no expectancy at the
  2-hour horizon on these 2024 windows, with or without their confirmation layer, so no exit
  geometry can make them a candidate; it does not say that a different signal with the 1.2.0
  geometry would fail. The r8b lead under forward test is such a different signal.
