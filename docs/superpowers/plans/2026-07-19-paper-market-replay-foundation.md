# Paper Market Replay Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the deterministic, fail-closed Paper market-data foundation required by issue #132 without adding network clients or executing strategies.

**Architecture:** Add an exchange-neutral immutable event contract, append-only local datasets with atomic manifests, verified deterministic replay with durable checkpoints, and explicit Paper runtime/database guards. Extend the existing persistence and `position_trade_analysis_v2` contracts with nullable `market_data_venue` provenance so legacy rows remain valid while future Paper rows can be segmented exactly.

**Tech Stack:** PHP 8.2, Symfony 7.1, PSR Clock, Doctrine ORM/DBAL/Migrations, PostgreSQL 15, PHPUnit 11, PHPStan, MkDocs.

---

## Scope Boundaries

This plan implements PR 1 from the approved design only:

- normalized event identity, validation, canonical hashing and secret-field rejection;
- append-only NDJSON recording, atomic manifest updates, immutable completion and checksum verification;
- deterministic replay order, controlled clock and atomic consumer checkpoints;
- dedicated Paper database and Fake-only execution guards;
- additive `market_data_venue` persistence and analytics/export visibility;
- small checked-in contract fixtures and operator documentation.

It does not add OKX or Hyperliquid HTTP/WebSocket clients, public historical pagination, strategy execution, Paper account orchestration, forced signals, population generation, or Bitmart changes. Those remain in PRs 2 through 5 from the approved design.

## File Map

Create the focused Paper domain under `trading-app/src/Trading/Paper/`:

- `MarketData/PaperMarketDataVenue.php`: allowlisted public data venues.
- `MarketData/PaperMarketDataChannel.php`: v1 exchange-neutral channels.
- `MarketData/PaperMarketDataQuality.php`: explicit dataset quality tiers.
- `MarketData/PaperMarketDataSourceInterface.php`: source boundary consumed by future capture/replay coordinators.
- `MarketData/CanonicalJson.php`: stable recursive JSON normalization and encoding.
- `MarketData/PaperMarketEvent.php`: immutable normalized event, identity and payload-hash verification.
- `MarketData/PaperMarketEventRedactor.php`: fail-closed sensitive-key scanner.
- `Dataset/PaperDatasetState.php`: `recording`, `complete`, `incomplete` states.
- `Dataset/PaperDatasetManifest.php`: typed non-secret manifest contract.
- `Dataset/PaperDatasetManifestCodec.php`: JSON serialization and strict decoding.
- `Dataset/PaperDatasetAppendResult.php`: `appended` or `replayed` result.
- `Dataset/PaperDatasetRecorder.php`: append-only NDJSON writer and atomic manifest finalization.
- `Dataset/PaperDatasetVerifier.php`: event, manifest and file checksum verification.
- `Replay/PaperReplayClock.php`: monotonic controlled PSR clock.
- `Replay/PaperReplayCheckpoint.php`: typed replay cursor.
- `Replay/PaperReplayCheckpointStore.php`: atomic filesystem checkpoint persistence.
- `Replay/PaperReplayReader.php`: verified deterministic event iteration.
- `Runtime/PaperRuntimeContext.php`: explicit execution mode and safety inputs.
- `Runtime/PaperRuntimeGuard.php`: Fake-only and write-disabled safety gate.
- `Runtime/PaperDatabaseInspection.php`: current database/migration status value object.
- `Runtime/PaperDatabaseInspectorInterface.php`: testable inspection port.
- `Runtime/DoctrinePaperDatabaseInspector.php`: connected PostgreSQL and migration inspection.
- `Runtime/PaperDatabaseGuard.php`: exact `trading_paper`/`*_paper_test` allowlist gate.

Modify the five durable facts, the current v2 view migration chain, the v2 read entity, and #132 divergence export. Do not thread provenance through live call sites in this PR; future Paper execution will set it explicitly through the new entity setters.

### Task 1: Normalized Paper Market Event Contract

**Files:**
- Create: `trading-app/src/Trading/Paper/MarketData/PaperMarketDataVenue.php`
- Create: `trading-app/src/Trading/Paper/MarketData/PaperMarketDataChannel.php`
- Create: `trading-app/src/Trading/Paper/MarketData/PaperMarketDataQuality.php`
- Create: `trading-app/src/Trading/Paper/MarketData/PaperMarketDataSourceInterface.php`
- Create: `trading-app/src/Trading/Paper/MarketData/CanonicalJson.php`
- Create: `trading-app/src/Trading/Paper/MarketData/PaperMarketEventRedactor.php`
- Create: `trading-app/src/Trading/Paper/MarketData/PaperMarketEvent.php`
- Test: `trading-app/tests/Trading/Paper/MarketData/PaperMarketEventTest.php`
- Test: `trading-app/tests/Trading/Paper/MarketData/PaperMarketEventRedactorTest.php`

- [ ] **Step 1: Write failing enum, hashing, identity, round-trip and redaction tests**

Cover these exact cases in `PaperMarketEventTest`:

```php
$event = PaperMarketEvent::create(
    venue: PaperMarketDataVenue::OKX,
    symbol: 'btcusdt',
    channel: PaperMarketDataChannel::TOP_OF_BOOK,
    exchangeTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.123456Z'),
    receivedTimestamp: new \DateTimeImmutable('2026-07-19T10:00:00.223456Z'),
    sequence: '42',
    payload: ['ask' => '30001.0', 'bid' => '29999.0'],
);

self::assertSame('BTCUSDT', $event->symbol);
self::assertSame(
    hash('sha256', '1|okx|BTCUSDT|top_of_book|2026-07-19T10:00:00.123456Z|42'),
    $event->eventId,
);
self::assertSame($event, PaperMarketEvent::fromArray($event->toArray()));
```

