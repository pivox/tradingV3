# OKX Adapter

API-first OKX demo integration slice for SWAP instruments.

- Official docs used:
  - https://www.okx.com/docs-v5/en/#overview-demo-trading-services
  - https://www.okx.com/docs-v5/en/#rest-api-public-data-get-instruments
  - https://www.okx.com/docs-v5/en/#rest-api-market-data-get-candlesticks
  - https://www.okx.com/docs-v5/en/#rest-api-market-data-get-ticker
  - https://www.okx.com/docs-v5/en/#rest-api-market-data-get-order-book
  - https://www.okx.com/docs-v5/en/#rest-api-trade-place-order
  - https://www.okx.com/docs-v5/en/#rest-api-trade-place-algo-order
  - https://www.okx.com/docs-v5/en/#rest-api-authentication-signature
- Defaults to demo. Live requires `OKX_ENV=live` and `OKX_LIVE_ENABLED=1`.
- Demo private reads require dedicated demo credentials and `OKX_SIMULATED_TRADING=1`.
- Demo order submission also requires `OKX_DEMO_TRADING_ENABLED=1`; private reads do not require the trading flag.
- Demo public REST defaults to the OKX EEA demo host `https://eea.okx.com`.
- REST private calls are signed with `OK-ACCESS-*` headers. Demo calls include `x-simulated-trading: 1`.
- Internal symbols are normalized as SWAP instruments, for example `BTCUSDT` to `BTC-USDT-SWAP`.
- Provider public read-only coverage is implemented for instruments, ticker, candles and order book.
- Provider private read-only coverage is implemented for account balance, positions, open orders, algo open orders and recent fills.
- Metadata normalization exposes OKX native tick/step/min/max/contract/leverage,
  public funding, and private fee rates without submitting orders.
- Entry orders use `/api/v5/trade/order`; standalone stop-loss/take-profit protection uses `/api/v5/trade/order-algo` conditional orders.
- WebSocket public demo is documented as `wss://wseeapap.okx.com:8443/ws/v5/public`, but OKX-003 keeps runtime market data on REST polling. WebSocket private payload normalization is available for `orders`, `fills`, and `positions` messages. A real OKX private WS client is not implemented yet, so the adapter still does not advertise private WS support.

Metadata mapping:

| TradingV3 | OKX |
|---|---|
| `instrumentId` | `instId` |
| `priceTick` | `tickSz` |
| `quantityStep` | `lotSz` |
| `minSize` | `minSz` |
| `maxSize` | `maxMktSz` then `maxLmtSz` |
| `contractValue` | `ctVal` |
| `contractType` | `ctType` (`linear` required) |
| `contractValueCurrency` | `ctValCcy` |
| `settleCurrency` | `settleCcy` |
| `maxLeverage` | `lever` |
| `makerFeeRate`, `takerFeeRate` | `/api/v5/account/trade-fee` queried with `groupId` when present, otherwise `instFamily` |
| `fundingRate`, `nextFundingTime` | `/api/v5/public/funding-rate` |

Sizing metadata is fail-closed: missing or non-positive required fields raise
`okx_metadata_incomplete`. OKX-005 only accepts `linear` SWAP instruments; inverse
contracts are blocked until their denomination-specific sizing is implemented.
Missing fee or funding values remain `null` with quality flags; they are not
taken as zero. Quantization checks flag `price_precision_mismatch` and
`quantity_rounding_changes_risk`.

Environment:

```dotenv
OKX_ENV=demo
OKX_DEMO_API_KEY=
OKX_DEMO_API_SECRET=
OKX_DEMO_API_PASSPHRASE=
OKX_API_BASE_URI=https://eea.okx.com
OKX_WS_PUBLIC_URI=wss://wseeapap.okx.com:8443/ws/v5/public
OKX_WS_PRIVATE_URI=wss://wspap.okx.com:8443/ws/v5/private?brokerId=9999
OKX_SIMULATED_TRADING=1
OKX_DEMO_TRADING_ENABLED=0
OKX_LIVE_ENABLED=0
```

Local safety checks:

```bash
./vendor/bin/phpunit tests/Exchange/Okx tests/Exchange/Adapter/OkxExchangeAdapterTest.php tests/Exchange/Contract/OkxExchangeAdapterContractTest.php
```
