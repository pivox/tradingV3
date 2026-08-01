# Paper Execution Coordinator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the deterministic Fake-only Paper coordinator that binds every durable strategy and execution effect to one explicit network, market-data venue, immutable configuration snapshot, strategy profile, and run ID.

**Architecture:** Use content-addressed value objects at the boundary, an append-only PostgreSQL journal with a rebuildable checkpoint, and a three-phase prepare/commit/Fake/acknowledge protocol. Extract the existing TradeEntry path into preparation and completion seams so OrderIntent and lineage are durable before the per-cell Fake adapter receives an idempotent request; replay and live capture then share one acknowledged consumer.

**Tech Stack:** PHP 8.4, Symfony 7, Doctrine ORM/DBAL, PostgreSQL, PHPUnit 11, PHPStan, existing Paper canonical JSON and market-event contracts, existing TradeEntry/MTF services, existing deterministic Fake exchange, MkDocs.

---

## File map

### New execution-domain files

- `trading-app/src/Trading/Paper/Execution/Configuration/PaperConfigurationSnapshot.php`: immutable canonical snapshot value.
- `trading-app/src/Trading/Paper/Execution/Configuration/PaperConfigurationSnapshotFactory.php`: strict allowlist, secret rejection and SHA-256 identity.
- `trading-app/src/Trading/Paper/Execution/Profile/PaperProfileEligibility.php`: `reference_only`/future `baseline_eligible` enum.
- `trading-app/src/Trading/Paper/Execution/Profile/PaperProfileRegistry.php`: exact legacy-profile registry without default or alias.
- `trading-app/src/Trading/Paper/Execution/Identity/PaperExecutionCell.php`: five-field deterministic cell and account namespace.
- `trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionCheckpoint.php`: typed journal checkpoint projection.
- `trading-app/src/Trading/Paper/Execution/Persistence/PaperPendingEffect.php`: durable effect request.
- `trading-app/src/Trading/Paper/Execution/Persistence/PaperSourceClaim.php`: accepted/replay/gap/conflict result.
- `trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionStoreInterface.php`: transactional journal contract.
- `trading-app/src/Trading/Paper/Execution/Persistence/DoctrinePaperExecutionStore.php`: PostgreSQL implementation.
- `trading-app/src/Trading/Paper/Execution/Market/PaperKlineWindow.php`: bounded deterministic candles.
- `trading-app/src/Trading/Paper/Execution/Market/PaperKlineProvider.php`: existing provider-contract adapter.
- `trading-app/src/Trading/Paper/Execution/Market/PaperMarketStateProjector.php`: candle/book event application and journal restoration.
- `trading-app/src/Trading/Paper/Execution/Strategy/PaperPreparedDecision.php`: pure strategy/preflight result.
- `trading-app/src/Trading/Paper/Execution/Strategy/PaperPreparedEffectCodec.php`: canonical durable prepared-plan payload and strict decoder.
- `trading-app/src/Trading/Paper/Execution/Strategy/PaperMtfStrategyBridge.php`: synchronous existing-MTF to prepared TradeEntry bridge.
- `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeRuntime.php`: one cell-scoped Fake graph and event cursor.
- `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeRuntimeFactory.php`: safe per-cell state path and Fake-only construction.
- `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeEffectDispatcher.php`: idempotent prepared-plan execution and event normalization.
- `trading-app/src/Trading/Paper/Execution/PaperExecutionCoordinator.php`: three-phase state machine.
- `trading-app/src/Trading/Paper/Execution/PaperExecutionCounters.php`: journal-derived requested/acknowledged/retried/failed counters.
- `trading-app/src/Trading/Paper/Execution/PaperExecutionConsumer.php`: live/replay acknowledged consumer.
- `trading-app/src/Command/PaperExecutionReplayCommand.php`: explicit single-cell replay entry point.

### Modified production files

- `trading-app/src/TradeEntry/Service/TradeEntryService.php`: delegate unchanged preparation and completion behavior to reusable seams.
- `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`: expose deterministic Paper preparation and deterministic Paper trade IDs.
- `trading-app/src/TradeEntry/Execution/ExchangeExecutionService.php`: execute an already prepared plan on an explicitly supplied adapter.
- `trading-app/src/Exchange/Fake/FakeExchangeEventNormalizer.php`: retain Paper lineage/provenance on all Fake fills.
- `trading-app/src/Entity/{OrderIntent,TradeLineage,TradeLifecycleEvent,FillCostLedgerEntry,TradeZoneEvent}.php`: additive Paper provenance.
- `trading-app/src/Service/OrderIntentManager.php`: require and persist coordinator provenance.
- `trading-app/src/Trading/Lineage/TradeLineageManager.php`: copy Paper provenance to lineage and lifecycle context.
- `trading-app/src/Trading/Pnl/FillCostLedgerIngestionService.php`: copy provenance from lineage to ledger entries.
- `trading-app/src/Logging/TradeLifecycleLogger.php`: persist Paper provenance from safe lifecycle context.
- `trading-app/src/TradeEntry/Service/ZoneSkipPersistenceService.php`: persist Paper provenance on zone facts.
- `trading-app/src/Trading/Paper/Runtime/{PaperRuntimeContext,PaperRuntimeGuard}.php`: require explicit cell network/venue/snapshot/profile/run fields.
- `trading-app/config/services.yaml`: coordinator ports, explicit Paper paths and Fake-only wiring.
- `docs/handbook/technical/paper-market-data-datasets.md`: coordinator/restart/operator contract.

