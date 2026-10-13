# Plan — forward test of the depth-supported long reversion on OKX (2026-10-13)

## Decision

After the book families closed on Binance archives
([report](../../handbook/reports/research-book-families-2026-10-13.md)), the owner chose on
2026-10-13 ~10:30Z to give the one surviving lead a forward test on OKX ("fais le 2"), and to
keep two other items for later, to be resumed only on an explicit no-go of this test:

1. a scalping setup version 1.2.0 with cost-attacking geometry (volatility-to-cost gate, wider
   target or time exit, maker take-profit);
3. the vocabulary change (the project's `scalping` is intraday elsewhere; its `micro_scalping`
   is what the web calls scalping).

The lead: `Q3-W900-K1.5-T0.1|long|E1|Z3`, a long reversion to the 15-minute EMA with
confirming flow and bid-side depth, passive entry, passive 60-minute exit. Positive mean on
three independent sets of Binance windows, beats random timing in each, failed the
pair-consistency check once (9 of 20 pairs in December 2024).

## What the forward test is, and is not

- **Is:** the frozen rule replayed on OKX public data captured from 2026-10-13 on, two judged
  weeks (2026-10-19..23 and 2026-10-26..30) with the r8 validation checks including the pair
  check that failed. Pre-registered and frozen before the capture started
  (`tradingV3-private/research/20261013T100000Z-forward-okx-r1/PREREGISTRATION-forward-okx-r1.md`).
- **Is not:** the OKX demo test. The rule works on one-second bars with a passive hourly exit;
  the platform's minute loop cannot execute it, and the demo flags stay at 0. A pass makes the
  dedicated execution path the next decision.

## Steps

1. **Tooling** — `scripts/research_exec/forward_okx.py` (capture into the research tape layout),
   protocol `fwd-okx-r1` in `screen.py`, tests `tests/test_research_exec_forward.py`
   ([runbook](../../handbook/runbooks/research-forward-okx.md)). **Done 2026-10-13.**
2. **Pre-registration frozen, capture started** (detached restart loop, 20 pairs, depth every
   30 s), **evaluation loop started** (replays each window once its days are final).
   **Done 2026-10-13.**
3. **W0 shakedown (2026-10-14..16):** check bars, features, fills, trades per pair-day, costs;
   fix capture or replay defects in code (disclosed); the rule is not touched.
4. **W1 (2026-10-19..23), read once when complete** → interim result in the report.
5. **W2 (2026-10-26..30), read once when complete** → final verdict: candidate if W1 and W2
   pass; otherwise the lead closes on OKX at this horizon.
6. **Report** `docs/handbook/reports/research-forward-okx-2026-10.md`, with the Binance rows
   beside the OKX rows and the budgets.
7. **If candidate:** design the dedicated execution path (second-level loop, passive orders,
   per-pair position, depth feed) for the OKX demo; owner's go on the flags remains separate.
   **If not:** resume items 1 and 3 above on the owner's explicit no-go.

## 2026-10-13 ~12:00Z: item 1 launched in parallel (owner's order)

The owner lifted the "only after a no-go" condition for item 1 and ordered it in parallel with
the forward test, under five conditions: the forward test untouched (capture, `forward_okx.py`,
protocol, evaluation, W1/W2 unread before their planned reading); a separate branch and
worktree; a frozen pre-registration before any validation or holdout data is read, with the
programme's gates, controls and budgets from 500 USDT; validation and holdout on windows never
read for the pairs concerned, or the forward weeks with the rule frozen first; at most 2–3
workers with the capture kept first. Item 3 (vocabulary) stays pending.

Done as protocol **r10** (`tradingV3-private/research/20261013T121000Z-scalping-120-r10/PREREGISTRATION-r10.md`,
frozen 2026-10-13T12:15:50Z, sha256 `fa24489d…`; worktree `.worktrees/research-r10`, branch
`claude/research-scalping-120`): the three scalping setups 1.1.0 ported to the tick screen and
replayed at the 5-minute cadence on 2024 Binance prints and klines with the 1.2.0 geometries
(cost gate, 1.8 R / 3 R maker target, time exit); training on twenty pairs (April, May, June,
August 2024), validation and holdout on the ten mid-caps (September, November 2024: the only
pairs whose 2024 months are unread at every level); the majors' out-of-sample check is track F
on the forward weeks W1 and W2, after their planned readings, and conditions any 1.2.0 contract
publication ([report](../../handbook/reports/research-scalping-120-2026-10-13.md)).

**Outcome (2026-10-13, 12:36Z): 0 of 96 pass training.** The full 1.1.0 stacks decide 17–208
times in 400 pair-days (12–166 trades, −13 to −37 bp); the core stacks equal random timing
(gross +1 to −5 bp before fees); the maker take-profit saves about 3 bp, the time exit 2–4 bp,
the cost gate nothing. No validation, no holdout, no track F, no 1.2.0 contract. Item 1 closes
negative; item 3 (vocabulary) stays pending; the forward test continues.

**Exploratory re-read r10x (owner's request, 2026-10-13 ~13:25Z; same windows, no selection):**
the volume-confirmation threshold (1.1 to 1.4) rescues nothing; the 1.1.0 entry zone isolates
gross-positive decisions in the three setups (2–5 % of them), +30, +23 and +9 bp net with the
time exit on 36, 64 and 223 trades, positive but not clearly (median −42 bp, LB90 below zero,
below the control p95 for the one row with 200 trades or more). A frozen pre-registration
"zone + time exit" (zone-restricted control, mid-cap validation September 2024, holdout November
2024) is proposed to the owner, not launched. The forward test is untouched.

## Constraints

- The research tape is a research artifact (≈ 50 MB/day), deletable after the report; the
  frozen run14/run15 captures are not touched.
- The host has 4 CPUs; the capture is light (one process, ~330 WebSocket messages per second);
  replays use 2 workers.
- Nothing here places an order or reads a private endpoint.
