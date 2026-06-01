# API-12 Cross-Profile Symbol Lock Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent two MTF profiles from opening the same `exchange + market_type + symbol` concurrently.

**Architecture:** Add a persistent `symbol_execution_lock` table with a manager service. `OrderIntentManager::reserveIntent()` acquires the global symbol lock in the same transaction as `OrderIntent` creation and returns `cross_profile_symbol_locked` when another active lock owns the symbol.

**Tech Stack:** Symfony 7, Doctrine ORM/DBAL, PostgreSQL partial unique index, PHPUnit, Symfony Console.

---

### File Structure

- Create `trading-app/src/Entity/SymbolExecutionLock.php`: Doctrine entity for active/released symbol locks.
- Create `trading-app/src/Repository/SymbolExecutionLockRepository.php`: active lock lookup/listing helpers.
- Create `trading-app/src/Service/SymbolExecutionLockManager.php`: reserve and release API.
- Modify `trading-app/src/Repository/FuturesOrderRepository.php`: expose local open-order lookup for lock release/reclaim guards.
- Modify `trading-app/src/Service/OrderIntentManager.php`: reserve global symbol lock around intent creation and release on failed/cancelled intents.
- Modify `trading-app/src/Service/OrderIntentReservation.php`: carry optional metadata for blocking locks.
- Create `trading-app/src/Trading/Listener/SymbolExecutionLockReleaseListener.php`: release eligible locks on position-close events.
- Create `trading-app/src/Command/SymbolLockListCommand.php`: list active locks.
- Create `trading-app/src/Command/SymbolLockReleaseCommand.php`: guarded manual release.
- Create `trading-app/migrations/Version20260601000000.php`: create table and partial unique index.
- Modify `trading-app/tests/Repository/ExchangeScopedStorageTest.php`: add functional lock tests.
- Create `trading-app/tests/Command/SymbolLockCommandTest.php`: list/release command tests.
- Create `trading-app/docs/CROSS_PROFILE_SYMBOL_LOCK.md`: runbook.

### Task 1: Entity and Repository

- [x] Write failing repository tests proving active lock lookup does not exist yet.
- [x] Add `SymbolExecutionLock` entity with `active`, `released`, and `key` helpers.
- [x] Add `SymbolExecutionLockRepository` with `findActive()`, `findActiveLocks()`.
- [x] Add migration creating `symbol_execution_lock` and partial unique index.
- [x] Add the new entity to the schema setup in `ExchangeScopedStorageTest`.

### Task 2: Lock Manager

- [x] Write failing tests for global key generation and same-symbol cross-profile blocking.
- [x] Implement `SymbolExecutionLockManager::reserveForIntent()` returning a created lock or a blocked lock result.
- [x] Implement `releaseForIntent()`, `releaseForSymbol()`, and `releaseManual()` with open-position/open-order guard.
- [x] Verify different exchange, market type, and symbol reservations are allowed.
- [x] Verify expired locks are reclaimed only when no local open exposure remains.

### Task 3: Order Intent Integration

- [x] Write failing tests using `OrderIntentManager::reserveIntent()` for `scalper` then `scalper_micro` on the same symbol.
- [x] Inject `SymbolExecutionLockManager` into `OrderIntentManager`.
- [x] Acquire the global lock in the transaction after decision-key replay check and before flushing the new intent.
- [x] Acquire the global lock in the fallback reservation path when `decision_key` is absent.
- [x] Return `OrderIntentReservation::blocked(..., 'cross_profile_symbol_locked', metadata)` when blocked.
- [x] Release locks when `markAsFailed()` or `markAsCancelled()` is called.

### Task 4: Execution Result Metadata

- [x] Cover lock metadata through `OrderIntentManager` reservation tests and `ExecuteOrderPlan` raw propagation.
- [x] Add `metadata` to `OrderIntentReservation`.
- [x] Include `lock` metadata in `ExecutionResult::raw` when the block reason is `cross_profile_symbol_locked`.
- [x] Add `TradeLifecycleReason::CROSS_PROFILE_SYMBOL_LOCKED`.

### Task 5: Commands

- [x] Write command tests for list and release.
- [x] Add `app:symbol-lock:list` with optional `--exchange`, `--market-type`, and `--symbol` filters.
- [x] Add `app:symbol-lock:release SYMBOL --exchange=... --market-type=... --reason=... [--force]`.
- [x] Ensure release refuses while an open position or open order exists unless `--force`.
- [x] Release eligible locks automatically on `PositionClosedEvent`.

### Task 6: Documentation and Verification

- [x] Add `trading-app/docs/CROSS_PROFILE_SYMBOL_LOCK.md`.
- [x] Run targeted tests:

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit tests/Repository/ExchangeScopedStorageTest.php tests/Command/SymbolLockCommandTest.php
```

- [x] Run a broader PHP check if targeted tests pass:

```bash
php -d error_reporting='E_ALL & ~E_DEPRECATED' ./vendor/bin/phpunit
```

Result: fails on pre-existing broad-suite issues unrelated to this branch (`539` tests, `1902` assertions, `116` errors, `6` failures, `24` skipped, `144` risky), including missing `App\Domain\Ports\Out\IndicatorProviderPort`, missing `App\Domain\Strategy\Service\StrategyBacktester`, missing `App\Domain\Ports\Out\TradingProviderPort`, undefined `Timeframe::H1/H4/M1`, and strict coverage-risky tests.

- [ ] Review staged diff and commit:

```bash
git add trading-app/src/Entity/SymbolExecutionLock.php trading-app/src/Repository/SymbolExecutionLockRepository.php trading-app/src/Repository/FuturesOrderRepository.php trading-app/src/Service/SymbolExecutionLockManager.php trading-app/src/Service/OrderIntentManager.php trading-app/src/Service/OrderIntentReservation.php trading-app/src/TradeEntry/Workflow/ExecuteOrderPlan.php trading-app/src/Trading/Listener/SymbolExecutionLockReleaseListener.php trading-app/src/Logging/TradeLifecycleReason.php trading-app/src/Command/SymbolLockListCommand.php trading-app/src/Command/SymbolLockReleaseCommand.php trading-app/migrations/Version20260601000000.php trading-app/tests/Repository/ExchangeScopedStorageTest.php trading-app/tests/Command/SymbolLockCommandTest.php trading-app/docs/CROSS_PROFILE_SYMBOL_LOCK.md docs/superpowers/specs/2026-06-01-api-12-cross-profile-symbol-lock-design.md docs/superpowers/plans/2026-06-01-api-12-cross-profile-symbol-lock-implementation.md
git commit -m "feat: add cross-profile symbol execution lock"
```

### Self-Review

- Spec coverage: the plan covers global key, atomic DB lock, skip reason, manual diagnostics, tests, and docs.
- Placeholder scan: no unresolved placeholder remains.
- Scope check: release on position-close is handled by a dedicated listener, while broader reconciliation cleanup beyond the existing event flow is intentionally deferred.
