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

- **Exploratory re-read of the same windows (owner's request, same day; not pre-registered, no
  selection — see "Exploratory analyses").** No volume-confirmation threshold between 1.1 and 1.4
  rescues the full stacks. The 1.1.0 entry zone does isolate better trades: inside VWAP ± 0.22 ATR
  the core decisions (2–5 % of them) are gross-positive for the three setups and the time exit
  makes them +30, +23 and +9 bp net on 36, 64 and 223 trades, against −8, −8 and −11 bp outside.
  The one row with 200 trades or more is positive but not clearly (median −42 bp, LB90 −2 bp, below
  the control p95, one window carrying it). A frozen pre-registration is proposed to the owner,
  not launched.

Programme total since 2026-10-12: eleven pre-registered protocols (A, r1 twice, r2–r10, r8b),
1,846 configurations, twenty pairs, plus the exploratory r10x (180 rows). The cost-attacking
geometry closes negative: a geometry can save a few basis points of fees, not create expectancy
where the signal has none. The September and November 2024 mid-cap windows stay unread; the
forward OKX test is unaffected.

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

## Exploratory analyses (owner's request, 2026-10-13 ~13:25Z; not pre-registered, no selection)

After the r10 result the owner asked two exploratory questions on the training windows already
read: what the full stacks do with the volume-ratio confirmation at 1.1, 1.2 and 1.3 instead of
1.4, and whether the 1.1.0 entry zone isolates better trades. Both are answered on the same 400
pair-days, with the r10 engine, exits and controls, as protocol `r10x`
(`tradingV3-private/research/20261013T121000Z-scalping-120-r10/EXPLORATORY-r10x.md`, frozen
2026-10-13T14:55:07Z, sha256 `a402cd10…`, before the run; `part-b/binance-r10x/`, 180
configurations, 423 s, code `1290c232` — the twelve package hashes recorded in the document are
those of the commit; the tree carried the runbook edit at the end). Nothing is selected, no
validation or holdout exists for `r10x` (the CLI refuses the phases), September and November
2024 stay unread, the forward test is untouched. The six k = 0 rules of r10 re-run in the same
pass reproduce the r10 rows exactly (24 rows, Δ = 0.000 bp). With the 96 rows of r10, 276 rows
have now been read on these windows: a positive row here is a hypothesis, not a result.

### 1. Volume confirmation threshold: no threshold rescues the full stacks

`P{n}-A4-K{k}-V{t}`: the full 1.1.0 stack whose 5m and 1m `volume_ratio_ok` use `t` instead of
1.4; k ∈ {0, 2, 4}. Rows at k = 0 (B1 = 1.1.0 geometry; `gross` before fees at B1; `control` the
median / p95 of the ten random controls of the B1 row; PF and series+ the best exit):