### Database and tests

- `trading-app/migrations/Version20260801120000.php`: Paper tables, append-only trigger and nullable provenance columns/indexes.
- `trading-app/tests/Trading/Paper/Execution/**`: unit, contract, recovery and end-to-end tests.
- `trading-app/tests/Trading/Paper/Persistence/PaperExecutionMigrationTest.php`: PostgreSQL migration contract.
- Existing TradeEntry, Fake, lineage, ledger and Paper tests are characterization/regression gates.

## Task 1: Add content-addressed configuration snapshots and exact profile eligibility

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Configuration/PaperConfigurationSnapshot.php`
- Create: `trading-app/src/Trading/Paper/Execution/Configuration/PaperConfigurationSnapshotFactory.php`
- Create: `trading-app/src/Trading/Paper/Execution/Profile/PaperProfileEligibility.php`
- Create: `trading-app/src/Trading/Paper/Execution/Profile/PaperProfileRegistry.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Configuration/PaperConfigurationSnapshotTest.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Profile/PaperProfileRegistryTest.php`

- [ ] **Step 1: Write failing snapshot and profile tests**

Pin canonical identity, type sensitivity, recursive secret rejection, exact names and legacy classification:

```php
$a = $factory->create(['strategy' => ['mode' => 'regular'], 'symbols' => ['BTCUSDT']]);
$b = $factory->create(['symbols' => ['BTCUSDT'], 'strategy' => ['mode' => 'regular']]);
self::assertSame($a->id, $b->id);
self::assertSame('sha256:' . hash('sha256', $a->canonicalJson), $a->id);

$this->expectExceptionMessage('paper_configuration_forbidden_key');
$factory->create(['strategy' => ['nested' => ['api_secret' => 'never-store']]]);

self::assertSame(PaperProfileEligibility::REFERENCE_ONLY, $registry->require('regular'));
$this->expectExceptionMessage('paper_strategy_profile_unknown');
$registry->require('REGULAR');
```

- [ ] **Step 2: Run tests and verify RED**

```bash
cd trading-app
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Configuration \
  tests/Trading/Paper/Execution/Profile
```

Expected: class-not-found failures.

- [ ] **Step 3: Implement the immutable values and strict builder**

Use the existing `CanonicalJson` and hash the versioned envelope:

```php
final readonly class PaperConfigurationSnapshot
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string, mixed> $configuration */
    public function __construct(
        public string $id,
        public string $canonicalJson,
        public array $configuration,
    ) {
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $id) !== 1) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_id_invalid');
        }
    }
}
```

Allow exactly `strategy`, `risk`, `execution`, `models`, `symbols`, and
`runtime`. Recursively compare normalized keys against
`api_key|apikey|secret|token|password|passphrase|credential|wallet|signer|signature|private_key`.
Define the profile registry with the exact map:

```php
private const PROFILES = [
    'regular' => PaperProfileEligibility::REFERENCE_ONLY,
    'scalper' => PaperProfileEligibility::REFERENCE_ONLY,
    'scalper_micro' => PaperProfileEligibility::REFERENCE_ONLY,
];
```

- [ ] **Step 4: Run tests and verify GREEN**

Run the Task 1 command. Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/Configuration \
  trading-app/src/Trading/Paper/Execution/Profile \
  trading-app/tests/Trading/Paper/Execution
git commit -m "feat(paper): add immutable execution snapshots"
```

## Task 2: Define deterministic execution cells and strengthen runtime safety

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Identity/PaperExecutionCell.php`
- Modify: `trading-app/src/Trading/Paper/Runtime/PaperRuntimeContext.php`
- Modify: `trading-app/src/Trading/Paper/Runtime/PaperRuntimeGuard.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Identity/PaperExecutionCellTest.php`
- Test: `trading-app/tests/Trading/Paper/Runtime/PaperRuntimeGuardTest.php`

- [ ] **Step 1: Write failing identity and guard tests**

```php
$cell = PaperExecutionCell::create(
    PaperMarketDataNetwork::TESTNET,
    PaperMarketDataVenue::HYPERLIQUID,
    'sha256:' . str_repeat('a', 64),
    'scalper_micro',
    'run-001',
);
self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $cell->id);
self::assertSame('paper:cell:v1:' . substr($cell->id, 7), $cell->accountNamespace);
self::assertNotSame($cell->id, PaperExecutionCell::create(
    PaperMarketDataNetwork::MAINNET,
    PaperMarketDataVenue::HYPERLIQUID,
    $cell->configurationSnapshotId,
    'scalper_micro',
    'run-001',
)->id);
```

Extend the guard matrix to reject `legacy_unknown`, blank run/profile/snapshot,
OKX testnet, and any event network/venue different from the cell. The supported
matrix in this lot is exactly OKX mainnet plus Hyperliquid mainnet/testnet.

- [ ] **Step 2: Run tests and verify RED**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Identity/PaperExecutionCellTest.php \
  tests/Trading/Paper/Runtime/PaperRuntimeGuardTest.php
```

