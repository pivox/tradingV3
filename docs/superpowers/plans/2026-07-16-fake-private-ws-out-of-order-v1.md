# Fake Private WS Out-of-Order v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Fake/Paper private WebSocket duplicate and out-of-order delivery deterministic, persistent, fail-closed, restartable, and executable as golden scenario 16.

**Architecture:** Add opt-in persisted private-WS scenario state to the existing checksummed Fake state envelope. In scenario mode the WS client consumes an explicit ordered delivery list, fingerprints raw event identity before normalization, acknowledges only after projection succeeds, persists gap/conflict state, and clears it only after simulated REST reconciliation plus snapshot completion. Ordinary clients retain the #274 per-instance traversal.

**Tech Stack:** PHP 8.4, Symfony 7, PHPUnit 11, Brick-safe deterministic serialization, existing Fake/Paper state store, existing exchange normalizer/event bus/reconciliation contracts, JSON golden fixtures, MkDocs.

---

### Task 1: Define Canonical Private-WS Deliveries

**Files:**
- Create: `trading-app/src/Exchange/Fake/FakePrivateWsDelivery.php`
- Create: `trading-app/src/Exchange/Fake/FakePrivateWsScenario.php`
- Create: `trading-app/tests/Exchange/Fake/FakePrivateWsScenarioTest.php`

- [ ] **Step 1: Write failing delivery identity tests**

Cover exact duplicates, recursively reordered payload keys, modified payloads,
invalid blank IDs/sequences, and finite ordered scenario construction:

```php
public function testFingerprintIsCanonicalButPayloadSensitive(): void
{
    $first = FakePrivateWsDelivery::fromEvent('entry-1', new FakeExchangeEvent(
        'order.created',
        'btcusdt',
        new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ['event_sequence' => 1, 'nested' => ['b' => 2, 'a' => 1]],
    ));
    $same = FakePrivateWsDelivery::fromEvent('entry-2', new FakeExchangeEvent(
        'order.created',
        'BTCUSDT',
        new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ['nested' => ['a' => 1, 'b' => 2], 'event_sequence' => 1],
    ));
    $conflict = FakePrivateWsDelivery::fromEvent('entry-3', new FakeExchangeEvent(
        'order.created',
        'BTCUSDT',
        new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        ['event_sequence' => 1, 'nested' => ['a' => 9, 'b' => 2]],
    ));

    self::assertSame('1', $first->sequence);
    self::assertSame($first->fingerprint, $same->fingerprint);
    self::assertNotSame($first->fingerprint, $conflict->fingerprint);
}
```

- [ ] **Step 2: Run the focused test and confirm RED**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/Exchange/Fake/FakePrivateWsScenarioTest.php
```

Expected: failure because the two value objects do not exist.

- [ ] **Step 3: Implement immutable delivery and scenario objects**

Implement these public contracts:

```php
final readonly class FakePrivateWsDelivery
{
    public function __construct(
        public string $fixtureEntryId,
        public string $sequence,
        public FakeExchangeEvent $event,
        public string $fingerprint,
    ) {}

    public static function fromEvent(string $fixtureEntryId, FakeExchangeEvent $event): self;

    /** @return array{fixture_entry_id:string,sequence:string,event:FakeExchangeEvent,fingerprint:string} */
    public function toArray(): array;

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self;
}

final readonly class FakePrivateWsScenario
{
    /** @param list<FakePrivateWsDelivery> $deliveries */
    public function __construct(public string $scenarioId, public array $deliveries) {}

    /** @param list<FakeExchangeEvent> $events */
    public static function fromEvents(string $scenarioId, array $events): self;

    /** @return array{scenario_id:string,deliveries:list<array<string,mixed>>} */
    public function toArray(): array;

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self;
}
```

Canonicalize arrays recursively by sorting associative keys while preserving
list order. Hash JSON encoded with `JSON_THROW_ON_ERROR |
JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES`. Include type, normalized
symbol, atom timestamp, and payload. Reject missing/scalar-invalid sequence,
blank IDs, empty scenario IDs, malformed fingerprints, and non-list deliveries.

- [ ] **Step 4: Run focused tests and confirm GREEN**

Run the command from Step 2.

Expected: all tests pass.

- [ ] **Step 5: Commit the value objects**

```bash
git add trading-app/src/Exchange/Fake/FakePrivateWsDelivery.php \
  trading-app/src/Exchange/Fake/FakePrivateWsScenario.php \
  trading-app/tests/Exchange/Fake/FakePrivateWsScenarioTest.php
