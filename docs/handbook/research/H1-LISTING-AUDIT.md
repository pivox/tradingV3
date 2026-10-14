# H1 — Listing audit (metadata only, 2026-10-14)

**No candle value, price, return or PnL was read.** The counts come from contract metadata, official archive
indexes (file names) and the timestamps of archived candles, the last used only to check coverage.

They are reproduced by:

```bash
python -m scripts.research_listing.audit build|funding|classify|summarize \
  --meta <campaign>/metadata --swing-okx <swing-v2-okx dir> --out <campaign>/audit
```

`<campaign>` = `tradingV3-private/research/20261014T210000Z-h1-listing`. Code:
`python-orchestrator/scripts/research_listing/audit.py`.

## 1. Sources (snapshots of 2026-10-14, sha256 in `H1-DATA-MANIFEST.md`)

| Source | What it gives | Limits |
|---|---|---|
| OKX `GET /api/v5/public/instruments?instType=SWAP` (`okx-swap.json`) | Live contracts. Official `listTime`, `ruleType` (normal / pre_market), `preMktSwTime` (pre-market → normal switch), `ctVal`, `ctValCcy`, `lotSz`, `minSz`, `tickSz`, `instCategory` | Live contracts only; current specifications only |
| OKX historical-file index `GET /api/v5/public/market-data-history` (file names) + the swing v2 OKX segment audit (`instrument-audit.json`, from candle timestamps) | Delisted contracts still in the archive; first archived day; interruptions | The archive starts in 2023-08 for contracts delisted since; no official listing date for delisted contracts |
| Binance `GET /fapi/v1/exchangeInfo` (`binance-fapi.json`) | `onboardDate` (official listing), `deliveryDate` and status `SETTLING` (delisted), filters `LOT_SIZE` / `MIN_NOTIONAL` | 927 symbols: 793 TRADING, 133 SETTLING, 1 PENDING; long-delisted symbols are absent |
| Binance archive bucket listing `data.binance.vision` (`binance-um-archive-symbols.json`) | **Every USD-M symbol ever archived** (1,056), delisted included; first daily file for symbols absent from exchangeInfo | 5 perpetuals with non-ASCII symbols could not be probed (listed in §6) |

## 2. Event definitions used

The audit distinguishes:

- **The venue's listing time.** OKX `listTime` (live); Binance `onboardDate`.
- **The first archived candle.** It is used only to check coverage. For delisted OKX contracts it is the only
  date available, so it is marked **UNVERIFIED_LISTING_DATE**.
- **Pre-market.** `ruleType = pre_market`, or a `preMktSwTime`.
- **Normal trading start.**
- **Order across the two venues studied.**
  - `FIRST_OBSERVED_PERP_LISTING`: the venue's listing is no more than 24 h after the other venue's, or the other
    venue never listed the base.
  - `FOLLOW_ON_VENUE_LISTING`: later than the other venue by more than 24 h.
  - `PREMARKET`: an OKX pre-market contract.
  - `UNKNOWN_ORDER`: one date is unverified and the two dates are within 31 days. Never promoted to "first".
  - A same-day co-listing is counted once, as the OKX event (`CO_LISTED_ASSIGNED_TO_OKX` on the Binance side).
- **Not observed.** The creation of the token, its first spot listing and perpetuals on venues other than OKX and
  Binance (Bybit, Bitget, Hyperliquid …). A "first observed" listing may follow a perpetual elsewhere. This is a
  coverage limit, stated.

Window of the historical sets: **2023-09-01 ≤ t0 < 2026-09-01**. Excluded bases: the 20 programme pairs,
stablecoins, gold / commodity tokens and non-crypto categories.

## 3. Results

