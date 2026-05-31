# OKX Adapter

API-first OKX demo integration slice for SWAP instruments.

- Official docs used:
  - https://www.okx.com/docs-v5/en/#overview-demo-trading-services
  - https://www.okx.com/docs-v5/en/#rest-api-trade-place-order
  - https://www.okx.com/docs-v5/en/#rest-api-trade-place-algo-order
  - https://www.okx.com/docs-v5/en/#rest-api-authentication-signature
- Defaults to demo. Live requires `OKX_ENV=live` and `OKX_LIVE_ENABLED=1`.
- Demo order submission also requires `OKX_DEMO_TRADING_ENABLED=1`; private reads only require credentials.
- REST private calls are signed with `OK-ACCESS-*` headers. Demo calls include `x-simulated-trading: 1`.
- Internal symbols are normalized as SWAP instruments, for example `BTCUSDT` to `BTC-USDT-SWAP`.
- Entry orders use `/api/v5/trade/order`; standalone stop-loss/take-profit protection uses `/api/v5/trade/order-algo` conditional orders.
- WebSocket private payload normalization is available for `orders`, `fills`, and `positions` messages. A real OKX private WS client is not implemented yet, so the adapter still does not advertise private WS support.

Environment:

```dotenv
OKX_ENV=demo
OKX_API_KEY=
OKX_API_SECRET=
OKX_API_PASSPHRASE=
OKX_API_BASE_URI=https://www.okx.com
OKX_WS_PUBLIC_URI=wss://ws.okx.com:8443/ws/v5/public
OKX_WS_PRIVATE_URI=wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999
OKX_DEMO_TRADING_ENABLED=0
OKX_LIVE_ENABLED=0
```

Local safety checks:

```bash
./vendor/bin/phpunit tests/Exchange/Okx tests/Exchange/Adapter/OkxExchangeAdapterTest.php tests/Exchange/Contract/OkxExchangeAdapterContractTest.php
```
