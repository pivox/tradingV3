# Effective Config History Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist every successfully resolved modern trading configuration as an immutable, redacted snapshot and expose deterministic history and diff APIs.

**Architecture:** A production resolver decorator converts each canonical resolver result into one safe viewer document and registers it before returning the unchanged runtime snapshot. A dedicated PostgreSQL registry stores content-addressed documents append-only; a read service exposes current preview, exact historical lookup, config-hash history, and lexical diffs without re-resolving old files.

**Tech Stack:** PHP 8.2+, Symfony, Doctrine DBAL/Migrations, PostgreSQL JSONB, PHPUnit, PHPStan, Symfony routing/container, MkDocs.

---

### Task 1: Canonical safe viewer document

**Files:**
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigRedactionResult.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigRedactor.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigCanonicalJson.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigViewerDocument.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigViewerDocumentFactory.php`
- Test: `trading-app/tests/TradingCore/Config/Audit/EffectiveConfigRedactorTest.php`
- Test: `trading-app/tests/TradingCore/Config/Audit/EffectiveConfigViewerDocumentFactoryTest.php`

- [ ] **Step 1: Write redaction tests that define the security boundary**

Create tests proving recursive traversal, case/punctuation/camel-case normalization, DSN user-info detection, stable lexical redacted paths, list preservation, and the literal replacement value:

```php
$result = (new EffectiveConfigRedactor())->redact([
    'Api-Key' => 'top-secret',
    'nested' => ['privateKey' => 'pem', 'safe_token_budget' => 12],
    'dsn' => 'postgresql://alice:password@db.example/app',
    'items' => [['walletSigner' => 'key'], ['public_endpoint' => 'https://example.test']],
]);

self::assertSame('***REDACTED***', $result->document['Api-Key']);
self::assertSame('***REDACTED***', $result->document['nested']['privateKey']);
self::assertSame(12, $result->document['nested']['safe_token_budget']);
self::assertSame('***REDACTED***', $result->document['dsn']);
self::assertSame('https://example.test', $result->document['items'][1]['public_endpoint']);
self::assertSame(['Api-Key', 'dsn', 'items.0.walletSigner', 'nested.privateKey'], $result->redactedPaths);
```

- [ ] **Step 2: Run the redaction test and confirm it fails because the classes do not exist**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigRedactorTest.php`

Expected: non-zero exit with `Class "App\\TradingCore\\Config\\Audit\\EffectiveConfigRedactor" not found`.

- [ ] **Step 3: Implement the recursive redactor and immutable result**

Use the exact public contract:

```php
final readonly class EffectiveConfigRedactionResult
{
    /** @param array<string,mixed> $document @param list<string> $redactedPaths */
    public function __construct(public array $document, public array $redactedPaths) {}
}

final class EffectiveConfigRedactor
{
    public const REDACTED = '***REDACTED***';

    /** @param array<string,mixed> $document */
    public function redact(array $document): EffectiveConfigRedactionResult;
}
```

Normalize keys by splitting camel case, replacing non-alphanumerics with `_`, and lowercasing. Treat these normalized concepts as sensitive: `api_key`, `secret`, `password`, `passphrase`, `token`, `credential`, `private_key`, `signature`, `wallet`, and `signer`. Match complete normalized segments, singular/plural and prefixed/suffixed forms, but explicitly keep benign keys such as `token_budget`. Redact `dsn`, `url`, or `uri` only when the string parses with non-empty user-info. Traverse lists by numeric path and maps by original key; sort and deduplicate paths before returning.

- [ ] **Step 4: Write deterministic canonical JSON and viewer-document tests**

The tests must prove map keys are sorted recursively, list order is preserved, non-finite floats are rejected, and a real `EffectiveTradingConfigSnapshot` yields this envelope:

```php
[
    'document_kind' => 'current_preview',
    'resolver_version' => EffectiveConfigViewerDocumentFactory::RESOLVER_VERSION,
    'validation_status' => 'valid',
    'redacted_paths' => [],
    // followed by every key from EffectiveTradingConfigSnapshot::toArray()
]
```

Also assert `snapshot_hash` remains the resolver-computed hash, `canonicalJson()` is byte-stable across input map order, and `redactedContentChecksum()` equals `hash('sha256', $document->canonicalJson())`.

