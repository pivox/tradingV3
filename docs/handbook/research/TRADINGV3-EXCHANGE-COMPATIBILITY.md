# TradingV3 exchange compatibility (2026-10-14)

How the existing code maps onto the venues that a French retail account can actually use
(`FRANCE-EXCHANGE-ELIGIBILITY.md`). **No adapter is developed here**; this is the gap list.

## 1. What exists

| Component | State | Files |
|---|---|---|
| OKX connector: public reads, private reads, guarded demo writes | Built for **USDT swaps** only. `instType=SWAP` everywhere; symbols mapped `BTCUSDT ↔ BTC-USDT-SWAP`; the event normaliser recognises `-SWAP` instruments only | `trading-app/src/Provider/Okx/*Gateway.php` (`instType => 'SWAP'`), `src/Exchange/Okx/OkxInstrumentResolver.php` (`-SWAP` suffix), `src/Exchange/Okx/OkxExchangeEventNormalizer.php:732` |
| OKX hosts | The demo already uses the **EEA** host `https://eea.okx.com` / `wseeapap.okx.com`; live defaults to `https://www.okx.com` (global) | `src/Exchange/Okx/OkxConfig.php` |
| OKX contract metadata | `ctVal`, `ctValCcy`, `settleCcy`, `expTime` are already parsed (`expireTimestamp`, `delistTime`) | `src/Provider/Okx/OkxMetadataProvider.php`, `OkxPublicReadMapper.php` |
| Hyperliquid connector | Testnet / mainnet hosts; HMAC readiness proof; no mainnet mutation wired | `src/Exchange/Hyperliquid/*`, `src/Exchange/Adapter/HyperliquidExchangeAdapter.php` |
| Market types | `PERPETUAL`, `SPOT` only; no dated future | `src/Common/Enum/MarketType.php` |
| Exchanges | `okx`, `hyperliquid`, `fake` | `src/Common/Enum/Exchange.php` |
| Research tooling (Python) | OKX USDT-swap archive acquisition, funding at real instants, 1-minute engine with stops through gaps, delisting settlement; Binance archive acquisition | `python-orchestrator/scripts/research_swing2/okx*.py`, `engine.py`; `scripts/research_exec/acquire.py` |
| Paper / backtests | Built on OKX `run14` and Hyperliquid `run15` captures, and on `*-USDT-SWAP` instrument maps | `src/Trading/Paper/*` |

## 2. Per target venue

### OKX Europe X-Perps (primary target)

| Question | Answer |
|---|---|
| **Works without change** | v5 signing, REST and WebSocket transport, the EEA hosts, metadata parsing (ctVal, settleCcy, expTime), the demo write gate's structure |
| **Needs an adapter** | Instrument resolution: `BTCUSDT → BTC-USD_UM_XPERP-310404` (via `instFamily`, by expiry). `instType=FUTURES` in every gateway. The event normaliser must accept `_UM_XPERP-` ids. Account mode and margin currency (USD / USDC). |
| **Needs a new contract model** | A dated linear future with **5-year expiry**, **8 h funding**, **USD settlement**, a contract value in coin. MarketType gets a distinct value (e.g. `EXPIRY_PERP`), not `PERPETUAL`. A roll / expiry policy is required, even if it is far off. |
| **Makes existing backtests incompatible** | Every past study used Binance USDⓈ-M or OKX USDT swaps. X-Perps have different books, liquidity (29 contracts ≥ 1 M USD / day), funding (8 h, cap ±0.375 %) and history (since 2026-03-30 at the earliest). **No past result transfers automatically**: transfer needs evidence that the price paths are the same (index-linked) and a cost model of the X-Perp book. |
| **Needs new data** | X-Perp 1-minute candles (`/api/v5/market/history-candles`, public), funding history (`funding-rate-history`), books (live capture: the forward OKX tooling can be pointed at X-Perps). History is short: about 6.5 months for the oldest, weeks for many. |
| **Can block real execution** | The French account's appropriateness test and X-Perp access (ACCOUNT_ELIGIBILITY_UNVERIFIED). Whether demo (simulated trading) lists X-Perps: unverified. API permissions for X-Perps. Liquidity of all but a few contracts. |

### Kraken EEA (alternative)

- **No connector exists:** new provider bundle, symbols, auth, futures API.
- **Contract model:** USD-margined perpetuals with **hourly** funding, multi-collateral.
- **Data:** the history of the EEA book (same as Kraken Futures?) is unverified.
- **Effort: strong.** Only after OKX Europe is found unusable.

### Coinbase EEA (alternative)

- **No connector exists.** 5-year "perpetual-style" futures with hourly funding.
- **History:** since 2026-03.
- **EEA API scope:** unverified.
- **Effort: strong.**

### Hyperliquid (not retained for real trading)

- The connector exists (testnet).
- Kept for research data only (public API, S3 archives); no real-execution target (no EU authorisation).

### Binance (blocked)

- **Data.** Research use only. The Binance Vision dataset licence (CC BY-NC-SA 4.0, 26 Aug 2026) forbids use for
  live trading execution.
- **Consequence.** No Binance-only validation can justify a live strategy. Rules found on Binance data must be
  re-validated on data of the target venue whose licence allows it.

## 3. A common instrument interface (proposal, not implemented)

To stop a strategy tested on one product being silently transferred to another, every research or execution
component should carry an **InstrumentSpec**:

| Field | Examples |
|---|---|
| `venue` | okx-eea, okx-global, kraken-eea, coinbase-eea, hyperliquid, binance |
| `jurisdiction_entity` | OKX Europe Markets Ltd (MFSA), … |
| `product` | `EXPIRY_PERP` (5-year future with funding), `PERPETUAL`, `DATED_FUTURE`, `SPOT` |
| `instrument_id` | BTC-USD_UM_XPERP-310404 |
| `underlying` | BTC (index) |
| `settlement_ccy` / `margin_ccys` | USD / USD, USDC, … |
| `contract_value`, `contract_value_ccy` | 0.0001 BTC |
| `lot`, `min_size` | 1 / 1 |
| `tick`, `price_limits` | 0.1, … |
| `expiry` | 2031-04-04 |
| `funding_interval`, `funding_cap` | 8 h, ±0.375 % |
| `fees_maker`, `fees_taker` | 2 bp / 5 bp |
| `max_leverage_retail` | 10× |
| `shortable` | true |
| `eligibility_status` | M1 / M2 gate status (see `RESEARCH-ELIGIBILITY-GATES.md`) |
| `data_sources` | candles, funding, books, with licence |
| `listed_since` | 2026-03-30 |

Backtests record the InstrumentSpec they ran on. A result is reported **for that spec**. Moving it to another
spec is a new validation, never a relabelling.

## 4. Consequences

- **OKX Europe X-Perps are the only execution target** close to the existing code. They still need a resolver,
  a market-type extension and a contract model.
- **The research datasets built so far** (Binance, OKX USDT swaps) describe **price behaviour of the same
  underlyings**. They are not the tradable products. They can inform hypotheses, but a final validation must run on
  X-Perp data or prospectively on X-Perps.
- **The running forward test `fwd-okx-r1`** captures OKX USDT swaps. Its result will describe a product that a
  French account cannot trade. It stays untouched (its protocol is frozen); its relevance for execution is limited
  to the underlying behaviour.