git commit -m "feat(fake): define private ws delivery fixtures (#196)"
```

### Task 2: Persist Opt-In Scenario Runtime and Audit

**Files:**
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeStateStore.php`
- Create: `trading-app/tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php`
- Modify: `trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php`

- [ ] **Step 1: Write failing persistence and legacy compatibility tests**

Use a temporary state file. Configure a scenario, mutate its runtime through
public state-store methods, reconstruct the store, and assert exact restoration.
Also persist a pre-feature payload without `privateWs` and prove it hydrates as
inactive/connected.

Required public shape:

```php
$state->configurePrivateWsScenario($scenario);
$delivery = $state->privateWsCurrentDelivery();
$state->acknowledgePrivateWsDelivery($delivery);
$state->markPrivateWsGap('2', '3', $delivery);
$audit = $state->privateWsAudit();

self::assertSame('resync_required', $audit['connection_state']);
self::assertSame(1, $audit['gap_total']);
self::assertSame('1', $audit['last_acknowledged_sequence']);
```

- [ ] **Step 2: Run focused persistence tests and confirm RED**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php \
  tests/Exchange/Adapter/FakeExchangeAdapterTest.php \
  --filter 'PrivateWs|LegacyState'
```

Expected: failures for missing state-store scenario APIs.

- [ ] **Step 3: Add additive private-WS state to the Fake envelope**

Add one validated `privateWs` runtime array to initialization, persistence,
hydrate defaults, runtime snapshots, rollback restoration, and recovery
metadata. Its stable shape is:

```php
[
    'scenario' => null, // or FakePrivateWsScenario::toArray()
    'next_delivery_index' => 0,
    'acknowledged_fingerprints' => [],
    'last_acknowledged_sequence' => null,
    'last_observed_numeric_sequence' => 0,
    'connection_state' => 'connected',
    'resync_reason' => null,
    'counters' => [
        'acknowledged_total' => 0,
        'duplicate_total' => 0,
        'gap_total' => 0,
        'conflict_total' => 0,
        'resync_total' => 0,
    ],
    'records' => [],
]
```

Implement transactional public methods for scenario configuration, current
delivery lookup, exact-duplicate advance, acknowledgement, gap/conflict marking,
snapshot completion, and read-only audit. Cap records at 100 entries and store
only stable codes, sequences, fixture entry ID, and the first 12 fingerprint
characters. Never store raw payload in audit records.

Validate every restored field. Missing `privateWs` is accepted as the default;
present malformed state throws `fake_exchange_state_shape_invalid`.

- [ ] **Step 4: Run persistence tests and broad state regressions**

Run:

```bash
php vendor/bin/phpunit \
  tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php \
  tests/Exchange/Adapter/FakeExchangeAdapterTest.php
```

Expected: all tests pass, including legacy recovery and transaction rollback.

- [ ] **Step 5: Commit persisted runtime state**

```bash
git add trading-app/src/Exchange/Fake/FakeExchangeStateStore.php \
  trading-app/tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php \
  trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php
git commit -m "feat(fake): persist private ws scenario state (#196)"
```

### Task 3: Execute Duplicate, Gap, and Conflict Semantics

**Files:**
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeWsClient.php`
- Modify: `trading-app/src/Exchange/Fake/FakePrivateWsException.php`
- Modify: `trading-app/tests/Exchange/Event/ExchangeWsIngestionServiceTest.php`

- [ ] **Step 1: Write failing ingestion tests**

Add minimum tests named:

```text
testExactDuplicateIsAuditedWithoutProjection
testOutOfOrderOneThreeTwoRequiresSnapshotBeforeFurtherProjection
testSameSequenceWithConflictingPayloadFailsClosed
testClientCrashBeforeAcknowledgementRetriesSameDeliveryAfterRestart
testProjectionRollbackRetriesScenarioDeliveryWithoutDuplicate
testScenarioRawEventStillProjectsNormalizedBatchAtomically
```

The `1,3,2` test must assert that sequence 3 and the later sequence 2 produce no
projection before snapshot completion. The conflict test must assert:

```php
self::assertSame('fake_private_ws_sequence_conflict', $exception->errorCode);
self::assertSame('resync_required', $exception->state);
self::assertSame('1', $exception->actualSequence);
```

- [ ] **Step 2: Run ingestion tests and confirm RED**

```bash
cd trading-app
php vendor/bin/phpunit tests/Exchange/Event/ExchangeWsIngestionServiceTest.php
```

Expected: new scenario tests fail.

- [ ] **Step 3: Implement opt-in scenario consumption**

Keep the existing ordinary-client branch unchanged. When
`$stateStore->hasPrivateWsScenario()` is true:

```php
while (($delivery = $this->stateStore->privateWsCurrentDelivery()) !== null) {
    $known = $this->stateStore->privateWsAcknowledgedFingerprint($delivery->sequence);
    if ($known !== null) {
        if (!hash_equals($known, $delivery->fingerprint)) {
            $this->stateStore->markPrivateWsConflict($delivery);
            throw FakePrivateWsException::sequenceConflict(
                $this->stateStore->privateWsLastAcknowledgedSequence(),
                $delivery->sequence,
            );
        }

        $this->stateStore->skipExactPrivateWsDuplicate($delivery);
        continue;
    }

    $expected = $this->stateStore->privateWsExpectedNumericSequence();
    $actual = ctype_digit($delivery->sequence) ? (int) $delivery->sequence : null;
    if ($actual !== null && $actual > $expected) {
        $this->stateStore->markPrivateWsGap((string) $expected, $delivery->sequence, $delivery);
        throw FakePrivateWsException::sequenceGap(
            $this->stateStore->privateWsLastAcknowledgedSequence(),
            (string) $expected,
            $delivery->sequence,
        );
    }

    yield $delivery->event;
    $this->stateStore->acknowledgePrivateWsDelivery($delivery);
}
```

Filtering by symbol must not acknowledge or advance a delivery for a different
symbol. Add `FakePrivateWsException::sequenceConflict()` and make both gap and
conflict reject plain `reconnect()`. `completeSnapshotResync()` delegates to the
state store in scenario mode and retains #274 behavior otherwise.

- [ ] **Step 4: Run ingestion and projection-store tests**

```bash
php vendor/bin/phpunit \
  tests/Exchange/Event/ExchangeWsIngestionServiceTest.php \
  tests/Exchange/Event/DoctrineExchangeLocalProjectionStoreTest.php
```

Expected: exact duplicates, crash/retry, rollback, atomic batch, gap, conflict,
and pre-existing disconnect tests pass.

- [ ] **Step 5: Commit client semantics**

```bash
git add trading-app/src/Exchange/Fake/FakeExchangeWsClient.php \
  trading-app/src/Exchange/Fake/FakePrivateWsException.php \
  trading-app/tests/Exchange/Event/ExchangeWsIngestionServiceTest.php
git commit -m "feat(fake): enforce private ws sequence identity (#196)"
```

### Task 4: Prove Snapshot Resync and Restart Recovery

**Files:**
- Modify: `trading-app/tests/Exchange/Event/ExchangeWsIngestionServiceTest.php`
- Modify: `trading-app/tests/Exchange/Reconciliation/ExchangeReconciliationServiceTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php`

- [ ] **Step 1: Write failing end-to-end resync tests**

Build canonical Fake state with orders/fills/positions, configure delivery order
`1,3,2`, drain until the gap, and run the existing
`ExchangeReconciliationService` with the Fake REST snapshot provider or the
existing adapter snapshot methods. Assert local open orders/positions match the
snapshot before calling `completeSnapshotResync()`.