Expected: missing cell/context fields and assertions.

- [ ] **Step 3: Implement exact canonical identity**

Hash this envelope with `CanonicalJson`:

```php
$identity = [
    'schema_version' => 1,
    'network' => $network->value,
    'market_data_venue' => $venue->value,
    'configuration_snapshot_id' => $configurationSnapshotId,
    'strategy_profile' => $strategyProfile,
    'run_id' => $runId,
];
$digest = hash('sha256', CanonicalJson::encode($identity));
```

Require the enum instances at the constructor boundary; do not accept strings,
aliases, case folding or null. Add the cell to `PaperRuntimeContext` and make
`PaperRuntimeGuard::assertEventProvenance()` compare exact enum instances.

- [ ] **Step 4: Run tests and verify GREEN**

Run the Task 2 command. Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/Identity \
  trading-app/src/Trading/Paper/Runtime \
  trading-app/tests/Trading/Paper
git commit -m "feat(paper): identify isolated execution cells"
```

## Task 3: Add the Paper journal schema and durable trade provenance

**Files:**
- Create: `trading-app/migrations/Version20260801120000.php`
- Create: `trading-app/tests/Trading/Paper/Persistence/PaperExecutionMigrationTest.php`
- Modify: `trading-app/src/Entity/OrderIntent.php`
- Modify: `trading-app/src/Entity/TradeLineage.php`
- Modify: `trading-app/src/Entity/TradeLifecycleEvent.php`
- Modify: `trading-app/src/Entity/FillCostLedgerEntry.php`
- Modify: `trading-app/src/Entity/TradeZoneEvent.php`
- Create: `trading-app/tests/Trading/Paper/Persistence/PaperExecutionProvenanceEntityTest.php`

- [ ] **Step 1: Write failing PostgreSQL and entity-contract tests**

Require four new tables and these nullable compatibility columns on all five
trade tables:

```php
private const PROVENANCE = [
    'paper_network' => 16,
    'paper_execution_cell_id' => 71,
    'configuration_snapshot_id' => 71,
    'paper_eligibility' => 32,
];
```

Assert PostgreSQL rejects unsupported network/eligibility values, duplicate
cell tuples, duplicate `(cell_id, journal_ordinal)`, and every `UPDATE` or
`DELETE` on `paper_execution_event` with SQLSTATE `P0001`.

- [ ] **Step 2: Run tests and verify RED**

```bash
DATABASE_URL="$PAPER_TEST_DATABASE_URL" XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Persistence/PaperExecutionMigrationTest.php \
  tests/Trading/Paper/Persistence/PaperExecutionProvenanceEntityTest.php
```

Expected: migration/class properties are absent. The command must refuse to run
unless the parsed database name ends in `_paper_test`.

- [ ] **Step 3: Implement the additive migration and entity accessors**

Create:

```sql
paper_configuration_snapshot(id varchar(71) primary key, schema_version int,
  canonical_json jsonb, content_checksum char(64), created_at timestamptz)
paper_execution_cell(id varchar(71) primary key, network varchar(16),
  market_data_venue varchar(32), configuration_snapshot_id varchar(71),
  strategy_profile varchar(80), run_id varchar(96), account_namespace varchar(78),
  eligibility varchar(32), terminal_state varchar(32), created_at timestamptz)
paper_execution_event(cell_id varchar(71), journal_ordinal bigint,
  event_type varchar(48), source_position bigint null, source_event_id char(64) null,
  effect_key varchar(71) null, payload jsonb, payload_checksum char(64),
  appended_at timestamptz, primary key(cell_id, journal_ordinal))
paper_execution_checkpoint(cell_id varchar(71) primary key,
  next_source_position bigint, journal_ordinal bigint, journal_checksum char(64),
  fake_event_cursor bigint, killed boolean, lock_version bigint, updated_at timestamptz)
```

Add a PostgreSQL trigger function that raises
`paper_execution_event_append_only` before update/delete. Add nullable indexed
provenance columns so existing datasets remain readable. Add a foreign key from
cell to snapshot, `UNIQUE(run_id)`, `UNIQUE(account_namespace)`, and check
constraints for the exact network, venue, eligibility and terminal-state enums.

- [ ] **Step 4: Run tests and verify GREEN**

Run the Task 3 command. Expected: all migration/entity tests pass on the
isolated PostgreSQL database.

- [ ] **Step 5: Commit**

```bash
git add trading-app/migrations/Version20260801120000.php \
  trading-app/src/Entity \
  trading-app/tests/Trading/Paper/Persistence
