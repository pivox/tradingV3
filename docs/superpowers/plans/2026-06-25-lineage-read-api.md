# Lineage Read API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only API to navigate persistent lineage by exact identifiers without symbol-only or time-window reconstruction.

**Architecture:** Add a small Symfony read model under `App\Trading\Lineage\ReadModel` backed by explicit Doctrine queries on `trade_lineage`, `order_intent`, and `trade_lifecycle_event`. The controller exposes versioned `/api/lineage/v1` endpoints, enforces bounded pagination and venue requirements for exchange identifiers, and serializes only whitelisted fields.

**Tech Stack:** Symfony 7 controllers, Doctrine ORM repositories, PHPUnit 11, PHPStan, Markdown docs.

---

## File Structure

- Create `trading-app/src/Trading/Lineage/ReadModel/LineageReadCriteria.php`: immutable criteria object for search inputs, pagination, and venue.
- Create `trading-app/src/Trading/Lineage/ReadModel/LineageReadPage.php`: typed page wrapper with `items`, `total`, `limit`, `offset`, `has_more`.
- Create `trading-app/src/Trading/Lineage/ReadModel/LineageReadService.php`: read-only orchestration, completeness/quality evaluation, redacted response mapping.
- Create `trading-app/src/Trading/Lineage/ReadModel/LineageReadException.php`: stable API error codes and HTTP status mapping.
- Create `trading-app/src/Trading/Controller/Api/LineageReadApiController.php`: `/api/lineage/v1` routes only.
- Modify `trading-app/src/Repository/TradeLineageRepository.php`: add bounded search and conflict-aware identifier queries.
- Modify `trading-app/src/Repository/OrderIntentRepository.php`: add read helpers for lineage by exact fields.
- Modify `trading-app/src/Repository/TradeLifecycleEventRepository.php`: add read helpers ordered by `happened_at, id`.
- Create `trading-app/tests/Trading/Lineage/LineageReadServiceTest.php`: unit tests for completeness, conflicts, pagination, redaction.
- Create `trading-app/tests/Trading/Controller/Api/LineageReadApiControllerTest.php`: controller tests for validation and response shape.
- Create `trading-app/tests/Trading/Lineage/LineageReadIntegrationTest.php`: Doctrine-backed tests for venue conflicts on reused `position_id` and `exchange_order_id`.
- Create `docs/handbook/technical/lineage-read-api.md`: endpoint contract and examples.
- Modify `docs/handbook/technical/internal-trade-lineage.md`: link the read API.
- Modify `docs/handbook/technical/persistent-lineage-context.md`: replace the "API missing" note with the delivered surface.

## Endpoints

- `GET /api/lineage/v1/search?orchestration_run_id=...`
- `GET /api/lineage/v1/search?correlation_run_id=...`
- `GET /api/lineage/v1/search?orchestration_set_id=...`
- `GET /api/lineage/v1/search?orchestration_dashboard_id=...`
- `GET /api/lineage/v1/search?internal_trade_id=...`
- `GET /api/lineage/v1/search?internal_position_id=...`
- `GET /api/lineage/v1/search?order_intent_id=...`
- `GET /api/lineage/v1/search?client_order_id=...&exchange=...&market_type=...`
- `GET /api/lineage/v1/search?exchange_order_id=...&exchange=...&market_type=...`
- `GET /api/lineage/v1/search?position_id=...&exchange=...&market_type=...`
- `GET /api/lineage/v1/{internal_trade_id}`
- `GET /api/lineage/v1/{internal_trade_id}/events`

All endpoints are read-only, require at least one exact identifier, use deterministic ordering, and apply `limit <= 100`.

## Task 1: Failing Unit Tests for Read Service

- [ ] **Step 1: Add service tests first**

Write tests that create `TradeLineage`, `OrderIntent`, and `TradeLifecycleEvent` objects in memory and assert:

- complete lineage returns `completeness_status=complete`;
- missing order intent returns `missing_order_intent`;
- legacy origin returns `legacy`;
- missing exchange order returns `missing_exchange_order_id`;
- missing position returns `missing_position_id`;
- unmatched/no close event returns `missing_close_event` or `unmatched`;
- conflict candidates return `identifier_conflict`;
- raw `extra`, `rawInputs`, and `validationErrors` never appear in response arrays.

- [ ] **Step 2: Run tests to verify RED**