- [ ] **Step 5: Run the viewer-document tests and confirm the missing implementation failure**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigViewerDocumentFactoryTest.php`

Expected: non-zero exit naming `EffectiveConfigViewerDocumentFactory`.

- [ ] **Step 6: Implement canonical JSON, document, and factory**

Use these contracts:

```php
final class EffectiveConfigCanonicalJson
{
    /** @param array<string,mixed> $value */
    public static function encode(array $value): string;
}

final readonly class EffectiveConfigViewerDocument
{
    /** @param array<string,mixed> $payload */
    public function __construct(public array $payload) {}
    public function snapshotHash(): string;
    public function configHash(): string;
    public function canonicalJson(): string;
    public function redactedContentChecksum(): string;
    /** @return array<string,mixed> */
    public function withDocumentKind(string $kind): array;
}

final readonly class EffectiveConfigViewerDocumentFactory
{
    public const RESOLVER_VERSION = '1.0.0';
    public function __construct(private EffectiveConfigRedactor $redactor) {}
    public function fromSnapshot(EffectiveTradingConfigSnapshot $snapshot): EffectiveConfigViewerDocument;
}
```

The factory redacts `snapshot->toArray()`, then prepends `document_kind=current_preview`, `resolver_version=1.0.0`, `validation_status=valid`, and `redacted_paths`. Canonical JSON must reject resources, objects, unsupported values, and `INF`, `-INF`, or `NAN` with `LogicException('effective_config_document_not_canonical')`.

- [ ] **Step 7: Run both unit suites and commit**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigRedactorTest.php tests/TradingCore/Config/Audit/EffectiveConfigViewerDocumentFactoryTest.php`

Expected: PASS.

```bash
git add trading-app/src/TradingCore/Config/Audit trading-app/tests/TradingCore/Config/Audit
git commit -m "feat(#192): define safe effective config documents"
```

### Task 2: Append-only PostgreSQL registry

**Files:**
- Create: `trading-app/migrations/Version20260820150000.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigSnapshotRegistryInterface.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigSnapshotRecord.php`
- Create: `trading-app/src/TradingCore/Config/Audit/DoctrineEffectiveConfigSnapshotRegistry.php`
- Test: `trading-app/tests/TradingCore/Config/Audit/EffectiveConfigSnapshotMigrationTest.php`
- Test: `trading-app/tests/TradingCore/Config/Audit/DoctrineEffectiveConfigSnapshotRegistryTest.php`

- [ ] **Step 1: Write the PostgreSQL migration test**

Follow `PaperExecutionMigrationTest`: require a `DATABASE_URL` whose database ends in `_paper_test`, create an isolated schema, instantiate `DoctrineMigrations\\Version20260820150000`, run `up()`, then assert the table, both indexes, and constraints exist. Insert one valid row, then prove UPDATE and DELETE each fail with SQLSTATE `P0001` and message `effective_trading_config_snapshot_append_only`. Prove malformed hashes and an invalid validation status fail with SQLSTATE `23514`, run `down()`, and drop the schema in `finally`.

