# Effective Config Usage Links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Expose the immutable redacted effective-config snapshot used by an exact run, set, decision, or trade identity.

**Architecture:** A DBAL store reads complete canonical lineage facts from the three persistence tables. A fail-closed read service validates references and hashes, resolves the immutable registry records, aggregates usage, and a thin controller maps stable domain failures to HTTP.

**Tech Stack:** PHP 8.2, Symfony HttpFoundation/Routing, Doctrine DBAL/PostgreSQL, PHPUnit 10, PHPStan.

---

### Task 1: Domain contract and fail-closed service

**Files:**
- Create: `trading-app/src/TradingCore/Config/Usage/EffectiveConfigUsageScope.php`
- Create: `trading-app/src/TradingCore/Config/Usage/EffectiveConfigUsageFact.php`
- Create: `trading-app/src/TradingCore/Config/Usage/EffectiveConfigUsageStoreInterface.php`
- Create: `trading-app/src/TradingCore/Config/Usage/EffectiveConfigUsageReadException.php`
- Create: `trading-app/src/TradingCore/Config/Usage/EffectiveConfigUsageReadService.php`
- Test: `trading-app/tests/TradingCore/Config/Usage/EffectiveConfigUsageReadServiceTest.php`

- [ ] **Step 1: Write failing service tests**

Cover exact successful lookup, multiple snapshots for run/set, source and distinct identity
counts, invalid identifier, no facts, a blank/malformed reference, an unregistered snapshot,
row/document config-hash disagreement, and multiple references for decision/trade. Use an
in-memory anonymous `EffectiveConfigUsageStoreInterface` and registry so the tests exercise
real grouping and validation without persistence mocks.

- [ ] **Step 2: Verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/Usage/EffectiveConfigUsageReadServiceTest.php
```

Expected: FAIL because the usage classes do not exist.

- [ ] **Step 3: Implement the minimal domain**

Define scopes `run`, `set`, `decision`, and `trade`. Define an immutable fact with:

```php
public function __construct(
    public string $source,
    public string $rowIdentity,
    public ?string $configHash,
    public ?string $effectiveConfigReference,
    public ?string $decisionId,
    public ?string $tradeId,
    public ?string $internalTradeId,
) {}
```

The store contract is:

```php
/** @return list<EffectiveConfigUsageFact> */
public function find(EffectiveConfigUsageScope $scope, string $identifier): array;
```

The read service method is:

```php
/** @return array<string,mixed> */
public function read(EffectiveConfigUsageScope $scope, string $identifier): array;
```

It must implement the ten validation steps from the design, recognize only
`effective-config-snapshot:(sha256:[0-9a-f]{64})`, sort snapshot groups by hash, return
historical documents from the registry, and throw `EffectiveConfigUsageReadException` with
an `errorCode` plus one of HTTP-neutral statuses `400`, `404`, `409`, or `422`.

- [ ] **Step 4: Verify GREEN and refactor**

Run the Task 1 test file again. Expected: all tests pass with no warnings.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/TradingCore/Config/Usage trading-app/tests/TradingCore/Config/Usage
git commit -m "feat(#192): resolve effective config usage"
```

### Task 2: Exact DBAL store

**Files:**
- Create: `trading-app/src/TradingCore/Config/Usage/DoctrineEffectiveConfigUsageStore.php`
- Test: `trading-app/tests/TradingCore/Config/Usage/DoctrineEffectiveConfigUsageStoreTest.php`

- [ ] **Step 1: Write the failing PostgreSQL integration test**

Create isolated temporary tables with the seven columns required by the store. Insert exact
and near-match rows in all three tables. Assert:

- run/set/decision only match their canonical equality column;
- trade matches `trade_lineage.internal_trade_id` and the canonical `trade_id` or exact
  `internal_trade_id` in intent/event rows;
- all facts are returned in deterministic source/row order;
- nullable fields remain null and are never reconstructed from JSON.

Use the repository `PostgresIntegrationDatabaseGuard`; skip locally when no safe PostgreSQL
test DSN exists, while the existing CI PostgreSQL job makes the gate non-skippable.

- [ ] **Step 2: Verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/Usage/DoctrineEffectiveConfigUsageStoreTest.php
```

Expected: FAIL because the store does not exist.

- [ ] **Step 3: Implement fixed parameterized queries**

Use one `UNION ALL` query per scope. Select the same normalized fields from
`trade_lineage`, `order_intent`, and `trade_lifecycle_event`; bind every identifier through
DBAL and never interpolate route input. For trade scope use:

```sql
trade_lineage.internal_trade_id = ?
order_intent.trade_id = ? OR order_intent.internal_trade_id = ?
trade_lifecycle_event.trade_id = ? OR trade_lifecycle_event.internal_trade_id = ?
```

Order by normalized `source`, then numeric `row_identity`.

- [ ] **Step 4: Verify GREEN**

Run the Task 2 test file with a safe PostgreSQL test DSN. Expected: all assertions pass and
no test is skipped in the PostgreSQL gate.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/TradingCore/Config/Usage/DoctrineEffectiveConfigUsageStore.php trading-app/tests/TradingCore/Config/Usage/DoctrineEffectiveConfigUsageStoreTest.php
git commit -m "feat(#192): read canonical config usage facts"
```

