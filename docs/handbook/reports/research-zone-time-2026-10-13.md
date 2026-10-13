# "Zone + time exit" — the platform's core scalping stacks inside the 1.1.0 entry zone (r11, 2026-10-13)

## Result

**No candidate: none of the twelve pre-declared configurations beats the zone-restricted random
control at the training stage (2026-10-13, 15:23–15:26Z). By the frozen stop rule nothing is
acquired, September and November 2024 stay unread for the mid-caps, and the hypothesis closes.**

- **The pooled row** (the three setups inside the zone, time exit) makes +12.1 bp on 296 trades
  (LB90 +2.3 bp, PF 1.28, 58 % of 78 series positive, positive without its best day and at
  fees × 1.5) — and random timing inside the same zone, at the same hours, with the same passive
  entry and the same exit, makes +3.2 bp in median and +12.7 bp at its 95th percentile. The
  stacks do not add to the zone: the gate G4 (mean above the control p95) fails, as it does for
  the eleven other rows.
- **Per setup**, the time-exit rows are +9.4 bp (trend momentum short, 223 trades) against a
  control median of +7.6 (p95 15.1); +30.4 (pullback long, 36 trades) against +24.3 (p95 56.0);
  +22.8 (trend continuation long, 64 trades) against +2.4 (p95 27.3). The maker-target rows (1.8 R
  and 3 R) are at or below zero, with controls near zero.
- **What the control says** (an observation, not a pre-registered row): inside VWAP(5m) ± 0.22 ATR
  a passive entry at the touch at a random second of the same hours, held two hours with the
  1.5 ATR stop, has a positive median control on these four 2024 windows (+3.2 bp pooled, +7.6 bp
  on the short side), where the unrestricted controls of r10 were −4 to −7 bp. What r10x read as
  the stacks' contribution was the zone's. Whether the zone alone carries expectancy out of sample
  is a different hypothesis; it would need its own pre-registration, and the September and
  November 2024 mid-cap windows remain available for it.

Programme total since 2026-10-12: twelve pre-registered protocols (A, r1 twice, r2–r11, r8b),
1,858 configurations, twenty pairs, plus the exploratory r10x (180 rows). The forward OKX test is
unaffected.

## Why

