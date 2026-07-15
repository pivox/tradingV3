# Fake Exchange Adapter

`FakeExchangeAdapter` is a local exchange implementation for API-first tests. It is registered in the exchange adapter registry as `fake/perpetual` and never calls external networks.

Supported scenarios:

- place a maker limit order and fill it later with `FakeExchangeScenarioService::movePrice()`;
- place a market order and fill it immediately;
- partially fill and then complete an order with `fillOrder()`;
- create local positions after entry fills;
- create or reject attached SL/TP protection orders;
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
