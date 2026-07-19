# Paper market replay and live execution design

## Status and references

- Status: approved design
- Date: 2026-07-19
- Primary issue: #132
- Related issue: #196
- Scope: Fake/Paper, OKX public market data, Hyperliquid public market data
- Explicitly out of scope: Bitmart, exchange-private APIs, demo/testnet writes,
  mainnet writes, strategy tuning

## Problem

The #132 baseline tooling is complete, but the local PostgreSQL database has no
certified trades. The current Fake/Paper runtime cannot generate a representative
population through the full application stack:

- `FakeKlineProvider` returns no market data;
- orchestrator Fake recipes remain `dry_run=true`;
- direct Fake exchange scenario endpoints bypass parts of OrderIntent, lineage,
  lifecycle and analytics persistence;
- the existing golden scenarios validate deterministic behavior but do not
  populate the complete PostgreSQL chain required by `position_trade_analysis_v2`.

The system needs a Paper execution mode that consumes public OKX and Hyperliquid
market data, executes the existing TradingV3 strategies locally through the Fake
adapter, persists complete trade facts in an isolated database, and records live
market input in a format that can be replayed deterministically.

## Goals

1. Capture BTC and ETH public market data from OKX and Hyperliquid without any
   private credential.
2. Record the normalized live stream in an append-only, checksummed dataset.
3. Replay the same dataset with a controlled clock.
4. Execute `regular`, `scalper` and `scalper_micro` without changing their
   strategy or risk parameters.
5. Route every order exclusively to `FakeExchangeAdapter`.
6. Persist coherent OrderIntent, lineage, zone, lifecycle, fill and cost facts
   into a dedicated `trading_paper` PostgreSQL database.
7. Produce at least 50 certified closed trades for each venue/profile pair, or
   return an explicit insufficient-population result without forcing signals.
8. Feed the existing #132 export and report generator without symbol-only or
   time-window reconciliation.

## Non-goals

- No Bitmart inventory, migration or runtime change.
- No OKX or Hyperliquid authenticated endpoint.
- No exchange order submission, cancellation or position mutation.
- No demo, testnet, live or mainnet trading activation.
- No strategy, MTF, EntryZone, sizing, leverage, SL/TP or frequency tuning.
- No synthetic trade insertion directly into analytics tables.
- No claim that an incomplete or modelled market dataset is equivalent to a
  recorded order book.
- No storage of large market datasets in Git.

## Terminology

- `execution_exchange`: always `fake` in Paper mode.
- `market_data_venue`: `okx` or `hyperliquid`.
- `capture`: consume a public live stream and write normalized events to disk.
- `live_paper`: capture public events and execute the local Paper workflow at
  the same time.
- `replay`: execute the Paper workflow from an immutable recorded dataset using
  the dataset timestamps as the controlled clock.
- `certified_trade`: a row accepted by the existing certified net-PnL contract.

## Architecture

### Market data contract

Introduce `PaperMarketDataSourceInterface`. Implementations emit one normalized
event type with these required fields:

```text
schema_version
event_id
source_venue
symbol
channel
exchange_timestamp
received_timestamp
sequence
payload
payload_hash
```

Supported channels in v1:

- `candle_1m`;
- `candle_5m`;
- `candle_15m`;
- `candle_1h`;
- `top_of_book`;
- `public_trade` when the venue exposes it through the selected public surface;
- `connection_state` and `snapshot_boundary` for recovery evidence.

`event_id` is deterministic:

```text
sha256(schema_version | source_venue | normalized_symbol | channel |
       exchange_timestamp | sequence_or_payload_hash)
```

An identical event is a replay. The same identity with a different payload is a
conflict and stops processing.

### Sources

Implement three source families behind the same contract:

1. `OkxPublicMarketDataSource`
   - public REST warm-up and snapshot;
   - public WebSocket live updates;
   - no API key, passphrase, signature or simulated-trading header.
2. `HyperliquidPublicMarketDataSource`
   - public HTTP warm-up and snapshot;
   - public WebSocket live updates;
   - no wallet, signer, nonce or private account call.
3. `PaperReplayMarketDataSource`
   - reads an immutable local dataset;
   - verifies its manifest and event hashes before execution;
   - emits events in deterministic timestamp/order sequence.

Each venue source supports two acquisition modes:

