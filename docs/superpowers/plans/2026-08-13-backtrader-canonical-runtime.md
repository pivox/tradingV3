# Backtrader Canonical Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute one authenticated modern `CanonicalOrderPlan` deterministically against verified Paper candles through a pinned Backtrader adapter.

**Architecture:** PHP exposes the existing canonical plan as a hash-bound wire document. Python validates that complete document, adapts one verified candle stream to Backtrader, and delegates each available bar to a small immutable execution state machine. Backtrader controls time iteration only; TradingCore remains the authority for rules, EntryZone, risk, leverage, protection and expected costs.

**Tech Stack:** PHP 8.4/Symfony, Python 3.12+, Pydantic 2.13, Backtrader 1.9.78.123, PHPUnit 11, pytest 8.

---

### Task 1: Canonical PHP order-plan wire boundary

**Files:**
- Create: `trading-app/src/TradingCore/Backtesting/OrderPlan/CanonicalBacktestOrderPlanProjection.php`
- Modify: `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalOrderPlan.php`
- Test: `trading-app/tests/TradingCore/Backtesting/OrderPlan/CanonicalBacktestOrderPlanProjectionTest.php`
- Create: `python-orchestrator/tests/fixtures/backtesting/php-canonical-order-plan.json`

- [ ] Write a failing PHPUnit test that builds the established `CanonicalOrderPlanPipelineFixture`, projects it, and asserts the exact schema, camel-case PHP plan payload, `plan_hash`, and deterministic canonical bytes.
- [ ] Run the focused test and observe the missing projection failure.
- [ ] Add an exact `toArray()` representation to `CanonicalOrderPlan` and a projection envelope containing `canonical-backtest-order-plan.v1`; preserve optional-field omission and PHP canonical float/timestamp encoding used by `expectedPlanHash()`.
- [ ] Generate the golden fixture from the public projection API, rerun the focused test and PHPStan, then commit.

### Task 2: Strict Python plan and result contracts

**Files:**
- Create: `python-orchestrator/app/backtesting/backtrader_contracts.py`
- Test: `python-orchestrator/tests/test_backtesting_backtrader_contracts.py`

- [ ] Write failing tests loading the PHP golden and rejecting every missing/extra field, forged modern identity, non-fake target, invalid stop/zone/target polarity, stale deadline order, non-finite values, mismatched input hashes and recomputed-envelope tampering.
- [ ] Run the focused tests and observe the missing-model failure.
- [ ] Implement frozen strict `CanonicalBacktestOrderPlan`, target, event and result Pydantic models; recompute PHP `plan_hash`, canonical input hash and result hash with the shared canonical encoder.
- [ ] Rerun focused tests under two `PYTHONHASHSEED` values, compile the module, then commit.

### Task 3: Verified Backtrader feed adapter

**Files:**
- Create: `python-orchestrator/app/backtesting/backtrader_feed.py`
- Test: `python-orchestrator/tests/test_backtesting_backtrader_feed.py`
- Modify: `python-orchestrator/requirements.txt`

- [ ] Add `backtrader==1.9.78.123`, install the pinned dependency locally, and write failing feed tests using strict `CandleRecord` fixtures.
- [ ] Cover one exact stream, canonical order, UTC timeframe continuity, run bounds, `available_at` gating, mixed identity, gaps, duplicates and incomplete evidence.
- [ ] Implement `VerifiedBacktraderFeedAdapter` plus a minimal Backtrader data feed that timestamps bars at `available_at` while retaining `source_record_id` evidence.
- [ ] Rerun the feed tests and dependency install check, then commit.

### Task 4: Conservative execution state machine

**Files:**
- Create: `python-orchestrator/app/backtesting/backtrader_execution.py`
- Test: `python-orchestrator/tests/test_backtesting_backtrader_execution.py`

- [ ] Write failing table-driven tests for full limit fill, non-fill, expiry, long/short stop, target, same-bar stop+target, holding expiry and dataset-end open-position rejection.
- [ ] Assert every fill event attaches the authenticated full-size stop atomically and every event binds dataset/plan/config/bar identity.
- [ ] Implement an immutable state transition function with stable reason codes; accept only `conservative_stop_first`, full fills and full-position first-target exits.
- [ ] Rerun focused tests under two hash seeds and commit.

### Task 5: Backtrader runtime adapter and deterministic golden

**Files:**
- Create: `python-orchestrator/app/backtesting/backtrader_runtime.py`
- Test: `python-orchestrator/tests/test_backtesting_backtrader_runtime.py`
- Create: `python-orchestrator/tests/fixtures/backtesting/backtrader-runtime-result.json`

- [ ] Write failing integration tests that run a minimal `bt.Cerebro` strategy whose only action is forwarding each available bar to the state machine.
- [ ] Prove byte-identical output for two reruns and for different hash seeds; add dependency-boundary assertions excluding TradingCore rule, risk and sizing formulas from Python runtime files.
- [ ] Implement the runtime, deterministic result serializer and bounded single-plan/single-stream checks; generate the golden result.
- [ ] Rerun focused integration tests and commit.

### Task 6: Documentation and full verification

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`
- Modify: `docs/superpowers/specs/2026-08-13-backtrader-canonical-runtime-design.md`

- [ ] Document the executable boundary, commands, stable reason codes, full-fill limitation and deferred partial fills/cost application/portfolio/replay work.
- [ ] Run targeted PHPUnit and PHPStan, the complete Python suite with coverage, `compileall`, `mkdocs build --strict`, dependency reproducibility and `git diff --check`.
- [ ] Review the complete `origin/main...HEAD` diff for scope, security, mainnet prohibition and contract parity; fix only concrete findings.
- [ ] Push, open a ready PR, request one Codex review, address concrete threads, require green CI, merge, update #191 and move to the next cost/fill lot.