Also prove that payload key ordering produces the same payload hash, list ordering remains significant, BTC/ETH are accepted, another symbol is rejected, a forged `payload_hash` is rejected, a forged `event_id` is rejected, unsupported channels/venues are rejected, and timestamps normalize to UTC microseconds.

In `PaperMarketEventRedactorTest`, recurse through nested maps/lists and reject these normalized key fragments: `authorization`, `api_key`, `apikey`, `api_secret`, `secret_key`, `passphrase`, `private_key`, `sign`, `signature`, `wallet`, `mnemonic`, and `seed_phrase`. Assert that public fields such as `symbol`, `price`, `size`, `bid`, `ask`, `timestamp`, and `sequence` pass.

- [ ] **Step 2: Run the tests and verify the expected red state**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/MarketData/PaperMarketEventTest.php tests/Trading/Paper/MarketData/PaperMarketEventRedactorTest.php
```

Expected: test bootstrap succeeds and both classes fail because the Paper market-data classes do not exist.

- [ ] **Step 3: Implement the enums, source port and canonical JSON encoder**

Use backed string enums with these exact values:

```php
enum PaperMarketDataVenue: string
{
    case OKX = 'okx';
    case HYPERLIQUID = 'hyperliquid';
}

enum PaperMarketDataChannel: string
{
    case CANDLE_1M = 'candle_1m';
    case CANDLE_5M = 'candle_5m';
    case CANDLE_15M = 'candle_15m';
    case CANDLE_1H = 'candle_1h';
    case TOP_OF_BOOK = 'top_of_book';
    case PUBLIC_TRADE = 'public_trade';
    case CONNECTION_STATE = 'connection_state';
    case SNAPSHOT_BOUNDARY = 'snapshot_boundary';
}

enum PaperMarketDataQuality: string
{
    case RECORDED_PUBLIC_BOOK_AND_TRADES = 'recorded_public_book_and_trades';
    case PUBLIC_HISTORICAL_CANDLES_AND_TRADES = 'public_historical_candles_and_trades';
    case INCOMPLETE = 'incomplete';
}
```

Define the source boundary without a network implementation:

```php
interface PaperMarketDataSourceInterface
{
    public function venue(): PaperMarketDataVenue;

    /** @return iterable<PaperMarketEvent> */
    public function events(): iterable;
}
```

`CanonicalJson::encode(mixed $value): string` must recursively sort associative keys with `SORT_STRING`, preserve list order, reject resources, objects and non-finite floats, and encode with:

```php
JSON_THROW_ON_ERROR
| JSON_UNESCAPED_SLASHES
| JSON_UNESCAPED_UNICODE
| JSON_PRESERVE_ZERO_FRACTION
```

- [ ] **Step 4: Implement fail-closed redaction and the immutable event**

Use schema version `1`, normalized symbols `BTCUSDT` and `ETHUSDT`, UTC format `Y-m-d\TH:i:s.u\Z`, and these exact calculations:

```php
$payloadHash = hash('sha256', CanonicalJson::encode($payload));
$identityTail = $sequence ?? $payloadHash;
$eventId = hash('sha256', implode('|', [
    '1',
    $venue->value,
    $symbol,
    $channel->value,
    $exchangeTimestampUtc->format('Y-m-d\TH:i:s.u\Z'),
    $identityTail,
]));
```

The constructor remains private. `create(...)` validates and derives hashes; a non-null sequence must contain decimal digits only. `fromArray(array $data)` parses strict required keys, recomputes both hashes, and throws `InvalidArgumentException` with stable codes such as `paper_market_payload_hash_mismatch`, `paper_market_event_id_mismatch`, `paper_market_symbol_not_allowed`, and `paper_market_sensitive_field_rejected`. `toArray()` returns keys in the contract order from the approved design.

- [ ] **Step 5: Run focused tests and static analysis**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/MarketData
php vendor/bin/phpstan analyse \
  src/Trading/Paper/MarketData \
  tests/Trading/Paper/MarketData \
  --memory-limit=1G
```

Expected: all Paper market-data tests pass and PHPStan reports no errors.

- [ ] **Step 6: Commit the event contract**

```bash
git add trading-app/src/Trading/Paper/MarketData trading-app/tests/Trading/Paper/MarketData
git commit -m "feat(paper): define normalized market event contract"
```

### Task 2: Append-Only Dataset, Manifest and Checksum Verification

**Files:**
- Create: `trading-app/src/Trading/Paper/Dataset/PaperDatasetState.php`
- Create: `trading-app/src/Trading/Paper/Dataset/PaperDatasetManifest.php`
- Create: `trading-app/src/Trading/Paper/Dataset/PaperDatasetManifestCodec.php`
- Create: `trading-app/src/Trading/Paper/Dataset/PaperDatasetAppendResult.php`
- Create: `trading-app/src/Trading/Paper/Dataset/PaperDatasetRecorder.php`
- Create: `trading-app/src/Trading/Paper/Dataset/PaperDatasetVerifier.php`
- Test: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetRecorderTest.php`
- Test: `trading-app/tests/Trading/Paper/Dataset/PaperDatasetVerifierTest.php`

- [ ] **Step 1: Write failing recorder and verifier tests using a per-test temporary directory**

Exercise the exact layout:

```text
$testRoot/paper-market-data/dataset-okx-001/
  manifest.json
  events.ndjson
  checkpoints/
