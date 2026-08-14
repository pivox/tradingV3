# #191 Canonical market-fallback prohibition design

## Goal

Make the modern maker-to-taker fallback decision explicit and hash-bound across
the PHP canonical OrderPlan, its backtest projection and the Python execution
boundary. The current approved `day_trading@1.1.0` and `scalping@1.1.0`
contracts both require `market_fallback: false`; this lot propagates that
prohibition and does not invent a taker fallback.

## Versioned wire contract

PHP emits `canonical-backtest-order-plan.v2`. Its plan payload contains the
required boolean `marketFallback`, included in `planHash`. The canonical
builder copies the value from the compiled order policy when one exists and
uses the conservative explicit value `false` for older policies that have no
fallback authority. The validator rejects every `true` value.

Python accepts both versions for historical readability:

- v1 requires `marketFallback` to be absent and retains the existing OHLCV
  runtime behavior;
- v2 requires `marketFallback` to be exactly `false`;
- cross-version field mixing fails closed even with a recomputed hash.

The public-tape queue model accepts only v2 with explicit false. It rejects v1
as `visible_queue_depletion_fallback_policy_missing`, because absence is not
authorization. A partially filled maker order therefore remains partial until
its authenticated deadline and is never converted to market implicitly.

## Boundaries

This change does not add a market order, taker cost branch, cancellation side
effect, exchange request, private API, credential or mainnet execution. A
future fallback would require a newly approved mode/setup contract, a new
canonical policy version and a separately reviewed execution model.

## Compatibility and proof

The existing v1 PHP golden remains readable in Python. New projection tests
prove v2 emission, exact false propagation, plan-hash sensitivity, rejection of
true, and v1/v2 cross-field rejection. Queue tests prove v1 cannot enter the
modeled maker-fill boundary and v2 remains deterministic.
