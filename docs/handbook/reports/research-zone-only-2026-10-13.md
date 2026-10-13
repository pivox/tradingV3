# The zone alone — a passive entry inside the 1.1.0 entry zone, time exit, no stack (r12, 2026-10-13)

## Result

**No candidate: the two configurations selected at the training stage fail every validation check
on the ten mid-caps over the whole of September 2024 (2026-10-13, 16:16–16:22Z); the holdout
(November 2024) is not read and the hypothesis closes.**

- On the training windows (April, May, June, August 2024, twenty pairs, already read by r10, r10x
  and r11) the zone alone beats random timing at the same hours by 5 to 9 bp — and still loses
  after fees: the pooled long-and-short rule makes −1.1 bp net on 2,215 trades (gross +5.9, fees
  7.0) against a control median of −6.8 bp; the long rule −0.5 bp on 1,133 trades against −9.2;
  the short rule −1.8 bp and does not beat its control. The mid-caps are the only positive group
  (+3.8 bp) and the majors lose 5 bp.
- On September 2024, read once as pre-registered, the selected rules are below zero **and below
  the random control**: the pooled rule −10.4 bp net on 1,840 trades (gross −3.4 before fees, PF
  0.76, 0 of 10 pairs positive, control median −7.1); the long rule −5.2 bp on 896 trades (gross
  +1.8, PF 0.88, 2 of 10 pairs positive, control median −5.0). Both fail the net, profit-factor,
  control, series and fees × 1.5 checks and pass only the trade count.
- What the three protocols of the day say together: r10x read "the stacks inside the zone"; r11
  showed the stacks add nothing to the zone; r12 shows the zone alone has no expectancy out of
  sample. The in-zone edge of the 2024 training weeks was a feature of windows read many times,
  not a property of the entry.

Programme total since 2026-10-12: thirteen pre-registered protocols (A, r1 twice, r2–r12, r8b),
1,861 configurations, twenty pairs, plus the exploratory r10x. September 2024 is now consumed
for the ten mid-caps (two configurations, read once); November 2024 stays unread. The forward
OKX test is unaffected.

## Why

r11 ([report](research-zone-time-2026-10-13.md)) closed with an observation: its random
controls, restricted to the 1.1.0 entry zone (VWAP(5m) ± 0.22 × ATR14(5m), clamped to 4–65 bp)
at the hours of the stacks' trades, with the same passive entry and the same two-hour time exit,
were positive (+3.2 bp pooled, +7.6 bp on the short side) where the unrestricted controls of r10
were −4 to −7 bp. The owner gave the go on 2026-10-13 to test the zone by itself under a frozen
pre-registration, with the rule's cadence, side rules and cooldown declared in advance (not chosen
on the training windows), a control that removes only the zone conditioning, the r8 validation
checks and an added fee-robustness check, on the full months of September and November 2024 for
the mid-caps, one series per pair and month.

Pre-registration `tradingV3-private/research/20261013T160000Z-zone-only-r12/PREREGISTRATION-r12.md`,
frozen 2026-10-13T16:10:04Z (body sha256 `a04f0358…`) before any September archive was
downloaded; the rule's density and expectancy were not computed before the freeze; the r12 code
(family `R1`, the cadence flag, the fee check) was written and tested on synthetic data before it
(commit `0015fde2`, [runbook](../runbooks/research-execution-study.md)).

## Protocol