The exploratory r10x ([report](research-scalping-120-2026-10-13.md), section "Exploratory
analyses") found that, inside the 1.1.0 entry zone, the core decisions of the three scalping
setups (1h regime, 15m context and trigger, no 5m/1m confirmation) are gross-positive and that
the two-hour time exit makes them +30.4, +22.8 and +9.4 bp net on 36, 64 and 223 trades, against
−8 to −11 bp outside the zone. The owner gave the go to a frozen pre-registration of this
post-hoc hypothesis on 2026-10-13, then chose its structure on a blocker raised before the freeze:
applied literally, the r8 training gates close the hypothesis by construction (G5, 70 % of the
series positive, is unreachable at ~4 trades per series with a 32 % win rate and the winners in
the right tail; G1, 300 trades, is reached by the pool only). The owner's decision: the training
windows, already read, are informational; the only training-stage selection is the new
information — a random control restricted to the zone; the real tests are the r8 validation
checks on windows never read for these pairs, full months with one series per pair so that the
series check measures consistency across pairs.

Pre-registration `tradingV3-private/research/20261013T153000Z-zone-time-r11/PREREGISTRATION-r11.md`,
frozen 2026-10-13T15:23:04Z (body sha256 `6c9dc905…`) before any September or November 2024
archive was downloaded; the zone-restricted control, the pooled family and the month windows were
written and tested on synthetic data before the freeze (commit `fe1cab3f`,
[runbook](../runbooks/research-execution-study.md)).

## Protocol

- **Rules.** `P{n}-A3-K0-Z1`, n ∈ {1, 2, 3} (pullback long, trend continuation long, trend momentum
  short; sides implied), and `P0-A3-K0-Z1`, the pool of the three replayed as a "both"
  configuration (long stream P1 ∪ P2, short stream P3, one position at a time per stream). The
  zone: the mid of the decision second inside `VWAP5 ± clamp(0.22 × ATR5 / close, 4 bp, 65 bp) ×
  VWAP5`, from the candle closed at the decision. Entry E1 at the touch (250 ms, through rule, 2 bp
  maker, 45 s TTL); exits B4 (time only), B2 (1.8 R maker), B3 (3 R maker), all with the stop at
  1.5 × ATR14(5m), the 2 h hold and the taker exit no later than 23:59:00 UTC; B1 excluded
  (pre-declared). **Twelve configurations**, nothing else to be run on the unread windows.
- **Controls.** Zone-restricted random timing: as many random decision seconds as the
  configuration's trades, in the same hours, drawn only among the seconds whose mid lies inside the
  zone with a usable ATR, same exit, 10 seeds (`screen._zone_candidates`,
  `_control_decisions(candidates)`).
- **Phases and gates.** Training on the r10 tape (twenty pairs, April, May, June, August 2024, 80
  series): every r8 gate reported; selection into validation = G4 only (mean above the control
  p95 and at least 3 bp above its median). Validation: the ten mid-caps over the whole of
  September 2024, one series per pair (10 series), the r8 validation checks unchanged (≥ 100
  trades, net > 0, PF ≥ 1.05, mean above the zone-restricted control median, ≥ 50 % of the series
  positive). Holdout: November 2024, read only for validated configurations, same checks.
  Candidate if and only if both pass and at least 300 trades over the three phases. No track F.
- **Lineage, verified before the freeze.** No tick tape holds a 2024-09, 2024-10 or 2024-11 file;
  the only files of those months under `research/` are the monthly klines of the ten majors in the
  candle dataset (no mid-cap). r9 read 2023-07 → 2024-03, r8/r8b 2025 with January 2025 and
  December 2024, r6 2025, r10/r10x April–August 2024: September and November 2024 were never
  read for the ten mid-caps at any level.

## Training stage: 0 of 12 selected

`part-b/binance-r11/screen-training.json` (80 series, 12 configurations, 161 s, 3 workers, code
`fe1cab3f` — the twelve package hashes recorded in the document are those of the commit; the tree
carried the runbook edit at the end). Net bp per trade; `control` is the median / p95 of the ten
zone-restricted random controls; `series+` the share of the series with trades that are positive.

| Configuration | Trades | Mean | Median | LB90 | PF | Win | series+ | control med / p95 | G4 |
|---|---|---|---|---|---|---|---|---|---|
| P0 pool, B4 time 2 h | 296 | +12.1 | −41.3 | +2.3 | 1.28 | 33 % | 58 % (78) | +3.2 / +12.7 | fail |
| P0 pool, B2 1.8 R maker | 310 | −1.2 | −37.6 | −7.3 | 0.97 | 39 % | 46 % (78) | −2.4 / +4.0 | fail |
| P0 pool, B3 3 R maker | 299 | −0.5 | −40.8 | −7.5 | 0.99 | 34 % | 50 % (78) | −0.5 / +8.7 | fail |
| P1 pullback long, B4 | 36 | +30.4 | −35.7 | −6.0 | 1.75 | 42 % | 39 % (18) | +24.3 / +56.0 | fail |
| P1 pullback long, B2 | 36 | +2.9 | −35.7 | −16.9 | 1.07 | 42 % | 44 % (18) | +0.8 / +9.6 | fail |
| P1 pullback long, B3 | 36 | +8.1 | −35.7 | −14.9 | 1.20 | 42 % | 39 % (18) | +3.1 / +24.1 | fail |
| P2 trend continuation long, B4 | 64 | +22.8 | −41.4 | −5.2 | 1.56 | 36 % | 48 % (25) | +2.4 / +27.3 | fail |
| P2 trend continuation long, B2 | 67 | −3.9 | −38.3 | −18.8 | 0.91 | 39 % | 56 % (25) | −11.4 / −2.0 | fail |
| P2 trend continuation long, B3 | 64 | −1.6 | −41.4 | −17.4 | 0.96 | 36 % | 44 % (25) | −9.5 / +8.5 | fail |
| P3 trend momentum short, B4 | 223 | +9.4 | −41.8 | −2.1 | 1.21 | 32 % | 52 % (69) | +7.6 / +15.1 | fail |
| P3 trend momentum short, B2 | 234 | −0.7 | −37.6 | −8.6 | 0.98 | 39 % | 46 % (69) | +0.5 / +8.2 | fail |
| P3 trend momentum short, B3 | 226 | −0.1 | −41.1 | −9.4 | 1.00 | 33 % | 49 % (69) | +2.2 / +14.3 | fail |

- **The pool with the time exit**, the only row that could reach the programme's trade gate, has
  296 trades (not the 323 of the three rows summed: one position at a time per stream), 70 % of
  fills, a median hold of 70 minutes, 116 stops and 180 time exits; majors +16.5 bp on 145 trades,
  mid-caps +7.9 on 151; April −1.6, May −9.1, June +29.8, August +26.3. It passes G2, G3, G6, G7
  and G8, fails G1 (296 < 300), G4 (12.1 < 12.7) and G5 (58 % < 70 %). Against the unrestricted
  control of r10x the same rows were "above the median, below the p95"; against the zone-restricted
  control the margin above the median shrinks to 8.9 bp for the pool and 1.8 bp for P3.
- **Budgets** (`budgets-training.json`, information): the pool with the time exit at 500 USDT and
  1× makes 0.74 trade a day and +0.45 USDT a day on the training windows, fees 0.35 USDT per
  round trip; no budget turns a row that fails its control into a candidate.

## Validation and holdout: not run

No configuration is selected, so by the pre-registered stop rule the validation (the ten mid-caps,
September 2024) and the holdout (November 2024) are not acquired and not read; no validation or
holdout tape directory exists.

## Limits

- The training rows were known before the freeze (r10x); the stage's only new information is the
  zone-restricted control and the pooled row, and both close the hypothesis. The result is "the
  stacks add nothing to the zone", not "the zone adds nothing": the positive in-zone controls are
  an observation on four 2024 weeks of twenty pairs, read many times, and would need their own
  pre-registration on the unread windows.
- Conventions as r10: a research port of the 1.1.0 stacks, Binance candles and prints, quotes
  proxied from prints, decisions at the last second of the 5-minute candle, the zone tested on the
  mid of that second; the platform's deviation guards and order placement are not modelled.
- Nothing was downloaded; no file of September, October or November 2024 was read; the forward
  OKX test (capture, protocol, evaluation, weeks W1 and W2) is untouched.