- [ ] **Step 2: Run the migration test and verify the missing migration failure**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigSnapshotMigrationTest.php`

Expected: FAIL because `Version20260820150000.php` is absent, or SKIP only when the isolated PostgreSQL test database is unavailable.

- [ ] **Step 3: Implement the migration**

Create `effective_trading_config_snapshot` with:

```sql
snapshot_hash VARCHAR(71) PRIMARY KEY,
config_hash VARCHAR(71) NOT NULL,
condition_catalog_hash VARCHAR(71),
schema_version VARCHAR(32) NOT NULL,
resolver_version VARCHAR(32) NOT NULL,
mode_id VARCHAR(64) NOT NULL,
mode_version VARCHAR(32) NOT NULL,
setup_id VARCHAR(128) NOT NULL,
setup_version VARCHAR(32) NOT NULL,
exchange VARCHAR(32) NOT NULL,
environment VARCHAR(32) NOT NULL,
side VARCHAR(8) NOT NULL,
execution_capability VARCHAR(32),
validation_status VARCHAR(16) NOT NULL,
redacted_snapshot JSONB NOT NULL,
redacted_content_checksum CHAR(64) NOT NULL,
created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL
```

Add exact SHA-256 check constraints, `validation_status IN ('valid')`, `side IN ('long','short')`, `jsonb_typeof(redacted_snapshot)='object'`, a `config_hash, created_at, snapshot_hash` index, a complete identity index, and a `BEFORE UPDATE OR DELETE` trigger that raises the append-only exception. `down()` removes trigger/function/table.

- [ ] **Step 4: Write registry tests before its implementation**

Create a real-PostgreSQL fixture using the same isolated-schema guard. Assert:

```php
$registry->register($document);
$registry->register($document); // exact idempotent replay
self::assertSame($document->payload, $registry->find($document->snapshotHash())?->document);
self::assertCount(2, $registry->findByConfigHash($document->configHash()));
```

The second history record must have a distinct `snapshot_hash` but the same `config_hash`. Directly corrupting a stored checksum or registering a same-hash/different-document object must throw `LogicException('effective_config_snapshot_conflict')`. Assert history ordering by `created_at, snapshot_hash`, canonical re-decoding of JSONB, and no raw secret in `redacted_snapshot::text`.

- [ ] **Step 5: Run registry tests and confirm the missing-class failure**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/DoctrineEffectiveConfigSnapshotRegistryTest.php`

Expected: FAIL naming the absent registry class, or SKIP only without the isolated PostgreSQL database.

- [ ] **Step 6: Implement record, interface, and DBAL registry**

Use these exact signatures:

```php
final readonly class EffectiveConfigSnapshotRecord
{
    /** @param array<string,mixed> $document */
    public function __construct(public array $document, public \DateTimeImmutable $createdAt) {}
}

interface EffectiveConfigSnapshotRegistryInterface
{
    public function register(EffectiveConfigViewerDocument $document): void;
    public function find(string $snapshotHash): ?EffectiveConfigSnapshotRecord;
    /** @return list<EffectiveConfigSnapshotRecord> */
    public function findByConfigHash(string $configHash): array;
}
```

`DoctrineEffectiveConfigSnapshotRegistry` receives `Doctrine\\DBAL\\Connection`. On register, start a transaction, select the complete row by primary key, compare every identity column plus canonicalized stored JSON and checksum, and return only for an exact replay. Otherwise insert the factory document. Convert uniqueness races and all mismatches to `LogicException('effective_config_snapshot_conflict')`. On reads, decode JSON with `JSON_THROW_ON_ERROR`, require an object/map, recompute its canonical checksum, and fail with `LogicException('effective_config_snapshot_checksum_mismatch')` on disagreement.

- [ ] **Step 7: Run migration and registry tests and commit**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigSnapshotMigrationTest.php tests/TradingCore/Config/Audit/DoctrineEffectiveConfigSnapshotRegistryTest.php`

Expected: PASS with PostgreSQL configured; otherwise only the explicit database-guard skips.

```bash
git add trading-app/migrations/Version20260820150000.php trading-app/src/TradingCore/Config/Audit trading-app/tests/TradingCore/Config/Audit
git commit -m "feat(#192): persist immutable effective configs"
```

### Task 3: Register every production resolution and secure preview

**Files:**
- Create: `trading-app/src/TradingCore/Config/Audit/PersistentEffectiveTradingConfigResolver.php`
- Modify: `trading-app/src/TradingCore/Config/EffectiveTradingConfigReadService.php`
- Modify: `trading-app/config/services.yaml`
- Create: `trading-app/tests/TradingCore/Config/Audit/PersistentEffectiveTradingConfigResolverTest.php`
- Modify: `trading-app/tests/Trading/Controller/Api/EffectiveTradingConfigApiControllerTest.php`
- Test: `trading-app/tests/TradingCore/Config/EffectiveTradingConfigContainerTest.php`

- [ ] **Step 1: Write decorator tests**

Use a recording in-memory registry implementing `EffectiveConfigSnapshotRegistryInterface`. Prove `resolve()` calls the concrete resolver once, registers once, and returns the exact same `EffectiveTradingConfigSnapshot` instance. Make registry `register()` throw and assert the same exception escapes before a snapshot is returned. Resolve twice and prove exact idempotent registrations are accepted.

- [ ] **Step 2: Run the decorator test and verify it fails for the absent decorator**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/PersistentEffectiveTradingConfigResolverTest.php`