- **Rule R1.** At the last second of each 5-minute candle (the platform's cadence), if the mid lies
  inside `VWAP5 ± clamp(0.22 × ATR5 / close, 4 bp, 65 bp) × VWAP5` (VWAP cumulated over the
  canonical 250-candle window, ATR14 of the candle closed at the decision) with a usable ATR: no
  stack, no regime, no hour filter, no cost gate. Three pre-declared configurations: `R1|long`,
  `R1|short`, `R1|both` (two independent streams, one position at a time per stream, no netting);
  no cooldown beyond the open position (a dense rule: ~8 decisions per pair-day and stream on
  the training windows). Entry E1 at the touch (250 ms, through rule, 2 bp maker, 45 s TTL); exit
  B4 only — time exit two hours after the fill, stop at 1.5 × ATR14(5m) of the decision (taker),
  taker exit no later than 23:59:00 UTC; fees maker 2 bp / taker 5 bp. **Three configurations.**
- **Control.** The unrestricted random-timing control of r10: as many random decision seconds as
  the stream's trades, in the same hours of the day, among all the seconds, same entry, exit and
  stop with the ATR of the random second, 10 seeds. It keeps everything but the zone conditioning
  and includes the in-zone seconds, which makes it conservative if the zone has expectancy.
- **Phases.** Training on the r10 tape (informational; every r8 gate reported; selection into
  validation = G4 only, mean above the control p95 and 3 bp above its median). Validation: the ten
  mid-caps over September 2024 (`m2409`), one series per pair, the r8 checks — at least 100 trades,
  net > 0, PF ≥ 1.05, mean above the control median, at least 50 % of the series positive — plus
  net > 0 at fees × 1.5 (`fees_x1_5`, the owner's addition). Holdout: November 2024, only for the
  validated configurations, same checks. Candidate if and only if both pass and at least 300
  trades over the three phases. No track F.
- **Lineage** (re-verified before the freeze): no tick tape held a 2024-09, 2024-10 or 2024-11
  file; the only files of those months under `research/` were the majors' klines and funding
  archives of the candle dataset (no mid-cap). September and November 2024 had never been read
  for the ten mid-caps at any level.

## Training stage: 2 of 3 selected

`part-b/binance-r12/screen-training.json` (80 series, 3 configurations, 158 s, 3 workers, code
`0015fde2`; the twelve package hashes recorded equal the commit; the tree carried the runbook edit
at the end). `control` is the median / p95 of the ten unrestricted random controls.

| Configuration | Decisions | Trades | Fill | Gross | Net | Median | LB90 | PF | Win | series+ | control med / p95 | G4 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| R1 both (long + short streams), B4 | 6,674 | 2,215 | 77 % | +5.9 | −1.1 | −42.0 | −4.0 | 0.97 | 32 % | 45 % (80) | −6.8 / −4.9 | pass |
| R1 long, B4 | 3,337 | 1,133 | 79 % | +6.5 | −0.5 | −41.6 | −5.4 | 0.99 | 31 % | 50 % (80) | −9.2 / −5.4 | pass |
| R1 short, B4 | 3,337 | 1,082 | 74 % | +5.2 | −1.8 | −42.7 | −6.4 | 0.96 | 33 % | 46 % (80) | −5.8 / −1.2 | fail |

- The zone beats random timing at the same hours (+5.7 bp for the pooled rule, +8.7 for the long
  one), but the edge is smaller than the 7 bp of fees: every informational gate but G1, G4 and G8
  fails. Majors −5.6 / −4.4 / −6.9 bp, mid-caps +3.8 / +3.9 / +3.6 bp (about 530–1,060 trades per
  group); by window, the pooled rule is at −3.8, −0.4, −0.2 and +0.4 bp. 61 % of the exits at the
  time stop, 39 % at the stop; median hold 53 minutes.
- Selected into validation by the pre-registered rule: `R1|both` and `R1|long`.

## Validation (September 2024, ten mid-caps): 0 of 2 pass

`screen-validation.json` (10 series of 30 days, 2 configurations, 103 s, 2 workers, no missing
day; tape `20261013T160000Z-tape-binance-r12-validation`, 300 pair-days, 385 MB).

| Configuration | Decisions | Trades | Fill | Gross | Net | Median | LB90 | PF | Win | pairs+ | control med / p95 | fees × 1.5 | Checks |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| R1 both, B4 | 5,744 | 1,840 | 73 % | −3.4 | −10.4 | −46.2 | −12.9 | 0.76 | 30 % | 0 of 10 | −7.1 / −4.8 | negative | trades only |
| R1 long, B4 | 2,872 | 896 | 71 % | +1.8 | −5.2 | −45.1 | −9.6 | 0.88 | 31 % | 2 of 10 | −5.0 / +1.0 | negative | trades only |

- Per pair, the pooled rule loses on every mid-cap (from −1.6 bp on SUI to −18.8 on NEAR); the
  long rule wins on FIL (+6.0) and SUI (+9.8) and loses on the eight others (NEAR −25.1, OP −16.6).
- Both rules are below their random control's median: on September the zone selects worse
  seconds than chance at the same hours. The holdout is not run; November 2024 stays unread.
- Budgets (`budgets-validation.json`, information): negative at every size from 500 USDT.

## Defect disclosed

The r12 acquisition scripts, derived from the r11 drafts, still carried the two-week dates of the
first r11 design instead of the pre-registered full months. The defect was seen on the first
acquisition log line (a total of 100 pair-days instead of 300), the chain was stopped at 16:16Z
before any validation screen ran (no September PnL computed), the partial downloads were
discarded, the scripts were fixed to the full months (r12 and the closed r11), and the chain was
relaunched at 16:16:34Z; the pre-registration was not touched.

## Limits

- The training windows had been read by three protocols before this one; the selection there
  is informational by design, and the September result is the only out-of-sample reading.
- Conventions as r10–r11: Binance candles and prints, quotes proxied from prints, decisions at
  the last second of the 5-minute candle, the zone tested on the mid of that second; the
  platform's deviation guards and order placement are not modelled; the dense rule holds a
  position most of the time and its trades are serially dependent.
- Nothing beyond September was downloaded; the validation tape is a research artifact left to the
  owner; the forward OKX test (capture, protocol, evaluation, weeks W1 and W2) is untouched.
