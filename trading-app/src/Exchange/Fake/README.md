# Fake Exchange Adapter

`FakeExchangeAdapter` is a local exchange implementation for API-first tests. It is registered in the exchange adapter registry as `fake/perpetual` and never calls external networks.

Supported scenarios:

- place a maker limit order and fill it later with `FakeExchangeScenarioService::movePrice()`;
- place a market order and fill it immediately;
- partially fill and then complete an order with `fillOrder()`;
- create local positions after entry fills;
- create or reject attached SL/TP protection orders;
- close a fully filled entry with an immediate reduce-only market order when
  attached protection is rejected;
- cancel by exchange order id or client order id;
- replay the same active `clientOrderId` without creating a second active order;
- inspect simulated events such as `order.created`, `order.filled`, `position.opened`, `protection_order.created`, and `protection_order.rejected`.

Useful local endpoints:

```bash
curl -X POST http://localhost:8082/fake-exchange/reset
curl -X POST http://localhost:8082/fake-exchange/orders -H 'Content-Type: application/json' -d '{"symbol":"BTCUSDT","side":"buy","position_side":"long","order_type":"limit","quantity":1,"price":24950,"client_order_id":"demo-1","post_only":true}'
curl -X POST http://localhost:8082/fake-exchange/move-price -H 'Content-Type: application/json' -d '{"symbol":"BTCUSDT","price":24949}'
curl http://localhost:8082/fake-exchange/open-orders?symbol=BTCUSDT
curl http://localhost:8082/fake-exchange/positions?symbol=BTCUSDT
curl http://localhost:8082/fake-exchange/events
```

The HTTP service state is stored in `var/fake_exchange_state.dat` so multi-step curl scenarios survive PHP-FPM request boundaries. Tests can instantiate `FakeExchangeStateStore` without a file path for isolated in-memory state, and should call `reset()` before each scenario when sharing a store.

## Attached-protection fail-safe

When the one-shot scenario fixture rejects attached protection after an entry
has filled, the entry remains recorded as filled and
`protection_status=rejected`. The matching engine then submits a deterministic
market reduce-only order for the failed entry's exact filled quantity through
its normal `submit()` and `fillOrder()` path. The entry metadata records
`fail_safe_action=reduce_only_market_close`, the compensation order identifiers,
`compensation_status=completed`, the compensation quantity, and position sizes
before and after the close.

This sequence preserves normal fill costs, lineage, `order.filled`, and the
ordinary `position.updated` or `position.closed` evidence. Replaying the
original `clientOrderId` returns the same entry and compensation identifiers
without another close. The rejection, compensation, and exposure invariant run
inside the existing state transaction.
A standalone entry becomes flat. If the fill increased an older protected
position, only the failed increase is removed and the residual position must
still have full active stop coverage. A wrong close quantity or unprotected
residual raises an exception and restores the whole local operation. No
credential, raw request payload, or external exchange mutation is involved.

## Private WS disconnect/resync fixture

`FakeExchangeWsClient` can inject one deterministic disconnect after a configured
number of acknowledged raw events. The client then reports
`fake_private_ws_disconnected` with state `resync_required`. A yielded sequence is
acknowledged only after its normalization and projection complete, so a projection
failure leaves that raw event available to the next drain. Numeric sequence gaps
report `fake_private_ws_sequence_gap` and also require resync.

After an injected disconnect, `reconnect()` resumes unacknowledged events. A
sequence gap fails closed: plain reconnect is rejected until the caller reconciles
orders, fills and positions from the Fake REST snapshots through
`ExchangeReconciliationService`, then confirms that snapshot with
`completeSnapshotResync()`. Events covered by that snapshot are not replayed and
the next contiguous sequence is consumed normally. The one-shot disconnect is not
reinjected. This fixture remains local, performs no network request and never
sends an exchange order.

## Persistent recovery contract

The file-backed store writes a versioned `fake-paper-state-v1` envelope containing the engine version, a deterministic scenario configuration hash, a payload checksum, and the next event sequence. Writes use a temporary file followed by an atomic replacement.

On restart, orders, the `client_order_id` index, positions, balances, order books, protection orders, events, and the pending protection-failure fixture are restored together. A legacy unversioned state file is accepted and upgraded on the next write. A present but unreadable, unsupported, or checksum-invalid file raises `FakeExchangeStateCorruptedException`; it is never silently replaced with an empty state.

`FakeExchangeStateStore::recoveryMetadata()` exposes the effective format, engine/config identity, whether the instance restored persisted state, whether that state used the legacy format, and the next event sequence. This is local Paper evidence only and does not certify exchange reconciliation or enable any demo/live write path.

## Deterministic adapter faults

`FakeExchangeScenarioService::failNext()` queues a typed one-shot fault for one
adapter operation. The supported kinds are `network_timeout`, `transport_error`,
`http_429`, and `http_500`. A 429 fixture must provide a positive normalized
`retry_after_seconds`; no raw transport response or request payload is retained.

Faults default to `not_applied` and are consumed before the matching engine or
read store is called. `place_order` and `cancel_order` also support
`applied_response_lost` for timeout/transport failures: the mutation is committed,
then `FakeExchangeInjectedException` reports an ambiguous outcome. Retrying the
same client order or cancel request returns the existing result without a second
order, fill, protection, or cancellation event.

```php
$scenario->failNext(new FakeExchangeFault(
    FakeExchangeOperation::PlaceOrder,
    FakeExchangeFaultKind::NetworkTimeout,
    FakeExchangeFaultOutcome::AppliedResponseLost,
));
```

The queue is FIFO per operation and survives a Paper restart. For an
`applied_response_lost` fixture, the matching mutation and fault removal are
committed in the same atomic state-file replacement. If the operation is a no-op
or fails, the transaction restores the state and keeps the fault queued. No delay
or `sleep` is used: timeout behavior is deterministic and does not contact a
network. Faults run at the adapter boundary, so a `not_applied` fixture may reject
a request before the matching engine performs its normal request validation.

## Runtime check

`app:exchange:runtime-check fake perpetual` evaluates the local Fake adapter
without credentials or network access. The adapter check probes an explicitly
loaded order book, balances, a controlled clock, the fee model, stop-loss
capability, and local persistence/recovery. The persistence probe uses a separate
temporary state file in the same directory and never mutates the active orders,
events, positions, balances, or queued faults. Persistence alone does not promote
the runtime to Paper: a real/replay market source must be configured separately.
When the active state file is absent, the check probes the directory without
creating that file.

The contract is intentionally dry-run only: trade permission is always false,
the kill switch remains active, and no demo/testnet or live write path can be
enabled. Taker fills report a deterministic adverse cost of 5 bps under
`fixed_adverse_slippage_bps_v1`; post-only maker fills report explicit zero.
Execution prices remain at top of book, so the separate additional spread cost
is zero under `top_of_book_embedded_spread_v1`. A missing, zero, malformed, or
unsupported runtime model adds `fake_paper_slippage_model_not_ready`. The
application runtime also reports a non-controlled clock and a missing explicit
market source as blockers. Until an operational Fake provider bundle, versioned
instrument metadata, precision fixtures, and those runtime inputs are available,
`Schedule ready` remains `no`; the adapter must not be presented as a complete
Paper runtime.
