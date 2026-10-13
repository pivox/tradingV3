# Forward test on OKX — depth-supported long reversion (fwd-okx-r1, October 2026)

## Status

**In progress.** Capture started 2026-10-13; W0 shakedown 2026-10-14..16; W1 2026-10-19..23;
W2 2026-10-26..30. Each window is replayed once when its twenty symbol-days are final, and its
row appears below as it comes. Pre-registration
`tradingV3-private/research/20261013T100000Z-forward-okx-r1/PREREGISTRATION-forward-okx-r1.md`,
frozen 2026-10-13 before the capture started ([runbook](../runbooks/research-forward-okx.md),
plan `docs/superpowers/plans/2026-10-13-research-forward-okx.md`).

## Why

The book families closed on Binance archives with one lead
([report](research-book-families-2026-10-13.md)): `Q3-W900-K1.5-T0.1|long|E1|Z3`, a long
reversion to the 15-minute EMA at 1.5 σ with confirming taker flow and bid-side depth share
within 1 % above 0.1, passive entry at the touch, passive exit at 60 minutes. It kept a positive
mean on three independent sets of windows and beat regime-matched random timing in each; it
failed the pair-consistency check once (9 pairs of 20 in December 2024). Two unknowns remain:
the OKX book, and data that did not exist when the rule was chosen. The forward test answers
both without any exchange mutation.

## Rule and checks (frozen)

The r8 definition, unchanged: one-second bars from OKX top of book and prints, `band_dev_900`,
`sigma_900`, `fi_60`, `di_1` from the full book every 30 s (truncated at 5,000 levels per side:
±0.8 % on BTC, ±2.1 % on ETH, full on the other eighteen pairs); entry E1 (passive at the bid,
250 ms latency, 30 s TTL, through rule); exit Z3 (passive at the ask at 3,600 s, 300 s TTL, taker
at 3,900 s); fees 2 / 5 bp; controls with 10 seeds. Checks per judged window on the twenty
series: trades ≥ 100, mean net > 0, PF ≥ 1.05, above the control median, ≥ 50 % of pairs
positive. Candidate = W1 and W2 pass.

## Results

| Venue | Window | Phase | Trades | Fill | Mean net bp | PF | LB90 | Control median | Pairs positive | Checks |
|---|---|---|---:|---:|---:|---:|---:|---:|---:|---|
| Binance | training, nine 2025 weeks | r8 | 1,130 | 75 % | +12.7 | 1.40 | +8.6 | −16.1 | 59 % of 167 series | one gate short (G5) |
| Binance | 2025-01-13..17 | r8b validation | 151 | 77 % | +18.6 | 1.62 | +7.0 | +5.5 | 14 of 20 | pass |
| Binance | 2024-12-09..13 | r8b holdout | 155 | 84 % | +6.5 | 1.13 | −7.1 | −26.4 | 9 of 20 | fails the pair check |
| OKX | W0 2026-10-14..16 | shakedown | — | — | — | — | — | — | — | counts for nothing |
| OKX | W1 2026-10-19..23 | validation | — | — | — | — | — | — | — | pending |
| OKX | W2 2026-10-26..30 | holdout | — | — | — | — | — | — | — | pending |

## Capture quality

To be filled from `tape/manifest-forward.json` at each window: symbol-days final, disconnects
and recovered prints, REST failures, minimum depth coverage per pair, seconds without quotes.

## Budgets

To be filled from `part-b/fwd-okx-r1/budgets-*.json` (500 USDT and up, one position per pair,
1× and 2×, fees per round trip, projected net per day at the window's mean).

## Limits

- OKX only; Hyperliquid has no comparable public depth feed in the capture.
- The depth band of BTC is ±0.8 % instead of ±1 % (endpoint truncation), disclosed in advance.
- Two weeks of one regime; the Binance record spans two years. A pass is a necessary step
  toward an execution path, not a proof of persistence.
- Fills are simulated with the through rule on the venue's own prints; no order is placed.