| Setup | Confirmation | Decisions / trades | gross | B1 | B2 | B3 | B4 | control | PF | series+ |
|---|---|---|---|---|---|---|---|---|---|---|
| P1 | none (A3, r10) | 1,783 / 317 | +1.0 | −6.0 | −3.0 | −4.9 | −6.1 | −6.5 / −3.1 | 0.92 | 36 % |
| P1 | volume ≥ 1.1 | 38 / 30 | −12.9 | −19.9 | −15.9 | −14.4 | −15.4 | −6.1 / 17.9 | 0.70 | 33 % |
| P1 | volume ≥ 1.2 | 27 / 20 | −22.5 | −29.5 | −25.8 | −34.4 | −18.5 | −8.1 / 22.2 | 0.67 | 27 % |
| P1 | volume ≥ 1.3 | 21 / 15 | −31.2 | −38.2 | −37.1 | −25.8 | −4.6 | −8.1 / 34.0 | 0.92 | 17 % |
| P1 | volume ≥ 1.4 (1.1.0, r10) | 17 / 12 | −30.5 | −37.5 | −36.0 | −21.6 | +0.8 | −5.7 / 64.2 | 1.01 | 22 % |
| P2 | none (A3, r10) | 8,857 / 1,230 | −5.5 | −12.5 | −9.9 | −11.2 | −8.0 | −7.3 / −4.9 | 0.82 | 27 % |
| P2 | volume ≥ 1.1 | 201 / 141 | +1.2 | −5.8 | −1.4 | −4.2 | −1.7 | −8.0 / 6.8 | 0.96 | 44 % |
| P2 | volume ≥ 1.2 | 151 / 111 | −0.3 | −7.3 | −3.6 | −9.6 | −8.7 | −6.4 / 6.5 | 0.90 | 42 % |
| P2 | volume ≥ 1.3 | 112 / 88 | −3.7 | −10.7 | −7.1 | −10.0 | −9.5 | −5.9 / 12.6 | 0.80 | 32 % |
| P2 | volume ≥ 1.4 (1.1.0, r10) | 92 / 73 | −5.6 | −12.6 | −8.9 | −5.9 | −5.9 | −4.7 / 11.8 | 0.85 | 33 % |
| P3 | none (A3, r10) | 21,887 / 3,080 | −4.2 | −11.2 | −8.4 | −9.5 | −10.3 | −3.8 / −0.3 | 0.80 | 25 % |
| P3 | volume ≥ 1.1 | 344 / 259 | −13.6 | −20.6 | −17.6 | −18.5 | −17.6 | −2.1 / 4.3 | 0.66 | 26 % |
| P3 | volume ≥ 1.2 | 292 / 226 | −16.3 | −23.3 | −20.4 | −20.6 | −22.0 | −4.1 / 6.5 | 0.60 | 26 % |
| P3 | volume ≥ 1.3 | 244 / 193 | −14.8 | −21.8 | −18.8 | −20.7 | −21.1 | −3.9 / 7.7 | 0.61 | 26 % |
| P3 | volume ≥ 1.4 (1.1.0, r10) | 208 / 166 | −11.5 | −18.5 | −15.6 | −17.6 | −18.3 | −3.4 / 10.9 | 0.67 | 31 % |

At k = 2 and 4 the picture is the same (108 rows in all); the only net-positive row is
`P2-A4-K4-V1.1|B4`, +1.9 bp on 78 trades (PF 1.04), below the control p95. The confirmation
layer removes 95–99 % of the core decisions at every threshold and the survivors are not
better: on P3 they are worse than the core (−14 to −17 bp gross against −4), on P1 worse at
every threshold, on P2 the 1.1 threshold lifts the gross to +1.2 bp with a net still negative on
141 trades. The threshold is not the lever.

### 2. The entry zone isolates better trades — on a small, fragile subset

`P{n}-A{3,4}-K0-Z{1,0}`: `Z1` keeps a decision only if the mid of the decision second lies inside
VWAP(5m) ± clamp(0.22 × ATR14(5m) / close, 4 bp, 65 bp) × VWAP (the 1.1.0 zone as the port
computes it from the candle closed at the decision), `Z0` only if outside; `Z1 ∪ Z0` is the r10
row (verified). Core stacks (A3), k = 0; the full stacks inside the zone decide 1, 2 and 1 times.

| Setup | Decisions | Decisions / trades | gross | B1 | B2 | B3 | B4 | control | PF | series+ |
|---|---|---|---|---|---|---|---|---|---|---|
| P1 | inside the zone (Z1) | 83 / 36 | +7.3 | +0.3 | +2.9 | +8.1 | +30.4 | −4.8 / 22.8 | 1.75 | 44 % |
| P1 | outside (Z0) | 1,700 / 318 | −0.9 | −7.9 | −4.9 | −6.3 | −8.0 | −5.8 / −2.8 | 0.87 | 33 % |
| P2 | inside the zone (Z1) | 176 / 68 | +1.2 | −5.8 | −3.9 | −1.6 | +22.8 | −8.3 / −0.8 | 1.56 | 56 % |
| P2 | outside (Z0) | 8,681 / 1,232 | −5.6 | −12.6 | −10.0 | −11.2 | −7.7 | −7.8 / −3.9 | 0.83 | 29 % |
| P3 | inside the zone (Z1) | 601 / 234 | +3.3 | −3.7 | −0.7 | −0.1 | +9.4 | −1.4 / 11.9 | 1.21 | 52 % |
| P3 | outside (Z0) | 21,286 / 3,062 | −4.3 | −11.3 | −8.4 | −9.6 | −10.7 | −2.7 / −0.2 | 0.80 | 26 % |

