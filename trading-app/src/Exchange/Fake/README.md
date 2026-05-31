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