Expected: FAIL naming `PersistentEffectiveTradingConfigResolver`.

- [ ] **Step 3: Implement the fail-closed resolver decorator**

```php
final readonly class PersistentEffectiveTradingConfigResolver implements EffectiveTradingConfigResolverInterface
{
    public function __construct(
        private EffectiveTradingConfigResolver $inner,
        private EffectiveConfigViewerDocumentFactory $documents,
        private EffectiveConfigSnapshotRegistryInterface $registry,
        private \Psr\Log\LoggerInterface $logger,
    ) {}

    public function resolve(EffectiveTradingConfigRequest $request): EffectiveTradingConfigSnapshot
    {
        $snapshot = $this->inner->resolve($request);
        $this->registry->register($this->documents->fromSnapshot($snapshot));
        return $snapshot;
    }
}
```

Measure the full resolve/register duration with `hrtime(true)`. After a successful
registration, emit one `info` record containing only `snapshot_hash`,
`config_hash`, the seven canonical identity strings, nullable
`execution_capability`, `layer_count`, `redaction_count`, `resolver_version`,
`validation_status`, and `duration_ms`. Do not catch persistence exceptions and
never pass the raw or redacted document to the logger.

- [ ] **Step 4: Write preview and container tests**

Update controller construction to give the read service an interface resolver plus `EffectiveConfigViewerDocumentFactory`. Assert successful preview contains `document_kind=current_preview`, `resolver_version=1.0.0`, `validation_status=valid`, and `redacted_paths`, while preserving existing resolver hashes and fail-closed 400/422 behavior. In a kernel container test, assert `EffectiveTradingConfigResolverInterface` resolves to `PersistentEffectiveTradingConfigResolver` and `EffectiveConfigSnapshotRegistryInterface` resolves to `DoctrineEffectiveConfigSnapshotRegistry`.

- [ ] **Step 5: Run preview/container tests and confirm the expected signature/alias failures**

Run: `cd trading-app && php bin/phpunit tests/Trading/Controller/Api/EffectiveTradingConfigApiControllerTest.php tests/TradingCore/Config/EffectiveTradingConfigContainerTest.php`

Expected: FAIL until the read service and aliases are updated.

- [ ] **Step 6: Present previews through the safe document factory and wire production aliases**

Change the read service constructor and method to:

```php
public function __construct(
    private EffectiveTradingConfigResolverInterface $resolver,
    private EffectiveConfigViewerDocumentFactory $documents,
) {}

public function describe(EffectiveTradingConfigRequest $request): array
{
    return $this->documents->fromSnapshot($this->resolver->resolve($request))->payload;
}
```

In `services.yaml`, alias `EffectiveTradingConfigResolverInterface` to the persistent decorator and `EffectiveConfigSnapshotRegistryInterface` to the Doctrine implementation. Leave concrete `EffectiveTradingConfigResolver` autowirable so the decorator receives it as its inner resolver.

- [ ] **Step 7: Run focused tests, container validation, and commit**

Run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/Audit/PersistentEffectiveTradingConfigResolverTest.php tests/Trading/Controller/Api/EffectiveTradingConfigApiControllerTest.php tests/TradingCore/Config/EffectiveTradingConfigContainerTest.php
php bin/console lint:container
php bin/console lint:yaml config
```

Expected: all commands PASS.

```bash
git add trading-app/src/TradingCore/Config trading-app/config/services.yaml trading-app/tests/TradingCore/Config trading-app/tests/Trading/Controller/Api/EffectiveTradingConfigApiControllerTest.php
git commit -m "feat(#192): register effective configs before use"
```

### Task 4: Historical lookup and deterministic diff API

**Files:**
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigHash.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigDiffService.php`
- Create: `trading-app/src/TradingCore/Config/Audit/EffectiveConfigSnapshotNotFound.php`
- Modify: `trading-app/src/TradingCore/Config/EffectiveTradingConfigReadService.php`
- Create: `trading-app/src/Trading/Controller/Api/EffectiveTradingConfigHistoryApiController.php`
- Create: `trading-app/tests/TradingCore/Config/Audit/EffectiveConfigDiffServiceTest.php`
- Create: `trading-app/tests/Trading/Controller/Api/EffectiveTradingConfigHistoryApiControllerTest.php`

