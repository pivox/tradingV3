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
- Signed REST calls accept only the exact HTTPS origin for the selected environment:
  `https://eea.okx.com` for demo or `https://www.okx.com` for live. Userinfo,
  explicit ports, paths, queries, fragments, subdomains and look-alike hosts are rejected.
- Internal symbols are normalized as SWAP instruments, for example `BTCUSDT` to `BTC-USDT-SWAP`.
- Provider public read-only coverage is implemented for instruments, ticker, candles and order book.
- Provider private read-only coverage is implemented for account balance, positions, open orders, algo open orders and recent fills.
- Metadata normalization exposes OKX native tick/step/min/max/contract/leverage,
  public funding, and private fee rates without submitting orders.
- Entry orders use `/api/v5/trade/order`; standalone stop-loss/take-profit protection uses `/api/v5/trade/order-algo` conditional orders.
- WebSocket public demo is documented as `wss://wseeapap.okx.com:8443/ws/v5/public`, but OKX-003 keeps runtime market data on REST polling.
- The private demo WebSocket observer is implemented but operationally disabled by default. It is started only through the Compose profile `okx-observability`.

## Private WebSocket observer

`app:okx:private-ws` runs a single read-only observer session. The command checks
the strict demo configuration before `OkxPrivateWebSocketWorker` connects through
the Pawl transport. `OkxPrivateWebSocketSession` owns login, channel
subscriptions, normalized events and readiness state. It never builds, submits,
cancels or amends an order.

The login deadline is 5 seconds. After a successful login, the EEA worker first
sends the subscription command for `orders`, `positions` and
`balance_and_position`, then immediately executes and projects the private REST
snapshot for account, positions, open orders, algo orders and recent fills. It
does not wait for subscription acknowledgements before starting the snapshot.
The EEA API does not expose the private `fills` channel, so fill observability
uses the `orders` stream plus the recent-fills REST snapshot. The status reports
`fills_source=orders_plus_rest` only after that snapshot is complete.

Each private REST read has a 2-second timeout and 2-second maximum duration. The
whole readiness phase is bounded to 10 seconds. Readiness is published only
after the snapshot has been validated, deduplicated, reconciled and projected to
the local order, position and fill stores, and all required subscription
acknowledgements have been received. Conflicting duplicates, missing required
acknowledgements or partial projection fail closed. A complete position snapshot
closes local OKX perpetual positions that are absent remotely. Fill IDs are
instrument-scoped hashes of `instId + tradeId`, so equal provider trade IDs on
different instruments do not collide.

`RedisOkxPrivateWebSocketStatusStore` publishes one bounded JSON document at
`tradingv3:okx:demo:private-observability:v1`. The schema contains only:

- `schema_version`, `exchange`, `environment`, `endpoint_id`;
- `connected`, `authenticated`, `orders_stream_ready`, `fills_stream_ready`,
  `fills_source`, `positions_stream_ready`, `initial_snapshot_loaded`,
  `reconciliation_fresh`, `reconnecting`;
- `connected_at`, `last_heartbeat_at`, `last_event_at`, `observed_at`;
- allow-listed `blocking_errors` and `warnings` codes.

The Redis TTL is 10 seconds and the readiness policy accepts at most 10 seconds
of age for both `observed_at` and the heartbeat. The worker sends a ping every 5
seconds, requires the pong within 4 seconds, and reconnects after
`1/2/4/8/15` seconds, capped at 15 seconds. Connection, auth, subscription,
snapshot, Redis or freshness failures remain fail-closed.

The Redis client is lazy. A failed Redis operation discards its current client,
and the next operation reconnects. An initial store failure does not terminate
the event-loop worker: it prevents the WS session from starting, leaves status
absent or expiring, and schedules recovery attempts. Authentication,
subscription, snapshot and malformed supported-event failures close the current
connection and enter the same bounded reconnect cycle.

Private WS events use positive allowlists for order, fill and position fields.
Unknown, sensitive and nested provider values are removed before normalized
events reach Redis, logs or Doctrine projection metadata. Raw provider payloads
are never persisted. Malformed supported envelopes or rows produce the bounded
`okx_private_ws_message_invalid` code and trigger reconnect.

