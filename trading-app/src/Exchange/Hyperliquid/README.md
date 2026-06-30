# Hyperliquid Adapter

First API-first Hyperliquid integration slice.

- Official docs used:
  - https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/info-endpoint
  - https://hyperliquid.gitbook.io/hyperliquid-docs/for-developers/api/exchange-endpoint
- Defaults to testnet. Mainnet requires `HYPERLIQUID_ENV=mainnet` and `HYPERLIQUID_MAINNET_ENABLED=1`.
- REST public/account reads use the official `/info` endpoint (`meta`, `l2Book`, `clearinghouseState`, `frontendOpenOrders`, `userFills`).
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
- Account and execution provider gateways remain fail-closed. They still throw
  `HyperliquidProviderNotReadyException`, do not sign, and do not broadcast
  `/exchange`.
- `HyperliquidRuntimeCheck` may reach `public_read_only` when public probes are
  supplied as good, but `app:exchange:runtime-check hyperliquid perpetual`
  remains `Schedule ready: no` until account read, local dry-run, protection
  readiness, and future controlled `/exchange` wiring are implemented.
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
