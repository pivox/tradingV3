# Open-interest positioning — flush reversal and build-up continuation (r13, 2026-10-13)

## Result

**No candidate by the frozen rule: none of the eight configurations passes every training gate
(2026-10-13, 17:33–17:36Z), so October and November 2024 are not acquired and r13 closes. But the
build-up continuation family is the first of the programme whose new information beats a strict
matched control at training with a positive lower bound.**

- **O1 — flush reversal fails.** Buying after a fall (selling after a rise) while the open interest
  fell by 1 % or 2 % over fifteen minutes loses 18 to 38 bp per trade on 56 to 455 trades, at or
  below random timing after the same price moves (control medians −9 to −19 bp). At this resolution
  the "liquidation exhaustion" does not revert within the hour.
- **O2 — build-up continuation is positive.** Following a 1.5-sigma fifteen-minute move while the
  open interest rose by at least 1 % (`O2-T1-K1.5|both|E1|Y3`) makes **+11.4 bp on 316 trades** (LB90
  +3.0 bp, PF 1.27, win rate 42 %, positive without its best day and at fees × 1.5), above the
  price-matched control (median +6.4, p95 +9.7): it passes seven gates and fails G5 — 11 of the 20
  pairs are positive (55 % < 70 %). With a 2 % build-up (`O2-T2-K1.5`) the rows make **+38.6 bp on 65
  trades** (Y3; LB90 +13.7, PF 1.79, 11 of 13 pairs positive) and +58.6 bp on 52 trades (Z3), above
  their controls, and fail only G1 (fewer than 300 trades).
- **What it means.** The open interest adds to the price move: random entries after the same
  1.5-sigma move, at the same cadence and hours, earn less (+6 bp for the 1 % rule, +20 bp for the
  2 % one) than entries where the open interest grew with the move. It is a training result on four
  2024 weeks, with the effect concentrated in April and May and in a handful of pairs (SUI, TIA,
  LINK, INJ); it needs the unread windows to mean anything. A frozen follow-up on October and
  November 2024 is proposed to the owner, not launched.

Programme total since 2026-10-12: fourteen pre-registered protocols (A, r1 twice, r2–r13, r8b),
1,869 configurations, twenty pairs, plus the exploratory r10x. October and November 2024 stay unread
for the ten mid-caps. The forward OKX test is unaffected.

## Why

On 2026-10-13 (~17:20Z) the owner gave the go for a new signal family "of another nature than the
platform's setups". Every family tested since 2026-10-12 read the price path, the prints, the book,
the best quotes or the platform's candle indicators. The open interest — how many contracts are
open — is in none of them. Its changes separate a move made by closing positions (often forced: a
flush, expected to revert) from a move made by opening positions (a build-up, expected to continue).
It is also executable by the platform: the cadence is five minutes and OKX publishes the open
interest of its swaps in real time.

Pre-registration `tradingV3-private/research/20261013T190000Z-oi-positioning-r13/PREREGISTRATION-r13.md`,
frozen 2026-10-13T17:31:12Z (body sha256 `c80ffb2c…`) before any metrics archive of the training days
and before any October or November 2024 archive was downloaded; one metrics file of a training day
was opened before the freeze to confirm the layout. The code (metrics archive kind, positioning
features, the O rules, the price-matched control, the pair-level series check) was written and
tested on synthetic data before the freeze (commit `fd1c0a79`,
[runbook](../runbooks/research-execution-study.md)).

## Protocol

- **Data.** Binance USD-M daily `metrics` archives (one snapshot every five minutes; only
  `sum_open_interest` is used), sha256-checked, converted onto the 5-minute grid: 400 files for the
  training days (6.7 MB; no missing step, no duplicate). Prints of the r10 tape (`w2404`, `w2405`,
  `w2406`, `w2408`; twenty pairs; 80 pair-week series), read before by r10–r12 for other families.
- **Features.** A snapshot stamped `T` is usable from `T + 60` s (the decision second); `oi_chg_15` is
  the change of the open interest between `T − 900` and `T`; the price move is the log change of the
  mid over the same window, against `sigma_900` at `T`.
- **Rules.** `O1-T{θ}-K1.5`: open interest down by θ % and a move of at least 1.5 sigma against the
  stream (the long stream buys after a fall). `O2-T{θ}-K1.5`: open interest up by θ % and a move of
  at least 1.5 sigma with the stream. θ ∈ {1, 2}. Both streams, one position at a time per stream.
  Entry E1 at the touch (250 ms, through rule, 2 bp maker, 30 s TTL). Exits **Z3** (passive exit
  posted 60 minutes after the fill, taker fallback at 65) and **Y3** (stop 1.5 × sigma_300, maker
  target 3 × sigma_300, taker after one hour). **Eight configurations.**
- **Control.** Price-matched random timing: random decision seconds of the same metrics cadence,
  in the same hours, among those where the same price condition holds for the stream, whatever
  the open interest did; same entry and exit; 10 seeds. It removes only the open-interest
  information.
- **Gates.** The r8 training gates, values unchanged, with one series per pair (the owner's
  convention of r11): G1 ≥ 300 trades, G2 mean ≥ +2 bp and LB90 > 0, G3 PF ≥ 1.15, G4 above the control
  p95 and 3 bp above its median, G5 ≥ 70 % of the pairs positive, G6 positive without the best day,
  G7 positive at fees × 1.5, G8 fill rate ≥ 20 %. Selection: every gate. Validation (October 2024,
  ten mid-caps, one series per pair) and holdout (November 2024) with the r8 checks plus net > 0 at
  fees × 1.5, each read once, only for selected configurations.