git commit -m "feat(paper): add execution journal schema"
```

## Task 4: Implement the transactional journal, ordering and kill switch

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionCheckpoint.php`
- Create: `trading-app/src/Trading/Paper/Execution/Persistence/PaperPendingEffect.php`
- Create: `trading-app/src/Trading/Paper/Execution/Persistence/PaperSourceClaim.php`
- Create: `trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionStoreInterface.php`
- Create: `trading-app/src/Trading/Paper/Execution/Persistence/DoctrinePaperExecutionStore.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Persistence/DoctrinePaperExecutionStoreTest.php`

- [ ] **Step 1: Write failing store tests**

Cover snapshot/cell idempotence, conflicting snapshot bytes, run-ID tuple
conflict, exact next position, exact duplicate, conflicting duplicate, old
unknown event, future gap, pending-effect order, journal checksum corruption,
kill persistence and explicit resume.

```php
self::assertSame(PaperSourceClaim::ACCEPTED, $store->claimSource($cell, 0, $event)->status);
self::assertSame(PaperSourceClaim::REPLAYED, $store->claimSource($cell, 0, $event)->status);
$this->expectExceptionMessage('paper_execution_source_gap');
$store->claimSource($cell, 2, $nextEvent);
```

- [ ] **Step 2: Run tests and verify RED**

```bash
DATABASE_URL="$PAPER_TEST_DATABASE_URL" XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Persistence/DoctrinePaperExecutionStoreTest.php
```

Expected: store classes are missing.

- [ ] **Step 3: Implement row locks, canonical checksums and transitions**

The interface must expose only explicit operations:

```php
public function registerSnapshot(PaperConfigurationSnapshot $snapshot): void;
public function registerCell(PaperExecutionCell $cell, PaperProfileEligibility $eligibility): void;
public function transactional(callable $operation): mixed;
public function claimSource(PaperExecutionCell $cell, int $position, PaperMarketEvent $event): PaperSourceClaim;
public function appendEffect(PaperExecutionCell $cell, int $position, string $effectKey, array $payload): void;
public function pendingEffects(PaperExecutionCell $cell): array;
public function acknowledge(PaperExecutionCell $cell, int $position, string $effectKey, array $payload, int $fakeEventCursor): void;
public function checkpoint(PaperExecutionCell $cell): PaperExecutionCheckpoint;
public function kill(PaperExecutionCell $cell): void;
public function resume(PaperExecutionCell $cell): void;
```

Use `SELECT ... FOR UPDATE` on the checkpoint. Allocate journal ordinals under
that lock. Compute each journal checksum from the prior checksum plus canonical
event content. Duplicate identity with a different payload hash throws
`market_event_identity_conflict`; lower unknown position throws
`paper_execution_source_out_of_order`; higher position throws
`paper_execution_source_gap`.

- [ ] **Step 4: Run tests and verify GREEN**

Run the Task 4 command. Expected: all store/restart tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/Persistence \
  trading-app/tests/Trading/Paper/Execution/Persistence
git commit -m "feat(paper): persist execution journal state"
```

## Task 5: Propagate cell provenance through every durable trade fact

**Files:**
- Modify: `trading-app/src/Service/OrderIntentManager.php`
- Modify: `trading-app/src/Trading/Lineage/TradeLineageManager.php`
- Modify: `trading-app/src/Trading/Pnl/FillCostLedgerIngestionService.php`
- Modify: `trading-app/src/Logging/TradeLifecycleLogger.php`
- Modify: `trading-app/src/TradeEntry/Service/ZoneSkipPersistenceService.php`
- Modify: entity files from Task 3
- Test: `trading-app/tests/Trading/Paper/Execution/Persistence/PaperTradeProvenancePropagationTest.php`

- [ ] **Step 1: Write a failing propagation test**

Create one OrderIntent and lineage, normalize one Fake fill, log lifecycle/zone,
and assert every entity contains:

```php
self::assertSame('testnet', $fact->getPaperNetwork());
self::assertSame($cell->id, $fact->getPaperExecutionCellId());
self::assertSame($cell->configurationSnapshotId, $fact->getConfigurationSnapshotId());
self::assertSame('reference_only', $fact->getPaperEligibility());
self::assertSame('fake', $fact->getExchange());
self::assertSame('hyperliquid', $fact->getMarketDataVenue());
```

Also assert coordinator writes fail if any of the four Paper values is missing
or conflicts with the cell.

- [ ] **Step 2: Run test and verify RED**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Persistence/PaperTradeProvenancePropagationTest.php
```

Expected: missing getters/context propagation.

- [ ] **Step 3: Implement one shared safe provenance envelope**

Add `PaperExecutionCell::provenance(PaperProfileEligibility $eligibility)`:

