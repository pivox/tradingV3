# Backtrader Historical Funding Settlement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Settle actual crossed historical funding instants through a strict integrity-bound schedule and the canonical PHP authority.

**Architecture:** Python owns and verifies immutable schedule bytes but performs no funding arithmetic. A bounded bridge sends a canonical request to a PHP service using Brick Math, validates the hash-bound response, and the net-outcome projector replaces only the planned funding component while retaining explicit non-certification.

**Tech Stack:** Python 3.12, Pydantic v2, pytest, PHP 8.2, Symfony Console, Brick Math, PHPUnit.

---

### Task 1: Strict historical schedule contract

**Files:**
- Create: `python-orchestrator/app/backtesting/historical_funding.py`
- Test: `python-orchestrator/tests/test_backtesting_historical_funding.py`

- [ ] Write failing tests for exact decimal/timestamp grammar, identity and dataset binding, canonical ordering, interval-grid coverage, availability guards, checksum tampering, and deterministic canonical bytes.
- [ ] Run `pytest -q tests/test_backtesting_historical_funding.py` and confirm import/contract failures.
- [ ] Implement immutable `HistoricalFundingRecord`, `HistoricalFundingScheduleArtifacts`, and `VerifiedHistoricalFundingSchedule` values. The artifact schema is `historical-funding-schedule.v1`; each record is `historical-funding-record.v1`; checksums are `sha256:<64 lowercase hex>`.
- [ ] Re-run the targeted tests until green and commit the contract.

### Task 2: Canonical PHP settlement authority

**Files:**
- Create: `trading-app/src/TradingCore/Backtesting/Funding/CanonicalHistoricalFundingSettlement.php`
- Create: `trading-app/src/TradingCore/Backtesting/Funding/CanonicalHistoricalFundingSettlementException.php`
- Test: `trading-app/tests/TradingCore/Backtesting/Funding/CanonicalHistoricalFundingSettlementTest.php`

- [ ] Write failing PHPUnit cases for long/short sign, negative rates, multiple instants, `entry_at < funding_at <= exit_at`, zero crossed instants, and malformed/incomplete schedules.
- [ ] Run the exact PHPUnit file and confirm the missing-class failure.
- [ ] Implement strict request parsing, coverage checks, Brick Math arithmetic, applied record lineage, canonical request/result hashes, and signed cashflow output.
- [ ] Re-run targeted PHPUnit tests until green and commit the authority.

### Task 3: Bounded command and Python bridge

**Files:**
- Create: `trading-app/src/Command/BacktestSettleHistoricalFundingCommand.php`
- Create: `python-orchestrator/app/backtesting/historical_funding_bridge.py`
- Test: `trading-app/tests/Command/BacktestSettleHistoricalFundingCommandTest.php`
- Test: `python-orchestrator/tests/test_backtesting_historical_funding_bridge.py`

- [ ] Write failing tests for strict stdin, stable errors, canonical stdout, timeout, output bound, child failure, forged result hash, and request/result binding.
- [ ] Run both targeted suites and confirm expected failures.
- [ ] Implement the Symfony command and a shell-free, bounded subprocess bridge following the existing TradingCore bridge pattern.
- [ ] Re-run targeted tests until green and commit the bridge.

### Task 4: Historical funding in net outcome

**Files:**
- Modify: `python-orchestrator/app/backtesting/backtrader_net_outcome.py`
- Modify: `python-orchestrator/app/backtesting/backtrader_runtime.py`
- Test: `python-orchestrator/tests/test_backtesting_backtrader_net_outcome.py`
- Test: `python-orchestrator/tests/test_backtesting_backtrader_runtime.py`

- [ ] Write failing tests showing that an explicit verified schedule and PHP result replace only the plan funding provision, preserve exact total/net reconciliation, carry applied-record lineage, reject feed/schedule mismatch, and never fall back after invalid historical evidence.
- [ ] Run targeted tests and confirm they fail because the optional historical authority is absent.
- [ ] Add explicit historical settlement inputs, output schema/version/evidence fields, and canonical hashing while retaining `costs_are_certified=false` and `result_is_live_proof=false`.
- [ ] Re-run targeted tests until green and commit integration.

### Task 5: Cross-runtime golden, docs, and delivery

**Files:**
- Create: `python-orchestrator/tests/fixtures/backtesting/historical-funding-schedule.json`
- Create: `python-orchestrator/tests/fixtures/backtesting/php-historical-funding-settlement.json`
- Modify: `python-orchestrator/tests/fixtures/backtesting/backtrader-runtime-result.json`
- Modify: `docs/backtesting/reproduction-handbook.md`

- [ ] Add a PHP-generated golden settlement and assert byte-identical Python validation.
- [ ] Document reproduction, trust limits, sign convention, and explicit deferred acquisition/certification.
- [ ] Run targeted PHP/Python tests, dependency checks, full Python suite/coverage, relevant PHP suites, and `git diff --check`.
- [ ] Perform local code review, push, open a ready PR referencing #191, request one real GitHub review, address concrete feedback, and merge when checks and threads are clean.
