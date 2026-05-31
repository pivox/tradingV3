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
- Live signing is intentionally not enabled by the default REST client. Inject a signed `HyperliquidRestClientInterface` implementation before sending real testnet orders.

Environment:

```dotenv
HYPERLIQUID_ENV=testnet
HYPERLIQUID_PRIVATE_KEY=
HYPERLIQUID_ACCOUNT_ADDRESS=
HYPERLIQUID_API_BASE_URI=https://api.hyperliquid-testnet.xyz
HYPERLIQUID_WS_URI=wss://api.hyperliquid-testnet.xyz/ws
HYPERLIQUID_MAINNET_ENABLED=0
```