- [ ] **Step 1: Write hash-validator and diff tests**

Assert `EffectiveConfigHash::require('sha256:'.str_repeat('a', 64))` returns its input, while uppercase, missing prefix, short, whitespace-padded, and non-hex values throw `InvalidArgumentException('effective_config_hash_invalid')`. Build two records whose `config` and `provenance` produce all four classifications and assert exact lexical detail order:

```php
[
    ['path' => 'added', 'classification' => 'added', 'left' => null, 'right' => 2],
    ['path' => 'changed', 'classification' => 'changed', 'left' => 1, 'right' => 2],
    ['path' => 'removed', 'classification' => 'removed', 'left' => 1, 'right' => null],
    ['path' => 'same_source_changed', 'classification' => 'same_but_different_source', 'left' => 'x', 'right' => 'x'],
]
```

Also assert unchanged paths are absent from `changes` but included in `summary.unchanged`, redacted values stay redacted, nested maps flatten with dot-separated paths, and list values compare canonically as leaf values.

- [ ] **Step 2: Run diff tests and confirm missing classes**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigDiffServiceTest.php`

Expected: FAIL naming `EffectiveConfigDiffService`.

- [ ] **Step 3: Implement exact hash validation and diffing**

Use:

```php
final class EffectiveConfigHash
{
    public static function require(string $hash): string;
}

final readonly class EffectiveConfigDiffService
{
    /** @return array{left_snapshot_hash:string,right_snapshot_hash:string,summary:array<string,int>,changes:list<array<string,mixed>>} */
    public function diff(EffectiveConfigSnapshotRecord $left, EffectiveConfigSnapshotRecord $right): array;
}
```

Flatten only `config` and its matching `provenance`. For each union path in `SORT_STRING` order, classify absent/present, canonical value inequality, then equal value with canonical provenance inequality. Use explicit presence booleans so JSON `null` is not confused with absence. Summaries contain integer counts for `added`, `removed`, `changed`, `same_but_different_source`, and `unchanged`.

- [ ] **Step 4: Write controller tests for every route and failure mode**

Use an in-memory registry and construct the history controller directly. Cover:

- exact snapshot GET returns 200 and `document_kind=historical_snapshot`;
- malformed snapshot/config hashes return 400 `invalid_config_hash`;
- unknown exact hash returns 404 `effective_config_snapshot_not_found`;
- config-hash history returns provenance-distinct items in registry order;
- missing `config_hash`, `left`, or `right` returns 400 `missing_query_parameter`;
- diff returns the exact service payload and uses only snapshot hashes;
- serialized bodies never contain seeded secret literals.

- [ ] **Step 5: Run controller tests and confirm missing methods/controller**

Run: `cd trading-app && php bin/phpunit tests/Trading/Controller/Api/EffectiveTradingConfigHistoryApiControllerTest.php`

Expected: FAIL until history methods and controller exist.

- [ ] **Step 6: Extend the read service and add read-only routes**

Add read-service methods:

```php
/** @return array<string,mixed> */
public function historical(string $snapshotHash): array;
/** @return list<array<string,mixed>> */
public function history(string $configHash): array;
/** @return array<string,mixed> */
public function diff(string $leftSnapshotHash, string $rightSnapshotHash): array;
```

Each method validates hashes first. `historical()`/`diff()` throw `EffectiveConfigSnapshotNotFound` for unknown exact hashes. Historical presentation replaces only `document_kind` with `historical_snapshot`; it never invokes the resolver.

Add these routes in `EffectiveTradingConfigHistoryApiController`:

```php
#[Route('/api/trading/config/effective/snapshots/{snapshot_hash}', methods: ['GET'])]
#[Route('/api/trading/config/effective/snapshots', methods: ['GET'])]
#[Route('/api/trading/config/effective/diff', methods: ['GET'])]
```

Do application-level validation too so malformed identifiers consistently produce JSON 400 instead of router 404. Map only `InvalidArgumentException` to 400 and `EffectiveConfigSnapshotNotFound` to 404; let registry corruption surface as a server failure.

- [ ] **Step 7: Run API/diff tests and commit**

Run: `cd trading-app && php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigDiffServiceTest.php tests/Trading/Controller/Api/EffectiveTradingConfigHistoryApiControllerTest.php`

Expected: PASS.

```bash
git add trading-app/src/TradingCore/Config trading-app/src/Trading/Controller/Api/EffectiveTradingConfigHistoryApiController.php trading-app/tests/TradingCore/Config/Audit trading-app/tests/Trading/Controller/Api/EffectiveTradingConfigHistoryApiControllerTest.php
git commit -m "feat(#192): expose effective config history and diff"
```

### Task 5: Documentation and full verification

**Files:**
- Modify: `docs/handbook/technical/effective-trading-config-resolver.md`
- Modify: `docs/superpowers/specs/2026-08-20-issue-192-effective-config-history-design.md`

- [ ] **Step 1: Document the operational contract**

Add an “Immutable history” section documenting all three routes, canonical hash syntax, `snapshot_hash` versus `config_hash`, append-only/fail-closed semantics, centralized redaction, the four current diff classes, and that `invalidated`, usage navigation, and UI remain deferred. Add curl examples using fake/test only. Add a final “Implemented” note to the design spec naming migration `Version20260820150000` and resolver version `1.0.0`; do not weaken the mainnet-private prohibition.

- [ ] **Step 2: Run the complete targeted test set**

Run:

```bash
cd trading-app
php bin/phpunit \
  tests/TradingCore/Config/Audit \
  tests/TradingCore/Config/EffectiveTradingConfigContainerTest.php \
  tests/Trading/Controller/Api/EffectiveTradingConfigApiControllerTest.php \
  tests/Trading/Controller/Api/EffectiveTradingConfigHistoryApiControllerTest.php