- `historical`: paginate the venue's audited public historical REST surfaces,
  normalize candles/trades and record an immutable replay dataset;
- `live`: warm up over public REST, then capture public WebSocket events while
  executing Paper and recording the same normalized stream.

`PaperHistoricalDatasetBuilder` coordinates historical pagination, continuity
checks, manifest completion and restart from the last durable cursor. When a
venue does not expose historical top-of-book data for the requested period, the
dataset records `public_historical_candles_and_trades` quality and names the
versioned book/spread model used by Fake execution. It may not label the data as
recorded book depth.

No source may fall back to another venue. A failed OKX source remains an OKX
failure, and likewise for Hyperliquid.

### Recording and dataset layout

Large market data stays outside Git under:

```text
var/paper-market-data/<dataset_id>/
  manifest.json
  events.ndjson
  checkpoints/
```

The manifest contains only non-secret metadata:

- schema and recorder versions;
- dataset ID;
- source venue;
- BTC/ETH normalized and venue-native symbols;
- start/end exchange timestamps;
- channels;
- event counts;
- sequence/gap statistics;
- market-data quality tier;
- SHA-256 for the event file;
- capture completion state.

Git stores schemas, small contract fixtures and example manifests, not real
datasets.

### Market-data quality tiers

The source quality must remain visible in every Paper run:

- `recorded_public_book_and_trades`: recorded public book/trade stream;
- `public_historical_candles_and_trades`: historical public data with any book
  reconstruction explicitly modelled;
- `incomplete`: gap, missing channel or unverified manifest.

Modelled spread or top-of-book values carry a model name and version. Missing
values remain unknown and cannot become zero implicitly. The #132 report must
segment results by quality tier and may not silently aggregate recorded and
modelled populations.

### Provider integration

Paper-specific providers consume the normalized event stream. They maintain the
bounded kline windows and current top-of-book required by existing strategy and
execution services. The MTF, indicator, EntryZone, sizing and protection code
continues to use its existing provider interfaces.

Replay time comes from a controlled `ClockInterface`. Live time uses exchange
timestamps for market ordering and UTC receipt timestamps for operational
diagnostics.

### Execution coordinator

`PaperExecutionCoordinator` owns a run for one venue, profile and dataset/live
session. It:

1. warms all required timeframes;
2. advances providers and the controlled clock;
3. invokes the existing strategy path;
4. persists OrderIntent and lineage before local submission;
5. routes orders only to `FakeExchangeAdapter`;
6. advances matching, fills, protections, funding and liquidation using the
   existing versioned Fake models;
7. projects exchange events into PostgreSQL;
8. records run counters and certification status;
9. checkpoints after each committed event batch.

Each run has an isolated Paper account namespace:

```text
paper:<market_data_venue>:<profile>:<run_id>
```

This prevents balance, One-Way state, open orders and positions from leaking
between profiles or venues.

## Execution mode and safety

Introduce an explicit `execution_mode=paper`. Do not overload `dry_run=false`
for this behavior.

Required invariants:

- `execution_exchange=fake`;
- `paper_execution_enabled=false` by default;
- `mainnet_write_enabled=false`;
- `demo_testnet_write_enabled=false`;
- BTC and ETH allowlist only in v1;
- only public hostnames configured for the selected market-data source;
- no private client service is available in the Paper container;
- a local kill switch stops event consumption and new local orders;
- stopping capture never submits, cancels or modifies an exchange order;
- raw references and logs never contain authorization headers or credentials.

The Paper runtime must refuse to start if any resolved execution gateway is not
Fake.

## Database isolation

Paper data is stored in a dedicated PostgreSQL database named `trading_paper`.
Tests use a database ending in `_paper_test`.

A startup guard reads the connected database name and refuses:

- the normal development database;
- a production database;
- any database not explicitly allowlisted as Paper;
- a database with unapplied migrations.

The Paper database uses the normal additive TradingV3 schema. No hand-written
rows are inserted into `position_trade_analysis_v2`; it remains a read-only view.

### Venue provenance

`exchange` remains `fake`. Add and propagate `market_data_venue` through the
minimum durable facts required for exact analytics:

- `order_intent`;
- `trade_lineage`;
- `trade_lifecycle_event`;
- `fill_cost_ledger`;
- `trade_zone_events` where a zone event exists.