```php
return [
    'paper_network' => $this->network->value,
    'market_data_venue' => $this->marketDataVenue->value,
    'paper_execution_cell_id' => $this->id,
    'configuration_snapshot_id' => $this->configurationSnapshotId,
    'paper_eligibility' => $eligibility->value,
    'strategy_profile' => $this->strategyProfile,
    'run_id' => $this->runId,
    'exchange' => 'fake',
];
```

Managers accept this envelope as a required argument on the Paper path and copy
it without normalization/fallback. Extend Fake fill-lineage metadata keys for
all fills, not only liquidation fills.

- [ ] **Step 4: Run targeted lineage/ledger regressions**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Persistence/PaperTradeProvenancePropagationTest.php \
  tests/Trading/Lineage \
  tests/Trading/Pnl \
  tests/Exchange/Event/FakeExchangeEventNormalizerTest.php
```

Expected: all tests pass; legacy constructors still leave Paper fields null.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Entity trading-app/src/Service/OrderIntentManager.php \
  trading-app/src/Trading/Lineage trading-app/src/Trading/Pnl \
  trading-app/src/Logging trading-app/src/TradeEntry/Service/ZoneSkipPersistenceService.php \
  trading-app/tests
git commit -m "feat(paper): propagate execution cell provenance"
```

## Task 6: Build deterministic Paper market-state providers

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Market/PaperKlineWindow.php`
- Create: `trading-app/src/Trading/Paper/Execution/Market/PaperKlineProvider.php`
- Create: `trading-app/src/Trading/Paper/Execution/Market/PaperMarketStateProjector.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Market/PaperKlineProviderTest.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Market/PaperMarketStateProjectorTest.php`

- [ ] **Step 1: Write failing common-event provider tests**

Feed equivalent OKX and Hyperliquid closed-candle events and require the same
`KlineDto`. Test all four timeframes, bounded windows, exact time ordering,
duplicate replay, conflicting candle, top-of-book update, crossed book and
journal restoration.

```php
$projector->apply($candleEvent);
self::assertSame('25000.5', (string) $provider->getLastKline(
    'BTCUSDT', Timeframe::TF_1M,
)?->close);
```

- [ ] **Step 2: Run tests and verify RED**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit tests/Trading/Paper/Execution/Market
```

Expected: provider/projector classes are missing.

- [ ] **Step 3: Implement bounded, source-neutral state**

Map `CANDLE_1M/5M/15M/1H` to the matching `Timeframe`; require
`confirmed=true`; build `KlineDto` from canonical payload fields; sort by
`openTime`; cap each `(symbol,timeframe)` window at 500. Store book tops as
positive `bid < ask`. `restore()` must replay acknowledged journal source
events without invoking strategy or Fake execution.

- [ ] **Step 4: Run tests and verify GREEN**

Run the Task 6 command. Expected: all common provider tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/Market \
  trading-app/tests/Trading/Paper/Execution/Market
git commit -m "feat(paper): project replayable market state"
```

## Task 7: Split existing strategy preparation from execution without behavior drift

**Files:**
- Create: `trading-app/src/TradeEntry/Dto/PreparedTradeEntry.php`
- Create: `trading-app/src/TradeEntry/Service/TradeEntryPreparationService.php`
- Modify: `trading-app/src/TradeEntry/Service/TradeEntryService.php`
- Modify: `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperPreparedDecision.php`
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperPreparedEffectCodec.php`
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperMtfStrategyBridge.php`
- Test: `trading-app/tests/TradeEntry/Service/TradeEntryPreparationParityTest.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Strategy/PaperMtfStrategyBridgeTest.php`

- [ ] **Step 1: Add characterization tests before extraction**

For accepted, out-of-zone, low-leverage and non-tradable fixtures, record the
existing `buildAndSimulate()` status plus stable plan fields. Assert the new
preparation seam returns the same values and emits no adapter or Messenger
call.

```php
$prepared = $preparation->prepare($request, $decisionKey, 'regular', $context);
self::assertSame($simulation->raw['plan'], $prepared->stablePlanPayload());
self::assertSame(0, $forbiddenAdapter->calls);
self::assertSame(0, $forbiddenMessageBus->calls);
```

- [ ] **Step 2: Run characterization tests and verify RED only for the new seam**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/TradeEntry/Service/TradeEntryPreparationParityTest.php \
  tests/Trading/Paper/Execution/Strategy/PaperMtfStrategyBridgeTest.php
```

Expected: existing simulation assertions pass; new classes/methods fail.

- [ ] **Step 3: Extract preparation and add the synchronous Paper bridge**

Use this result contract:

```php
final readonly class PreparedTradeEntry
{
    public function __construct(
        public ?OrderPlanModel $plan,
        public ?ExecutionResult $terminalResult,
        public string $decisionKey,
        public string $internalTradeId,
        public LifecycleContextBuilder $lifecycle,
        public string $mode,
        public string $executionTimeframe,
    ) {
        if (($plan === null) === ($terminalResult === null)) {
            throw new \InvalidArgumentException('prepared_trade_entry_state_invalid');
        }
    }
}
```

