# Plan — research cycle v2 after the swing holdout (2026-10-12)

## Mandate

Written by the owner on 2026-10-12 00:30, before the holdout result was known, for a day of
autonomous work: if the swing holdout is not good, restart candidate selection from zero as an
expert trader (every indicator family, proper setups); if it is good, advance; if nothing is
good after that, explore scalping and micro-scalping; every simulation also reports several
budgets from 500 USDT; cleanup is allowed when disk or memory becomes a problem.

Decision rule fixed before the result: "good" = holdout `complete` and, under both cost
profiles, net > 0, mean net R > 0, PF ≥ 1.1 baseline / ≥ 1.0 adverse, at least 50 trades,
drawdown ≤ 6,000 USDT.

## Verdict on the swing candidate

The holdout closed `complete` at 02:12 with PF 1.01 (baseline) and 0.97 (adverse): **not
good**. The candidate is closed ([report](../../handbook/reports/research-swing-campaign-2026-10-12.md)).
The 2026 window has been used once.

## Cycle v2

1. Pre-registration frozen before any PnL computation
   (`tradingV3-private/research/20261012T003000Z-explore-v2/PREREGISTRATION.md`, sha256
   `4e6fdb79…`): 24 setups in four families (trend and momentum, mean reversion, volatility,
   multi-timeframe and structure), both sides, 15m and 4h cadences, pre-specified exits, 436
   configurations, regime-matched random controls, gates G1–G7, at most three survivors per
   family and cadence, one validation pass on 2025, budgets 500 → 100,000 USDT.
2. Tooling: `python-orchestrator/scripts/research_v2/` ([runbook](../../handbook/runbooks/research-v2-screen.md)).
3. Training screen, then the second pass (E filters on the survivors), then validation once.
   **Done 2026-10-12 03:30:** 8 of 436 pass training (all 4h long trend-following); the four
   selected variants all lose on 2025; outcome `no_eligible_candidate`
   ([report](../../handbook/reports/research-v2-selection-2026-10-12.md)).
4. Report in the handbook. Survivors, if any, need kernel and contract work before a canonical
   campaign (15m cadence, trailing and indicator exits are not in the swing kernel profile),
   and any holdout on 2026 would be its second use. **No survivor: nothing to transfer.**
5. If nothing survives: scalping and micro-scalping on 1-minute data under their own
   pre-registration, with explicit fill and queue caveats. **Done 2026-10-12 04:10:**
   pre-registration frozen (`PREREGISTRATION-scalping.md`), 40 configurations screened on
   2023–2024, 0 pass, costs dominate, no validation run per the stop rule
   ([report](../../handbook/reports/research-scalping-2026-10-12.md)). The mandate's three
   branches are closed; next steps need the owner's decision (live public-data observation for
   execution edges, or a different research scope).

## Morning of 2026-10-12: the objective is restated

The owner's correction (~06:00Z): a negative screen is not a finished mission; the standing
objective is a scalping / micro-scalping candidate, to be looked for on data that shows maker
fills; items in order: (1) execution study on the OKX / Hyperliquid captures, (2) OKX demo
checklist, (3) wider-universe data sizing, then scalping resumed on tick data; a candidate is
due by 18:00 local (16:00Z).

1. Pre-registration frozen at 06:18:05Z (`tradingV3-private/research/20261012T060000Z-execution-r1/PREREGISTRATION.md`,
   sha256 `a8feedc4…`): part A (passive-order mark-outs) and part B (tick-data scalping screen,
   six families, maker entries, exits X1–X4, controls, gates, selection, one validation).
2. Part A done 06:18Z: no unconditional maker edge on either venue; a passive fill costs
   about 3.1–3.5 bp (fee + adverse selection) against 5 bp for a taker entry
   ([report](../../handbook/reports/research-execution-tick-2026-10-12.md)).
3. Binance aggTrades tape acquired 06:14–06:33Z (10 pairs × 15 weekdays of 2025, 131 M prints,
   every checksum verified) — [sizing note](../../handbook/reports/research-universe-sizing-2026-10-12.md).
4. Part B: captures training screen 06:31–06:52Z (368 configurations): **0 pass**; Binance
   tape r1 (304): **0 pass** (07:33–07:47Z); r2 (intraday holds, 152, pre-registered
   06:54Z): **0 pass** (07:28–07:33Z); r3 (focused reversion families on four training
   windows, 64, pre-registered 07:36Z): 2 training passes, both fail the November
   validation (long band reversion, −1.8 / −5.3 bp while the control loses −22 bp); r4
   (two-sided band reversion, 4, pre-registered 08:00Z): the 2 σ passive-exit version passes
   seven gates of eight and fails the trade minimum (110 < 200); r5 (that single
   configuration on nine training windows, pre-registered 08:05Z): **0 pass** (+2.5 bp on
   260 trades; the four windows r3/r4 had seen were the four best of nine). **No candidate**;
   November and December unread by r4/r5. Report in the handbook; PR #478.
5. Item 2 (OKX demo checklist): runbook from the repository's gates and commands, no flag
   changed.

## 2026-10-13: mid-caps (r6, r7)

Owner's "go" at ~02:15Z. The running Docker stack was found on the main checkout of
2026-10-07 without the exchange environment (demo impossible as is; noted in the checklist).
Research continued on the one untested hypothesis, the fee hurdle: ten mid-cap perpetuals,
550 tape files acquired and audited, r6 (200 configurations, nine windows): 0 pass, one
configuration short of one gate; r7 (that configuration out of sample): November +12.8 bp on
206 trades, December −20.9 bp on 145 — **no candidate**
([report](../../handbook/reports/research-midcap-tick-2026-10-13.md), PR #480).

## Boundaries

No exchange mutation, no flag change, no Docker restart, no rerun of the consumed holdout claim.
Cleanup never touches the run14/run15 captures, the shared databases, the campaign's frozen
evidence, the datasets or the research runtimes.
