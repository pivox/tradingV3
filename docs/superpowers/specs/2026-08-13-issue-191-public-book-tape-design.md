# #191 Authenticated public book tape design

## Goal

Project only verified, non-synthetic public level-one book facts into the
backtest boundary. This creates the evidence prerequisite for a later,
separately versioned execution model without claiming depth, fillability or
queue priority.

## Chosen boundary

The Paper v2 verified snapshot remains the source authority. PHP admits
`top_of_book` records only from datasets whose quality is
`recorded_public_book_and_trades`:

- OKX: materialized REST or WebSocket books, prices and visible quantities in
  `contracts`, with the venue-provided order counts preserved;
- Hyperliquid: live WebSocket L2 books, prices and visible quantities in
  `base_asset`; level counts are validated as source provenance but are not
  relabelled as order counts;
- Hyperliquid historical candle-model books remain excluded because they are
  synthetic.

Each normalized `backtest-public-book.v1` record contains the exact source
record ID and source snapshot checksum, network, venue, symbol, exchange and
availability timestamps, bid/ask price, bid/ask visible quantity, explicit
quantity unit, nullable bid/ask order counts, and the admitted public origin.
The normalized record deliberately omits strategy identity and source-private
transport details.

Python validates the PHP records, binds them to the exact normalized candle
dataset and emits a canonical immutable
`backtest-public-book-tape.v1` manifest plus NDJSON. Its checksum commits the
dataset identity and exact record bytes.

## Fail-closed rules

- Only mainnet/testnet OKX or Hyperliquid perpetual public data already
  admitted by Paper v2 is accepted.
- The source dataset must have `recorded_public_book_and_trades` quality for a
  book to be projected.
- Every record checksum must equal the exact dataset source checksum.
- `available_at >= happened_at`, and event time must belong to a matching
  symbol stream; delayed receipt remains explicit.
- Bid, ask and visible quantities are strict positive canonical decimals;
  `bid_price < ask_price` is mandatory.
- OKX quantities use `contracts` and require canonical non-negative order
  counts. Hyperliquid quantities use `base_asset` and require null order
  counts.
- Origins are limited to the already certified public source variants for the
  venue.
- Source record identities are unique and records are ordered by availability,
  event time and source identity.
- The tape is non-empty and bounded to 30,000 records, keeping maximum
  canonical output below the immutable 64 MiB artifact cap.
- No mode, setup, profile or strategy identity may enter source artifacts.

## Data flow

1. `PaperDatasetVerifier` authenticates the immutable Paper snapshot.
2. `PaperBacktestDatasetAdapter` validates the exact venue payload and creates
   normalized book records alongside candles and public trades.
3. `PaperBacktestDatasetEncoder` emits deterministic book NDJSON.
4. Python validates the records against the exact candle dataset descriptor.
5. `VerifiedPublicBookTape` exposes immutable evidence to a future execution
   model; it performs no execution decision itself.

## Error handling and testing

All shape, provenance, unit, timestamp, spread, duplicate, ordering, coverage,
size and tamper violations fail closed with stable generic errors. PHP tests
cover both venue contracts and the synthetic-book exclusion. Python tests cover
dataset binding, strict venue semantics, stream coverage, deterministic bytes,
artifact bounds and tampering. A checked-in PHP-produced fixture proves the
cross-runtime contract.

## Explicit non-goals

This lot does not reconstruct depth beyond the best level, infer queue rank,
interpret OKX order counts as our position, convert contracts into base units,
or produce full/partial fills. Those behaviors require authenticated instrument
conversion inputs and a separately versioned execution/queue policy.