Move the existing daily-loss, preflight, EntryZone, fallback and leverage blocks
unchanged into `TradeEntryPreparationService::prepare()`. Make both
`buildAndExecute()` and `buildAndSimulate()` delegate to it. Add a deterministic
Paper trade ID derived from `cell_id + source_event_id + decision_key`; retain
the legacy random ID outside Paper.

`PaperMtfStrategyBridge` calls `MtfValidatorInterface::run()` synchronously only
after a closed 1m candle and only when all required profile timeframes are warm.
It passes exact `Exchange::FAKE`, `MarketType::PERPETUAL`, profile, run ID and
cell lineage; it converts at most one tradable result per symbol into
`PreparedTradeEntry`. No Messenger dispatcher is used.

`PaperPreparedEffectCodec` serializes every field required after a process
restart: the full `OrderPlanModel`, decision key, internal trade ID, execution
timeframe, profile, lifecycle safe context, OrderIntent identity and cell
provenance. Decode requires the exact versioned key set and recomputes the
canonical payload checksum; unknown/missing fields fail with
`paper_prepared_effect_payload_invalid`.

- [ ] **Step 4: Run parity and existing TradeEntry/MTF suites**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/TradeEntry \
  tests/MtfValidator \
  tests/Trading/Paper/Execution/Strategy
```

Expected: all tests pass with unchanged legacy outputs.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/TradeEntry trading-app/src/MtfValidator \
  trading-app/src/Trading/Paper/Execution/Strategy trading-app/tests
git commit -m "refactor(trade-entry): separate paper preparation"
```

## Task 8: Create one durable Fake runtime per execution cell

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeRuntime.php`
- Create: `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeRuntimeFactory.php`
- Modify: `trading-app/src/TradeEntry/Execution/ExchangeExecutionService.php`
- Create: `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeEffectDispatcher.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Fake/PaperFakeRuntimeFactoryTest.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Fake/PaperFakeEffectDispatcherTest.php`

- [ ] **Step 1: Write failing isolation and idempotency tests**

Create two cells differing by each identity dimension and assert distinct state
files/namespaces. Dispatch the same prepared effect twice and assert one order,
one balance mutation and an idempotent replay result. Inject a non-Fake adapter
and expect `paper_execution_exchange_must_be_fake`.

- [ ] **Step 2: Run tests and verify RED**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit tests/Trading/Paper/Execution/Fake
```

Expected: runtime factory/dispatcher classes are missing.

- [ ] **Step 3: Implement safe per-cell Fake construction**

Resolve state only under `%kernel.project_dir%/var/paper-fake-state/`:

```php
$statePath = sprintf('%s/%s.dat', rtrim($root, '/'), substr($cell->id, 7));
$state = new FakeExchangeStateStore($statePath);
$book = new FakeExchangeOrderBook($state);
$engine = new FakeExchangeMatchingEngine($state, $book, $clock);
$adapter = new FakeExchangeAdapter($state, $book, $engine, $clock);
```

Reject symlinks, non-private directories and digest/path mismatch. Add
`ExchangeExecutionService::executeOnAdapter()`; existing `execute()` resolves
its adapter then delegates, while Paper supplies the cell runtime adapter
directly. Capture the Fake event cursor before/after execution and normalize
only the new events.

- [ ] **Step 4: Run Fake and execution regressions**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/Fake \
  tests/TradeEntry/Execution/ExchangeExecutionServiceTest.php \
  tests/Exchange/Fake \
  tests/Exchange/Adapter/FakeExchangeAdapterTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/Fake \
  trading-app/src/TradeEntry/Execution/ExchangeExecutionService.php \
  trading-app/tests
git commit -m "feat(paper): isolate fake runtime per cell"
```

## Task 9: Implement the three-phase coordinator and recovery protocol

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/PaperExecutionCoordinator.php`
- Create: `trading-app/src/Trading/Paper/Execution/PaperExecutionCounters.php`
- Test: `trading-app/tests/Trading/Paper/Execution/PaperExecutionCoordinatorTest.php`
- Test: `trading-app/tests/Trading/Paper/Execution/PaperExecutionCoordinatorRecoveryTest.php`

- [ ] **Step 1: Write failing coordinator and crash-matrix tests**

Test a no-decision event, accepted order, exact duplicate, network/venue
mismatch, gap, out-of-order, kill/restart/resume and corruption. Inject faults
at these named boundaries:

```php
PaperCrashPoint::BEFORE_PHASE_1_COMMIT;
PaperCrashPoint::AFTER_PHASE_1_COMMIT;
PaperCrashPoint::AFTER_FAKE_EFFECT;
PaperCrashPoint::BEFORE_PHASE_3_COMMIT;
PaperCrashPoint::AFTER_PHASE_3_COMMIT;
```

For every restart case assert one Fake order and identical stable journal,
intent, lineage, lifecycle, fill, cost and checkpoint output.
Assert journal-derived counters distinguish requested, acknowledged, retried
and failed effects without counting a retry as another order/fill. Capture logs
and assert only stable reason codes, cell/snapshot IDs, safe event types,
positions and ordinals appear; raw payloads and adapter exception text do not.