## Training: 0 of 8 pass every gate

`part-b/binance-r13/screen-training.json` (80 series, 8 configurations, 142 s, 3 workers, code
`fd1c0a79`; the package hashes recorded equal the commit; the tree carried the runbook edit at the
end). `control` is the median / p95 of the ten price-matched controls; `pairs+` the share of pairs
with trades that are positive.

| Configuration | Decisions | Trades | Fill | Gross | Net | Median | LB90 | PF | Win | pairs+ | control med / p95 | Gates failed |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| O1-T1, Y3 | 622 | 455 | 87 % | −11.6 | −18.1 | −51.6 | −23.9 | 0.64 | 30 % | 15 % (20) | −15.0 / −10.3 | G2–G7 |
| O1-T1, Z3 | 622 | 351 | 88 % | −19.3 | −23.5 | −17.1 | −34.8 | 0.67 | 45 % | 20 % (20) | −18.1 / −12.9 | G2–G7 |
| O1-T2, Y3 | 92 | 70 | 93 % | −31.6 | −38.4 | −68.9 | −53.0 | 0.40 | 23 % | 32 % (19) | −18.8 / −14.6 | G1–G7 |
| O1-T2, Z3 | 92 | 56 | 97 % | −23.2 | −27.3 | −24.6 | −51.7 | 0.60 | 43 % | 42 % (19) | −9.3 / +9.7 | G1–G7 |
| **O2-T1, Y3** | 515 | **316** | 83 % | +17.4 | **+11.4** | −38.5 | **+3.0** | 1.27 | 42 % | 55 % (20) | +6.4 / +9.7 | **G5 only** |
| O2-T1, Z3 | 515 | 271 | 82 % | +5.0 | +0.7 | −9.3 | −12.9 | 1.01 | 47 % | 50 % (20) | +9.1 / +26.6 | G1–G7 |
| **O2-T2, Y3** | 117 | **65** | 76 % | +44.3 | **+38.6** | +51.2 | **+13.7** | 1.79 | 52 % | 85 % (13) | +20.1 / +34.4 | **G1 only** |
| **O2-T2, Z3** | 117 | **52** | 73 % | +62.9 | **+58.6** | +20.6 | **+15.7** | 1.90 | 52 % | 77 % (13) | +28.2 / +50.7 | **G1 only** |

- **O1 by stream and group.** Both streams lose (T1-Y3: long −15.0 bp on 260 trades, short −22.1 on
  195); mid-caps lose more than majors (−24.7 against −10.4).
- **O2-T1-Y3 in detail** (316 trades, median hold 17 minutes): long +15.2 bp on 193, short +5.4 on
  123; mid-caps +13.2 on 202, majors +8.0 on 114; April +22.1 (125 trades), May +10.4 (80), June 0.0
  (50), August −0.1 (61); the best day carries 40 % of the total; positive pairs SUI, TIA, LINK, SOL,
  INJ, NEAR, BTC, ATOM, FIL, DOGE, APT, negative SEI (−421 bp on 44 trades), LTC, XRP, AVAX, BNB,
  ADA, OP, ETH, ARB. At 500 USDT and 1×: 0.79 trade a day, +0.45 USDT a day on the training windows.
- **O2-T2 in detail.** Y3: every window positive (April +52.0, May +20.4, June +17.9, August
  +58.1), long +37.8 on 49, short +41.2 on 16, best day 32 % of the total, SEI and NEAR the two
  losing pairs. Z3: long +97.3 on 37, short −37.1 on 15, best day 21 % of the total.
- **Reading.** The controls of the O2 rows are themselves positive: on these windows, following a
  1.5-sigma fifteen-minute move with these exits pays a few basis points (more in the hours where
  the 2 % build-ups happened). The open-interest condition adds about 5 bp (1 % rule) and 18–30 bp
  (2 % rule) to that. The 1 % rule fails pair consistency; the 2 % rule is consistent across pairs
  but too sparse (0.16 trade per pair-day) for the 300-trade gate on four weeks.

## Validation and holdout: not run

No configuration passes every gate, so by the pre-registered stop rule the validation (the ten
mid-caps, October 2024) and the holdout (November 2024) are not acquired and not read
(`run-validate.log`: "no training survivor: validation not run (October unread)").

## Proposed follow-up (not launched)

The O2 build-up continuation is a post-hoc lead on read windows. A frozen pre-registration on the
unread windows would test it: the two Y3 rows pre-declared (`O2-T1-K1.5` and `O2-T2-K1.5`, both
streams), the price-matched control, the r8 validation checks plus fees × 1.5, one series per pair.
The open question is data volume: on the ten mid-caps the 1 % rule makes about one trade per
pair-day (≈ 300 a month) and the 2 % rule about 0.2 (≈ 70 a month, under the 100-trade check).
Options are October 2024 as validation and November as holdout (the 2 % rule likely fails on count),
or both months as validation with an unread later window as holdout. The choice and the go are the
owner's.

## Limits

- Four training weeks of 2024 on twenty pairs, read before by other families; eight
  configurations; the effect is concentrated in two windows and a handful of pairs.
- The 5-minute snapshots and the 60-second availability lag are a coarse view of the open
  interest; the platform would read it in real time. Quotes are proxied from prints; funding is
  not modelled (holds of at most 65 minutes).
- Nothing beyond the training metrics was downloaded; no file of October or November 2024 was read;
  the forward OKX test is untouched.