The observer accepts only `OKX_ENV=demo`, `OKX_SIMULATED_TRADING=1`, the canonical
demo private endpoint and dedicated non-empty demo credentials. The operational
service forces `OKX_DEMO_TRADING_ENABLED=0` and `OKX_LIVE_ENABLED=0`, and its code
path sends no order. Provisioning an API key with read-only permissions is a
manual operator precondition: the worker does not inspect or prove the
permissions configured in OKX. Logs use the dedicated `okx_private_ws` channel
and contain only state names, phases, bounded error codes, endpoint identifiers
and close codes, never raw messages or credentials.

The real OKX demo recipe attempted for OKX-010 did not establish readiness: OKX
closed the connection during login. The observer remained fail-closed, all write
gates remained disabled, and no exchange order was sent. A successful demo
runtime recipe is still an external gate; this implementation must not be used
as evidence of write readiness.

Exact order quantities are stored in additive nullable columns
`futures_order.quantity_decimal` and
`futures_order.filled_quantity_decimal` as PostgreSQL `NUMERIC(36,18)`. Migration
`Version20260713150000` backfills legacy integer quantities. Readers prefer the
exact columns and fall back to legacy rows when needed. The real-schema check is:

```bash
docker compose exec -T trading-app-php php vendor/bin/phpunit tests/Repository/FuturesOrderExactQuantityPostgresTest.php
```

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

Lifecycle mapping:

| OKX source | TradingV3 lifecycle |
|---|---|
| order request built from `PlaceOrderRequest` | normalized OKX body only; no HTTP write |
| `state=pending` | `pending` |
| accepted placement response | `accepted` |
| `state=live` | `open` |
| `state=partially_filled` | `partially_filled` |
| `state=filled` | `filled` |
| `state=canceling` / `cancelling` | `cancel_pending` |
| `state=canceled` / `cancelled` / `mmp_canceled` | `canceled` |
| `state=rejected` | `rejected` |
| `state=expired` | `expired` |
| `state=order_failed` / `partially_failed` / API error payload | `failed` |
| unknown state or ambiguous order payload | `unknown_requires_resync` |

`OkxLifecycleNormalizer` keeps fills as separate normalized records with
`tradeId`-based deterministic IDs, fee amount and fee currency per fill. Order
rows are sorted by OKX timestamps, duplicate rows are ignored, and a terminal
cancel that already contains fills is flagged with `terminal_cancel_with_fill`
instead of discarding the fill. Unknown states are not guessed; they require REST
resync before feeding ledger or position state.

Environment:

```dotenv
OKX_ENV=demo
OKX_DEMO_API_KEY=
OKX_DEMO_API_SECRET=
OKX_DEMO_API_PASSPHRASE=
OKX_API_BASE_URI=https://eea.okx.com
OKX_WS_PUBLIC_URI=wss://wseeapap.okx.com:8443/ws/v5/public
OKX_WS_PRIVATE_URI=wss://wseeapap.okx.com:8443/ws/v5/private
OKX_WS_BUSINESS_URI=wss://wseeapap.okx.com:8443/ws/v5/business
OKX_SIMULATED_TRADING=1
OKX_DEMO_TRADING_ENABLED=0
OKX_LIVE_ENABLED=0
```

Le worker prive ouvre deux transports distincts. Le socket `/private` couvre
`orders` et `positions`; le socket `/business` couvre
`orders-algo`. `orders_stream_ready` reste faux tant que les deux abonnements ne
sont pas acquittes, et la perte d'un socket recycle la paire.

Local safety checks:

```bash
./vendor/bin/phpunit tests/Exchange/Okx tests/Exchange/Adapter/OkxExchangeAdapterTest.php tests/Exchange/Contract/OkxExchangeAdapterContractTest.php
```

Operations are documented in
[`docs/handbook/runbooks/okx-private-ws-observability.md`](../../../../docs/handbook/runbooks/okx-private-ws-observability.md).