Persist at the gap, reconstruct both state store and WS client, and assert:

```php
self::assertTrue($restoredClient->requiresResync());
self::assertSame('resync_required', $restoredClient->connectionState());
self::assertSame('fake_private_ws_sequence_gap', $restoredClient->audit()['resync_reason']);
```

- [ ] **Step 2: Run resync tests and confirm RED**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Event/ExchangeWsIngestionServiceTest.php \
  tests/Exchange/Reconciliation/ExchangeReconciliationServiceTest.php \
  tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php \
  --filter 'Resync|Restart|Snapshot'
```

- [ ] **Step 3: Complete snapshot-watermark handling**

Use the maximum numeric sequence present in canonical Fake events as the
snapshot watermark. Starting at the current delivery cursor, consume every
consecutive fixture entry covered by that watermark:

```php
while (isset($scenario->deliveries[$nextDeliveryIndex])) {
    $delivery = $scenario->deliveries[$nextDeliveryIndex];
    if (!ctype_digit($delivery->sequence) || (int)$delivery->sequence > $watermark) {
        break;
    }
    $fingerprints[$delivery->sequence] = $delivery->fingerprint;
    ++$nextDeliveryIndex;
}
```

Do not sort the delivery order. The loop therefore advances over both `3` and
the following `2` when the watermark is 3, while a non-numeric or future entry
remains pending. Clear resync only after this persisted transition succeeds.
Append one `resync_completed` record and increment `resync_total` exactly once.

- [ ] **Step 4: Run all WS/state/reconciliation tests**

```bash
php vendor/bin/phpunit \
  tests/Exchange/Event/ExchangeWsIngestionServiceTest.php \
  tests/Exchange/Event/DoctrineExchangeLocalProjectionStoreTest.php \
  tests/Exchange/Reconciliation/ExchangeReconciliationServiceTest.php \
  tests/Exchange/Fake/FakePrivateWsScenarioTest.php \
  tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php
```

Expected: all tests pass.

- [ ] **Step 5: Commit recovery proof**

```bash
git add trading-app/tests/Exchange/Event/ExchangeWsIngestionServiceTest.php \
  trading-app/tests/Exchange/Reconciliation/ExchangeReconciliationServiceTest.php \
  trading-app/tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php \
  trading-app/src/Exchange/Fake/FakeExchangeStateStore.php
git commit -m "test(fake): prove private ws snapshot recovery (#196)"
```

### Task 5: Promote Golden Scenario 16

**Files:**
- Create: `trading-app/tests/fixtures/fake-paper/private-ws-out-of-order-v1.json`
- Modify: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`
- Modify: `trading-app/tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php`

- [ ] **Step 1: Write failing catalogue and runner expectations**

Change scenario 16 to:

```json
{
  "evidence": [
    "tests/Exchange/Event/ExchangeWsIngestionServiceTest.php::testOutOfOrderOneThreeTwoRequiresSnapshotBeforeFurtherProjection",
    "tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php"
  ],
  "gap_codes": [],
  "id": 16,
  "name": "duplicate_out_of_order_event",
  "requirement": "Duplicate and out-of-order private events are injected, deduplicated and reconciled.",
  "runner": "duplicate_out_of_order_event",
  "status": "executable"
}
```

Add the key to `FakePaperGoldenScenarioRunner::KEYS` and expected facts:

```php
'duplicate_out_of_order_event' => [
    'conflict_code' => 'fake_private_ws_sequence_conflict',
    'conflict_total' => 1,
    'duplicate_total' => 1,
    'gap_code' => 'fake_private_ws_sequence_gap',
    'gap_total' => 1,
    'no_projection_after_gap' => true,
    'resync_total' => 1,
    'restart_preserved_resync' => true,
    'resumed_contiguously' => true,
],
```

- [ ] **Step 2: Run golden tests and confirm RED**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php \
  tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php