```

Tests must prove:

- first append writes one canonical JSON line and returns `APPENDED`;
- exact replay returns `REPLAYED` without changing file size or count;
- same `event_id` with another payload throws `market_event_identity_conflict`;
- sequence regression within the same venue/symbol/channel throws `market_event_out_of_order`;
- a forward numeric sequence gap is counted in the manifest and remains visible for source-level resynchronization;
- another channel may have an independent sequence;
- `complete()` fsyncs the event file, stores its SHA-256, marks the manifest complete and prevents further appends;
- `markIncomplete()` durably freezes an unusable capture as `incomplete` and prevents replay;
- recorder restart rebuilds its identity/sequence index from `events.ndjson`;
- a truncated JSON line, forged event hash, wrong count or changed event file fails verification;
- the manifest contains no payload and no sensitive field.

- [ ] **Step 2: Run the dataset tests and verify they fail for missing classes**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Dataset
```

Expected: failures identify the missing dataset classes.

- [ ] **Step 3: Implement typed state, append result and manifest contracts**

Use these states and append results:

```php
enum PaperDatasetState: string
{
    case RECORDING = 'recording';
    case COMPLETE = 'complete';
    case INCOMPLETE = 'incomplete';
}

enum PaperDatasetAppendResult: string
{
    case APPENDED = 'appended';
    case REPLAYED = 'replayed';
}
```

`PaperDatasetManifest` must expose immutable typed fields for schema version, recorder version, dataset ID, venue, normalized-to-native symbol map, start/end exchange timestamps, sorted unique channels, event count, per-channel sequence gaps, quality tier, model name/version when quality is historical/modelled, event-file SHA-256, state, and last event ID. Its constructor enforces:

- dataset IDs match `^[a-z0-9][a-z0-9._-]{2,127}$`;
- only BTCUSDT/ETHUSDT normalized symbols are accepted;
- model name and version are both present for `public_historical_candles_and_trades`;
- checksum is exactly 64 lowercase hexadecimal characters when state is complete;
- a complete manifest has an end timestamp and cannot use quality `incomplete`.

`PaperDatasetManifestCodec` uses `CanonicalJson`, writes a final newline, and decodes with `JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING`.

- [ ] **Step 4: Implement atomic manifest writes and append-only event writes**

`PaperDatasetRecorder` receives a dataset root and manifest. Resolve paths only after validating the dataset ID, create directories with `0700`, files with `0600`, and refuse symlink components. For each event:

1. require the event venue and symbol to match the manifest;
2. check the in-memory identity map;
3. compare numeric sequence strings with `Brick\Math\BigInteger` per venue/symbol/channel and reject regressions;
4. open `events.ndjson` in `ab`, acquire `LOCK_EX`, write the canonical event plus newline, `fflush()`, call `fsync()` when available, then unlock;
5. atomically rewrite `manifest.json` through `tempnam()` in the dataset directory, `chmod(0600)`, `fflush()`/`fsync()`, and `rename()`;
6. update memory only after durable writes succeed.

On restart, scan existing lines through `PaperMarketEvent::fromArray()`, rebuild identities, last sequences and forward-gap counts, and reconcile a stale recording manifest to those durable facts. An exact existing event is a replay. A duplicate identity with another payload hash is always a conflict. If an event append succeeds but the following manifest rewrite fails, mark that recorder instance unusable; reopening it performs the same durable scan before another append.

- [ ] **Step 5: Implement full verifier and immutable completion**

`PaperDatasetVerifier::verify(string $datasetDirectory): PaperDatasetManifest` must:

- reject symlinks and missing/unreadable files;
- parse the manifest strictly;
- require state `complete` for replay verification;
- stream every non-empty NDJSON line through `PaperMarketEvent::fromArray()`;
- enforce identity uniqueness and monotonic per-channel sequence;
- compare actual event count, last event ID, start/end exchange timestamps and `hash_file('sha256', events.ndjson)` with the manifest;
- throw stable `RuntimeException` codes prefixed with `paper_dataset_` without including payloads.

`PaperDatasetRecorder::complete()` computes the event file SHA-256, transitions to `COMPLETE` atomically, then invokes the verifier before returning the final manifest. `markIncomplete()` computes the same audit checksum, transitions to `INCOMPLETE`, and leaves the dataset permanently non-replayable.

- [ ] **Step 6: Run dataset tests, all Paper tests and PHPStan**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Dataset tests/Trading/Paper/MarketData
php vendor/bin/phpstan analyse src/Trading/Paper tests/Trading/Paper --memory-limit=1G
```

Expected: tests pass; PHPStan reports no errors.

- [ ] **Step 7: Commit the dataset foundation**

```bash
git add trading-app/src/Trading/Paper/Dataset trading-app/tests/Trading/Paper/Dataset
git commit -m "feat(paper): record immutable checksummed datasets"
```

### Task 3: Deterministic Replay Clock and Atomic Checkpoints

**Files:**
- Create: `trading-app/src/Trading/Paper/Replay/PaperReplayClock.php`
- Create: `trading-app/src/Trading/Paper/Replay/PaperReplayCheckpoint.php`
- Create: `trading-app/src/Trading/Paper/Replay/PaperReplayCheckpointStore.php`
- Create: `trading-app/src/Trading/Paper/Replay/PaperReplayReader.php`
- Test: `trading-app/tests/Trading/Paper/Replay/PaperReplayClockTest.php`
- Test: `trading-app/tests/Trading/Paper/Replay/PaperReplayCheckpointStoreTest.php`
- Test: `trading-app/tests/Trading/Paper/Replay/PaperReplayReaderTest.php`

- [ ] **Step 1: Write failing clock, checkpoint and replay tests**

Prove that:

- the clock starts at the first event timestamp and only advances monotonically;
- moving backwards throws `paper_replay_clock_regression`;
- replay verifies the complete dataset before yielding any event;
- deterministic order is `(exchange_timestamp, channel value, sequence with null last, event_id)`;
- `received_timestamp` does not influence business ordering;
- a checkpoint round-trips `schema_version`, `dataset_id`, `consumer_id`, `event_id`, `event_index`, `exchange_timestamp`, and `events_file_sha256`;
- resume skips exactly through the checkpointed event and yields the next event;
- a checkpoint from another dataset/checksum/consumer is rejected;
- checkpoint writes are atomic and mode `0600`.

- [ ] **Step 2: Run the replay tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Replay
```