```

Expected: PASS, with PostgreSQL suites passing when `DATABASE_URL` targets `_paper_test` and otherwise only their explicit skips.

- [ ] **Step 3: Run static, container, YAML, and documentation checks**

Run:

```bash
cd trading-app
vendor/bin/phpstan analyse src/TradingCore/Config src/Trading/Controller/Api/EffectiveTradingConfigApiController.php src/Trading/Controller/Api/EffectiveTradingConfigHistoryApiController.php --no-progress
php bin/console lint:container
php bin/console lint:yaml config
cd ..
python -m mkdocs build --strict
```

Expected: every command exits 0.

- [ ] **Step 4: Run the real PostgreSQL integration suites**

Use the project’s existing isolated `_paper_test` database command/environment and run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/Audit/EffectiveConfigSnapshotMigrationTest.php tests/TradingCore/Config/Audit/DoctrineEffectiveConfigSnapshotRegistryTest.php
```

Expected: PASS with no skips. Never point these destructive isolated-schema tests at a non-`_paper_test` database.

- [ ] **Step 5: Review the final diff for security and scope**

Run:

```bash
git diff --check origin/main...HEAD
git diff --stat origin/main...HEAD
rg -n "top-secret|postgresql://alice:password|private.*pem" trading-app/src trading-app/migrations docs/handbook || true
git status --short
```

Expected: no whitespace errors, no production secret fixture, only #192 backend/history files plus its spec/plan, and no mainnet execution change.

- [ ] **Step 6: Commit documentation, open the PR, and complete review**

```bash
git add docs/handbook/technical/effective-trading-config-resolver.md docs/superpowers/specs/2026-08-20-issue-192-effective-config-history-design.md docs/superpowers/plans/2026-08-20-issue-192-effective-config-history.md
git commit -m "docs(#192): document effective config history"
git push -u origin codex/issue-192-effective-config-viewer
gh pr create --draft --title "feat(#192): add effective config history and diff" --body-file /tmp/issue-192-pr.md
```

The PR body must state scope/deferred lots, migration and redaction behavior, exact verification evidence, `Closes #192` only if the issue’s remaining acceptance criteria are fully met (otherwise `Refs #192`), and the mainnet-private prohibition. Mark ready, request Codex review once, address only actionable feedback, rerun impacted/full checks, and merge when CI is green and there are no unresolved blocking threads. A Codex approval or thumbs-up counts as clean review; do not manufacture empty review cycles.
