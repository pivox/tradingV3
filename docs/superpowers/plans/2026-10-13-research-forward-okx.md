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

## Constraints

- The research tape is a research artifact (≈ 50 MB/day), deletable after the report; the
  frozen run14/run15 captures are not touched.
- The host has 4 CPUs; the capture is light (one process, ~330 WebSocket messages per second);
  replays use 2 workers.
- Nothing here places an order or reads a private endpoint.