The migration is additive and indexed where the baseline queries filter or group
by the field. The v2 analytics view and #132 export expose the same provenance.
No code may encode the market-data venue by pretending that the execution
exchange is OKX or Hyperliquid.

## Persistent trade flow

For each valid strategy decision:

1. persist `OrderIntent` with profile, config hash, orchestration context,
   `exchange=fake` and `market_data_venue`;
2. create `trade_lineage` with the exact `internal_trade_id`;
3. persist EntryZone evidence when the normal workflow emits it;
4. submit to `FakeExchangeAdapter` with the Paper account namespace;
5. normalize and project order/fill/position events;
6. ingest entry, exit, funding and adjustment costs into `fill_cost_ledger`;
7. persist position-open and position-close lifecycle events with exact lineage;
8. let `position_trade_analysis_v2` match and calculate the closed-trade result.

A trade contributes to the target population only when:

- `analysis_status=matched_closed`;
- `close_match_status=matched`;
- `position_fully_closed=true`;
- costs are complete through v2 or the ledger certification path;
- entry and exit quantities are coherent;
- net PnL and net PnL R are available;
- no blocking quality flag remains.

## Population target

The recipe runs these six cells:

| Market-data venue | Profile |
|---|---|
| OKX | regular |
| OKX | scalper |
| OKX | scalper_micro |
| Hyperliquid | regular |
| Hyperliquid | scalper |
| Hyperliquid | scalper_micro |

Each cell requires at least 50 certified closed trades. The total target is 300.

The coordinator never creates a forced signal and never changes strategy
parameters to reach the target. If a dataset ends before a cell reaches 50, the
result is:

```text
insufficient_certified_population
```

with exact processed-event, decision, order, closed-trade, certified-trade and
exclusion counters. A later dataset may resume the cell from a checkpoint.

## Live capture and replay equivalence

Live mode records every accepted public event before applying its business
effects. After capture, replaying the complete dataset from a fresh Paper database
must produce the same normalized sequence of:

- strategy decisions;
- OrderIntent identities derived from run/dataset inputs;
- fills and costs;
- lifecycle transitions;
- closed-trade analytics values.

Operational receipt timestamps may differ and are excluded from business-state
equality. Every excluded field is documented; exclusions may not hide financial
or identity differences.

## Recovery and failure handling

- Duplicate event: ignore as replay after payload-hash verification.
- Conflicting duplicate: stop with `market_event_identity_conflict`.
- Sequence gap: pause consumption, fetch a public snapshot, reconcile, then
  resume; otherwise stop with `market_data_gap_unresolved`.
- Dataset checksum mismatch: refuse replay.
- Unsupported instrument metadata: refuse the affected cell.
- Missing applicable cost: persist unknown/quality flag and exclude the trade.
- Database loss during a batch: roll back the batch and resume from the previous
  durable checkpoint.
- Crash after commit but before checkpoint: replay events idempotently until the
  checkpoint catches up.
- Public source unavailable: leave the cell incomplete; never fall back to the
  other venue.

## Operator commands

The implementation provides these exact CLI commands rather than a mutative web
endpoint:

```text
app:paper-market:backfill --venue=<okx|hyperliquid> --symbols=BTC,ETH --from=<UTC> --to=<UTC>
app:paper-market:capture --venue=<okx|hyperliquid> --symbols=BTC,ETH --execute
app:paper-market:replay --dataset=<dataset_id> --profiles=regular,scalper,scalper_micro
app:paper-baseline:status --target-per-cell=50
app:paper-baseline:export
```

Every command prints the execution exchange (`fake`), market-data venue, Paper
database, kill-switch state, dataset ID and write-safety status before doing
work. It never prints configuration secrets.

## Delivery plan

This initiative is delivered as five atomic PRs.

### PR 1 - Paper foundation

- event contract and validation;
- append-only recorder, manifest and hash verification;
- controlled replay clock and checkpoints;
- `trading_paper` database guard;
- additive market-data venue persistence contract;
- no network client and no strategy execution yet.

### PR 2 - OKX public source

- historical public dataset builder for BTC/ETH;
- BTC/ETH public REST warm-up;
- public WebSocket capture;
- normalization, gap detection, snapshot resync and contract fixtures;
- recorder integration;
- no authenticated endpoint.

