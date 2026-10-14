# Tradable instrument universe for a French retail account (2026-10-14)

Built from public metadata and the day's public tickers only: no price archive was downloaded, no order placed.
Eligibility of the venues: `FRANCE-EXCHANGE-ELIGIBILITY.md`.

**Liquidity** below is the 24-hour traded value of 2026-10-14, a single snapshot. **"Min. order"** is the smallest
order in USD at that day's price (minimum size × contract value × last price).

## 1. Categories

| Venue | Product | Accessible from France | Short | API trading | History | Instruments | Fees maker / taker | Min. order | 500 USDT compatible | TradingV3 adaptation |
|---|---|---|---|---|---|---|---|---|---|---|
| **OKX Europe** (OKX Europe Markets Ltd, MiFID, Malta) | **X-Perps**: futures with a 5-year expiry, 8 h funding, settled in USD | **Conditional**: authorised, appropriateness test, ACCOUNT_ELIGIBILITY_UNVERIFIED | Yes | Yes (v5 API, `eea.okx.com`) | Since **2026-03-30** (BTC), most since 2026-06 to 09; 1-minute candles via `history-candles`; funding history | **160 crypto** (+79 equity / ETF, 4 commodities); **29 crypto ≥ 1 M USD / day**, 78 ≥ 100 k | from 2 / 5 bp | median 0.85 USD, max 19.6 USD (ENA) | **Yes** for 159 of 160 at a 15 % stop; all at tighter stops | **Strong**: new product (instType `FUTURES`, expiry, USD settlement) |
| OKX Europe | Spot (EUR / USDC pairs) | **Conditional** (MiCA CASP, France passported) | No (no short without margin) | Yes | Yes | not counted | not verified | — | long only | Medium (spot market type exists, not wired) |
| OKX Global | USDT swaps (`*-USDT-SWAP`) | **Not available** to EEA retail (OKX routes the EEA to X-Perps) | — | — | Archive 2022→ (on disk) | 486 | 2 / 5 bp | — | — | Exists today (research only) |
| **Kraken EEA** (CySEC 342/17) | Perpetuals and fixed-maturity, USD-margined, hourly funding | **Conditional** (MiFID; appropriateness, TIN) | Yes | Yes | EEA since 2025-05-20; same book as Kraken Futures not verified | several hundred, not counted | 2 / 5 bp (base tier) | 0.0001 BTC, 0.001 ETH | Likely; not verified per contract | **Strong**: new connector |
| **Coinbase EEA** (CySEC 374/19) | Perpetual-style 5-year futures, dated futures | **Conditional** (MiFID, launched 2026-03-09) | Yes | API scope for EEA not verified | Since 2026-03 | not verified | "as low as" 2 bp, schedule not verified | 10 USDC (API docs) | Likely | **Strong**: new connector |
| Hyperliquid | USDC perpetuals | **Not retained**: no EU authorisation (contract does not exclude France) | Yes | Yes | API 5,000 candles; S3 archives of books and fills | 178 active | 1.5 / 4.5 bp | 10 USD | Yes technically | Exists (testnet path) |
| Binance | USDⓈ-M perpetuals | **Blocked**: no MiCA, services stopped 2026-07-01 | — | — | Archive 2019→, **non-commercial licence, no live-trading use** | 702 | — | — | — | Research data only |

## 2. OKX X-Perps (crypto), the 29 with ≥ 1 M USD traded on 2026-10-14

Contract `<BASE>-USD_UM_XPERP-<expiry>`, linear, settled in USD. Funding every 8 h, method `withRate`, cap ±0.375 %
(BTC). Leverage up to 10× for retail per OKX; the `lever` field shows the platform maximum (10–50).

