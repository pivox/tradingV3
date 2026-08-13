# #191 Paper Dataset Adapter Design

## Scope

This atomic lot adapts an already verified PHP Paper dataset v2 into the
normalized `backtest-candle.v1` boundary merged in #344. It does not fetch
market data, implement a strategy, modernize the backtest run/config contract,
or activate any execution mode.

The source authority is `PaperDatasetVerifier`, not `KlineDto`. The generic
kline DTO loses source network, venue, event identity, close/availability time
and dataset checksum. The adapter therefore consumes a verifier-owned immutable
snapshot so no caller can verify `events.ndjson` and then reopen a different
file.

## Verified snapshot boundary

`PaperDatasetVerifier` gains a baseline snapshot operation that performs the
same pinned-directory, regular-file, canonical-event, checksum, count,
coverage, network provenance and final stability checks as
`verifyForBaseline()`. The operation returns a frozen value containing:

- the verified complete `PaperDatasetManifest`;
- the exact ordered `PaperMarketEvent` values parsed during that verification.

The snapshot operation must share the verifier's existing scan path. It must
not verify once and then reopen the event file. Existing verification methods
keep their behavior and delegate to the shared implementation.

The in-memory boundary is intentional for this first adapter lot: the Paper
format already has explicit event-count and byte limits, and it gives one clear
atomic snapshot. Streaming/export publication is a later concern and must not
weaken the verification boundary.

## Candle normalization

The adapter accepts only:

- Paper event schema v2;
- a certifiable `mainnet` or `testnet` network;
- `okx` or `hyperliquid` venue matching the manifest;
- candle channels `candle_1m`, `candle_5m`, `candle_15m`, or `candle_1h`;
- a confirmed, complete candle payload;
- `BTCUSDT` or `ETHUSDT`, matching the manifest symbol map.

Non-candle events are ignored because an order-book or trade event is not a
candle defect. Any malformed candle event fails the whole adaptation. Legacy
events, mixed venue/network facts, unsupported timeframes, missing volume,
invalid decimals, false confirmation, bad geometry, grid mismatch, or duration
mismatch fail closed.

Venue payloads are normalized explicitly:

- Hyperliquid: `interval`, `start_time`, `close_time`, `open`, `high`, `low`,
  `close`, `volume`, `confirmed`;
- OKX: `bar`, exchange timestamp as candle open, `open`, `high`, `low`, `close`,
  `volume_base`, `confirmed`.

The normalized close boundary is exclusive and equals `open_at + timeframe`.
Hyperliquid's inclusive millisecond close must equal that boundary minus one
millisecond. OKX receives the derived exclusive close boundary.

Each output candle uses:

- `source_record_id = event_id`;
- manifest network and venue;
- `market_type = perpetual`, under this adapter schema's strict venue contract;
- canonical symbol and timeframe;
- exact canonical decimal strings;
- `available_at = max(received_timestamp, close_at)`;
- `complete = true`.

This availability rule prevents a historical OKX event whose received and
exchange timestamps equal candle open from becoming visible before close.

## Source identity and output

The adapter returns a frozen result containing a `DatasetSourceIdentity`-shaped
map plus ordered candle maps. The source identity is:

- `source = paper_market_dataset`;
- `source_schema_version = paper-market-dataset.v2`;
- `source_build_version = manifest.recorder_version`;
- `source_checksum = sha256:<manifest events_file_sha256>`;
- manifest network and venue;
- `market_type = perpetual`.

The output encoder emits canonical JSON suitable for strict Python validation:
one source identity JSON document and candle NDJSON. PHP remains responsible
for authenticating the Paper files; Python remains responsible for validating
the normalized contracts, quality, canonical serialization and publication.

No mode, setup, profile or strategy field is allowed in the adapter API.

## Errors and safety

Public failures expose stable reason codes and do not include filesystem paths,
raw payloads or credentials. No failed adaptation writes an output artifact.
The adapter is pure after it receives the verified snapshot.

This lot adds no HTTP endpoint or console command. Filesystem publication and
operator-facing export commands are deferred to a later publisher lot. Tests
exercise the in-memory encoder directly.

## Tests and acceptance

PHP tests prove:

- the snapshot uses the events parsed inside the successful verification;
- OKX and Hyperliquid candles produce exact golden normalized values;
- availability never precedes close;
- event order is deterministic;
- mixed provenance and each malformed candle boundary fail closed;
- non-candle events are ignored;
- no profile/mode/setup key appears.

A Python cross-language fixture test parses the PHP golden source identity and
candle NDJSON with `DatasetSourceIdentity` and `CandleRecord`, then builds a
dataset through `DatasetBuilder`. This is a contract fixture, not a claim that
Python verified the original Paper dataset.

The relevant PHP unit suites, Python focused suite, Python coverage gate and
repository diff/compile checks must pass. No modern mode becomes executable.

## Deferred work

The following remain separate #191 lots:

1. replace legacy `Profile` in `EffectiveConfigSnapshot` and
   `BacktestRunRequest` with exact modern mode/setup/version/side plus the
   immutable #133/#303 snapshot;
2. invoke the canonical TradingCore rule/runtime boundary without duplicating
   trading rules in Python;
3. implement deterministic Backtrader execution, fills, costs and results;
4. add replay smoke, rerun evidence and certification artifacts.