- **What the zone does.** In the three setups and in the same direction, the decisions taken with
  the price at the 5-minute VWAP are gross-positive at the 1.1.0 geometry and the others are
  not; the time exit (B4) is the best exit inside the zone; the losing bulk of r10 is the
  out-of-zone 95–98 %. The in-zone fill rate is lower (70–80 %) and the in-zone short of P3 is,
  in effect, a short at the VWAP under a bearish 1h/15m regime.
- **Why it is not a candidate.** The one row with 200 trades or more, `P3-A3-K0-Z1|short|E1|B4`:
  +9.4 bp on 223 trades, PF 1.21, 52 % of 69 series positive, positive without its best day and
  at fees × 1.5 — but median −41.8 bp, win rate 32 %, LB90 −2.1 bp, above the control median
  (+0.8) and below its p95 (12.1; max 15.0); June carries it (+35.5 bp on 66 trades; April +1.0,
  May −29.8, August +11.7); majors +17.4 bp on 110 trades against mid-caps +1.7 on 113; 61 % of
  the exits at the 2-hour time stop, 39 % at the stop. `P1` and `P2` inside the zone with the
  time exit (+30.4 on 36 trades, +22.8 on 64) beat their control p95 but rest on 36 and 64
  trades, carried by August (P1) and May/August (P2). Pooled, the three in-zone time-exit rows
  make +14.4 bp on 323 trades (an exploratory pool, not a configuration).
- **Proposal to the owner (not launched).** If the "zone + time exit" hypothesis is worth a
  test, it goes through a frozen pre-registration: family `P-A3-K0-Z1` (the three setups; exits
  B4 and B2/B3; the pool of the three pre-declared as a row, the only one that can reach the
  300-trade gate on four weeks), the r8 gates unchanged, a random control restricted to the
  zone (the current control ignores it and is therefore too easy — to implement before the
  freeze), validation on the mid-caps in September 2024, holdout on the mid-caps in November
  2024, budgets from 500 USDT. On the training windows the mid-caps carry less than the majors,
  so that validation is a real test. Nothing starts without the owner's explicit go.

## Limits

- The stacks are a research port, not the PHP evaluation: indicators are seeded a month back
  instead of 250 candles back; candles are Binance's, prints are Binance's; the hysteresis gaps of
  the MACD cross conditions are in price units as in the platform (a sign flip on BTC, nearly
  unreachable on sub-dollar pairs).
- The entry zone of 1.1.0 is not enforced in the pre-registered screen (diagnostic only); the
  platform's zone and deviation guards would reject part of these entries. The exploratory
  re-read above imposes it after the fact, on the mid of the decision second.
- Fills follow the through rule on prints; the book is not seen (no bookTicker after 2024-03-30).
- The training windows overlap the earlier candle studies' training years for the majors
  (other families); the mid-cap validation and holdout windows, unread, remain available to a
  future protocol. The run's code tree was clean at the start (`dirty=0`) and carried only
  documentation edits at the end; the package hashes are in the screen document.
- What the result says, and does not: the three condition stacks have no expectancy at the
  2-hour horizon on these 2024 windows, with or without their confirmation layer, so no exit
  geometry can make them a candidate; it does not say that a different signal with the 1.2.0
  geometry would fail. The r8b lead under forward test is such a different signal.