| Instrument | Contract value | Min / lot | Tick | Min. order (USD) | 24 h value (M USD) | Listed | Expiry |
|---|---|---|---|---:|---:|---|---|
| BTC-USD_UM_XPERP-310404 | 0.0001 BTC | 1 / 1 | 0.1 | 8.19 | 353.1 | 2026-03-30 | 2031-04-04 |
| ETH-USD_UM_XPERP-310404 | 0.001 ETH | 1 / 1 | 0.01 | 2.42 | 67.8 | 2026-04-01 | 2031-04-04 |
| SOL-USD_UM_XPERP-310404 | 0.01 SOL | 1 / 1 | 0.01 | 1.05 | 25.9 | 2026-04-01 | 2031-04-04 |
| NEAR-USD_UM_XPERP-310613 | 1 NEAR | 1 / 1 | 0.001 | 4.83 | 20.8 | 2026-06-11 | 2031-06-13 |
| ZEC-USD_UM_XPERP-310530 | 0.01 ZEC | 1 / 1 | 0.01 | 11.36 | 18.7 | 2026-05-27 | 2031-05-30 |
| XRP-USD_UM_XPERP-310404 | 1 XRP | 1 / 1 | 0.0001 | 1.33 | 14.6 | 2026-04-01 | 2031-04-04 |
| HYPE-USD_UM_XPERP-310523 | 0.1 HYPE | 1 / 1 | 0.001 | 8.11 | 7.4 | 2026-05-20 | 2031-05-23 |
| ONDO-USD_UM_XPERP-310620 | 10 ONDO | 1 / 1 | 0.0001 | 4.46 | 6.4 | 2026-06-17 | 2031-06-20 |
| SUI-USD_UM_XPERP-310404 | 1 SUI | 1 / 1 | 0.0001 | 1.02 | 5.5 | 2026-04-01 | 2031-04-04 |
| TAO-USD_UM_XPERP-310523 | 0.01 TAO | 1 / 1 | 0.1 | 2.70 | 4.6 | 2026-05-20 | 2031-05-23 |
| PUMP-USD_UM_XPERP-310404 | 1000 PUMP | 1 / 1 | 0.000001 | 4.77 | 4.5 | 2026-04-01 | 2031-04-04 |
| INJ-USD_UM_XPERP-310711 | 0.1 INJ | 1 / 1 | 0.001 | 0.76 | 4.2 | 2026-07-08 | 2031-07-11 |
| STRK-USD_UM_XPERP-310919 | 1 STRK | 1 / 1 | 0.00001 | 0.08 | 4.1 | 2026-09-17 | 2031-09-19 |
| QUANT-USD_UM_XPERP-311003 | 0.01 QUANT | 1 / 1 | 0.1 | 2.06 | 3.9 | 2026-10-01 | 2031-10-03 |
| ENA-USD_UM_XPERP-310613 | 100 ENA | 1 / 1 | 0.00001 | 19.58 | 3.8 | 2026-06-11 | 2031-06-13 |
| PEPE-USD_UM_XPERP-310404 | 1,000,000 PEPE | 1 / 1 | 1e-9 | 4.06 | 2.9 | 2026-04-01 | 2031-04-04 |
| ARB-USD_UM_XPERP-310815 | 10 ARB | 0.1 / 0.1 | 0.00001 | 0.18 | 2.8 | 2026-08-11 | 2031-08-15 |
| RENDER-USD_UM_XPERP-310711 | 1 RENDER | 1 / 1 | 0.001 | 1.91 | 2.8 | 2026-07-08 | 2031-07-11 |
| ADA-USD_UM_XPERP-310404 | 10 ADA | 1 / 1 | 0.0001 | 2.29 | 2.6 | 2026-04-01 | 2031-04-04 |
| DOGE-USD_UM_XPERP-310404 | 10 DOGE | 1 / 1 | 0.00001 | 0.82 | 2.4 | 2026-04-01 | 2031-04-04 |
| LINK-USD_UM_XPERP-310523 | 1 LINK | 1 / 1 | 0.001 | 12.43 | 2.2 | 2026-05-20 | 2031-05-23 |
| LIT-USD_UM_XPERP-310704 | 1 LIT | 1 / 1 | 0.0001 | 3.12 | 2.2 | 2026-06-28 | 2031-07-04 |
| AVAX-USD_UM_XPERP-310530 | 10 AVAX | 0.1 / 0.1 | 0.001 | 9.97 | 2.0 | 2026-05-27 | 2031-05-30 |
| OP-USD_UM_XPERP-310815 | 1 OP | 1 / 1 | 0.00001 | 0.14 | 1.6 | 2026-08-11 | 2031-08-15 |
| PONS-USD_UM_XPERP-310919 | 10 PONS | 1 / 1 | 0.0001 | 3.38 | 1.4 | 2026-09-16 | 2031-09-19 |
| CT-USD_UM_XPERP-311003 | 10 CT | 1 / 1 | 0.0001 | 3.70 | 1.4 | 2026-09-30 | 2031-10-03 |
| FET-USD_UM_XPERP-310912 | 10 FET | 1 / 1 | 0.0001 | 1.98 | 1.3 | 2026-09-07 | 2031-09-12 |
| DOT-USD_UM_XPERP-310808 | 1 DOT | 1 / 1 | 0.0001 | 1.14 | 1.1 | 2026-08-07 | 2031-08-08 |
| GRASS-USD_UM_XPERP-310829 | 1 GRASS | 1 / 1 | 0.0001 | 0.62 | 1.0 | 2026-08-25 | 2031-08-29 |

## 3. Constraints with 500 USDT (leverage 1×, 0.5 % = 2.50 USDT risk per trade, 1 % in total)

The allowed notional is 2.50 / (stop distance + costs). Taker 5 bp per side; baseline slippage 20 / 50 bp:

| Stop distance | Allowed notional |
|---|---:|
| 2 % | about 85 USDT |
| 5 % | about 43 USDT |
| 15 % | about 14.6 USDT |
| 60 % | about 4 USDT |

- **Minimum orders.** 159 of the 160 crypto X-Perps have a minimum order below 14.65 USD; ENA is at 19.6 USD.
  Stops at or below ~15 % are therefore executable on almost every contract. Very wide stops (> 50 %) exclude the
  contracts whose minimum order exceeds 4–5 USD (BTC, ZEC, HYPE, LINK, AVAX, ENA…).
- **Granularity.** One contract is 0.0001 BTC (8.2 USD). At small notionals the rounding down of the quantity
  costs a visible share of the risk budget; it is to be modelled.
- **Liquidity.** Only 29 crypto X-Perps traded over 1 M USD on 2026-10-14, and 6 over 10 M (BTC, ETH, SOL, NEAR, ZEC,
  XRP). A research universe on X-Perps should start from the liquid ones.
- **Fees.** 2 / 5 bp make holds of hours to days compatible. Minute-level scalping is not, per every past TradingV3
  study.
- **Leverage.** 1× is below every cap (2:1, 10×). No constraint.

## 4. Accessible vs liquid vs tradable

| Venue and set | Accessible | Liquid (≥ 1 M USD / day) | Tradable at 500 USDT (≤ 15 % stop) |
|---|---:|---:|---:|
| OKX X-Perps crypto | 160 | 29 | 159 |
| Kraken EEA | several hundred, not counted | not measured | not verified |
| Coinbase EEA | not counted | not measured | not verified |