### PR 3 - Hyperliquid public source

- historical public dataset builder for BTC/ETH;
- BTC/ETH public HTTP warm-up;
- public WebSocket capture;
- the same normalization, gap and recorder contracts;
- no wallet, signer or private account endpoint.

### PR 4 - Full Paper execution

- Paper providers and execution coordinator;
- isolated Paper account namespaces;
- existing strategies and Fake execution path;
- complete lineage, lifecycle, ledger, zone and analytics propagation;
- restart/idempotence/protection/cost integration tests.

### PR 5 - #132 population and baseline recipe

- six-cell orchestration;
- target and insufficient-population accounting;
- live-capture-to-replay equality proof;
- certified population export;
- existing #132 Markdown/JSON/CSV generation;
- no strategy or YAML tuning.

Each PR gets its own branch, tests, CI, Codex review, rollback and merge. A later
PR may start only after its prerequisite PR is merged.

## Test strategy

### Unit tests

- normalized event schema and canonical hashing;
- venue symbol mapping for BTC/ETH;
- duplicate/conflict/out-of-order handling;
- manifest and dataset checksum verification;
- controlled clock behavior;
- checkpoint serialization and recovery;
- Paper database allowlist guard;
- execution-gateway Fake-only guard.

### Contract tests

- redacted OKX public fixtures;
- redacted Hyperliquid public fixtures;
- both normalizers produce the same exchange-neutral event contract;
- no fixture contains authorization headers, API keys, signatures or wallets;
- public clients reject private paths and hosts.

### PostgreSQL integration tests

Use only `_paper_test` databases. Prove:

- additive migrations and indexes;
- one complete trade per venue/profile cell;
- exact OrderIntent-to-lineage-to-lifecycle-to-ledger joins;
- complete entry/exit quantities and costs;
- `position_trade_analysis_v2` certification;
- restart without duplicate rows;
- rollback of an interrupted batch.

### End-to-end tests

- live public fixture stream recorded then replayed from a fresh database;
- identical business results after replay;
- partial fills and successive fills;
- SL, TP and trailing closure;
- funding and all applicable cost fields;
- public disconnect, snapshot resync and resume;
- kill switch before and during a run;
- no exchange-private or exchange-write call;
- insufficient dataset returns a non-success population status.

Real public-network smoke tests are opt-in and never required for deterministic
CI. CI uses captured public contract fixtures and a local HTTP/WS server.

## Validation gates

Before any PR merge:

- targeted unit and integration suites pass;
- PostgreSQL tests run on an isolated Paper test database;
- PHPStan passes on all touched PHP files;
- Python static/tests pass where Python is touched;
- Symfony container and YAML lint pass when applicable;
- MkDocs strict passes for documentation changes;
- secret/redaction and private-network scans pass;
- `git diff --check` passes;
- current-HEAD CI is green;
- current-HEAD Codex review is favorable;
- no unresolved review thread remains.

Before #132 analysis:

- all six cells report at least 50 certified trades;
- the database and dataset manifests are archived locally;
- source quality is visible in every row/report;
- replay equality has passed on the final dataset;
- no strategy change is included with the baseline.

## Rollback

Rollback is entirely local:

1. disable `paper_execution_enabled`;
2. stop Paper capture/replay workers;
3. preserve or archive dataset manifests for audit;
4. drop or recreate only the dedicated `trading_paper` database when approved;
5. revert the relevant PR if code rollback is required.

No exchange-side order, position or cleanup can exist because execution never
leaves `FakeExchangeAdapter`.

## Acceptance criteria

- OKX and Hyperliquid public capture support BTC and ETH.
- Live capture and replay share one event contract.
- Market datasets are local, immutable after completion and checksummed.
- Paper refuses a non-Paper database and any non-Fake execution gateway.
- Existing strategies run without parameter changes.
- Every persisted trade carries exact lineage and market-data venue provenance.
- Complete fills, costs, lifecycle and analytics are persisted in PostgreSQL.
- Restart and replay are idempotent.
- No private credential or exchange write is required or attempted.
- The six venue/profile cells each reach 50 certified trades, or the run reports
  the exact insufficient population without claiming baseline completion.
- The existing #132 export consumes the resulting database without heuristic
  symbol/time matching.
