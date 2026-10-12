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

## Boundaries

No exchange mutation, no flag change, no Docker restart, no rerun of the consumed holdout claim.
Cleanup never touches the run14/run15 captures, the shared databases, the campaign's frozen
evidence, the datasets or the research runtimes.
