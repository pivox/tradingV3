# H1 on OKX Europe X-Perps — TradingV3 technical gap (2026-10-15)

A read-only audit of the code at `origin/main` `e2bbe02a`. No adapter or service was changed. This note extends
`TRADINGV3-EXCHANGE-COMPATIBILITY.md` with file-level evidence. Paths are under `trading-app/src/` unless stated
otherwise.

## 1. Product differences that matter to the code

| | OKX Global USDT swap | OKX Europe X-Perp |
|---|---|---|
| `instType` | `SWAP` | `FUTURES` (`ruleType` `xperp`) |
| Id | `BTC-USDT-SWAP` | `BTC-USD_UM_XPERP-310404` (family `BTC-USD_UM_XPERP`, underlying `BTC-USD`) |
| Settlement and margin | USDT | **USD** |
| Expiry | none | 5 years (`expTime`) |
| Funding | 8 h | 8 h |
| REST host | `www.okx.com` | `eea.okx.com` (EEA site `my.okx.com`) |

**The two products sharing OKX's infrastructure does not make the code compatible.** Every product-specific value
above is hardcoded today.

## 2. Matrix

| Function | OKX Global SWAP today | OKX Europe X-Perp | Change needed (effort) |
|---|---|---|---|
| **Listing** | `Provider/Okx/OkxMetadataProvider.php:231,235` requests and keeps `instType=SWAP`. `Exchange/Okx/OkxInstrumentResolver.php:9-42` maps `BTCUSDT` ↔ `BTC-USDT-SWAP`. The repository universe defaults to USDT (`Provider/Repository/ContractRepository.php:420`). | **Not compatible.** The resolver turns `_` into `-` and throws on `BTC-USD_UM_XPERP-310404`. `instId('BTCUSD')` returns `BTC-USD-SWAP`, **OKX's inverse swap** (wrong-instrument risk on the order path, `Exchange/Adapter/OkxExchangeAdapter.php:162-166`). | An X-Perp resolver from the instruments map (family → live expiry); `instType=FUTURES`, `ruleType=xperp`, `state=live`; USD quote accepted. (M) |
| **Market data** | `Provider/Okx/OkxMarketDataGateway.php:200` (`/market/candles`), books, trades, tickers by instId; health check `:115` uses SWAP. | Works through instId once the resolver works; the health check needs `instType`. | Parametrise `instType`. (S) |
| **History** | The app reads recent candles only. Research scripts (`python-orchestrator/scripts/research_swing2/okx.py:35,74,86,228,135`, `research_listing/audit.py:37,89,107`, `research_exec/forward_okx.py:49-50`) hardcode `www.okx.com`, `SWAP`, `-USDT-SWAP`, USDT. | X-Perp history starts on 2026-03-30 at the earliest, and for a new token at its `listTime`. Whether the `market-data-history` archive serves FUTURES X-Perp families is unverified. | Host, `instType`, settle and id parameters, or a new `research_xperp` fetcher. (M) |
| **Short** | `Exchange/Okx/OkxActionFactory.php:25-34,170-180`: `posSide` long/short is always sent, `tdMode` cross / isolated, `reduceOnly`. No position-mode check anywhere. | Same order API. If the EEA account is in net mode, a long/short `posSide` is rejected. The account's mode is unverified. | Read `posMode` (`GET /api/v5/account/config`) in runtime-check, or send `net`. (S) |
| **Stop** | `OkxActionFactory.php:40-72`: `/trade/order-algo` conditional, mark trigger, market fill, reduce-only. Pending / cancel queries (`OkxExchangeAdapter.php:140,150,269-276`) and private WebSocket subscriptions (`Exchange/Okx/PrivateWebSocket/OkxPrivateWebSocketSession.php:234-236,485`) use SWAP. **The normalisers drop non-SWAP events** (`Exchange/Okx/OkxExchangeEventNormalizer.php:726-745`, `Exchange/Okx/Lifecycle/OkxLifecycleNormalizer.php:601-618`). | The algo payload carries over, but **stop and fill events would be dropped silently**. The kill switch and reconciliation go blind. | `instType` and id pattern made configurable in queries, subscriptions and normalisers. (M) |
| **Funding** | Current rate by instId (`OkxMetadataProvider.php:288-306`). The paper normaliser requires SWAP (`Trading/Paper/Okx/Normalization/OkxPaperMarketEventNormalizer.php:205`). PnL: a USD amount gives `funding_currency_not_normalized` (`Trading/Pnl/FillCostLedgerIngestionService.php:142-166`). No OKX funding-bill ingestion exists. Research engines assume 8 h (matches). | 8 h cadence, cap per contract; `/public/funding-rate` answers for X-Perp ids (see `H1-XPERP-MARKET-ACCESS.md`). | Accept FUTURES; treat USD as the quote currency; ingest funding bills. (S–M) |
| **Sizing** | `ctVal` → contract size (`Provider/Okx/OkxPublicReadMapper.php:43`); whole contracts (`TradeEntry/RiskSizer/PositionSizer.php:10-15`); notional = qty × ctVal × price (`Exchange/Adapter/OkxDemoGuardedExchangeAdapter.php:252-287`). `Exchange/Okx/OkxContractValueResolver.php:68` loads SWAP only. | The formula carries over. X-Perps have `minSz = lotSz = 1`, which fits whole-contract sizing. The ctVal resolver would not find X-Perp ids, so fills would be flagged `contract_value_missing`. | Parametrise `instType` in the resolver. (S) |
| **PnL** | Positions, fills, fee tier and order history query SWAP (`Provider/Okx/OkxPositionGateway.php:61`, `Provider/Okx/OkxAccountGateway.php:87,116,231,332-335`, `Provider/Okx/OkxOrderGateway.php:100,237,306,327`). The balance requires a USDT line (`OkxAccountGateway.php:30-38`). Fee currency must be USDT (`Trading/Pnl/NetPnlCertificationService.php:174`). Mode budgets must be USDT (`TradingCore/Mode/ModeContractValidator.php:310,330`). | **Not compatible.** X-Perp positions and fills are not returned; balances, fees and PnL are in USD. | `instType` and `instFamily` parameters; a configurable account and settle currency; USD accepted (or an explicit FX rule) in fees, funding, PnL and budgets. (M) |
| **API** | `Exchange/Okx/OkxConfig.php:9-10`: demo `https://eea.okx.com`, live `https://www.okx.com`. Exact-host allowlist in `Exchange/Okx/OkxRestEndpointGuard.php:9-25`; the private WebSocket allowlist is demo only (`OkxPrivateWebSocketEndpointGuard.php:9-12`). `python-orchestrator/app/services/live_guard.py:39` forbids OKX live. Runtime-check hardcodes `permissionsTrade: false` (`Command/ExchangeRuntimeCheckCommand.php:433`). | **Demo on `eea.okx.com` is already allowed.** Live on `eea.okx.com` / `my.okx.com` is rejected by design. Whether the EEA demo lists X-Perps is unverified. | Runtime-check probes: X-Perp instruments under FUTURES, position mode, account currency, key permission (read-only GETs). Any live allowlisting is the owner's separate decision. (S demo / M live) |
| **Paper / backtest** | The paper stack accepts only BTC / ETH-USDT-SWAP (`Trading/Paper/Okx/OkxPaperInstrumentMap.php:10-13`). Its instId regex rejects `_` (`Trading/Paper/Okx/Http/OkxPaperPublicRestClient.php:318`), hosts are pinned to `www.okx.com` (`OkxPaperPublicConfig.php:20-30`), and SWAP + USDT are required (`NormalizedBacktestInstrumentMetadata.php:73`). Python tapes are `Literal["BTCUSDT","ETHUSDT"]`. | Not compatible. Expiry can be ignored for a 5-year contract within a 14-day hold. | Large for the certified paper stack (L); small for a research-only simulator (S). |

