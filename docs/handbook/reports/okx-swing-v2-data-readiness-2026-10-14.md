# OKX_SWING_V2_DATA_READINESS — revision 2 (2026-10-14, owner's corrections)

Data readiness for the single OKX historical test of `B120-Q0.5|long|C|S3|T3|H14d`. **No performance computed.** Revision 1 (the first report) is superseded by this one; the owner validated the end of data (2026-10-12T16:00Z), the no-entry rule for interruptions in the warm-up, the reconstruction of retired contracts' metadata (no automatic 1 coin = 1 contract) and the enumeration limit, and required corrections A–D, all applied below. Per-series audit: `OKX_SWING_V2_INSTRUMENT_AUDIT.md` (193 rows).

## A. Contracts and the monthly universe

- **Contract value reconstructed from history**, day by day, never carried backwards: REST daily `volCcy / vol` (OKX's own record, listed instruments, back to 2022-09) and archive minutes with both base and contract volume (from 2023-08 for every instrument). `ctMult` = 1 for every linear swap. For every listed instrument the observed value equals the current `ctVal` over its whole history (0 differences). **One real contract change: OL-USDT-SWAP, 10 → 100 on 2025-10-30**, split into two series; BSV shows ±1 % rounding noise only (threshold for a change: 5 %).
- **Volume units checked**: on the 267 listed instruments, the archive quote volume over a full day equals the REST `volCcyQuote` (median ratio 1.000–1.000).
- **Re-listings and contract changes are separate series**: an interruption longer than 7 days or a contract change starts a new segment, with its own warm-up, metadata, funding and membership: DASH-USDT-SWAP#2, ENJ-USDT-SWAP#2, FET-USDT-SWAP#1, OL-USDT-SWAP#1, PIPPIN-USDT-SWAP#2, ZEC-USDT-SWAP#2, ZK-USDT-SWAP#2 (members), plus non-member segments of KITE, ZEN, ZK.
- **Reliable volumes only in the ranking** (day sources over all instruments: rest_quote 196,636, archive_quote 44,527, archive_contracts_x_prior_ctval 5). Unreliable days (contract volume without a contract value observed on or before the day — the pre-2023-08 archive of instruments that are delisted today) are excluded. **Effect on the top 30**: 2023-02: out LRC / in KSM; 2023-03: out LRC / in AGLD; 2023-07: out CETUS / in MINA; 2023-09: out BNT / in PEOPLE; every other month unchanged.
- **Survivorship limit, stated**: for some swaps delisted since, the OKX archive starts only in 2023-08 (e.g. MATIC, FTM); others have earlier files but with the contract volume only and no contract value observable before 2023-08 (e.g. LRC), so they are excluded as unreliable. In 2023-01…07 the universe is therefore drawn mostly from instruments still listed today. This cannot be corrected (no source) and is reported.
- **Retired contracts' tick, lot and minimum**: tick = smallest increment of the traded prices in the segment, lot = smallest increment of the per-minute contract volume (rounded down to a 1/2/5 step), minimum = one lot; 1 coin = 1 contract is never assumed (ctVal observed). For 14 listed series whose archive files carry no contract volume, the current lot is used, justified by a contract value unchanged over the segment. Current vs observed: tick differs on 5 listed series (BCH, PEPE, RAVE, SOON, TRB), lot on 10 (observed historical values are used).
- **No future data in the universe**: the universe of M uses only days before the 1st of M (reliable volumes of M-1) and the start of the segment (≥ 42 days before M); tested (`test_okx_monthly_universe_uses_no_data_from_the_month_or_later`, `test_okx_a_segment_starting_less_than_42_days_before_the_month_is_not_eligible`). 193 series enter at least one month; eligible per month 57–257; 30 members every month.

## B. Interruptions and delistings

- Interruptions longer than 60 minutes inside member series: 5 (AGLD-USDT-SWAP 2022-12-18 03:06 81 min; CHZ-USDT-SWAP 2022-12-18 03:07 80 min; GMT-USDT-SWAP 2022-12-18 03:06 81 min; LPT-USDT-SWAP 2022-12-18 03:07 80 min; TRX-USDT-SWAP 2022-12-18 03:06 81 min).
- **No retroactive forced exit**: an open position is carried across an interruption; the first bar after it applies (stop or target at its open, the worse of the stop and the open for a gap through the stop). A holding deadline that falls inside an interruption moves to the first bar after it; beyond the end of the data the position is **censored** (valued at the last close, reported).
- **No new entry** when an interruption longer than 60 minutes lies within the 42 days before the decision (validated).
- **Delisting**: a series ending before the end of the data is a delisting; a position still open settles at the **mean of the last 60 one-minute closes** (proxy of OKX's settlement on the last-hour average index price; the official per-contract settlement prices are not in the archive or a public endpoint), with taker exit costs. 38 member series are delisted.
- **Conservative scenario (declared, required by the verdict)**: every trade touched by a delisting, an interruption or the censoring is booked at no better than −1 R of its conservative planned risk. Results are reported for the main and the conservative scenario; the verdict requires both.

## C. Funding

- Real settlement instants and rates (archive, then REST for October 2026); no fixed calendar. Cadences observed per member series (1 / 2 / 4 / 8 h, many changing from 8 h to 4 h in 2026): see the audit.
- **Risk provision at the cadence known at entry** (the last interval between recorded settlements before the decision): one provision per settlement of the 14-day horizon. **Conservative scenario**: settlements twice as frequent (cadence / 2, at least 1 h).
- **Missing settlements are never zero**: between consecutive recorded settlements spaced more than 1.5 × the cadence, each missing one is booked against the position at the nearest recorded rate (absolute value), and counted. None is found where a position can be open (the only one, AUCTION at its listing, precedes its eligibility).

## D. End of window

- End of data **2026-10-12T16:00Z**; **no new trade after 2026-09-28T16:00Z** (14 full days); decisions from 2023-01-01. Positions run to their exit; a position still open at the end of the data (only possible through an interruption) is censored and reported.

## Technical checks (no performance)

- Tests: 40 passing (`tests/test_research_swing2.py`), including the anti-lookahead tests of the universe, the segment split, interruptions, deadlines inside interruptions, censoring, delisting settlement, cadence and missing settlements, the conservative scenario, the 14-day stop of decisions. The B30 check of the engine (339 paths) is still identical.
- Root load: 193 series, 36 s, 4.0 GB of memory in one process (candles kept from the first month of membership to the last + 15 days; whole tail for series with interruptions). All candidate decisions fall inside their series' months of membership; all 193 series have a contract value, a tick and a lot.

## Monthly universe (top 10; full lists in `universe-monthly-v2.json`)

| Month | Eligible | First ten |
|---|---:|---|
| 2023-01 | 57 | MASK, ETC, CHZ, DYDX, PEOPLE, APE, AXS, DOT, SHIB, CRV |
| 2023-02 | 57 | ETC, GALA, SHIB, MASK, DYDX, AXS, APE, CRV, MANA, DOT |
| 2023-03 | 57 | CFX, DYDX, SHIB, ETC, MASK, GALA, DOT, GRT, SAND, PEOPLE |
| 2023-04 | 64 | CFX, MASK, BLUR, DYDX, ETC, SHIB, LDO, IMX, GALA, DOT |
| 2023-05 | 66 | CFX, BLUR, MASK, ETC, SHIB, DYDX, DOT, LDO, STX, GALA |
| 2023-06 | 66 | CFX, BLUR, MASK, LDO, SHIB, ETC, DYDX, GALA, STX, DOT |
| 2023-07 | 67 | PEPE, CFX, BLUR, MASK, STX, LDO, APE, CORE, ETC, DYDX |
| 2023-08 | 68 | BCH, PEPE, COMP, CFX, ETC, ORDI, AGLD, XLM, MASK, APE |
| 2023-09 | 68 | LPT, BCH, PEPE, YGG, SHIB, CFX, CRV, ETC, COMP, DYDX |
| 2023-10 | 106 | TRB, WLD, BCH, PEPE, STORJ, CFX, FLM, CRV, MKR, LPT |
| 2023-11 | 106 | TRB, BCH, WLD, PEPE, BSV, CFX, YGG, STORJ, EOS, MKR |
| 2023-12 | 111 | ORDI, TRB, BLUR, WLD, DYDX, BCH, MATIC, GALA, UNI, PEPE |
| 2024-01 | 115 | ORDI, TRB, BIGTIME, WLD, MATIC, DOT, MEME, DYDX, BLUR, USTC |
| 2024-02 | 121 | ORDI, TRB, PEOPLE, ETC, SATS, ENS, BLUR, MATIC, BSV, CFX |
| 2024-03 | 128 | ORDI, WLD, API3, STX, SATS, ETC, MATIC, ENS, TRB, PYTH |
| 2024-04 | 132 | PEPE, WLD, SHIB, ORDI, FLOKI, BCH, JUP, CFX, MATIC, RNDR |
| 2024-05 | 146 | PEPE, ORDI, BCH, CORE, WLD, ETHFI, SHIB, JTO, YGG, TON |
| 2024-06 | 151 | PEPE, PEOPLE, TRB, ORDI, WLD, WIF, SHIB, BONK, ETHFI, AR |
| 2024-07 | 152 | NOT, PEPE, PEOPLE, ORDI, TURBO, WLD, WIF, CRV, TON, TRB |
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
| 2025-12 | 206 | PEPE, HYPE, PUMP, ASTER, XPL, RESOLV, ICP, WLFI, TRUMP, BCH |
| 2026-01 | 228 | BEAT, ZEC#2, PEPE, HYPE, BCH, PUMP, PIEVERSE, XPL, ASTER, AAVE |
| 2026-02 | 229 | PEPE, ZEC#2, PUMP, HYPE, BCH, PIPPIN#2, AXS, IP, XPL, BEAT |
| 2026-03 | 229 | PIPPIN#2, RIVER, PEPE, HYPE, ZEC#2, BCH, WLFI, PUMP, ENSO, AXS |
| 2026-04 | 227 | RIVER, HYPE, PIPPIN#2, PEPE, ZEC#2, BEAT, TAO, TRUMP, BCH, PUMP |
| 2026-05 | 230 | RAVE, PEPE, ZEC#2, HYPE, RIVER, TRUMP, TAO, BEAT, WLD, LAB |
| 2026-06 | 236 | LAB, ZEC#2, HYPE, BSB, PEPE, TON, ONDO, TRUMP, WLD, BASED |
| 2026-07 | 244 | HYPE, ZEC#2, WLD, LAB, BEAT, H, PEPE, ALLO, TRUMP, TON |
| 2026-08 | 249 | ZEC#2, HYPE, LAB, WLD, PEPE, BEAT, ALLO, KAITO, PUMP, BASED |
| 2026-09 | 254 | ZEC#2, HYPE, BEAT, PUMP, WLD, PEPE, BICO, UNI, CAP, KAITO |
| 2026-10 | 257 | ZEC#2, HYPE, UNI, TRUMP, PEPE, PUMP, WLD, ENA, USELESS, LIT |

## Files

- `instrument-audit.json` sha256 `108416c0d1c7a615…`
- `reliable-daily-volume.json` sha256 `f09574d451f52874…`
- `universe-monthly-v2.json` sha256 `ef6897b6f2ff0004…`
- `instrument-audit-193.json` sha256 `c395fe584a26bd4d…`
- `OKX_SWING_V2_INSTRUMENT_AUDIT.md` sha256 `1ec4763a6f115530…`
- `root-okx/build-receipt.json` sha256 `0e80f046c2883cba…`
- `root-okx/instrument-assumptions.json` sha256 `c67055cde4ee308c…`
