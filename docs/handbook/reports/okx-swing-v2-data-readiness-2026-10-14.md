# OKX_SWING_V2_DATA_READINESS (2026-10-14)

Data readiness report for the OKX historical test of `B120-Q0.5|long|C|S3|T3|H14d` (owner's conditional go of 2026-10-14). **Nothing is frozen and no performance has been computed**; this report covers data only and is sent to the owner before the freeze.

## 1. Sources

- **Candles 1 m and funding**: OKX official historical files, listed by the public endpoint `GET /api/v5/public/market-data-history` (module 2 = candlesticks, module 3 = funding rates; monthly files, daily files for October 2026). Each file was downloaded, its sha256 recorded (OKX publishes none), converted, the zip deleted.
  - candles: 11267 files, 0 failures; funding: 5595 files, 0 failures.
  - Funding after the last archived month (October 2026) comes from the public `funding-rate-history` endpoint for instruments still listed.
  - Two quirks of the archive, handled: the monthly files of 2023-09..11 and 2024-02..07 flag every row `confirm=0` (the rows are historical and kept); files before ~2023-09 carry the volume only in contracts (`vol`), with `vol_ccy`/`vol_quote` = None: the quote volume is rebuilt as contracts × contract value × close (contract value from the current metadata, or inferred from the instrument's own later rows where both are present).
- **Metadata**: `GET /api/v5/public/instruments?instType=SWAP` (current values: `ctVal`, `tickSz`, `lotSz`, `minSz`, `listTime`). Delisted instruments are no longer returned (see §4).
- **Fees** (assumption, OKX regular tier for USDT-margined swaps): maker 0.02 %, taker 0.05 % — the values of the research profiles. Spread and slippage: the r1/r2 `baseline` / `adverse` profiles (hypothetical, not historical quotes).

## 2. Universe rule (owner, 2026-10-14) and enumeration

- On the 1st of each month M from 2023-01 to 2026-10: the **30** linear USDT crypto swaps with the highest **median daily quote volume of M-1** (UTC days with ≥ 720 minutes and a complete volume), among the instruments eligible at that date: candles in the archive since ≥ 42 days before the 1st of M, ≥ 15 such days in M-1, base not among the 20 pairs already used nor a stablecoin or gold token (ADA, APT, ARB, ATOM, AVAX, BNB, BTC, BUSD, DAI, DOGE, ETH, EUR, EURT, FDUSD, FIL, GBP, INJ, LINK, LTC, NEAR, OP, PAXG, PYUSD, RLUSD, SEI, SOL, SUI, TIA, TUSD, USD1, USDC, USDD, USDE, USDG, USDT, XAG, XAU, XAUT, XRP). Only data before M is used. Membership gates only new entries.
- **Enumeration of past instruments** (no endpoint lists them): union of OKX live swaps (crypto) and live spot pairs, Binance USD-M symbols (all statuses) and Binance spot pairs → 1107 candidate families, each probed in the OKX archive: **363 have OKX swap files** between 2022-09 and 2026-10, 744 have none (never listed as OKX swaps, or delisted before 2022-09, or absent from the archive — the archive cannot tell which). An OKX swap absent from all four lists cannot enter; it is not replaced.
- Result: **193 instruments** enter the universe at least one month; eligible per month from 60 (2023-01) to 258 (2026-10); 30 members every month.
- Window end proposed (open point, owner to decide): **2026-10-12T16:00Z**, the last archived minute + 1 (the daily files are aligned on UTC+8; the 10-13 file was not yet published). The window starts with the decisions of 2023-01-01.

### Monthly universe (top 10 shown; full lists in `universe-monthly.json`)

| Month | Eligible | First ten members by M-1 volume |
|---|---:|---|
| 2023-01 | 60 | MASK, ETC, CHZ, DYDX, PEOPLE, APE, AXS, DOT, SHIB, CRV |
| 2023-02 | 60 | ETC, GALA, SHIB, MASK, DYDX, AXS, APE, CRV, MANA, DOT |
| 2023-03 | 61 | CFX, DYDX, SHIB, ETC, MASK, GALA, DOT, GRT, SAND, PEOPLE |
| 2023-04 | 68 | CFX, MASK, BLUR, DYDX, ETC, SHIB, LDO, IMX, GALA, DOT |
| 2023-05 | 70 | CFX, BLUR, MASK, ETC, SHIB, DYDX, DOT, LDO, STX, GALA |
| 2023-06 | 70 | CFX, BLUR, MASK, LDO, SHIB, ETC, DYDX, GALA, STX, DOT |
| 2023-07 | 72 | PEPE, CFX, BLUR, MASK, STX, LDO, APE, CORE, ETC, DYDX |
| 2023-08 | 73 | BCH, PEPE, COMP, CFX, ETC, ORDI, AGLD, XLM, MASK, APE |
| 2023-09 | 73 | LPT, BCH, PEPE, YGG, SHIB, CFX, CRV, ETC, COMP, DYDX |
| 2023-10 | 106 | TRB, WLD, BCH, PEPE, STORJ, CFX, FLM, CRV, MKR, LPT |
| 2023-11 | 106 | TRB, BCH, WLD, PEPE, BSV, CFX, YGG, STORJ, EOS, MKR |
| 2023-12 | 111 | ORDI, TRB, BLUR, WLD, DYDX, BCH, MATIC, GALA, UNI, PEPE |
| 2024-01 | 115 | ORDI, TRB, BIGTIME, WLD, MATIC, DOT, MEME, DYDX, BLUR, USTC |
| 2024-02 | 121 | ORDI, TRB, PEOPLE, ETC, SATS, ENS, BLUR, MATIC, BSV, CFX |
| 2024-03 | 128 | ORDI, WLD, API3, STX, SATS, ETC, MATIC, ENS, TRB, PYTH |
| 2024-04 | 133 | PEPE, WLD, SHIB, ORDI, FLOKI, BCH, JUP, CFX, MATIC, RNDR |
| 2024-05 | 146 | PEPE, ORDI, BCH, CORE, WLD, ETHFI, SHIB, JTO, YGG, TON |
| 2024-06 | 151 | PEPE, PEOPLE, TRB, ORDI, WLD, WIF, SHIB, BONK, ETHFI, AR |
| 2024-07 | 153 | NOT, PEPE, PEOPLE, ORDI, TURBO, WLD, WIF, CRV, TON, TRB |
| 2024-08 | 149 | PEPE, WLD, PEOPLE, NOT, ORDI, SATS, TURBO, ZRO, WIF, CRV |
| 2024-09 | 151 | PEPE, SATS, ORDI, PEOPLE, CRV, NOT, WIF, WLD, MEW, TURBO |
| 2024-10 | 151 | PEPE, ORDI, PEOPLE, SATS, UXLINK, WLD, AAVE, WIF, TURBO, NOT |
| 2024-11 | 157 | PEPE, PEOPLE, WLD, ORDI, WIF, TURBO, MEW, SHIB, NEIROETH, POPCAT |
| 2024-12 | 162 | PEPE, ORDI, WLD, WIF, SHIB, MOODENG, PEOPLE, NEIRO, BONK, BCH |
| 2025-01 | 167 | PEPE, PNUT, HBAR, AAVE, ORDI, CRV, UXLINK, SHIB, WLD, ACT |
| 2025-02 | 181 | PEPE, AI16Z, FARTCOIN, PNUT, WLD, MOVE, AAVE, PENGU, WIF, ACT |
| 2025-03 | 188 | PEPE, FARTCOIN, WIF, PNUT, AI16Z, WLD, OM, AIXBT, ORDI, AAVE |
| 2025-04 | 200 | PEPE, TRUMP, AUCTION, IP, PNUT, FARTCOIN, ORDI, AAVE, WIF, WLD |
| 2025-05 | 205 | PEPE, FARTCOIN, TRUMP, PI, CRV, LAYER, VINE, EOS, PNUT, AAVE |
| 2025-06 | 210 | PEPE, MOODENG, TRUMP, KAITO, AAVE, WLD, PI, WIF, PNUT, FARTCOIN |
| 2025-07 | 205 | PEPE, HYPE, AAVE, FARTCOIN, UNI, TRUMP, WIF, WLD, MOODENG, BCH |
| 2025-08 | 213 | PEPE, PENGU, BONK, UNI, AAVE, WIF, CRV, TRUMP, FARTCOIN, WLD |
| 2025-09 | 211 | PEPE, PENGU, BIO, PUMP, AAVE, TRUMP, CRV, UNI, WLD, MAGIC |
| 2025-10 | 207 | PUMP, PEPE, WLD, HYPE, IP, PENGU, AAVE, WIF, FARTCOIN, MERL |
| 2025-11 | 207 | XPL, PUMP, PEPE, HYPE, WLFI, WLD, PENGU, H, AAVE, OKB |
| 2025-12 | 210 | ZEC, PEPE, HYPE, PUMP, ASTER, XPL, RESOLV, ICP, WLFI, TRUMP |
| 2026-01 | 229 | BEAT, ZEC, PIPPIN, PEPE, HYPE, BCH, PUMP, PIEVERSE, XPL, ASTER |
| 2026-02 | 229 | PEPE, ZEC, PUMP, HYPE, BCH, PIPPIN, AXS, IP, XPL, BEAT |
| 2026-03 | 229 | PIPPIN, RIVER, PEPE, HYPE, ZEC, BCH, WLFI, PUMP, ENSO, AXS |
| 2026-04 | 227 | RIVER, HYPE, PIPPIN, PEPE, ZEC, BEAT, TAO, TRUMP, BCH, PUMP |
| 2026-05 | 231 | RAVE, PEPE, ZEC, HYPE, RIVER, TRUMP, TAO, BEAT, WLD, LAB |
| 2026-06 | 236 | LAB, ZEC, HYPE, BSB, PEPE, TON, ONDO, TRUMP, WLD, BASED |
| 2026-07 | 244 | HYPE, ZEC, WLD, LAB, BEAT, H, PEPE, ALLO, TRUMP, TON |
| 2026-08 | 249 | ZEC, HYPE, LAB, WLD, PEPE, BEAT, ALLO, KAITO, PUMP, BASED |
| 2026-09 | 254 | ZEC, HYPE, BEAT, PUMP, WLD, PEPE, BICO, UNI, CAP, KAITO |
| 2026-10 | 258 | ZEC, HYPE, UNI, TRUMP, PEPE, PUMP, WLD, ENA, USELESS, LIT |

## 3. Candle coverage and gaps

- Over the 193 members, between their first archived candle (≥ 2022-09) and the window end: 3,618,570 missing minutes in all, of which 3,617,498 in gaps longer than 60 minutes on **12 instruments**; the rest are isolated minutes (filled flat at the previous close, counted).
- Gaps longer than 60 minutes (delisting then relisting, or archive holes):

| Instrument | Gap start | Length | Months in universe |
|---|---|---:|---:|
| FET-USDT-SWAP | 2024-07-05 08:05 | 1,140,204 min (791.8 d) | 3 |
| ZEC-USDT-SWAP | 2023-12-19 08:10 | 990,424 min (687.8 d) | 11 |
| DASH-USDT-SWAP | 2023-12-19 08:07 | 990,413 min (687.8 d) | 2 |
| ENJ-USDT-SWAP | 2023-08-24 08:00 | 273,747 min (190.1 d) | 1 |
| PIPPIN-USDT-SWAP | 2025-07-18 08:02 | 197,697 min (137.3 d) | 5 |
| ZK-USDT-SWAP | 2024-06-06 08:11 | 17,293 min (12.0 d) | 3 |
| OL-USDT-SWAP | 2025-10-24 08:17 | 7,317 min (5.1 d) | 2 |
| AGLD-USDT-SWAP | 2022-12-18 03:05 | 81 min (0.1 d) | 5 |
| GMT-USDT-SWAP | 2022-12-18 03:05 | 81 min (0.1 d) | 5 |
| TRX-USDT-SWAP | 2022-12-18 03:05 | 81 min (0.1 d) | 19 |
| CHZ-USDT-SWAP | 2022-12-18 03:06 | 80 min (0.1 d) | 8 |
| LPT-USDT-SWAP | 2022-12-18 03:06 | 80 min (0.1 d) | 6 |

- **Proposed rule for the gaps (to freeze):** no new entry when a gap lies within the 42 days before the decision (the warm-up); a position open when a gap starts settles at the last close before it (like a delisting). The engine applies it (tested); it uses no future information for entries.

## 4. Delisted instruments

- **38 members are delisted** (their data stop before the window end); their files are in the archive, their current metadata are not. A position open at the end of their data settles at the last close (reason `delisting`, counted apart).
- Metadata assumption for them (to freeze): tick inferred from the archived prices (smallest increment, as a power of ten); 1 coin per contract; no lot or minimum beyond the 5-USDT notional floor. It affects only rounding and the minimum-size checks.

| Instrument | First candle | Last candle | Months in universe | Inferred tick |
|---|---|---|---:|---:|
| YFII-USDT-SWAP | 2023-08-20 | 2024-02-07 | 1 | 0.1 |
| AGIX-USDT-SWAP | 2023-11-27 | 2024-06-28 | 1 | 0.0001 |
| RNDR-USDT-SWAP | 2023-10-09 | 2024-07-09 | 4 | 0.001 |
| MATIC-USDT-SWAP | 2023-08-20 | 2024-09-04 | 12 | 0.0001 |
| FRONT-USDT-SWAP | 2023-10-09 | 2024-09-04 | 1 | 0.0001 |
| GFT-USDT-SWAP | 2023-08-20 | 2024-12-03 | 2 | 1e-06 |
| FTM-USDT-SWAP | 2023-08-20 | 2025-01-07 | 9 | 0.0001 |
| VELO-USDT-SWAP | 2024-03-12 | 2025-05-21 | 1 | 1e-06 |
| EOS-USDT-SWAP | 2023-08-20 | 2025-05-22 | 20 | 0.0001 |
| BSV-USDT-SWAP | 2023-08-20 | 2025-06-12 | 4 | 0.01 |
| X-USDT-SWAP | 2024-10-25 | 2025-06-12 | 1 | 1e-08 |
| ID-USDT-SWAP | 2023-12-08 | 2025-08-01 | 2 | 0.0001 |
| MKR-USDT-SWAP | 2023-08-20 | 2025-09-04 | 5 | 0.1 |
| ZEREBRO-USDT-SWAP | 2024-12-27 | 2025-09-12 | 1 | 0.0001 |
| NEIROETH-USDT-SWAP | 2024-09-06 | 2025-09-12 | 2 | 1e-05 |
| ALCH-USDT-SWAP | 2025-01-03 | 2025-09-12 | 2 | 0.0001 |
| LUNC-USDT-SWAP | 2023-08-20 | 2025-09-18 | 1 | 1e-08 |
| USTC-USDT-SWAP | 2023-08-20 | 2025-09-18 | 1 | 1e-05 |
| UXLINK-USDT-SWAP | 2024-07-18 | 2025-09-23 | 4 | 0.0001 |
| ARC-USDT-SWAP | 2025-01-24 | 2025-09-26 | 1 | 0.0001 |
| GRIFFAIN-USDT-SWAP | 2024-12-27 | 2025-09-26 | 1 | 0.0001 |
| VINE-USDT-SWAP | 2025-01-26 | 2025-09-26 | 4 | 1e-05 |
| FLM-USDT-SWAP | 2023-08-20 | 2025-10-24 | 2 | 1e-05 |
| DOGS-USDT-SWAP | 2024-08-26 | 2025-10-24 | 2 | 1e-06 |
| PERP-USDT-SWAP | 2023-08-20 | 2025-10-24 | 1 | 0.0001 |
| STORJ-USDT-SWAP | 2023-08-20 | 2025-10-30 | 4 | 0.0001 |
| TNSR-USDT-SWAP | 2024-04-08 | 2025-10-30 | 1 | 0.001 |
| AI16Z-USDT-SWAP | 2024-12-20 | 2025-11-06 | 4 | 0.0001 |
| BNT-USDT-SWAP | 2022-11-01 | 2025-11-14 | 2 | 0.0001 |
| GOAT-USDT-SWAP | 2024-10-24 | 2025-12-17 | 3 | 0.0001 |
| CETUS-USDT-SWAP | 2023-05-10 | 2025-12-26 | 1 | 1e-05 |
| CATI-USDT-SWAP | 2024-09-20 | 2026-01-24 | 1 | 0.0001 |
| OM-USDT-SWAP | 2024-02-20 | 2026-02-20 | 4 | 1e-05 |
| CAT-USDT-SWAP | 2024-09-06 | 2026-02-27 | 1 | 1e-09 |
| ACE-USDT-SWAP | 2023-12-18 | 2026-02-27 | 2 | 0.001 |
| TON-USDT-SWAP | 2022-12-28 | 2026-06-16 | 14 | 0.001 |
| IP-USDT-SWAP | 2025-02-13 | 2026-07-02 | 13 | 1e-05 |
| LRC-USDT-SWAP | 2022-11-01 | 2026-07-20 | 2 | 0.0001 |

## 5. Funding

- All 193 members have funding events from the archive (no member without funding; no member whose funding starts more than 24 h after its candles or stops more than 24 h before them).
- Funding gaps longer than 12 h: all inside the candle gaps of §3 (no position can be open there), **except one: AUCTION-USDT-SWAP, 16 h between its first two settlements at its listing (2023-11-30 16:00Z)**, more than two months before it can first enter the universe (2024-02). Isolated intervals of 9–11 h occur once per instrument around 2022-12-18 (an irregular settlement time in the archive), not a missing event.
- **Settlement frequencies observed** (rounded hours between consecutive settlements; 5–11 h values are timestamp irregularities, not regimes): 8 h: 56 instruments; 4/8 h: 46 instruments; 4 h: 34 instruments; 1/4 h: 15 instruments; 1/2/4/8 h: 13 instruments; 1/4/8 h: 10 instruments; 1/2/4 h: 10 instruments; 2/4 h: 5 instruments; 2/4/8 h: 4 instruments. Older instruments moved from 8 h to 4 h around 2026-04; several recent ones settle every 1, 2 or 4 h. **Funding is debited and credited at the recorded settlement instants** (no fixed 8 h calendar); the sizing provision is the profile rate per 8 h of horizon (an assumption of the sizing only).
- Treatment of missing funding (to freeze): no missing settlement is found where a position can be open (the AUCTION one precedes its eligibility); should one appear, the missing event is booked conservatively (the rate of the nearest recorded settlement taken against the position, whatever its sign) and reported — never booked as zero.

## 6. Contract metadata (current values for listed members)

| Instrument | ctVal | tickSz | lotSz | minSz | listTime | First / last month in universe |
|---|---:|---:|---:|---:|---|---|
| 1INCH-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2020-12-25 | 2023-01 / 2023-08 |
| AAVE-USDT-SWAP | 0.1 | 0.01 | 0.1 | 0.1 | 2020-12-10 | 2023-01 / 2026-10 |
| ACH-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2024-02-22 | 2025-03 / 2025-03 |
| ACT-USDT-SWAP | 1 | 1e-06 | 1 | 1 | 2024-11-14 | 2025-01 / 2026-01 |
| AEVO-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2024-03-13 | 2024-05 / 2024-07 |
| AGLD-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2021-09-07 | 2023-07 / 2025-02 |
| AIXBT-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-01-03 | 2025-03 / 2025-07 |
| ALGO-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2020-04-21 | 2023-01 / 2025-01 |
| ALLO-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-11-11 | 2026-07 / 2026-09 |
| ANIME-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2025-01-23 | 2025-07 / 2025-07 |
| APE-USDT-SWAP | 0.1 | 1e-05 | 1 | 1 | 2022-03-17 | 2023-01 / 2024-12 |
| API3-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2022-03-03 | 2023-09 / 2024-03 |
| AR-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2023-02-14 | 2024-04 / 2024-10 |
| ASTER-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2025-09-22 | 2025-12 / 2026-10 |
| AUCTION-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2023-12-01 | 2024-02 / 2025-04 |
| AXS-USDT-SWAP | 0.1 | 0.0001 | 1 | 1 | 2021-07-28 | 2023-01 / 2026-03 |
| BABY-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2025-04-10 | 2025-06 / 2025-06 |
| BAND-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2020-09-07 | 2023-01 / 2023-02 |
| BARD-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2025-09-18 | 2026-04 / 2026-04 |
| BASED-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2026-03-30 | 2026-06 / 2026-08 |
| BCH-USDT-SWAP | 0.1 | 0.1 | 0.1 | 0.1 | 2019-11-12 | 2023-01 / 2026-10 |
| BEAT-USDT-SWAP | 10 | 1e-05 | 0.1 | 0.1 | 2025-11-12 | 2026-01 / 2026-10 |
| BERA-USDT-SWAP | 0.1 | 0.0001 | 1 | 1 | 2025-02-06 | 2025-04 / 2026-03 |
| BICO-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2021-12-09 | 2026-09 / 2026-09 |
| BIGTIME-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2023-10-12 | 2023-12 / 2024-11 |
| BILL-USDT-SWAP | 100 | 1e-05 | 1 | 1 | 2026-05-06 | 2026-08 / 2026-08 |
| BIO-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2025-01-03 | 2025-09 / 2026-06 |
| BLUR-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2023-02-15 | 2023-04 / 2026-05 |
| BOME-USDT-SWAP | 1000 | 1e-07 | 1 | 1 | 2024-08-15 | 2024-11 / 2024-12 |
| BONK-USDT-SWAP | 100000 | 1e-09 | 1 | 1 | 2024-01-08 | 2024-04 / 2025-11 |
| BSB-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2026-04-02 | 2026-06 / 2026-07 |
| CAP-USDT-SWAP | 100 | 1e-05 | 1 | 1 | 2026-06-26 | 2026-09 / 2026-09 |
| CELO-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2021-09-24 | 2023-01 / 2024-11 |
| CFX-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2021-03-12 | 2023-03 / 2025-10 |
| CHZ-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2021-03-12 | 2023-01 / 2026-06 |
| COMP-USDT-SWAP | 0.1 | 0.01 | 1 | 1 | 2020-04-21 | 2023-01 / 2023-11 |
| CORE-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2023-02-08 | 2023-04 / 2024-08 |
| CRV-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2020-09-04 | 2023-01 / 2026-10 |
| DASH-USDT-SWAP | 0.01 | 0.01 | 1 | 1 | 2025-11-06 | 2026-02 / 2026-10 |
| DOT-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2020-08-25 | 2023-01 / 2026-10 |
| DYDX-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2021-09-08 | 2023-01 / 2024-11 |
| EIGEN-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-10-01 | 2025-10 / 2025-11 |
| ENA-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-09-17 | 2025-11 / 2026-10 |
| ENJ-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2024-03-01 | 2026-05 / 2026-05 |
| ENS-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2021-11-11 | 2023-01 / 2025-01 |
| ENSO-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2025-10-29 | 2026-03 / 2026-03 |
| ETC-USDT-SWAP | 10 | 0.001 | 0.01 | 0.01 | 2019-11-12 | 2023-01 / 2026-10 |
| ETHFI-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-03-18 | 2024-05 / 2026-10 |
| ETHW-USDT-SWAP | 0.1 | 0.0001 | 1 | 1 | 2022-09-16 | 2023-01 / 2023-07 |
| FARTCOIN-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-12-20 | 2025-02 / 2026-02 |
| FET-USDT-SWAP | 10 | 0.0001 | 1 | 1 | 2026-09-05 | 2024-04 / 2024-07 |
| FLOKI-USDT-SWAP | 100000 | 1e-08 | 1 | 1 | 2023-02-20 | 2023-06 / 2025-08 |
| GALA-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2021-09-24 | 2023-01 / 2026-05 |
| GAS-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2023-10-24 | 2024-01 / 2024-01 |
| GIGGLE-USDT-SWAP | 0.01 | 0.01 | 1 | 1 | 2025-10-25 | 2026-05 / 2026-09 |
| GMT-USDT-SWAP | 1 | 1e-06 | 1 | 1 | 2022-04-01 | 2023-01 / 2023-12 |
| GRT-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2020-12-20 | 2023-02 / 2023-06 |
| H-USDT-SWAP | 100 | 1e-05 | 0.1 | 0.1 | 2025-07-03 | 2025-10 / 2026-07 |
| HBAR-USDT-SWAP | 100 | 1e-05 | 0.1 | 0.1 | 2023-08-16 | 2024-12 / 2025-12 |
| HOME-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2025-06-13 | 2026-07 / 2026-09 |
| HYPE-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2025-02-21 | 2025-06 / 2026-10 |
| ICP-USDT-SWAP | 0.01 | 0.001 | 1 | 1 | 2021-05-14 | 2023-01 / 2026-10 |
| IMX-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2021-11-18 | 2023-03 / 2024-03 |
| JELLYJELLY-USDT-SWAP | 100 | 1e-05 | 1 | 1 | 2025-03-26 | 2025-10 / 2026-01 |
| JTO-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-01-08 | 2024-03 / 2026-08 |
| JUP-USDT-SWAP | 10 | 0.0001 | 1 | 1 | 2024-01-31 | 2024-04 / 2025-03 |
| KAITO-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2025-02-20 | 2025-05 / 2026-09 |
| LAB-USDT-SWAP | 10 | 1e-05 | 0.1 | 0.1 | 2025-11-01 | 2026-05 / 2026-09 |
| LAYER-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2025-02-11 | 2025-04 / 2025-06 |
| LDO-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2023-02-02 | 2023-04 / 2025-11 |
| LIT-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2025-12-24 | 2026-03 / 2026-10 |
| LPT-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2021-04-23 | 2023-09 / 2025-07 |
| LUNA-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2022-05-28 | 2023-01 / 2026-01 |
| MAGIC-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2023-02-02 | 2023-04 / 2025-09 |
| MANA-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2021-03-12 | 2023-01 / 2023-07 |
| MASK-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2021-03-04 | 2023-01 / 2025-07 |
| ME-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2024-12-10 | 2025-03 / 2025-03 |
| MEME-USDT-SWAP | 100 | 1e-07 | 1 | 1 | 2023-11-03 | 2024-01 / 2024-11 |
| MERL-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2024-04-19 | 2024-06 / 2026-02 |
| MEW-USDT-SWAP | 1000 | 1e-07 | 1 | 1 | 2024-04-15 | 2024-07 / 2024-12 |
| MINA-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2021-06-09 | 2023-03 / 2023-04 |
| MON-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-10-10 | 2026-01 / 2026-05 |
| MOODENG-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2024-10-03 | 2024-12 / 2025-08 |
| MORPHO-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-11-25 | 2025-02 / 2025-02 |
| MOVE-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2024-12-09 | 2025-02 / 2025-05 |
| NEIRO-USDT-SWAP | 1000 | 1e-08 | 1 | 1 | 2024-10-03 | 2024-12 / 2025-08 |
| NEO-USDT-SWAP | 1 | 0.001 | 1 | 1 | 2020-02-28 | 2023-04 / 2024-05 |
| NIGHT-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2025-12-09 | 2026-02 / 2026-02 |
| NMR-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2024-01-11 | 2025-10 / 2025-10 |
| NOT-USDT-SWAP | 100 | 1e-07 | 1 | 1 | 2024-05-16 | 2024-07 / 2025-04 |
| O-USDT-SWAP | 10 | 0.0001 | 1 | 1 | 2026-06-17 | 2026-08 / 2026-08 |
| OKB-USDT-SWAP | 0.01 | 0.01 | 1 | 1 | 2025-09-04 | 2025-11 / 2026-10 |
| OL-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2025-10-29 | 2025-02 / 2025-03 |
| ONDO-USDT-SWAP | 10 | 0.0001 | 1 | 1 | 2024-08-06 | 2025-01 / 2026-10 |
| ONT-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2020-04-21 | 2023-05 / 2024-05 |
| OPN-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2026-02-23 | 2026-07 / 2026-07 |
| ORDI-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2023-05-21 | 2023-08 / 2026-08 |
| PENGU-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2024-12-17 | 2025-02 / 2026-10 |
| PEOPLE-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2021-12-02 | 2023-01 / 2025-07 |
| PEPE-USDT-SWAP | 1e+07 | 1e-09 | 0.1 | 0.1 | 2023-05-03 | 2023-07 / 2026-10 |
| PI-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2025-02-20 | 2025-05 / 2026-04 |
| PIEVERSE-USDT-SWAP | 10 | 0.0001 | 1 | 1 | 2025-11-16 | 2026-01 / 2026-02 |
| PIPPIN-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-12-02 | 2026-01 / 2026-05 |
| PNUT-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2024-11-12 | 2025-01 / 2025-08 |
| POL-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2024-09-24 | 2026-02 / 2026-02 |
| POPCAT-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2024-08-29 | 2024-11 / 2025-05 |
| PUMP-USDT-SWAP | 1000 | 1e-06 | 1 | 1 | 2025-07-14 | 2025-09 / 2026-10 |
| PYTH-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2023-11-20 | 2024-01 / 2024-03 |
| RAVE-USDT-SWAP | 10 | 0.0001 | 0.01 | 0.01 | 2025-12-15 | 2026-05 / 2026-06 |
| RAY-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-03-12 | 2026-10 / 2026-10 |
| RE-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2026-06-17 | 2026-08 / 2026-09 |
| RESOLV-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-06-10 | 2025-08 / 2026-04 |
| RIVER-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2026-01-09 | 2026-03 / 2026-06 |
| RVN-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2021-04-23 | 2025-07 / 2025-07 |
| SAHARA-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2025-06-26 | 2026-04 / 2026-06 |
| SAND-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2021-04-02 | 2023-01 / 2025-01 |
| SATS-USDT-SWAP | 1e+07 | 1e-12 | 1 | 1 | 2023-12-18 | 2024-02 / 2025-05 |
| SENT-USDT-SWAP | 100 | 1e-05 | 1 | 1 | 2025-11-14 | 2026-03 / 2026-03 |
| SHIB-USDT-SWAP | 1e+06 | 1e-09 | 0.1 | 0.1 | 2021-05-09 | 2023-01 / 2026-10 |
| SLX-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2026-06-01 | 2026-08 / 2026-08 |
| SNX-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2020-08-21 | 2023-02 / 2025-11 |
| SOON-USDT-SWAP | 10 | 0.0001 | 0.1 | 0.1 | 2025-05-23 | 2025-12 / 2025-12 |
| SPK-USDT-SWAP | 100 | 1e-05 | 1 | 1 | 2025-06-17 | 2025-08 / 2025-09 |
| STABLE-USDT-SWAP | 100 | 1e-05 | 1 | 1 | 2025-12-08 | 2026-03 / 2026-03 |
| STRK-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2024-02-20 | 2024-05 / 2025-12 |
| STX-USDT-SWAP | 10 | 0.0001 | 0.1 | 0.1 | 2023-02-21 | 2023-05 / 2024-09 |
| SUSHI-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2020-09-02 | 2023-01 / 2023-12 |
| TAO-USDT-SWAP | 0.01 | 0.1 | 1 | 1 | 2024-09-20 | 2025-11 / 2026-10 |
| TRB-USDT-SWAP | 0.1 | 0.01 | 1 | 1 | 2020-09-08 | 2023-01 / 2025-07 |
| TRIA-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2026-02-05 | 2026-04 / 2026-04 |
| TRUMP-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2025-01-19 | 2025-04 / 2026-10 |
| TRX-USDT-SWAP | 1000 | 1e-05 | 0.01 | 0.01 | 2019-11-12 | 2023-01 / 2026-10 |
| TURBO-USDT-SWAP | 10000 | 1e-07 | 0.1 | 0.1 | 2023-12-14 | 2024-06 / 2025-01 |
| UB-USDT-SWAP | 100 | 1e-05 | 1 | 1 | 2026-04-28 | 2026-07 / 2026-09 |
| UMA-USDT-SWAP | 0.1 | 0.0001 | 1 | 1 | 2020-09-04 | 2023-06 / 2024-03 |
| UNI-USDT-SWAP | 1 | 0.001 | 1 | 1 | 2020-09-17 | 2023-01 / 2026-10 |
| USELESS-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-07-25 | 2025-11 / 2026-10 |
| VIRTUAL-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-12-11 | 2025-02 / 2026-02 |
| VVV-USDT-SWAP | 0.1 | 0.001 | 1 | 1 | 2026-07-03 | 2026-10 / 2026-10 |
| W-USDT-SWAP | 1 | 1e-06 | 1 | 1 | 2024-04-03 | 2024-06 / 2024-07 |
| WCT-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-04-15 | 2025-06 / 2025-07 |
| WIF-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-04-15 | 2024-06 / 2026-04 |
| WLD-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2023-07-24 | 2023-10 / 2026-10 |
| WLFI-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-08-23 | 2025-11 / 2026-09 |
| WOO-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2023-02-14 | 2023-05 / 2023-07 |
| XLM-USDT-SWAP | 100 | 1e-05 | 0.1 | 0.1 | 2020-04-21 | 2023-08 / 2026-10 |
| XPL-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2025-08-28 | 2025-11 / 2026-10 |
| YFI-USDT-SWAP | 0.0001 | 1 | 1 | 1 | 2020-09-02 | 2023-01 / 2023-12 |
| YGG-USDT-SWAP | 1 | 1e-05 | 1 | 1 | 2021-08-06 | 2023-09 / 2024-06 |
| ZAMA-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2026-01-09 | 2026-03 / 2026-03 |
| ZEC-USDT-SWAP | 0.01 | 0.01 | 1 | 1 | 2025-11-06 | 2025-12 / 2026-10 |
| ZIL-USDT-SWAP | 100 | 1e-06 | 1 | 1 | 2020-09-09 | 2023-02 / 2023-03 |
| ZK-USDT-SWAP | 10 | 1e-06 | 1 | 1 | 2024-06-18 | 2024-07 / 2025-12 |
| ZRO-USDT-SWAP | 1 | 0.0001 | 1 | 1 | 2024-06-20 | 2024-08 / 2026-03 |
| ZRX-USDT-SWAP | 10 | 1e-05 | 1 | 1 | 2020-08-21 | 2023-05 / 2024-04 |

## 7. Points for the owner before the freeze

1. Window end 2026-10-12T16:00Z (last archived minute) instead of 2026-10-14.
2. The gap rule of §3 and the delisting settlement.
3. The metadata assumption for the delisted instruments (§4).
4. The enumeration limit (§2): an OKX swap absent from the four public lists cannot enter.
5. Expected sample (no performance read): 30 slots per month over 46 months; the validation rate (~0.4 trade per pair-month) with 4 portfolio slots suggests on the order of 300–450 trades, to be confirmed by the run.

## 8. Files

- `candidates.json` sha256 `4aa04b678b0027ed…`
- `inventory.json` sha256 `e6ecaf55020873fa…`
- `universe-monthly.json` sha256 `cd3378aaa266d166…`
- `instrument-assumptions-okx-monthly.json` sha256 `d40923257b3291b0…`
- `readiness-monthly.json` sha256 `7abc76b60ea14d64…`
- tape `20261014T113000Z-tape-okx-swing-v2-monthly` (manifest-candles.json, manifest-funding.json, daily-quote-volume.json); code `scripts/research_swing2/okx.py`, `okx_universe.py` (branch claude/research-swing-v2-okx, commit 2c3cfb3e).
