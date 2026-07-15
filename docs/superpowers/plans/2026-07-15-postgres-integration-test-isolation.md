# PostgreSQL Integration Test Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent destructive PostgreSQL integration tests from ever targeting the development database and restore the locally removed schema objects.

**Architecture:** A test-only guard parses the PostgreSQL DSN and requires a database name ending in `_test` before destructive suites connect. Local ignored configuration points to a dedicated migrated test database; missing development schema objects are restored additively from a temporary freshly migrated database.

**Tech Stack:** PHP 8.2, PHPUnit, Doctrine DBAL/Migrations, PostgreSQL 15, Docker Compose.

---

### Task 1: Test-only database guard

**Files:**
- Create: `trading-app/tests/Support/PostgresIntegrationDatabaseGuard.php`
- Create: `trading-app/tests/Support/PostgresIntegrationDatabaseGuardTest.php`

- [ ] **Step 1: Write the failing unit tests**

Cover acceptance of `trading_app_test`, rejection of `trading_app`, rejection of a
missing database path, and absence of password/DSN text in the exception.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php vendor/bin/phpunit tests/Support/PostgresIntegrationDatabaseGuardTest.php
```

Expected: failure because `PostgresIntegrationDatabaseGuard` does not exist.

- [ ] **Step 3: Implement the minimal guard**

Expose one static method:

```php
PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase(string $dsn): void
```

Parse only the database path, accept `test` or a name ending `_test`, and throw a
redacted `LogicException` otherwise.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run the same PHPUnit command. Expected: all guard tests pass.

### Task 2: Protect destructive integration suites

**Files:**
- Modify: `trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php`
- Modify: `trading-app/tests/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReaderTest.php`

- [ ] **Step 1: Add the guard calls before DBAL connection creation**

After the existing PostgreSQL scheme check and before `DriverManager::getConnection()`,
call `PostgresIntegrationDatabaseGuard::assertIsolatedTestDatabase($dsn)`.

- [ ] **Step 2: Run both suites against `trading_app_test`**

```bash
php vendor/bin/phpunit \
  tests/Trading/View/PositionTradeAnalysisViewTest.php \
  tests/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReaderTest.php
```

Expected: PASS and no schema changes in `trading_app`.

### Task 3: Isolate local configuration and restore schema

**Files:**
- Local ignored file only: `trading-app/.env.test`

- [ ] **Step 1: Create and migrate `trading_app_test`**

Create the database if absent, change only the database path in `.env.test`, and run
all Doctrine migrations against it without printing credentials.

- [ ] **Step 2: Build a temporary migrated repair database**

Create `trading_app_schema_repair` and apply all Doctrine migrations.

- [ ] **Step 3: Restore only absent development objects**

Pipe a schema-only `pg_dump` for `indicator_snapshots`, `trade_lifecycle_event`,
`position_trade_analysis`, and `position_trade_analysis_v2` from the repair database
into `trading_app`. Do not drop or overwrite any existing object.

- [ ] **Step 4: Verify schema and data preservation**

Confirm the four objects exist, Doctrine remains at the latest migration, and all
previously audited trading table counts remain zero.

### Task 4: Verification and delivery

**Files:**
- Modify if needed: documentation files above

- [ ] **Step 1: Run focused PHPUnit and PHPStan**

```bash
php vendor/bin/phpunit tests/Support/PostgresIntegrationDatabaseGuardTest.php \
  tests/Trading/View/PositionTradeAnalysisViewTest.php \
  tests/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReaderTest.php
php vendor/bin/phpstan analyse \
  tests/Support/PostgresIntegrationDatabaseGuard.php \
  tests/Support/PostgresIntegrationDatabaseGuardTest.php \
  tests/Trading/View/PositionTradeAnalysisViewTest.php \
  tests/Trading/Backfill/PositionTradeAnalysisBackfillDivergenceReaderTest.php
```

- [ ] **Step 2: Run project checks**

```bash
php bin/console lint:container --no-debug
git diff --check
```

- [ ] **Step 3: Review, commit, push and open a focused PR**

Stage only the guard, its tests, the two protected suites, design, and plan. Never
stage `.env.test`. Monitor CI and automatic Codex review; merge only when green and
approved, then fast-forward local `main`.
