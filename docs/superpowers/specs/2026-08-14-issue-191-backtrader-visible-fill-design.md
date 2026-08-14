# #191 Backtrader visible-fill integration design

## Scope

Bind the deterministic `visible-queue-depletion-result.v1` maker evidence to
the canonical Backtrader runtime. This lot executes complete public-tape fills
and preserves explicit non-fills. It does not infer a queue, use OHLCV volume,
or prorate plan-authoritative costs in Python.

## Version boundary

- Historical `canonical-backtest-order-plan.v1` keeps the OHLCV-touch runtime.
- `canonical-backtest-order-plan.v2` requires one revalidated visible-queue
  result with the same dataset, plan, config, market and symbol identities.
- Cross-version evidence is rejected. The runtime result becomes v2 only for
  the public-tape path and hash-binds the queue result and its three tape
  checksums.

## Execution semantics

- `unfilled` produces `not_executed` with a stable public-queue reason and no
  synthetic entry event.
- An atomic `filled` trace creates the entry at the canonical limit price and
  at the fill availability time. The source is that authenticated public trade
  record.
- The bar containing the fill is ambiguous: a touched stop is charged as the
  conservative bound, while a target touch alone is never credited. Normal
  stop/target evaluation then starts with bars whose complete interval begins
  at or after the fill availability time.
- `partially_filled`, including a final full quantity accumulated through
  multiple positive fills, fails closed until PHP exposes plan-bound costs and
  risk for each executed quantity. Python must not scale the full-plan cost
  branch itself or ignore exposure between staged fills.

The stop remains canonical and immediately attached logically to the entry.
Maker-to-taker fallback remains forbidden and mainnet access remains public and
read-only.

## Verification

Tests cover v1 compatibility, missing/mismatched/tampered evidence, unfilled,
full fill, partial-fill rejection, the ambiguous fill-bar stop bound,
deterministic bytes and net-outcome replay against the authenticated entry
source plus OHLCV exit.
