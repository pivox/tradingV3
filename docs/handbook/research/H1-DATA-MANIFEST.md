# H1 — Data manifest: allowed and forbidden data (2026-10-14)

## Metadata snapshots used by the audit (read; no price, no return)

Directory `tradingV3-private/research/20261014T210000Z-h1-listing/`:

| File | sha256 | Content |
|---|---|---|
| `metadata/okx-swap.json` | `c0b82819c743bf3bfa6abd91899f2f9f318ca37c458fbc29f5ab39dffec86e08` | OKX live SWAP instruments |
| `metadata/binance-fapi.json` | `681604f3996d46d8cc3261e0e32511ca4a79c52b8589b185a4f19ac570ff53ad` | Binance USD-M exchangeInfo (onboardDate, deliveryDate, status, filters) |
| `metadata/binance-um-archive-symbols.json` | `e5f123f031960f8f2e1686215188248ec4ef68b9a8417b4c81824796b0e74a3a` | every USD-M symbol in the data.binance.vision archive (1,056) |
| `metadata/binance-archive-first-day.json` | `1afa82364f28c71ae0abcecabf81e838f1cb852efe33c57c9621b81bb1508223` | first archived day of symbols absent from exchangeInfo |
| `metadata/okx-extra-families.json` | `06c75f58d1bd4e1998d451900ae23ce0134a1cfb03d777fe4a5440e886e21b1f` | OKX archive file months for Binance bases not probed before |
| `audit/okx-events.json` | `5caeb68b25abc128c9e624224aa68273fc484ac8c4c745c6aff41a4fd416ebc9` | OKX listing events (metadata) |
| `audit/binance-events.json` | `9e4fe64fd6622a1b025426f7bfe35aeca6bcea0d6d9332aa3d6dad08e4d19627` | Binance listing events (metadata) |
| `audit/events-classified.json` | `8878e6d0a6f012f6e7fae81e576b542376e8f11e0c71b05bed17909d01e38b23` | events in the window with their class |
| `audit/funding-presence.json` | `e4abe1d3736a96274b122f1508d8d55f83cb8cc025027c3ba176f52dd9c72a63` | funding file presence per event |
| `audit/audit-summary.json` | `9a03c7cdb287c0fe7d1684b7fe76caf7fec144f8e3a63ae109d5328de3aa095a` | the counts of H1-LISTING-AUDIT.md |
| `../20261014T110000Z-swing-v2-okx/instrument-audit.json` | `108416c0d1c7a615995cf39306f0549027569ca58bfa4fd9c6785e0a8a55e660` | swing v2 OKX segments (candle timestamps only) |
| `../20261014T110000Z-swing-v2-okx/inventory.json` | `e6ecaf55020873fa931851e5d910f91e78906122f596b9b6047dbf4f79bc6ca4` | OKX archive file index |

## Allowed, after the corresponding authorisation

| Step | Data | Status |
|---|---|---|
| S_A screen (Gate 1, step 9.1) | OKX 1-minute candles and funding already on disk (`20261014T113000Z-tape-okx-swing-v2-monthly`), event windows [t0 − 1 d, t0 + 31 d] of the S_A events; the same tape for the OKX index and control candidates | On disk; **not read for outcomes yet** |
| S_B decision (Gate 1, step 9.2) | Binance: 1-minute monthly klines of the first two months after each S_B listing; hourly monthly klines of the index and control candidates; monthly funding | **Not downloaded**; downloaded only after the owner's Gate 1 authorisation |
| Gate 2 development | S_A ∪ S_B, as read above | Conditional on Gate 1 |
| S_C prospective | Public data of every primary listing during the 12 fixed months | Future; claim file before observation |

## Forbidden

- **Forward OKX `fwd-okx-r1`.** Its windows W0, W1 and W2 (2026-10-14…16, 19…23, 26…30), its tape, logs and
  verdicts, and its systemd services.
- **Any post-listing price or return before the owner's Gate 1 authorisation.**
- **Binance candles of S_B before that authorisation.**
- **Results of past campaigns used to choose events or parameters.** Events and parameters come from metadata and
  this protocol only.
- **Consumed windows used as independent validation.** These include November 2024 (scalp-q3-r2), the 2025
  tick weeks, the programme pairs' candles and the swing v2 OKX series beyond their first 42 days.
- **Any order, demo or live.** No change of `*_ENABLED` flags.

## Not observed (coverage limits)

- Perpetuals on venues other than OKX and Binance.
- Token creation and first spot listing.
- Historical contract specifications.
- Order-book history of new listings.
- Official delisting settlement prices.
- 5 non-ASCII Binance symbols.
