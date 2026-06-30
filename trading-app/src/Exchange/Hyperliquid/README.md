# Hyperliquid Adapter

First API-first Hyperliquid integration slice.

- Official docs used:
  - https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint
  - https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/exchange-endpoint
  - https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/tick-and-lot-size
- Defaults to testnet. Mainnet requires `HYPERLIQUID_ENV=mainnet` and `HYPERLIQUID_MAINNET_ENABLED=1`.
- REST public/account reads use the official `/info` endpoint (`meta`, `l2Book`, `clearinghouseState`, `frontendOpenOrders`, `userFills`, `userFillsByTime`, `userFunding`, `userFees`).
- Open-order reconciliation uses `frontendOpenOrders` so standalone trigger protection orders expose `triggerPx`, `reduceOnly`, and frontend order type metadata.
- Trading actions are built in official `/exchange` wire format (`order`, `cancel`, `cancelByCloid`, `updateLeverage`).
- Market orders are sent as IOC limit orders with a 5% slippage cap derived from the current L2 top. Stop-loss/take-profit trigger market orders use the same 5% cap around `stopPrice`.
- Internal app client order IDs are deterministically mapped to Hyperliquid Cloid values (`0x` + 128-bit hex) before they reach `/exchange`.
- Private WebSocket support is not advertised yet; keep reconciliation on REST snapshots until a Hyperliquid WS client and normalizer are added.
- Live signing is intentionally not enabled by the default REST client. HL-004 adds a
  signer boundary (`HyperliquidSignerInterface`, `FakeHyperliquidSigner`,
  `HyperliquidAgentSigner`) for deterministic tests and future testnet signing, but it
  is not wired to `/exchange` broadcast.
- HL-002 adds a separate `App\Provider\Hyperliquid\*` provider bundle for
  `hyperliquid/perpetual`.
- HL-003 enables only public REST reads through `/info` on that provider bundle:
  `metaAndAssetCtxs`, `allMids`, `l2Book`, `candleSnapshot`, and
  `fundingHistory`. Candles are normalized as `KlineDto` with source
  `HYPERLIQUID_REST_PUBLIC`; public 429 responses are normalized as
  `hyperliquid_public_rate_limited`.
- Public WebSocket is not wired in HL-003. The supported public fallback is
  bounded REST polling through `/info`.
- HL-006 enables account read-only on the provider bundle:
  `clearinghouseState` for collateral/equity/positions,
  `frontendOpenOrders` for active orders, `userFills`/`userFillsByTime` for
  fills, and `userFunding` for funding history. These reads use
  `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS`, reject using the agent address as the
  account address, redact sensitive metadata, do not sign, and do not broadcast
  `/exchange`.
- HL-007 strengthens market metadata and costs. `HyperliquidInstrumentMetadataDto`
  exposes derived price tick, quantity step, max leverage, funding, status and
  quality flags. Missing required sizing fields or suspended markets make
  `isCompleteForSizing()` false. Duplicate asset names are rejected with
  `hyperliquid_asset_collision` instead of resolving one arbitrarily.
- HL-007 also reads user fee schedule through `/info` `userFees`:
  maker=`userAddRate`, taker=`userCrossRate`, fee currency `USDC`. Missing fee
  data remains `null` with `maker_fee_unknown`, `taker_fee_unknown` and/or
  `fee_schedule_unknown`; no absent fee is converted to zero.
- HL-008 adds read-only lifecycle normalizers for order requests, order status
  rows, fills, positions, funding rows, and exchange errors. These normalizers
  only transform local/request or `/info` payloads; they do not sign, allocate a
  nonce, or broadcast `/exchange`. Ambiguous streams, such as fills without an
  order row, are returned as `unknown_requires_resync` with quality flags instead
  of being resolved from symbol or time alone.
- Mutative execution provider methods remain fail-closed with
  `HyperliquidProviderNotReadyException`.
- `HyperliquidRuntimeCheck` may reach `private_read_only` when public and
  account probes are supplied as good, but
  `app:exchange:runtime-check hyperliquid perpetual` remains
  `Schedule ready: no` until local dry-run, protection readiness, and future
  controlled `/exchange` wiring are implemented.
- `HyperliquidAgentSigner` only accepts `HYPERLIQUID_NETWORK=testnet` and
  `HYPERLIQUID_ENV=testnet`. The application never accepts the wallet principal
  private key; only a dedicated testnet agent key is allowed, and signer outputs
  are redacted.
- `PersistentHyperliquidNonceManager` stores monotonic nonces in
  `hyperliquid_nonce_state`, scoped by
  `environment + network + signer_address`. It keeps `account_address` for audit,
  rejects signer reuse across accounts with `hyperliquid_nonce_scope_conflict`,
  rejects replay with `hyperliquid_nonce_replay_detected`, and is not a business
  idempotency key.

Environment:

```dotenv
HYPERLIQUID_ENV=testnet
HYPERLIQUID_NETWORK=testnet
HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY=
HYPERLIQUID_TESTNET_AGENT_ADDRESS=
HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS=
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_WS_URI=wss://api.hyperliquid-testnet.xyz/ws
HYPERLIQUID_MAINNET_ENABLED=0
HYPERLIQUID_TESTNET_TRADING_ENABLED=0
```

Rollback for HL-004: unset the three `HYPERLIQUID_TESTNET_*` signer/account
variables and keep `HYPERLIQUID_TESTNET_TRADING_ENABLED=0`. Public read-only
HL-003 remains available; no `/exchange` broadcast path is enabled by this slice.

Rollback for HL-005: migrate down `hyperliquid_nonce_state`, remove the
`PersistentHyperliquidNonceManager` DI alias, and keep
`HYPERLIQUID_TESTNET_TRADING_ENABLED=0`. Signer boundaries remain available, but
no `/exchange` broadcast path is enabled by this slice.

Rollback for HL-006: unset `HYPERLIQUID_TESTNET_ACCOUNT_ADDRESS` or remove the
account/execution provider read-only wiring to the Hyperliquid `/info` client.
Public read-only HL-003 and nonce HL-005 remain available; mutative paths stay
fail-closed and no `/exchange` broadcast path is enabled by this slice.

Rollback for HL-007: revert the metadata strictness and `userFees` read-only
mapping changes. Public/account reads from HL-003/HL-006 remain available, but
cost model consumers must treat Hyperliquid fees and incomplete metadata as
unknown until this slice is restored.

Rollback for HL-008: remove consumers of
`App\Exchange\Hyperliquid\Lifecycle\HyperliquidLifecycleNormalizer` and revert
the lifecycle DTOs. Public/account reads from HL-003/HL-006 and metadata/cost
reads from HL-007 remain available, but ledger and position-state consumers must
not ingest Hyperliquid order/fill/position lifecycle payloads until the
normalizer slice is restored.
