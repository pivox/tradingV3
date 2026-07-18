# Fake/Paper One-Way Conflict Guard v1 Design

## Scope

This lot implements Prompt 5 of issue #196: deterministic One-Way enforcement
for Fake/Paper and executable golden scenario 19. It does not change strategy,
MTF, EntryZone, frequency, sizing, leverage, fees, funding, liquidation, or any
demo/testnet/mainnet write gate.

The only supported runtime scope remains `fake + perpetual`. Within that
runtime, One-Way identity is the exact tuple:

```text
exchange + market_type + uppercase(symbol)
```

There is no hedge mode, implicit netting, exchange fallback, or inferred
`positionSide`.

## Audited Existing Flow and Root Cause

`FakeExchangeMatchingEngine::submit()` currently validates request context and
the explicit `positionSide`, resolves an idempotent `clientOrderId` replay,
reads the order book and available margin, validates the order, then persists
and fills it. `FakeExchangeStateStore` persists positions under
`symbol + positionSide`, so both LONG and SHORT records can coexist. No step
compares a non-reduce-only entry with the opposite position or active entry
order.

`PlaceOrderRequest::positionSide` is non-null. `assertRequestIntent()` already
requires BUY for LONG entry, SELL for SHORT entry, SELL to reduce LONG, and BUY
to reduce SHORT. The new guard therefore consumes this explicit enum and never
derives position side from BUY/SELL.

Orders and positions themselves carry exchange and market type. The guard will
filter those fields as well as the normalized symbol, rather than relying on
the state store's legacy in-memory index shape. This preserves restart
compatibility with the existing `fake-paper-state-v1` envelope while enforcing
the full canonical One-Way scope.

## Considered Approaches

### Guard in providers or the exchange adapter

This would reject normal API calls but leave direct matching-engine paths,
scenario services, fallback children, and tests able to bypass the policy.
Rejected because the guard would not be central.

### Add mutable state to `FakeOrderValidator`

This would place the check near validation, but the validator is currently a
pure instrument/margin validator. It would also run only after available margin
has been calculated. Rejected because it mixes responsibilities and violates
the required ordering.

### Guard in the matching engine before margin validation

Selected. A focused `FakeOneWayConflictGuard` evaluates current persisted state
after exact replay resolution and before order-book/margin work. Every Fake
order path ultimately uses the matching engine, so the policy has one
enforcement point and remains inside the adapter's existing atomic state
transaction.

## Conflict Contract

For a non-reduce-only entry, the guard checks only records matching the exact
exchange, market type, and uppercase symbol:

1. an open position with the opposite `positionSide` conflicts;
2. an active, non-reduce-only entry order with the opposite `positionSide`
   conflicts;
3. a persisted active non-reduce-only order without `positionSide` fails
   closed as ambiguous instead of enabling hedge fallback;
4. same-side positions and entry orders may be increased;
5. other symbols and other exact scopes are independent.

Reduce-only exits and standalone reduce-only protection orders bypass the
entry-conflict check. They still follow the existing validation and fill rules,
including exact target `positionSide`. Once the prior position is closed and
incompatible active entries are absent, an opposite entry is accepted.

The stable rejection reason is:

```text
one_way_position_conflict
```

Derived rejection metadata contains only the mode version, canonical scope,
requested position side, conflict source (`open_position`, `active_order`, or
`ambiguous_active_order`), and conflicting side when known. It contains no raw
request, client payload, credential, header, URL, or secret.

## Ordering, Persistence, Replay, and Restart

Exact `clientOrderId` replay remains first. A prior rejection returns the same
rejected order with `idempotent_replay=true` and creates no new order or event;
a mismatched intent keeps the existing stable duplicate-intent rejection.

For a new intent, One-Way evaluation precedes order-book lookup, available
margin calculation, margin validation, accepted-order persistence, and fill.
A conflict creates only the existing rejected-order audit record plus one
`order.rejected` event. It creates no active order, reserves no margin, and
changes no exposure. Those records are already persisted in the checksummed
Fake/Paper state envelope, so restart retains both the conflict evidence and
the position/order state that caused it.

## Tests and Golden Scenario 19

Focused engine tests cover LONG then SHORT rejection, SHORT then LONG
rejection, reduce-only exit, opposite entry after closure, active opposing
entry without a position, exact rejection replay, restart/replay, and symbol
independence. Assertions include unchanged exposure and available margin, one
rejected record/event, stable reason, redacted metadata, and no active
conflicting order.

Golden scenario 19 runs the representative long-position conflict, replay,
restart, reduce-only closure, opposite entry after flat, active-order conflict,
and independent-symbol cases twice from fresh controlled state. Its catalogue
row changes from `unsupported` to `executable`; no gap is reported as complete
without the runner passing.

## Safety and Rollback

The change is local Fake/Paper logic and documentation only. It reads no
credential, contacts no exchange endpoint, sends no demo/testnet/live order,
and leaves `mainnet_write_enabled=false`.

Rollback removes the guard call/class/tests, restores scenario 19 to
`unsupported` with `one_way_conflict_guard_not_implemented`, and reverts the
documentation. No schema or state-envelope migration is required; rejected
orders and events remain ordinary auditable v1 records.
