# Paper Strategy Observability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist an exact, replay-safe explanation for every modern Paper strategy evaluation without changing trading behavior.

**Architecture:** A typed preparation result preserves planned decisions and rejection reason codes. Evidence-stage failures use a dedicated bounded exception, and the coordinator writes a strict observation into the existing append-only journal atomically with the source claim.

**Tech Stack:** PHP 8.2, Symfony services, Doctrine DBAL/PostgreSQL, PHPUnit, PHPStan.

---

### Task 1: Typed canonical preparation result

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparationResult.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparationInterface.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparation.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparationTest.php`

- [x] Add failing tests asserting `missing_evidence`, runtime `no_trade`, and `planned` result shapes and exact reason codes.
- [x] Run `php bin/phpunit tests/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparationTest.php` and confirm the nullable contract fails.
- [x] Add a strict result value object whose decision is present only for `planned`, then return it from the interface and implementation.
- [x] Re-run the targeted test and confirm it passes.

### Task 2: Exact missing-evidence stage

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyEvidenceUnavailable.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyEvidenceSource.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyPreparation.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyEvidenceSourceTest.php`

- [x] Add failing tests for projection, book, instrument, cost, and plan absence, each asserting its exact bounded reason.
- [x] Run the new test and confirm the current nullable returns fail it.
- [x] Throw the dedicated unavailable exception at each evidence boundary and translate it to `missing_evidence` only in canonical preparation.
- [x] Re-run evidence-source and preparation tests and confirm they pass.

### Task 3: Append-only strategy observation

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Strategy/PaperCanonicalStrategyObservation.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Persistence/PaperExecutionStoreInterface.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Persistence/DoctrinePaperExecutionStore.php`
- Modify: `trading-app/src/Trading/Paper/Execution/PaperExecutionCoordinator.php`
- Test: `trading-app/tests/Trading/Paper/Execution/Persistence/DoctrinePaperExecutionStoreTest.php`
- Test: `trading-app/tests/Trading/Paper/Execution/PaperExecutionCoordinatorTest.php`

- [x] Add failing tests for strict payload serialization, transactional append, source linkage, and replay idempotence.
- [x] Run the targeted coordinator and store tests and confirm the missing observation API fails them.
- [x] Append `strategy_observed` inside the accepted source transaction and derive the trade effect only from a `planned` result.
- [x] Re-run targeted tests and confirm planned, no-trade, missing-evidence, legacy, and replay paths pass.

### Task 4: Compatibility and delivery

**Files:**
- Modify affected Paper test doubles implementing `PaperCanonicalStrategyPreparationInterface`.
- Modify: `docs/superpowers/plans/2026-08-24-paper-strategy-observability.md`

- [x] Run the complete `tests/Trading/Paper/Execution` subsystem (the monolithic Paper runner has an unrelated Symfony temporary-file teardown race) and fix only contract fallout.
- [x] Run targeted PHPStan on all changed production files.
- [x] Mark completed plan steps and inspect the diff for tuning or mainnet-write changes.
- [ ] Push a PR against `main`, request Codex review, resolve any substantive thread, and merge only when checks and review are clean.
- [ ] Start a distinct Paper replay campaign after merge; never relabel the earlier zero-trade campaign as observed or certified.