Expected: failures identify missing replay classes.

- [ ] **Step 3: Implement the monotonic clock and checkpoint value object**

`PaperReplayClock` implements `Psr\Clock\ClockInterface`:

```php
final class PaperReplayClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $current) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advanceTo(\DateTimeImmutable $next): void
    {
        $next = $next->setTimezone(new \DateTimeZone('UTC'));
        if ($next < $this->current) {
            throw new \LogicException('paper_replay_clock_regression');
        }
        $this->current = $next;
    }
}
```

`PaperReplayCheckpoint` is immutable, uses schema version `1`, validates consumer IDs with the dataset ID pattern, requires a non-negative event index and a 64-character lowercase checksum, and has strict `toArray()`/`fromArray()` methods.

- [ ] **Step 4: Implement atomic checkpoint storage**

Store checkpoints at:

```text
$datasetDirectory/checkpoints/$consumerId.json
```

The store validates the consumer ID before path construction, refuses symlinks, writes canonical JSON with a trailing newline via a same-directory temporary file, flushes/fsyncs, renames atomically and applies `0600`. `load()` returns `null` for an absent checkpoint and throws a stable exception for malformed or mismatched content.

- [ ] **Step 5: Implement verified deterministic replay**

`PaperReplayReader` receives `PaperDatasetVerifier`, `PaperReplayCheckpointStore`, and `PaperReplayClock`. It verifies first, reads events, sorts with a stable comparator using exchange timestamp, channel, numeric sequence and event ID, validates an optional checkpoint against the manifest checksum, advances the clock immediately before each yield, and exposes the current zero-based event index for checkpoint creation.

Because this foundation targets local BTC/ETH datasets, explicitly enforce a configurable bounded event count with a default of `1_000_000` before sorting; reject larger datasets with `paper_replay_event_limit_exceeded`. A future streaming merge may replace this without changing the public replay contract.

- [ ] **Step 6: Run replay and complete Paper tests plus PHPStan**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper
php vendor/bin/phpstan analyse src/Trading/Paper tests/Trading/Paper --memory-limit=1G
```

Expected: all tests pass and PHPStan reports no errors.

- [ ] **Step 7: Commit replay primitives**

```bash
git add trading-app/src/Trading/Paper/Replay trading-app/tests/Trading/Paper/Replay
git commit -m "feat(paper): add deterministic replay checkpoints"
```

### Task 4: Fail-Closed Paper Runtime and Database Guards

**Files:**
- Create: `trading-app/src/Trading/Paper/Runtime/PaperRuntimeContext.php`
- Create: `trading-app/src/Trading/Paper/Runtime/PaperRuntimeGuard.php`
- Create: `trading-app/src/Trading/Paper/Runtime/PaperDatabaseInspection.php`
- Create: `trading-app/src/Trading/Paper/Runtime/PaperDatabaseInspectorInterface.php`
- Create: `trading-app/src/Trading/Paper/Runtime/DoctrinePaperDatabaseInspector.php`
- Create: `trading-app/src/Trading/Paper/Runtime/PaperDatabaseGuard.php`
- Modify: `trading-app/config/services.yaml`
- Test: `trading-app/tests/Trading/Paper/Runtime/PaperRuntimeGuardTest.php`
- Test: `trading-app/tests/Trading/Paper/Runtime/PaperDatabaseGuardTest.php`
- Test: `trading-app/tests/Trading/Paper/Runtime/DoctrinePaperDatabaseInspectorTest.php`

- [ ] **Step 1: Write failing runtime safety tests**

Construct contexts and prove:

- `executionMode=paper`, `executionExchange=fake`, Paper enabled, both exchange-write flags false, and BTC/ETH pass;
- `dry_run`, `okx`, `hyperliquid`, any Bitmart exchange, disabled Paper, either write flag true, or a non-BTC/ETH symbol fails with a distinct stable reason;
- the error output contains no environment value or credential;
- `trading_paper` passes outside tests;
- `feature_paper_test` passes only in `APP_ENV=test`;
- `trading_app`, an empty database name, `trading_paper_test` outside tests, or pending migrations fails;
- the Doctrine inspector uses `SELECT current_database()` and returns the number of pending migrations without exposing the DSN.

- [ ] **Step 2: Run runtime tests and verify they fail for missing guards**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Runtime
```

Expected: failures identify missing runtime classes.

- [ ] **Step 3: Implement the runtime context and Fake-only guard**

Use an immutable context with:

```php
public function __construct(
    public string $executionMode,
    public Exchange $executionExchange,
    public bool $paperExecutionEnabled,
    public bool $mainnetWriteEnabled,
    public bool $demoTestnetWriteEnabled,
    /** @var list<string> */
    public array $symbols,
) {}
```

`PaperRuntimeGuard::assertSafe()` requires exact mode `paper`, `Exchange::FAKE`, explicit enablement, both write flags false, and a non-empty subset of `BTCUSDT`/`ETHUSDT`. Throw `LogicException` using only stable codes: `paper_execution_mode_required`, `paper_execution_exchange_must_be_fake`, `paper_execution_disabled`, `paper_exchange_writes_must_be_disabled`, and `paper_symbol_not_allowed`.

- [ ] **Step 4: Implement the connected-database and migration inspection port**

`DoctrinePaperDatabaseInspector` receives `Doctrine\DBAL\Connection` and `Doctrine\Migrations\DependencyFactory`. It obtains the real connected name with:

```sql
SELECT current_database()
```

and pending migration count with:

```php
$pending = count($dependencyFactory
    ->getMigrationStatusCalculator()
    ->getNewMigrations()
    ->getItems());
```