```

- [ ] **Step 3: Add the persistable JSON DSL and golden runner**

Create a versioned fixture with `scenario_id`, `deliveries`, full raw event
envelopes, and an independent conflict case. Load it with `JSON_THROW_ON_ERROR`;
do not sort entries. The runner must execute from a fresh temporary state file,
prove the `1,duplicate-1,3,2` path, restart while resync is required, complete
snapshot resync, append the next contiguous event, and run the conflict case in
a separate fresh state.

Return only booleans, stable error codes, and counters. Do not expose raw fixture
payloads or state paths.

- [ ] **Step 4: Run golden and fixture validation**

```bash
php -r 'foreach (glob("tests/fixtures/fake-paper/*.json") as $file) { json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }'
php vendor/bin/phpunit \
  tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php \
  tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php
```

Expected: scenario 16 is executable and both deterministic runs match.

- [ ] **Step 5: Commit golden scenario 16**

```bash
git add trading-app/tests/fixtures/fake-paper/private-ws-out-of-order-v1.json \
  trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json \
  trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php \
  trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php \
  trading-app/tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php
git commit -m "test(fake): execute out-of-order golden scenario (#196)"
```

### Task 6: Document, Validate, and Prepare the Atomic PR

**Files:**
- Modify: `trading-app/src/Exchange/Fake/README.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`
- Modify: `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`

- [ ] **Step 1: Document operator contract and rollback**

Document explicit fixture order, fingerprint identity, exact duplicate behavior,
conflict failure, gap/resync, acknowledgement-after-projection, restart
persistence, bounded redacted audit, snapshot watermark, ordinary-client
compatibility, local-only safety, and PR revert rollback.

- [ ] **Step 2: Run targeted and broad PHPUnit suites**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Event/ExchangeWsIngestionServiceTest.php \
  tests/Exchange/Event/DoctrineExchangeLocalProjectionStoreTest.php \
  tests/Exchange/Reconciliation/ExchangeReconciliationServiceTest.php \
  tests/Exchange/Fake/FakePrivateWsScenarioTest.php \
  tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php \
  tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php

REDIS_ORDER_WATCH_CHANNEL=test php vendor/bin/phpunit \
  tests/Exchange tests/Provider/Fake tests/TradingCore \
  tests/TradeEntry/Execution/ExchangeExecutionServiceTest.php
```

- [ ] **Step 3: Run static analysis and repository checks**

```bash
php vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
  src/Exchange/Fake/FakePrivateWsDelivery.php \
  src/Exchange/Fake/FakePrivateWsScenario.php \
  src/Exchange/Fake/FakeExchangeStateStore.php \
  src/Exchange/Fake/FakeExchangeWsClient.php \
  src/Exchange/Fake/FakePrivateWsException.php \
  tests/Exchange/Fake/FakePrivateWsScenarioTest.php \
  tests/Exchange/Fake/FakePrivateWsStatePersistenceTest.php \
  tests/Exchange/Event/ExchangeWsIngestionServiceTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php

php bin/console lint:container --no-debug
python3 -m mkdocs build --strict
git diff --check
```

From the repository root, validate every touched JSON file, scan the diff for
credential names paired with values, private keys, tokens, unredacted raw
payload logging, executable exchange HTTP calls, `mainnet_write_enabled=true`,
and any mainnet mutation path. Any finding blocks the PR until resolved.

- [ ] **Step 4: Mark the registry row ready for PR validation and commit docs**

Keep Prompt 3 `in_progress`, record the branch and implementation HEAD, and
leave PR/CI/review fields unset until they factually exist.

```bash
git add trading-app/src/Exchange/Fake/README.md \
  docs/handbook/technical/fake-paper-gateway.md \
  TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md
git commit -m "docs(fake): document private ws sequence recovery (#196)"
```

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin issue/196-fake-out-of-order-v1
gh pr create \
  --base main \
  --head issue/196-fake-out-of-order-v1 \
  --title "feat(fake): inject persistent out-of-order private ws events (#196)" \
  --body-file /tmp/prompt-3-pr-body.md
```

The PR body must include `Part of #196`, reference PR #274, list exact tests and
results, state that scenario 16 is executable, leave #196 open, and confirm no
exchange network request or demo/testnet/mainnet order occurred.