Run:

```bash
DEFAULT_URI=http://localhost APP_ENV=test php bin/phpunit tests/Trading/Lineage/LineageReadServiceTest.php
```

Expected: FAIL because read model classes do not exist.

- [ ] **Step 3: Implement minimal read model**

Create criteria/page/exception/service classes and whitelist serializers.

- [ ] **Step 4: Run service tests to verify GREEN**

Run the same PHPUnit command and expect PASS.

## Task 2: Repository Queries and Integration Conflict Tests

- [ ] **Step 1: Add repository integration tests first**

Write Doctrine-backed tests proving:

- search by run/set/internal trade/order intent returns deterministic rows;
- `position_id=POS-1` reused by Bitmart and OKX only returns matches when `exchange + market_type` are supplied;
- `exchange_order_id=EX-1` reused by two venues behaves the same;
- same venue duplicate identifier produces `identifier_conflict`;
- pagination returns `limit`, `offset`, `total`, and `has_more`.

- [ ] **Step 2: Run integration tests to verify RED**

Run:

```bash
DEFAULT_URI=http://localhost APP_ENV=test php bin/phpunit tests/Trading/Lineage/LineageReadIntegrationTest.php
```

Expected: FAIL because repository helpers do not exist.

- [ ] **Step 3: Implement repository helpers**

Add bounded, parameterized query builders. Do not add symbol-only matching or timestamp-window matching.

- [ ] **Step 4: Run integration tests to verify GREEN**

Run the same PHPUnit command and expect PASS, or document PostgreSQL unavailability if the local DB is absent.

## Task 3: Controller API Tests and Endpoints

- [ ] **Step 1: Add controller tests first**

Test:

- missing identifier returns 400 with structured error;
- exchange identifier without `exchange` or `market_type` returns 400;
- unknown identifier returns 404;
- conflict returns 409 with `identifier_conflict`;
- `limit` above max is capped;
- responses always include `completeness_status` and `quality_flags`;
- responses never include raw payload fields.

- [ ] **Step 2: Run controller tests to verify RED**

Run:

```bash
DEFAULT_URI=http://localhost APP_ENV=test php bin/phpunit tests/Trading/Controller/Api/LineageReadApiControllerTest.php
```

Expected: FAIL because controller does not exist.

- [ ] **Step 3: Implement controller**

Add versioned routes, query parsing, venue validation, pagination cap, and structured error responses.

- [ ] **Step 4: Run controller tests to verify GREEN**

Run the same PHPUnit command and expect PASS.

## Task 4: Documentation

- [ ] **Step 1: Add API docs**

Document endpoints, required venue parameters, examples, pagination, status codes, completeness statuses, quality flags, and redaction guarantees.

- [ ] **Step 2: Update lineage docs**

Link the API from existing lineage docs and remove the stale "API missing" statement.

- [ ] **Step 3: Run docs diff check**

Run:

```bash
git diff --check
```

Expected: no whitespace errors.

## Task 5: Final Verification and PR

- [ ] **Step 1: Run targeted PHPUnit**

```bash
DEFAULT_URI=http://localhost APP_ENV=test php bin/phpunit tests/Trading/Lineage tests/Trading/Controller/Api/LineageReadApiControllerTest.php
```

- [ ] **Step 2: Run PHPStan on touched files**

```bash
DEFAULT_URI=http://localhost APP_ENV=test vendor/bin/phpstan analyse --no-progress src/Trading/Lineage/ReadModel src/Trading/Controller/Api/LineageReadApiController.php src/Repository/TradeLineageRepository.php src/Repository/OrderIntentRepository.php src/Repository/TradeLifecycleEventRepository.php tests/Trading/Lineage tests/Trading/Controller/Api/LineageReadApiControllerTest.php --memory-limit=1G
```

- [ ] **Step 3: Run Symfony and YAML lints**

```bash
DEFAULT_URI=http://localhost APP_ENV=test php bin/console lint:container --no-debug
DEFAULT_URI=http://localhost APP_ENV=test php bin/console lint:yaml config
```

- [ ] **Step 4: Run whitespace check**

```bash
git diff --check
```

- [ ] **Step 5: Commit, push, and open PR**

PR body must include `Part of #189`, `Related to #173`, and references to #199, #201, #202, #203, #204. Do not write `Closes #189` unless the final audit proves all remaining #189 criteria are covered.