`MarketType` has only `PERPETUAL` and `SPOT` (`Common/Enum/MarketType.php:9-10`), and there is no OKX-EEA variant
of `Exchange`. The least disruptive design keeps `MarketType::PERPETUAL` and adds an **OKX product setting**
(`instType`, id resolver, settle currency) inside the OKX gateways.

## 3. Hard blockers

1. **The resolver throws on X-Perp ids**, and `BTCUSD` resolves to the inverse swap.
2. **`instType=SWAP` is hardcoded** in about 54 places across 22 files: listing, positions, fills, pending orders,
   fee tier, order history and private WebSocket.
3. **Event and lifecycle normalisers drop non-SWAP rows.** Stops and fills would be invisible.
4. **USDT is assumed** for the account, PnL, fees and mode budgets.
5. **Live EEA hosts are not allowlisted.** This is deliberate: real trading stays forbidden. A demo on
   `eea.okx.com` is allowed.

## 4. Minimum for H1 (in order, none started)

1. **Read-only.** An X-Perp metadata and public-data capture (instruments, `listTime`, candles, funding, books for
   new listings). No adapter is needed: a research script on `eea.okx.com` public endpoints, outside the trading
   services. It is what a prospective S_C on X-Perps needs from day one.
2. **Runtime-check probes** (read-only, authenticated GET by the owner): X-Perps visible, position mode, account
   currency, key permissions.
3. **Execution path** (only after Gate 2 and the owner's go): the resolver, `instType` and normalisers, the USD
   currency, then demo. Estimated M to L, since nothing in the execution path supports FUTURES today.
