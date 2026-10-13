# Open-interest build-up continuation on unread windows (r14, 2026-10-13)

## Result

**No candidate: the two pre-declared configurations fail validation on the ten mid-caps over the
whole of October 2024 (2026-10-13, 18:38–18:48Z); the holdout (November 2024) is not read and the
hypothesis closes.**

- The 1 % build-up rule, the real test, makes **−11.7 bp on 165 trades** in October (gross −5.5 bp
  before fees, PF 0.77, 3 of 9 pairs positive), **below** the price-matched random control (median
  −10.0 bp): it fails the net, profit-factor, control, series and fees × 1.5 checks and passes only
  the 100-trade count. Both streams lose (long −11.4 bp on 124 trades, short −12.5 on 41).
- The 2 % build-up rule makes +4.2 bp on 43 trades (LB90 −18.7, PF 1.09, 4 of 8 pairs) and fails the
  100-trade check, as declared before the run; one week (+131.5 bp on 4 trades) carries its sign.
- The +11.4 bp of the r13 training (four 2024 weeks, concentrated in April and May and in four
  pairs) does not hold out of sample: in October the open interest no longer adds anything to the
  price move.

Programme total since 2026-10-12: fifteen pre-registered protocols (A, r1 twice, r2–r14, r8b),
1,871 configurations, twenty pairs, plus the exploratory r10x. October 2024 is now consumed for the
ten mid-caps (two configurations, read once); November 2024 stays unread. The forward OKX test is
unaffected.

## Why

r13 ([report](research-oi-positioning-2026-10-13.md)) tested open-interest positioning at the
5-minute cadence and closed at its training stage, no configuration passing every gate. Its
build-up continuation rows beat the price-matched control — `O2-T1-K1.5|both|E1|Y3` +11.4 bp on
316 trades (LB90 +3.0), failing only the pair-consistency gate, and `O2-T2-K1.5|both|E1|Y3` +38.6 bp
on 65 trades, failing only the trade-count gate. The owner gave the go on 2026-10-13 (~18:25Z) to
the proposed follow-up, with October 2024 as validation and November 2024 as holdout.

Pre-registration `tradingV3-private/research/20261013T183000Z-oi-buildup-r14/PREREGISTRATION-r14.md`,
frozen 2026-10-13T18:38:32Z (body sha256 `dd6859ee…`) before any October or November 2024 archive
was downloaded; the configuration list was written before the freeze; protocol `r14` in
`screen.py` (commit `b7967d0e`, [runbook](../runbooks/research-execution-study.md)).

## Protocol

- **Configurations** (pre-declared, unchanged from r13): `O2-T1-K1.5|both|E1|Y3` and
  `O2-T2-K1.5|both|E1|Y3` — at the 5-minute metrics cadence (a snapshot stamped `T` usable at `T + 60`
  s), the open interest up by at least 1 % (2 %) over fifteen minutes and a price move of at least
  1.5 × sigma_900 over the same window, followed by the stream of that side; entry E1 at the touch
  (250 ms, through rule, 2 bp maker, 30 s TTL); exit Y3 (stop 1.5 × sigma_300, maker target 3 ×
  sigma_300, taker after one hour).
- **Control.** The price-matched random timing of r13 (same cadence, hours and price move, no
  open-interest condition), 10 seeds.
- **Windows.** Validation: the ten mid-caps over the whole of October 2024, one series per pair,
  aggTrades and metrics acquired after the freeze (310 pair-days each; 549 MB; no missing day).
  Holdout: November 2024, only for validated configurations. No training stage: the r13 training
  document is the post-hoc origin, passed to the verdict and counted in the trade total.
- **Checks.** The r8 validation checks — at least 100 trades, net > 0, PF ≥ 1.05, mean above the
  control median, at least 50 % of the pairs positive — plus net > 0 at fees × 1.5. Candidate if and
  only if validation and holdout pass and at least 300 trades over the three phases.
- **Lineage.** October and November 2024 had never been read for the ten mid-caps at any level
  (re-verified before the freeze; r13 acquired nothing for those months).

## Validation (October 2024): 0 of 2 pass

`part-b/binance-r14/screen-validation.json` (10 series of 31 days, 2 configurations, 101 s, 2
workers; the package hashes recorded equal commit `b7967d0e`). `control` is the median / p95 of the
ten price-matched controls.

| Configuration | Decisions | Trades | Fill | Gross | Net | Median | LB90 | PF | Win | pairs+ | control med / p95 | fees × 1.5 | Checks passed |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| O2-T1-K1.5, Y3 | 265 | 165 | 85 % | −5.5 | −11.7 | −52.0 | −21.7 | 0.77 | 38 % | 3 of 9 | −10.0 / −4.7 | negative | trades only |
| O2-T2-K1.5, Y3 | 73 | 43 | 90 % | +10.3 | +4.2 | +0.9 | −18.7 | 1.09 | 51 % | 4 of 8 | −15.4 / +5.0 | positive | all but trades |

- **Density.** The 1 % rule made 0.53 trade per pair-day in October against about one on the
  mid-caps of the r13 training: open-interest build-ups with a 1.5-sigma move were half as frequent.
- **By week (1 % rule):** −20.5 bp (56 trades), −16.5 (39), −10.9 (26), +5.9 (18), +1.8 (26). By
  pair: ATOM, ARB and OP positive on 7, 4 and 19 trades; SUI −9.7 (38), APT −10.4 (39), TIA −17.6
  (22), INJ −18.4 (11), SEI −57.5 (20). SUI, TIA and INJ, three of the four pairs that carried the
  training result, lose in October.
- **Budgets** (`budgets-validation.json`): the 1 % rule at 500 USDT and 1× makes 0.53 trade a day
  and −0.31 USDT a day; the 2 % rule 0.14 trade a day and +0.03 USDT a day.

## Holdout: not run

No configuration passes validation, so by the pre-registered stop rule November 2024 is not
acquired and not read (`run-validate.log`: "validation failed for every configuration: holdout not
run (November unread)").

## Limits

- One month of ten pairs; the 2 % rule is too sparse for the 100-trade check on one month, as
  declared before the run.
- Conventions as r13: 5-minute open-interest snapshots with a 60-second availability lag (the
  platform would read the open interest in real time), quotes proxied from prints, funding not
  modelled.
- The validation tape stays on disk (549 MB) for the owner; the forward OKX test is untouched.