Return `PaperDatabaseInspection(databaseName: $name, pendingMigrations: $pending)`. Never include connection parameters in exceptions.

- [ ] **Step 5: Implement and wire the allowlist guard**

`PaperDatabaseGuard::assertReady(string $environment)` accepts exactly:

```php
$allowed = $inspection->databaseName === 'trading_paper'
    || ($environment === 'test' && str_ends_with($inspection->databaseName, '_paper_test'));
```

Reject everything else with `paper_database_not_allowlisted`; reject non-zero pending migrations with `paper_database_migrations_pending`. Add the interface alias to `services.yaml`:

```yaml
App\Trading\Paper\Runtime\PaperDatabaseInspectorInterface:
    alias: App\Trading\Paper\Runtime\DoctrinePaperDatabaseInspector
```

Do not invoke this guard globally from the normal kernel: PR 1 has no Paper command/process startup. PRs 2 through 5 must call it before their first mutation.

- [ ] **Step 6: Run runtime tests, container lint and PHPStan**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Runtime
php bin/console lint:container --no-debug
php bin/console lint:yaml config
php vendor/bin/phpstan analyse \
  src/Trading/Paper/Runtime \
  tests/Trading/Paper/Runtime \
  --memory-limit=1G
```

Expected: tests and lints pass; PHPStan reports no errors.

- [ ] **Step 7: Commit safety guards**

```bash
git add trading-app/src/Trading/Paper/Runtime trading-app/tests/Trading/Paper/Runtime trading-app/config/services.yaml
git commit -m "feat(paper): enforce isolated fake-only runtime"
```

### Task 5: Additive Market-Data Venue Persistence Contract

**Files:**
- Create: `trading-app/migrations/Version20260719120000.php`
- Modify: `trading-app/src/Entity/OrderIntent.php`
- Modify: `trading-app/src/Entity/TradeLineage.php`
- Modify: `trading-app/src/Entity/TradeLifecycleEvent.php`
- Modify: `trading-app/src/Entity/FillCostLedgerEntry.php`
- Modify: `trading-app/src/Entity/TradeZoneEvent.php`
- Test: `trading-app/tests/Trading/Paper/Persistence/MarketDataVenueEntityContractTest.php`
- Test: `trading-app/tests/Trading/Paper/Persistence/MarketDataVenueMigrationTest.php`

- [ ] **Step 1: Write failing entity-contract tests**

For each entity, assert a nullable default, enum/string normalization and blank rejection:

```php
self::assertNull($entity->getMarketDataVenue());
self::assertSame(
    $entity,
    $entity->setMarketDataVenue(PaperMarketDataVenue::OKX),
);
self::assertSame('okx', $entity->getMarketDataVenue());
$entity->setMarketDataVenue(' HYPERLIQUID ');
self::assertSame('hyperliquid', $entity->getMarketDataVenue());
```

Reject unsupported values and empty strings with `market_data_venue_invalid`. Legacy construction must remain source-compatible.

- [ ] **Step 2: Write a failing PostgreSQL migration integration test**

Use `PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase()` and create minimal versions of all five tables. Execute the new migration SQL and assert:

```sql
SELECT column_name, is_nullable
FROM information_schema.columns
WHERE table_schema = current_schema()
  AND table_name IN (
    'order_intent',
    'trade_lineage',
    'trade_lifecycle_event',
    'fill_cost_ledger',
    'trade_zone_events'
  )
  AND column_name = 'market_data_venue'
ORDER BY table_name
```

returns five nullable columns. Query `pg_indexes` and prove one index exists for each table with `market_data_venue` as its leading column. Insert a legacy null row and both allowed venue values; assert a third value fails the check constraint.

- [ ] **Step 3: Run focused tests and verify the red state**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Persistence
```

Expected: entity methods and migration are missing.

- [ ] **Step 4: Add nullable normalized entity fields**

Add this property and equivalent accessors to all five entities:

```php
#[ORM\Column(name: 'market_data_venue', type: Types::STRING, length: 32, nullable: true)]
private ?string $marketDataVenue = null;

public function getMarketDataVenue(): ?string
{
    return $this->marketDataVenue;
}

public function setMarketDataVenue(PaperMarketDataVenue|string|null $venue): self
{
    if ($venue === null) {
        $this->marketDataVenue = null;
        return $this;
    }

    $normalized = $venue instanceof PaperMarketDataVenue
        ? $venue->value
        : strtolower(trim($venue));
    if (!in_array($normalized, array_column(PaperMarketDataVenue::cases(), 'value'), true)) {
        throw new \InvalidArgumentException('market_data_venue_invalid');
    }

    $this->marketDataVenue = $normalized;
    return $this;
}
```

Import `App\Trading\Paper\MarketData\PaperMarketDataVenue`. Do not default legacy rows to a venue and do not alter the `exchange` fields.

- [ ] **Step 5: Implement the additive migration and indexes**

The migration `up()` executes:

```sql
ALTER TABLE order_intent ADD market_data_venue VARCHAR(32) DEFAULT NULL;
ALTER TABLE trade_lineage ADD market_data_venue VARCHAR(32) DEFAULT NULL;
ALTER TABLE trade_lifecycle_event ADD market_data_venue VARCHAR(32) DEFAULT NULL;
ALTER TABLE fill_cost_ledger ADD market_data_venue VARCHAR(32) DEFAULT NULL;
ALTER TABLE trade_zone_events ADD market_data_venue VARCHAR(32) DEFAULT NULL;

ALTER TABLE order_intent ADD CONSTRAINT chk_order_intent_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'));
ALTER TABLE trade_lineage ADD CONSTRAINT chk_trade_lineage_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'));
ALTER TABLE trade_lifecycle_event ADD CONSTRAINT chk_trade_lifecycle_event_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'));
ALTER TABLE fill_cost_ledger ADD CONSTRAINT chk_fill_cost_ledger_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'));
ALTER TABLE trade_zone_events ADD CONSTRAINT chk_trade_zone_events_market_data_venue CHECK (market_data_venue IS NULL OR market_data_venue IN ('okx', 'hyperliquid'));

CREATE INDEX idx_order_intent_market_data_venue ON order_intent (market_data_venue, strategy_profile, created_at);
CREATE INDEX idx_trade_lineage_market_data_venue ON trade_lineage (market_data_venue, profile, created_at);
CREATE INDEX idx_trade_lifecycle_market_data_venue ON trade_lifecycle_event (market_data_venue, config_profile, happened_at, id);
CREATE INDEX idx_fill_cost_ledger_market_data_venue ON fill_cost_ledger (market_data_venue, internal_trade_id, occurred_at, id);
CREATE INDEX idx_trade_zone_market_data_venue ON trade_zone_events (market_data_venue, config_profile, happened_at, id);
```

These index suffixes match the existing mapped columns (`strategy_profile`, `profile`, `config_profile`, `created_at`, `occurred_at`, and `happened_at`). `down()` drops indexes and constraints before columns in reverse table order.

- [ ] **Step 6: Run entity, migration, mapping and PHPStan checks**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/Persistence
php bin/console doctrine:schema:validate --skip-sync
php vendor/bin/phpstan analyse \
  src/Entity/OrderIntent.php \
  src/Entity/TradeLineage.php \
  src/Entity/TradeLifecycleEvent.php \
  src/Entity/FillCostLedgerEntry.php \
  src/Entity/TradeZoneEvent.php \
  migrations/Version20260719120000.php \
  tests/Trading/Paper/Persistence \
  --memory-limit=1G
```

Expected: tests pass, Doctrine mapping is valid and PHPStan reports no errors.

- [ ] **Step 7: Commit the additive persistence contract**

```bash
git add \
  trading-app/migrations/Version20260719120000.php \
  trading-app/src/Entity/OrderIntent.php \
  trading-app/src/Entity/TradeLineage.php \
  trading-app/src/Entity/TradeLifecycleEvent.php \
  trading-app/src/Entity/FillCostLedgerEntry.php \
  trading-app/src/Entity/TradeZoneEvent.php \
  trading-app/tests/Trading/Paper/Persistence
git commit -m "feat(paper): persist market data venue provenance"
```

### Task 6: Surface Venue Provenance in v2 Analytics and #132 Exports

**Files:**
- Create: `trading-app/migrations/Version20260719130000.php`
- Modify: `trading-app/src/Trading/Entity/PositionTradeAnalysisV2.php`
- Modify: `trading-app/src/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReader.php`
- Modify: `trading-app/src/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReportService.php`
- Modify: `trading-app/src/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceExporter.php`
- Modify: `trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php`
- Modify: `trading-app/tests/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReaderTest.php`
- Modify: `trading-app/tests/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReportServiceTest.php`
- Create: `trading-app/tests/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceExporterTest.php`

- [ ] **Step 1: Add failing PostgreSQL view-provenance tests**

Extend minimal `trade_lifecycle_event` schemas with nullable `market_data_venue`. Add one OKX and one Hyperliquid entry/close pair sharing symbol and external identifiers but carrying distinct `internal_trade_id` values. Apply `Version20260719130000` after the current v2 migration and assert:

```sql
SELECT market_data_venue, exchange, analysis_status
FROM position_trade_analysis_v2
WHERE run_id = :run
ORDER BY market_data_venue
```

returns `hyperliquid/fake` and `okx/fake`, each matched independently. Add an entry whose close has a different venue and prove it remains unmatched. This prevents venue ambiguity from being resolved by symbol or time.

- [ ] **Step 2: Add failing reader/report/export tests**

Assert the divergence row contains `market_data_venue`, JSON preserves it, and CSV header/order includes it directly after `exchange`:

```text
entry_event_id,classification,symbol,exchange,market_data_venue,market_type,profile,...
```

Assert legacy v1-only and null-provenance rows serialize as an empty CSV field, never as `okx`, `hyperliquid`, `fake`, `unknown`, or zero.

- [ ] **Step 3: Run view and backfill tests and verify failures**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/View/PositionTradeAnalysisViewTest.php \
  tests/Trading/Backfill
```

Expected: missing migration/class field/export column assertions fail.

- [ ] **Step 4: Create a view migration derived from the current authoritative v2 SQL**

Copy only the `position_trade_analysis_v2` definition from `Version20260626000000` into `Version20260719130000`; do not modify old migrations. Add `market_data_venue` to entry, close and opened-bridge CTEs, every exact-match grouping/partition/join key, and the final projection. Use null-safe comparisons:

```sql
AND close.market_data_venue IS NOT DISTINCT FROM entry.market_data_venue
```

Every recursive CTE key becomes:

```text
match_key, symbol, exchange, market_type, market_data_venue, run_id
```

The final view exposes:

```sql
ee.market_data_venue AS market_data_venue
```

adjacent to `exchange`/`market_type`. Preserve all current certified-PnL columns and calculations byte-for-byte except for the venue key propagation. The migration `down()` restores the exact view SQL from `Version20260626000000` using the same established migration-loading pattern already present in v2 migrations.

- [ ] **Step 5: Extend the Doctrine view entity and #132 read/export pipeline**

Add to `PositionTradeAnalysisV2`:

```php
#[ORM\Column(type: Types::STRING, length: 32, nullable: true, name: 'market_data_venue')]
private ?string $marketDataVenue = null;

public function getMarketDataVenue(): ?string
{
    return $this->marketDataVenue;
}
```

Select `v2.market_data_venue` in `PositionTradeAnalysisBackfillDivergenceReader`. Normalize only blank-to-null in the report service:

```php
'market_data_venue' => ($venue = trim((string) ($row['market_data_venue'] ?? ''))) !== '' ? $venue : null,
```

Insert `market_data_venue` after `exchange` in the exporter column list. Do not infer it from `exchange`, symbol, timestamps, dataset names or run IDs.

- [ ] **Step 6: Run PostgreSQL view tests, backfill tests and PHPStan**

Use an isolated database whose name ends in `_paper_test`:

```bash
cd trading-app
DATABASE_URL='postgresql://postgres:password@127.0.0.1:5432/trading_v3_paper_test?serverVersion=15&charset=utf8' \
  php vendor/bin/phpunit \
    tests/Trading/View/PositionTradeAnalysisViewTest.php \
    tests/Trading/Backfill
php vendor/bin/phpstan analyse \
  migrations/Version20260719130000.php \
  src/Trading/Entity/PositionTradeAnalysisV2.php \
  src/Trading/Backfill \
  tests/Trading/View/PositionTradeAnalysisViewTest.php \
  tests/Trading/Backfill \
  --memory-limit=1G
```

Expected: PostgreSQL assertions pass without skips and PHPStan reports no errors. If the local mapped PostgreSQL port differs, derive the host/port from `docker compose ps` while retaining a database ending in `_paper_test`; never point this command at `trading_app` or `trading_paper`.

- [ ] **Step 7: Commit analytics provenance**

```bash
git add \
  trading-app/migrations/Version20260719130000.php \
  trading-app/src/Trading/Entity/PositionTradeAnalysisV2.php \
  trading-app/src/Trading/Backfill \
  trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php \
  trading-app/tests/Trading/Backfill
git commit -m "feat(paper): expose venue provenance in pnl analytics"
```

### Task 7: Contract Fixtures, Documentation and Secret Scans

**Files:**
- Create: `trading-app/tests/Fixtures/PaperMarketData/okx-top-of-book.normalized.json`
- Create: `trading-app/tests/Fixtures/PaperMarketData/hyperliquid-top-of-book.normalized.json`
- Create: `trading-app/tests/Fixtures/PaperMarketData/complete-dataset/manifest.json`
- Create: `trading-app/tests/Fixtures/PaperMarketData/complete-dataset/events.ndjson`
- Create: `trading-app/tests/Trading/Paper/PaperFixtureContractTest.php`
- Create: `docs/operations/paper-market-replay.md`
- Modify: `mkdocs.yml`
- Modify: `trading-app/.gitignore`

- [ ] **Step 1: Write a failing fixture contract test**

The test loads the two standalone normalized-event fixtures and the two files in `complete-dataset`, parses events through `PaperMarketEvent::fromArray()`, verifies the complete fixture dataset through `PaperDatasetVerifier`, and recursively scans keys and string values for:

```text
authorization
api_key
apikey
api_secret
secret_key
passphrase
private_key
signature
wallet
mnemonic
seed_phrase
Bearer 
OK-ACCESS-
```

It also asserts each fixture is below 16 KiB, contains BTC or ETH only, uses `exchange=fake` nowhere as a market-data venue, and has no raw HTTP headers.

- [ ] **Step 2: Run the fixture test and verify missing fixtures fail**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/PaperFixtureContractTest.php
```

Expected: failure because the fixture files do not exist.

- [ ] **Step 3: Add deterministic normalized fixtures and example manifest**

Generate the two event fixtures through `PaperMarketEvent::create()` in a one-off local PHP expression so hashes cannot be hand-forged, then verify them through `fromArray()`. The OKX fixture uses BTCUSDT and the Hyperliquid fixture uses ETHUSDT; both use `top_of_book`, distinct UTC timestamps and public bid/ask payloads only.

Build `complete-dataset/events.ndjson` from one canonical normalized event line and derive `complete-dataset/manifest.json` with the exact event count, timestamps, last event ID and SHA-256 of that file. Verify the checked-in pair with `PaperDatasetVerifier`; no hand-written checksum is accepted.

- [ ] **Step 4: Document the operator and safety contract**

`docs/operations/paper-market-replay.md` must document:

- execution exchange is always Fake while `market_data_venue` is OKX or Hyperliquid;
- dataset layout under `trading-app/var/paper-market-data/<dataset_id>`;
- event identity, duplicate/conflict behavior and checksum verification;
- quality tiers and model name/version requirement;
- checkpoint resume semantics and the controlled clock;
- exact allowed database names: `trading_paper` and `*_paper_test` only in tests;
- `PAPER_EXECUTION_ENABLED=0` as the default expected by future command wiring;
- no private APIs, credentials, exchange writes, strategy tuning or Bitmart scope;
- rollback by stopping local consumers, preserving manifests and recreating only the dedicated Paper database after explicit approval;
- PR 1 has no operator capture/replay command yet; commands from the approved design arrive with their owning source/execution PR.

Add the page under the existing Operations navigation in `mkdocs.yml`. Add an explicit comment to `trading-app/.gitignore` above `/var/` naming Paper datasets as local-only; do not unignore any file under `var/`.

- [ ] **Step 5: Run fixture tests, secret scans and strict docs build**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper/PaperFixtureContractTest.php
rg -n -i \
  'authorization|api[_-]?key|api[_-]?secret|passphrase|private[_-]?key|signature|mnemonic|seed[_-]?phrase|OK-ACCESS-' \
  tests/Fixtures/PaperMarketData
cd ..
python3 -m mkdocs build --strict
```

Expected: fixture test passes; `rg` exits `1` with no matches in fixtures; MkDocs strict exits `0`. The documentation may name forbidden field categories but must contain no credential value.

- [ ] **Step 6: Commit fixtures and documentation**