| Indicator | OKX | Binance USD-M |
|---|---:|---:|
| Events announced in the draft | ≈ 255 (OKX, 2023-09 → 2026-10) | — |
| Events found in the window (excluded bases removed) | **245** | **476** |
| Dates verified (official listing metadata) | 183 | 457 |
| Uncertain dates (`UNVERIFIED_LISTING_DATE`) | 62 (all delisted contracts: first archived candle) | 19 (archive-only symbols: first daily file) |
| First observed perpetual listing | 99 | 253 |
| Follow-on venue listings | 92 | 128 (+ 58 co-listings assigned to OKX) |
| Pre-market (OKX) | 17 | — |
| Unknown order | 37 | 37 |
| Delisted contracts | 62 | 104 |
| Re-listings of an earlier contract (excluded) | 8 (DASH, ENJ, KITE, OL, PIPPIN, ZEC, ZEN, ZK) | 0 |
| Archive coverage not at the listing (> 24 h) | 8 (the same re-listings) | 0 |
| Funding files present (listing month or the next) | 240 | 472 |
| Funding files missing | 5 (CC, GUN, JUP, MEGA, ORDER) | 4 (AERGO, BDXN, BTCST, SXP) |
| Historical metadata insufficient: specifications of 2023-24 unknown | all (only current specifications exist) | all |
| **Potentially eligible, primary** (first observed, verified date, not a re-listing, coverage and funding present) | **83** | **240** |
| Primary eligible by year (2023 / 2024 / 2025 / 2026) | 7 / 23 / 33 / 20 | 23 / 71 / 125 / 21 |
| Calendar months with at least one primary event | 32 | 34 (at most 18 events in one month) |

**The draft's "≈ 255" is not the number of usable events.** It counted OKX series starting after 2023-09. Only
**83** OKX events are first observed listings with a verified date. Of the others:

- 92 are follow-on listings (Binance listed first);
- 37 have an unknown order;
- 17 are pre-market;
- 13 first listings are delisted contracts with an unverified date (kept for a sensitivity analysis).

### Checks on the verified OKX dates

For the 183 live OKX contracts listed in the window:

- the first archived candle is within one hour of `listTime` for 175;
- the 8 others are the re-listings, whose archive starts with the earlier contract.

The archive therefore starts at the listing for live contracts.

## 4. Delisted contracts and survivorship

- **Binance: resolved.**
  - The archive bucket lists every USD-M symbol ever archived, delisted included, with its files.
  - The 133 SETTLING symbols carry an official `onboardDate` and `deliveryDate`.
  - Of the 104 delisted Binance events in the window, 77 are first observed listings.
- **OKX: bounded, not fully resolved.**
  - No endpoint lists past OKX contracts. Delisted ones were found through the union of four public lists
    (OKX live swaps and spot, Binance USD-M including the archive bucket, Binance spot): 62 in the window.
  - A delisted OKX contract can be missed only if its base never traded on Binance (perp or spot) and is not live
    on OKX spot.
  - Among the 183 live OKX listings of the window, only 9 (4.9 %) were never Binance perpetuals. Among the 62
    delisted found, 5 were.
  - The missing set is therefore, by this ratio, of the order of a few events (≤ ~10, under 5 % of the OKX
    window).
  - Status: **SURVIVORSHIP_BOUNDED** for OKX (not `SURVIVORSHIP_UNRESOLVED`). A sensitivity analysis is required
    (protocol §5.4).
- **Delisted OKX contracts have no official listing date.** Their 13 "first observed" events are excluded from
  the primary analysis and form a mandatory survivorship sensitivity analysis. Gate 1 must pass with them as well.

## 5. Consequences for the protocol

| Finding | Consequence (applied in `PREREGISTRATION-H1.md`) |
|---|---|
| Only 83 primary OKX events, 240 Binance | S_A (OKX, on disk) is too small to decide alone (power in `H1-METHODOLOGY-REVIEW.md`). The decisive Gate 1 test is on the larger, never-read Binance set S_B. S_A is a pre-specified development screen. |
| Pre-market contracts | Analysed apart (`PREMARKET`), never in the primary sample: price limits before the normal switch |
| Unknown order, unverified dates | Excluded from the primary; reported; included in a sensitivity analysis only when the order is determinable |
| Re-listings | Excluded (a new contract on an old token is not a first listing) |
| Funding missing for 9 events | Excluded from Gate 2 (funding never assumed zero); kept in Gate 1, which uses prices only |
| Current specifications only | Execution feasibility is uncertain historically (`H1-EXECUTION-FEASIBILITY.md`) |

## 6. Not probed or not covered

- **Perpetuals on other venues.** A token's first perpetual may have been on another venue (Bybit, Bitget,
  Hyperliquid …); "first observed" is first among OKX and Binance.
- **Non-ASCII symbols.** 5 Binance perpetuals (Chinese-named meme contracts) could not be probed through the
  archive API and are not in the inventory. Binance quarterly futures are excluded by design.
- **Unknown token history.** The token creation date and first spot listing are not established.
