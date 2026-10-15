# H1 on OKX Europe X-Perps — listing inventory (2026-10-15)

Metadata only: no price archive, no return, no PnL. Status words follow `RESEARCH-ELIGIBILITY-GATES.md`.

## 1. Sources

All public, unauthenticated GET requests, fetched at **2026-10-15T05:35Z**. Raw files and their sha256 are in
`tradingV3-private/research/20261015T0100Z-h1-xperp-audit/raw/`.

| Source | Use |
|---|---|
| `eea.okx.com/api/v5/public/instruments?instType=FUTURES` | The X-Perp list: 243 live contracts, `ruleType` `xperp` (240) or `pre_market` (3), `listTime`, specifications |
| `www.okx.com/api/v5/public/instruments?instType=SWAP` | OKX Global USDT swaps, `listTime` (first OKX perpetual of a token) |
| `fapi.binance.com/fapi/v1/exchangeInfo` | Binance USDⓈ-M `onboardDate` (first Binance perpetual) |
| H1 audit (`20261014T210000Z-h1-listing/audit/`, PR #502) | First perpetuals of delisted contracts, from archives |
| `eea.okx.com/api/v5/support/announcements` | 417 new-listing, 39 delisting and 474 trading-update announcements of OKX Europe |
| `eea.okx.com/api/v5/market/tickers?instType=FUTURES` | Today's price and 24 h volume, used only for minimum-order sizes in USD |

Script: `…/20261015T0100Z-h1-xperp-audit/scripts/inventory.py`. Output: `xperp-inventory.json`, one row per X-Perp.

### Date quality

- **The X-Perp launch date** is the instrument's API `listTime`. OKX documents it as the listing time; it is not a
  first-observation date.
- **The announcements barely cover X-Perp launches.** The EEA announcement feed has only 3 X-Perp listing notes
  (ACT and AAVE, CAP; 2026-06-30) and 1 X-Perp delisting (SKDD, an equity X-Perp, 2026-09-03). The official date of
  every other launch therefore rests on `listTime` alone.
- **Withdrawn X-Perps are not listed** by the instruments endpoint. Apart from SKDD, no withdrawn crypto X-Perp was
  found in the announcements, but the inventory of withdrawn contracts is **not complete**
  (`INSUFFICIENT_LISTING_HISTORY` for withdrawals only).
- **"First perpetual"** is the first among OKX Global and Binance only, as in H1 (§2 of the protocol). Other
  venues are not observed.

## 2. Classification

| Category | Verified count |
|---|---:|
| Total live X-Perps | 243 |
| Crypto X-Perps (`instCategory` 1) | **160** |
| Non-crypto (79 equity / ETF, 4 commodities; 3 pre-market contracts on private companies) | 83 |
| Crypto X-Perps on established tokens | **130** |
| — programme majors (BTC, ETH, SOL, XRP…) | 19 |
| — first OKX / Binance perpetual > 180 days before the X-Perp | 111 |
| **New tokens** (first OKX / Binance perpetual ≤ 180 days before the X-Perp) | **30** |
| — X-Perp a secondary listing, > 48 h after the first perpetual (12 days to 6 months) | 17 |
| — X-Perp within 48 h of the first perpetual | 13 |
| Token whose first perpetual is the X-Perp itself (no OKX / Binance perpetual) | 0 |
| First perpetual on OKX Global (among the 13 within 48 h) | 13 of 13 |
| Pre-market before the standard contract (OKX flag) | 1 (RE) |
| **X-Perp live at T = ceil_hour(t0 + 24 h)** | **9** |
| X-Perp live at T + 48 h (secondary diagnostic only) | 13 |
| Shorts allowed | 160 of 160 (product rule; see `H1-XPERP-MARKET-ACCESS.md`) |
| API listing confirmed (public instruments endpoint) | 160 of 160 |
| **Events potentially compatible with H1** (new token, first OKX / Binance perpetual, not pre-market, X-Perp live at T) | **9** |

### Crypto X-Perps by month of `listTime`

| 2026-03 | 04 | 05 | 06 | 07 | 08 | 09 | 10 (to the 15th) |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 2 | 8 | 9 | 19 | 28 | 42 | 46 | 6 |

Most of the growth is established tokens being added. The first new-token X-Perp is SLX (2026-06-03). The first
one live at T is GRAM (2026-06-17).

## 3. The 30 new tokens

t0 is the first OKX / Binance perpetual and the lag is X-Perp `listTime` − t0. "T" is ceil_hour(t0 + 24 h). A
contract live only at T + 48 h does not meet the original setup.

| X-Perp | First perpetual t0 | Venue | X-Perp `listTime` | Lag (h) | Live at T | Live at T + 48 h | Note |
|---|---|---|---|---:|---|---|---|
| `SLX-USD_UM_XPERP-310606` | 2026-06-01T05:30Z | OKX | 2026-06-03T08:00Z | 50.5 | no | no |  |
| `GRAM-USD_UM_XPERP-310620` | 2026-06-17T10:30Z | OKX | 2026-06-17T12:00Z | 1.5 | **yes** | yes |  |
| `O-USD_UM_XPERP-310620` | 2026-06-17T16:30Z | OKX | 2026-06-18T09:00Z | 16.5 | **yes** | yes |  |
| `RE-USD_UM_XPERP-310620` | 2026-06-17T10:30Z | OKX | 2026-06-19T06:30Z | 44.0 | no | yes | pre-market on OKX (excluded by H1 anyway) |
| `ARX-USD_UM_XPERP-310627` | 2026-06-22T12:30Z | OKX | 2026-06-24T06:30Z | 42.0 | no | yes |  |
| `NES-USD_UM_XPERP-310627` | 2026-06-24T13:30Z | OKX | 2026-06-24T13:30Z | 0.0 | **yes** | yes |  |
| `CAP-USD_UM_XPERP-310704` | 2026-06-26T12:30Z | OKX | 2026-06-30T08:45Z | 92.3 | no | no | announced |
| `BASED-USD_UM_XPERP-310704` | 2026-03-30T10:00Z | Binance | 2026-07-03T08:15Z | 2278 | no | no |  |
| `BILL-USD_UM_XPERP-310718` | 2026-05-06T08:00Z | OKX | 2026-07-15T09:00Z | 1681 | no | no |  |
| `BSB-USD_UM_XPERP-310718` | 2026-03-25T11:45Z | Binance | 2026-07-16T09:00Z | 2709 | no | no |  |
| `AEON-USD_UM_XPERP-310801` | 2026-07-27T11:30Z | OKX | 2026-07-27T11:30Z | 0.0 | **yes** | yes |  |
| `PROS-USD_UM_XPERP-310801` | 2026-04-28T14:00Z | OKX | 2026-07-30T08:00Z | 2226 | no | no |  |
| `GRVT-USD_UM_XPERP-310801` | 2026-07-30T15:00Z | OKX | 2026-07-30T15:00Z | 0.0 | **yes** | yes |  |
| `ESP-USD_UM_XPERP-310801` | 2026-02-10T08:10Z | Binance | 2026-07-31T10:00Z | 4106 | no | no |  |
| `ROBO-USD_UM_XPERP-310815` | 2026-02-27T08:45Z | Binance | 2026-08-11T09:00Z | 3960 | no | no |  |
| `DOS-USD_UM_XPERP-310815` | 2026-08-10T12:00Z | OKX | 2026-08-12T09:45Z | 45.8 | no | yes |  |
| `OPN-USD_UM_XPERP-310822` | 2026-02-21T15:00Z | Binance | 2026-08-17T08:30Z | 4242 | no | no |  |
| `CHIP-USD_UM_XPERP-310822` | 2026-04-16T07:15Z | Binance | 2026-08-18T08:00Z | 2977 | no | no |  |
| `EDGE-USD_UM_XPERP-310822` | 2026-03-19T14:00Z | Binance | 2026-08-21T07:00Z | 3713 | no | no |  |
| `CP-USD_UM_XPERP-310905` | 2026-09-02T15:40Z | OKX | 2026-09-04T07:00Z | 39.3 | no | yes |  |
| `PONS-USD_UM_XPERP-310919` | 2026-09-05T03:00Z | OKX | 2026-09-16T08:00Z | 269 | no | no |  |
| `UP-USD_UM_XPERP-310919` | 2026-04-08T11:00Z | OKX | 2026-09-18T07:15Z | 3908 | no | no |  |
| `DGAI-USD_UM_XPERP-310919` | 2026-09-03T03:00Z | OKX | 2026-09-18T07:30Z | 365 | no | no |  |
| `CASHCAT-USD_UM_XPERP-310926` | 2026-09-03T03:30Z | OKX | 2026-09-23T08:30Z | 485 | no | no |  |
| `CNPY-USD_UM_XPERP-310926` | 2026-09-07T13:00Z | OKX | 2026-09-23T08:45Z | 380 | no | no |  |
| `KII-USD_UM_XPERP-310926` | 2026-09-24T11:00Z | OKX | 2026-09-24T12:00Z | 1.0 | **yes** | yes |  |
| `DATA-USD_UM_XPERP-311003` | 2026-07-02T12:30Z | OKX | 2026-09-28T08:00Z | 2108 | no | no |  |
| `XDP-USD_UM_XPERP-311003` | 2026-09-28T14:00Z | OKX | 2026-09-28T15:00Z | 1.0 | **yes** | yes |  |
| `CT-USD_UM_XPERP-311003` | 2026-09-30T11:00Z | OKX | 2026-09-30T12:00Z | 1.0 | **yes** | yes |  |
| `QUANT-USD_UM_XPERP-311003` | 2026-10-01T08:00Z | OKX | 2026-10-01T09:00Z | 1.0 | **yes** | yes |  |

All 30 contracts share these specifications:

- linear, settled in USD, 5-year expiry;
- `minSz = lotSz = 1`;
- `posLmtAmt` 250,000 USD;
- maximum platform leverage 20× (GRAM and QUANT 50×);
- price-limit fields `initPxLmtPct` 0.04 and `maxPxLmtPct` 0.30 (QUANT 0.02 / 0.05).

## 4. What the H1-compatible events are, and are not

- **The 9 events** are GRAM, O, NES, AEON, GRVT, KII, XDP, CT and QUANT. All were listed between 2026-06-17 and
  2026-10-01, and they meet the frozen H1 event definition:
  - a new token;
  - its first OKX / Binance perpetual;
  - not pre-market;
  - a contract live at T.

  **No definition was changed to obtain them.**
- **All 9 are OKX-first listings:** the X-Perp opened together with the OKX Global swap, within 0 to 16.5 h. None
  of the 30 new tokens first listed on Binance had an X-Perp within 48 h; their lag was 2 to 6 months.
  - The tradable subset is therefore the OKX-first population of H1. Its historical evidence is S_A: −11.4 %, but
    unstable in time (positive in 2023–24) and only a screen.
  - S_B, the decisive Gate 1 pass, is entirely Binance-first events (the audit gives each token to one venue).
  - **The S_B result does not transfer automatically to the X-Perp subset.**
- **The X-Perp selection is OKX's choice.** Which new tokens get a same-day X-Perp may depend on token features
  (launch partnerships, OKX-led listings). The 9 events can differ systematically from the historical population;
  this is a selection risk to be stated in any prospective protocol.
- **Rate.** 9 events over the 4 months 2026-06-17 → 2026-10-15, i.e. **about 2.2 per month**.
  - Since 2026-07-01: 6 events in 3.5 months (1.7 per month).
  - There was none before mid-June: the product existed but listed only established tokens.
  - For comparison, about 4 to 10 new tokens a month got a first OKX / Binance perpetual in 2026. **About one in
    three of those since June reached an X-Perp by T.**