- [ ] **Step 2: Run tests and verify RED**

```bash
DATABASE_URL="$PAPER_TEST_DATABASE_URL" XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/PaperExecutionCoordinatorTest.php \
  tests/Trading/Paper/Execution/PaperExecutionCoordinatorRecoveryTest.php
```

Expected: coordinator is missing.

- [ ] **Step 3: Implement the exact state machine**

Expose one explicit method:

```php
public function consumeAt(
    PaperExecutionCell $cell,
    PaperProfileEligibility $eligibility,
    string $datasetId,
    int $sourcePosition,
    PaperMarketEvent $event,
): void;
```

Sequence:

1. run database/runtime guards and exact provenance checks;
2. restore acknowledged market state and decode/reconcile pending effects only
   from the journal plus the cell Fake state;
3. apply the event to a copy of market state and prepare the strategy result;
4. Phase 1 `store->transactional(...)`: claim source, persist
   OrderIntent/lineage, append the deterministic encoded `effect_requested`,
   then commit;
5. Phase 2: dispatch only through the cell `PaperFakeRuntime`;
6. Phase 3 transaction: project normalized Fake events with full provenance,
   append `effect_acknowledged`/source ack and advance checkpoint;
7. publish the prepared market state only after Phase 3 commits.

Log through a dedicated Paper channel with a fixed context allowlist. Derive
`PaperExecutionCounters` from journal event types rather than mutable in-memory
counters, so restart does not alter totals.

For no-order events, Phase 1 and acknowledgement happen in one transaction.
For a killed cell, reconcile existing pending effects but reject a new prepared
order with `paper_execution_cell_killed` and do not advance its source event.

- [ ] **Step 4: Run coordinator/recovery tests and verify GREEN**

Run the Task 9 command. Expected: all crash points converge to the same stable
state with exactly one Fake effect.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/PaperExecutionCoordinator.php \
  trading-app/tests/Trading/Paper/Execution/PaperExecutionCoordinator*
git commit -m "feat(paper): coordinate durable fake execution"
```

## Task 10: Wire live capture and replay to the same acknowledged consumer

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/PaperExecutionConsumer.php`
- Create: `trading-app/src/Command/PaperExecutionReplayCommand.php`
- Modify: `trading-app/config/services.yaml`
- Test: `trading-app/tests/Trading/Paper/Execution/PaperExecutionConsumerTest.php`
- Test: `trading-app/tests/Command/PaperExecutionReplayCommandTest.php`
- Test: `trading-app/tests/Trading/Paper/Execution/PaperExecutionServiceWiringTest.php`

- [ ] **Step 1: Write failing consumer, command and container tests**

The command requires every value explicitly:

```text
app:paper-market:replay
  --dataset=/absolute/private/path
  --configuration=/absolute/private/config.json
  --profile=regular
  --run-id=paper-run-001
```

Venue and network come from the verified manifest and must match every event;
they are never CLI-overridable. Assert missing options, relative/symlink paths,
legacy manifests, unknown profiles and non-Paper DBs fail before source
iteration. Assert service inspection finds no exchange HTTP/WS client,
credential provider, wallet or signer in the coordinator dependency graph.

- [ ] **Step 2: Run tests and verify RED**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/PaperExecutionConsumerTest.php \
  tests/Command/PaperExecutionReplayCommandTest.php \
  tests/Trading/Paper/Execution/PaperExecutionServiceWiringTest.php
```

Expected: consumer/command/services are absent.

- [ ] **Step 3: Implement shared consumption and explicit command wiring**

`PaperExecutionConsumer` implements `PaperLiveEventConsumerInterface` and
keeps a dataset-scoped next position from the durable checkpoint. Its replay
entry point passes `PaperReplayReader::currentEventIndex()` to `consumeAt()`.
Register only factories/ports; do not register a generic exchange adapter
registry in the Paper coordinator graph. Add disabled-by-default parameters:

```yaml
env(PAPER_EXECUTION_ENABLED): '0'
env(PAPER_FAKE_STATE_ROOT): '%kernel.project_dir%/var/paper-fake-state'
```

The command prints safe cell/network/venue/snapshot/profile/run/database/kill
state, never the configuration payload.

- [ ] **Step 4: Run wiring plus Paper regressions**

```bash
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper \
  tests/Command/PaperExecutionReplayCommandTest.php
```

Expected: all Paper tests pass without network.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Paper/Execution/PaperExecutionConsumer.php \
  trading-app/src/Command/PaperExecutionReplayCommand.php \
  trading-app/config/services.yaml trading-app/tests
git commit -m "feat(paper): wire execution replay consumer"
```

## Task 11: Prove end-to-end provenance, restart equality and credential-zero behavior