### Task 3: HTTP routes and stable errors

**Files:**
- Create: `trading-app/src/Trading/Controller/Api/EffectiveConfigUsageApiController.php`
- Test: `trading-app/tests/Trading/Controller/Api/EffectiveConfigUsageApiControllerTest.php`

- [ ] **Step 1: Write failing controller tests**

Instantiate the real service with fake store/registry data. Exercise all four public methods
and assert route payloads. For representative failures assert status/code pairs:

```text
400 invalid_effective_config_usage_identifier
404 effective_config_usage_not_found
409 effective_config_usage_conflict
409 effective_config_snapshot_unregistered
409 effective_config_hash_conflict
422 effective_config_reference_missing
```

- [ ] **Step 2: Verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/Trading/Controller/Api/EffectiveConfigUsageApiControllerTest.php
```

Expected: FAIL because the controller does not exist.

- [ ] **Step 3: Implement the thin controller**

Add the four routes from the design. Each action delegates to a private method with the
appropriate enum scope. On `EffectiveConfigUsageReadException`, return:

```json
{"error":{"code":"<stable-code>","message":"<safe-message>"}}
```

using the exception status. Do not catch registry corruption `LogicException`; it must remain
an observable server failure rather than being mislabeled as user input.

- [ ] **Step 4: Verify GREEN and routing**

Run the Task 3 test and:

```bash
cd trading-app
php bin/console debug:router | rg 'effective.config|effective_config'
```

Expected: all tests pass and all four usage routes appear.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Trading/Controller/Api/EffectiveConfigUsageApiController.php trading-app/tests/Trading/Controller/Api/EffectiveConfigUsageApiControllerTest.php
git commit -m "feat(#192): expose effective config usage API"
```

### Task 4: Query indexes and operator contract

**Files:**
- Create: `trading-app/migrations/Version20260820160000.php`
- Create: `trading-app/tests/TradingCore/Config/Usage/EffectiveConfigUsageMigrationTest.php`
- Modify: `docs/handbook/technical/effective-trading-config-resolver.md`

- [ ] **Step 1: Write failing migration contract tests**

Assert PostgreSQL-only, non-transactional concurrent DDL; exact partial indexes for each
uncovered leading lookup column; deterministic recovery of an invalid same-name index; abort
on a valid same-name index with a different definition; and matching concurrent rollback.

- [ ] **Step 2: Verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/Usage/EffectiveConfigUsageMigrationTest.php
```

Expected: FAIL because the migration does not exist.

- [ ] **Step 3: Add the index migration**

Reuse the catalog validation pattern from `Version20260801093000`. Create only these missing
leading indexes, all partial on non-null identifiers:

```text
trade_lineage(orchestration_run_id)
trade_lineage(orchestration_set_id)
order_intent(orchestration_set_id)
trade_lifecycle_event(orchestration_set_id)
order_intent(decision_id)
trade_lifecycle_event(decision_id)
order_intent(trade_id)
trade_lifecycle_event(trade_id)
```

Existing order-intent/lifecycle run-leading indexes and unique lineage decision/internal
trade indexes are intentionally reused.

- [ ] **Step 4: Document the route and failure contract**

Add a concise section to `docs/handbook/technical/effective-trading-config-resolver.md` listing the four
routes, multi-snapshot run/set behavior, exact lineage sources, and fail-closed status codes.

- [ ] **Step 5: Verify targeted and full gates**

Run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/Usage tests/Trading/Controller/Api/EffectiveConfigUsageApiControllerTest.php tests/Trading/Controller/Api/EffectiveTradingConfigHistoryApiControllerTest.php tests/Trading/Lineage
vendor/bin/phpstan analyse --memory-limit=1G
php bin/console lint:container
php bin/console lint:yaml config
cd ..
git diff --check
```

Expected: all tests and static/config checks pass.

- [ ] **Step 6: Commit**

```bash
git add trading-app/migrations/Version20260820160000.php trading-app/tests/TradingCore/Config/Usage/EffectiveConfigUsageMigrationTest.php docs/handbook/technical/effective-trading-config-resolver.md
git commit -m "docs(#192): document effective config usage API"
```

### Task 5: Delivery

- [ ] **Step 1: Run the repository's relevant CI-equivalent suites and inspect the complete diff**
- [ ] **Step 2: Push and open a PR referencing #192 as the usage-link backend lot**
- [ ] **Step 3: Request Codex review, address every actionable thread, and rerun affected gates**
- [ ] **Step 4: Merge once required CI is green and no blocking review remains**
- [ ] **Step 5: Pull main, update #192 with the remaining #318/UI boundary, then select the next global #132 lot**
