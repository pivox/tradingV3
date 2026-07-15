# Fake/Paper Golden Suite v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate the 20 mandatory #196 Fake/Paper scenarios in one versioned, deterministic and fail-closed golden contract without claiming unsupported capabilities as passing.

**Architecture:** A JSON catalog is the source of truth for scenario order, support status, stable gap codes and runner keys. PHPUnit validates the catalog and COMMON-005 fixture parity, while a test-only runner executes only `executable` scenarios twice from fresh state and compares normalized results. `partial` and `unsupported` entries are asserted as explicit gaps and cannot carry a runner key or expected PASS payload.

**Tech Stack:** PHP 8.4, PHPUnit 11, existing `App\Exchange\Fake` and `App\TradingCore\Execution\Fake` engines, JSON fixtures, MkDocs.

---

### Task 1: Versioned catalog contract

**Files:**
- Create: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Create: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`

- [x] **Step 1: Write the failing catalog test**

Create a test that loads `tests/fixtures/fake-paper/golden-scenarios-v1.json` and asserts:

```php
self::assertSame('fake-paper-golden-v1', $catalog['schema_version']);
self::assertSame(range(1, 20), array_column($catalog['scenarios'], 'id'));
self::assertSame($expectedNames, array_column($catalog['scenarios'], 'name'));
```

For every row, require exactly one of these contracts:

```php
if ($scenario['status'] === 'executable') {
    self::assertNotSame('', $scenario['runner']);
    self::assertSame([], $scenario['gap_codes']);
} else {
    self::assertContains($scenario['status'], ['partial', 'unsupported']);
    self::assertNull($scenario['runner']);
    self::assertNotEmpty($scenario['gap_codes']);
}
```

- [x] **Step 2: Verify RED**

Run `cd trading-app && php vendor/bin/phpunit tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`.

Expected: failure because `golden-scenarios-v1.json` does not exist.

- [x] **Step 3: Add the minimal catalog**

Create exactly 20 ordered rows with these statuses:

| ID | Name | Status | Stable gap when not executable |
| --- | --- | --- | --- |
| 1 | `limit_maker_full_fill` | executable | none |
| 2 | `limit_unfilled_then_expired` | executable | none |
| 3 | `partial_fill_then_cancel` | executable | none |
| 4 | `fallback_taker` | unsupported | `fallback_taker_not_implemented` |
| 5 | `market_with_slippage` | partial | `slippage_model_zero` |
| 6 | `insufficient_balance` | unsupported | `balance_margin_validation_not_implemented` |
| 7 | `precision_reject` | unsupported | `instrument_precision_validation_not_implemented` |
| 8 | `leverage_cap_reject` | unsupported | `leverage_cap_validation_not_implemented` |
| 9 | `duplicate_client_order_id` | executable | none |
| 10 | `timeout_after_acceptance` | executable | none |
| 11 | `stop_loss_attach_success` | executable | none |
| 12 | `stop_loss_attach_failure` | partial | `stop_attach_failure_compensation_not_integrated` |
| 13 | `tp1_then_trailing` | partial | `trailing_stop_not_implemented` |
| 14 | `gap_at_stop_loss` | executable | none |
| 15 | `websocket_disconnect_resync` | executable | none |
| 16 | `duplicate_out_of_order_event` | partial | `out_of_order_event_injection_not_implemented` |
| 17 | `restart_with_open_position` | executable | none |
| 18 | `funding` | unsupported | `funding_model_not_implemented` |
| 19 | `one_way_conflict` | unsupported | `one_way_conflict_guard_not_implemented` |
| 20 | `dry_run_multi_profiles_same_symbol` | partial | `multi_profile_fake_recipe_not_consolidated` |

Every row also contains `requirement`, `evidence` as a non-empty list of current test references, `runner` as a stable string or `null`, and `gap_codes` as a list.

- [x] **Step 4: Verify GREEN**

Run the catalog test and expect it to pass.

### Task 2: COMMON-005 fixture parity

**Files:**
- Modify: `trading-app/tests/fixtures/fake-paper/demo-recipe-scenarios.json`
- Create: `trading-app/tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php`

- [x] **Step 1: Write the failing parity test**

Map each JSON row to a public fixture factory declared by a new `php_fixture` field, invoke the factory, normalize it to the JSON keys, and assert strict equality:

```php
$scenario = FakeExecutionScenarioFixtures::{$row['php_fixture']}();
self::assertSame([
    'name' => $scenario->name,
    'order_outcome' => $scenario->orderOutcome,
    'fill_ratio' => $scenario->fillRatio,
    'protection_outcome' => $scenario->protectionOutcome,
    'reject_reason' => $scenario->rejectReason,
    'quality_flags' => $scenario->qualityFlags,
    'fail_safe_action' => $scenario->failSafeAction,
], $normalizedJsonRow);
```

Also assert that factory names are unique and callable.

- [x] **Step 2: Verify RED**

Run the parity test and expect failure because existing JSON rows have no `php_fixture` field.

- [x] **Step 3: Add explicit factory names**

Add `orderAccepted`, `orderRejected`, `fullFillStopAttachSuccess`, `fullFillStopAttachFailure`, `partialFillStopRejected`, and `cancelAcceptedOrder` without changing scenario semantics.

- [x] **Step 4: Verify GREEN**

Run both fixture tests and expect them to pass.

### Task 3: Deterministic executable golden runner

**Files:**
- Create: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`
- Create: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`

- [x] **Step 1: Write the failing execution test**

The data provider reads only catalog rows with `status=executable`. The test executes each runner twice using two fresh runner instances and compares the complete normalized result:

```php
$first = (new FakePaperGoldenScenarioRunner())->run($scenario['runner']);
$second = (new FakePaperGoldenScenarioRunner())->run($scenario['runner']);