**Files:**
- Create: `trading-app/tests/Fixtures/PaperExecution/okx-mainnet-cell/`
- Create: `trading-app/tests/Fixtures/PaperExecution/hyperliquid-mainnet-cell/`
- Create: `trading-app/tests/Fixtures/PaperExecution/hyperliquid-testnet-cell/`
- Create: `trading-app/tests/Trading/Paper/Execution/PaperExecutionEndToEndTest.php`
- Create: `trading-app/tests/Trading/Paper/Execution/PaperExecutionReplayEqualityTest.php`
- Modify: `trading-app/tests/Trading/Paper/PaperFixtureContractTest.php`

- [ ] **Step 1: Add failing local-fixture end-to-end tests**

Each fixture warms `1m/5m/15m/1h`, emits a deterministic strategy decision,
opens and closes one Fake position, and contains no private field. Assert exact
joins from OrderIntent through lineage/lifecycle/ledger, `exchange=fake`, exact
network/venue/snapshot/cell, complete costs and `reference_only` baseline
exclusion.

Run uninterrupted and crash/restart variants in isolated schemas and compare
canonical rows after removing only append timestamps and generated SQL IDs.

- [ ] **Step 2: Run tests and verify RED**

```bash
DATABASE_URL="$PAPER_TEST_DATABASE_URL" XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper/Execution/PaperExecutionEndToEndTest.php \
  tests/Trading/Paper/Execution/PaperExecutionReplayEqualityTest.php \
  tests/Trading/Paper/PaperFixtureContractTest.php
```

Expected: fixtures/equality contract are missing.

- [ ] **Step 3: Add minimal redacted fixtures and close remaining integration gaps**

Fixtures may contain only normalized `PaperMarketEvent` JSON and manifests.
Extend the fixture scanner forbidden-key list with:

```php
['authorization', 'api_key', 'apikey', 'secret', 'token', 'passphrase',
 'credential', 'wallet', 'signer', 'signature', 'private_key', 'post/action']
```

Use sentinel services whose every method throws
`paper_forbidden_dependency_called`; assert their call count remains zero.

- [ ] **Step 4: Run end-to-end and complete targeted suites**

```bash
DATABASE_URL="$PAPER_TEST_DATABASE_URL" XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Trading/Paper \
  tests/Trading/Lineage \
  tests/Trading/Pnl \
  tests/TradeEntry \
  tests/Exchange/Fake
```

Expected: all tests pass, with no real HTTP/WS access.

- [ ] **Step 5: Commit**

```bash
git add trading-app/tests/Fixtures/PaperExecution \
  trading-app/tests/Trading/Paper
git commit -m "test(paper): prove coordinator replay equality"
```

## Task 12: Document, validate and prepare the review checkpoint

**Files:**
- Modify: `docs/handbook/technical/paper-market-data-datasets.md`
- Modify: `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`
- Modify only if generated by the project command: repository documentation indexes.

- [ ] **Step 1: Document the exact operator and recovery contract**

Document snapshot hashing, five-dimensional cell identity, Fake account path,
Paper database names, kill/resume, restart behavior, stable failure codes,
`reference_only`, and the replay command. Record this as PR4 after merged #330;
do not change BitMart #305 status.

- [ ] **Step 2: Run the complete fresh verification gate**

```bash
cd trading-app
DATABASE_URL="$PAPER_TEST_DATABASE_URL" XDEBUG_MODE=off php vendor/bin/phpunit
XDEBUG_MODE=off php -d memory_limit=1G vendor/bin/phpstan analyse
php bin/console lint:container
php bin/console lint:yaml config
cd ..
python3 -m mkdocs build --strict
git diff --check
```

Expected: every command exits 0. PHPUnit reports zero failures/errors. The
PostgreSQL URL must name a database ending `_paper_test`.

- [ ] **Step 3: Run explicit safety scans**

```bash
rg -n "Bitmart|BITMART" \
  trading-app/src/Trading/Paper/Execution \
  trading-app/tests/Trading/Paper/Execution
rg -n "api[_-]?key|secret|token|passphrase|wallet|signer|signature|post/action" \
  trading-app/tests/Fixtures/PaperExecution
git status --short
```

Expected: both `rg` commands return no matches; status contains only intended
coordinator files.

- [ ] **Step 4: Commit documentation**

```bash
git add docs/handbook/technical/paper-market-data-datasets.md \
  TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md
git commit -m "docs(paper): document execution coordinator"
```

- [ ] **Step 5: Push a draft PR checkpoint**

```bash
git push -u origin issue/132-paper-execution-coordinator
gh pr create --draft \
  --base main \
  --head issue/132-paper-execution-coordinator \
  --title "feat(paper): add deterministic execution coordinator" \
  --body-file /tmp/paper-execution-coordinator-pr.md
```

The PR body lists the five-field cell, snapshot contract, journal recovery
proof, Fake-only proof, `_paper_test` evidence, reference-only exclusion and
explicit remaining issues #300/#301/#310/#133/#302. Request GitHub Codex review
once, resolve every actionable thread, push fixes, wait for current-HEAD CI and
merge only after all blocking checks and threads are clear.