```bash
git add \
  trading-app/tests/Fixtures/PaperMarketData \
  trading-app/tests/Trading/Paper/PaperFixtureContractTest.php \
  trading-app/.gitignore \
  docs/operations/paper-market-replay.md \
  mkdocs.yml
git commit -m "docs(paper): document replay dataset foundation"
```

### Task 8: Full Verification and PR-Ready Audit

**Files:**
- Modify only files already listed when a verification failure proves a defect.

- [ ] **Step 1: Run all Paper tests**

```bash
cd trading-app
php vendor/bin/phpunit tests/Trading/Paper
```

Expected: all tests pass with zero failures, errors, warnings or risky tests.

- [ ] **Step 2: Run affected lineage, ledger, zone and backfill regression suites**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Trading/Lineage \
  tests/Trading/Pnl \
  tests/Trading/Backfill \
  tests/Trading/View/PositionTradeAnalysisViewTest.php
```

Expected: all tests pass. PostgreSQL tests must execute against the isolated `_paper_test` database and must not be skipped for the final recorded validation.

- [ ] **Step 3: Run PHPStan on every touched PHP file**

```bash
cd trading-app
php vendor/bin/phpstan analyse \
  src/Trading/Paper \
  src/Entity/OrderIntent.php \
  src/Entity/TradeLineage.php \
  src/Entity/TradeLifecycleEvent.php \
  src/Entity/FillCostLedgerEntry.php \
  src/Entity/TradeZoneEvent.php \
  src/Trading/Entity/PositionTradeAnalysisV2.php \
  src/Trading/Backfill \
  migrations/Version20260719120000.php \
  migrations/Version20260719130000.php \
  tests/Trading/Paper \
  tests/Trading/Backfill \
  tests/Trading/View/PositionTradeAnalysisViewTest.php \
  --memory-limit=1G
```

Expected: `[OK] No errors`.

- [ ] **Step 4: Run framework, migration and documentation validation**

```bash
cd trading-app
php bin/console lint:container --no-debug
php bin/console lint:yaml config
php bin/console doctrine:schema:validate --skip-sync
php bin/console doctrine:migrations:list --no-interaction
cd ..
python3 -m mkdocs build --strict
```

Expected: all commands exit `0`; both new migrations are listed; no container or mapping error is reported.

- [ ] **Step 5: Run private/write-surface and repository hygiene scans**

```bash
rg -n -i \
  'private.*(rest|websocket|ws)|place.?order|cancel.?order|api[_-]?key|api[_-]?secret|passphrase|private[_-]?key|signature|wallet' \
  trading-app/src/Trading/Paper \
  trading-app/tests/Trading/Paper \
  trading-app/tests/Fixtures/PaperMarketData
git status --short
git diff --check origin/main...HEAD
git diff --stat origin/main...HEAD
```

Expected: scan hits are limited to explicit guard/redaction tests and documentation assertions, with no client or mutative exchange implementation; `git diff --check` exits `0`; no dataset under `trading-app/var/` is tracked.

- [ ] **Step 6: Audit approved-spec coverage and prohibited scope**

Verify from the diff that:

- events are exchange-neutral, deterministic and reject identity conflicts;
- completed datasets are immutable and checksummed;
- replay verifies before yielding and checkpoints bind to the event checksum;
- Paper refuses a non-Fake gateway and a non-Paper database;
- `market_data_venue` remains nullable for legacy data and never overwrites `exchange`;
- v2 matching includes venue provenance and the #132 export surfaces it;
- no OKX/Hyperliquid network client, strategy execution, YAML strategy change, exchange-private call, exchange write, credential, Bitmart code change or synthetic analytics insertion exists.

- [ ] **Step 7: Commit only evidence-driven final fixes, then verify a clean tree**

When Step 1 through Step 6 required a correction:

```bash
git add \
  trading-app/src/Trading/Paper \
  trading-app/src/Entity/OrderIntent.php \
  trading-app/src/Entity/TradeLineage.php \
  trading-app/src/Entity/TradeLifecycleEvent.php \
  trading-app/src/Entity/FillCostLedgerEntry.php \
  trading-app/src/Entity/TradeZoneEvent.php \
  trading-app/src/Trading/Entity/PositionTradeAnalysisV2.php \
  trading-app/src/Trading/Backfill \
  trading-app/migrations/Version20260719120000.php \
  trading-app/migrations/Version20260719130000.php \
  trading-app/tests/Trading/Paper \
  trading-app/tests/Trading/Backfill \
  trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php \
  trading-app/tests/Fixtures/PaperMarketData \
  trading-app/config/services.yaml \
  trading-app/.gitignore \
  docs/operations/paper-market-replay.md \
  mkdocs.yml
git commit -m "fix(paper): satisfy foundation validation gates"
```

Then run:

```bash
git status --short --branch
git log --oneline --decorate origin/main..HEAD
```

Expected: the worktree is clean and the branch contains the approved design commit plus the focused implementation commits from this plan.

## PR Handoff

After Task 8 passes, push `issue/132-paper-market-replay-foundation` and open a PR whose body states:

```text
Part of #132
Related to #196

Delivers PR 1 of the approved Paper public-market replay design:
- normalized event and dataset contracts;
- append-only checksummed recording and deterministic replay checkpoints;
- fail-closed Paper/Fake/database guards;
- additive market_data_venue persistence and v2 analytics provenance.

Explicitly excludes network clients, strategy execution, exchange-private APIs,
exchange writes, strategy tuning and Bitmart scope.
```

After every push, invalidate previous review evidence. Wait for current-HEAD CI, then wait before requesting a Codex GitHub review to avoid duplicate review cycles. Address every applicable thread with `review_fix`; escalate security, persistence, race, fail-open or repeated findings with `review_escalated`. Merge only after current-HEAD CI is green, Codex is favorable, and no unresolved blocking thread remains.