self::assertSame($first, $second);
self::assertSame($scenario['name'], $first['scenario']);
self::assertSame('pass', $first['outcome']);
self::assertSame('2026-01-01T00:00:00+00:00', $first['clock']);
```

Add a separate test proving the executable runner key set equals the catalog runner key set, so no supported row can silently skip execution.

- [x] **Step 2: Verify RED**

Run the execution test and expect failure because `FakePaperGoldenScenarioRunner` does not exist.

- [x] **Step 3: Implement the runner with current real engines**

Use a fixed PSR clock, fresh `FakeExchangeStateStore`, `FakeExchangeOrderBook`, `FakeExchangeMatchingEngine`, `FakeExchangeAdapter`, `FakeExchangeScenarioService`, and where appropriate `FakeExecutionPort` or `FakeExchangeWsClient`. Implement one method per executable key:

```text
limit_maker_full_fill
limit_unfilled_then_expired
partial_fill_then_cancel
duplicate_client_order_id
timeout_after_acceptance
stop_loss_attach_success
gap_at_stop_loss
websocket_disconnect_resync
restart_with_open_position
```

Each method must assert the semantic invariant in its returned normalized fields, omit generated temporary paths, and expose only stable IDs, statuses, quantities, sequences and redacted error codes.

- [x] **Step 4: Verify GREEN and determinism**

Run:

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php \
  tests/TradingCore/Execution/FakeExecutionScenarioFixtureParityTest.php
```

Expected: all executable rows run twice and all tests pass.

### Task 4: Documentation and validation

**Files:**
- Modify: `docs/handbook/technical/fake-paper-gateway.md`

- [x] **Step 1: Document the contract**

Add the command to run the consolidated suite, the three statuses, the exact executable scenario list, and the stable gaps. State explicitly that a catalog row is not a PASS, `partial`/`unsupported` cannot enable demo/testnet mutation, and no private exchange endpoint is contacted.

- [x] **Step 2: Run focused and broad validation**

Run the focused PHPUnit suite, PHPStan on all touched PHP, `php bin/console lint:container --no-debug`, `php bin/console lint:yaml config`, `python3 -m mkdocs build --strict`, and `git diff --check`. All commands must exit 0. Then inspect `git diff`, run independent spec and quality reviews, and fix every finding before opening the PR.
