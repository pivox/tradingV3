# #191 Canonical TradingCore Rule Bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute the canonical PHP setup-rule runtime from deterministic Python backtests through a strict, versioned, fail-closed CLI protocol.

**Architecture:** A Symfony service owns request decoding, snapshot re-resolution, lineage construction, rule execution, and deterministic result hashing. A thin console command exposes one JSON-in/JSON-out invocation; a Python adapter validates the same protocol and subprocess behavior without reproducing rules.

**Tech Stack:** PHP 8.3/Symfony Console/PHPUnit/PHPStan, Python 3.11+/Pydantic/subprocess/pytest.

---

### Task 1: PHP rule evaluation protocol

**Files:**
- Create: `trading-app/src/TradingCore/Backtesting/CanonicalBacktestRuleEvaluation.php`
- Create: `trading-app/src/TradingCore/Backtesting/CanonicalBacktestRuleEvaluator.php`
- Create: `trading-app/tests/TradingCore/Backtesting/CanonicalBacktestRuleEvaluatorTest.php`

- [ ] Write failing tests for exact request shape, fake/backtest identity, resolved-snapshot equality, pass/no-trade output, deterministic trace without `plan_cache_hit`, indicator identity, missing/stale timeframes, forged hashes, legacy/extra fields, and canonical input/result hashes.
- [ ] Run the focused PHPUnit class and confirm failures are caused by missing protocol classes.
- [ ] Implement immutable evaluation/result DTO parsing with explicit type/key/size/time/identity guards and canonical JSON hashing.
- [ ] Implement the evaluator using `EffectiveTradingConfigResolverInterface`, `CanonicalSetupRuleRuntime`, and `LineageContext`; do not invoke shadow OrderPlan or portfolio paths.
- [ ] Re-run focused tests and targeted PHPStan, then commit.

### Task 2: JSON stdin/stdout Symfony command

**Files:**
- Create: `trading-app/src/Command/BacktestEvaluateCanonicalRulesCommand.php`
- Create: `trading-app/tests/Command/BacktestEvaluateCanonicalRulesCommandTest.php`

- [ ] Write failing command tests for exact successful stdout, pass/no-trade exit zero, malformed/blank/oversized/multiple JSON, non-object roots, service failures, and stderr-only diagnostics.
- [ ] Run the command tests and observe the missing command failure.
- [ ] Implement `app:backtest:rules:evaluate` with an 8 MiB bounded stdin reader, depth-128 strict JSON decoder, one compact JSON result plus newline on stdout, and `Command::INVALID` for protocol failures.
- [ ] Verify command tests, service tests, lint, and targeted PHPStan, then commit.

### Task 3: Strict Python subprocess bridge

**Files:**
- Create: `python-orchestrator/app/backtesting/tradingcore_bridge.py`
- Create: `python-orchestrator/tests/test_backtesting_tradingcore_bridge.py`
- Modify: `python-orchestrator/app/backtesting/__init__.py`

- [ ] Write failing tests for frozen request/result models, exact/extra fields, fake/backtest guards, canonical hashing, fixed shell-free argv/stdin, default timeout, timeout/missing executable/non-zero/malformed/multiple/oversized output, and identity/hash/result drift.
- [ ] Run the focused Python module and confirm missing bridge failures.
- [ ] Implement strict models and `BacktestTradingCoreBridge` using `subprocess.run(..., shell=False, input=..., capture_output=True, timeout=...)`; cap both outputs and never retry/fallback.
- [ ] Add a real golden invocation against Symfony proving repeated byte-identical pass/no-trade output.
- [ ] Run focused and full Python tests with coverage, then commit.

### Task 4: Cross-runtime documentation and delivery

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`

- [ ] Document the CLI protocol, reproduction command, exit semantics, timeout/size limits, error codes, and explicit Paper-candle-to-indicator dependency.
- [ ] Run focused PHP/Python tests, targeted PHPStan, full Python coverage gate, two deterministic Python hash seeds, compile/lint/diff checks, and cross-runtime golden twice.
- [ ] Request one whole-lot review, fix every concrete finding, open a focused PR linked to #191, wait for CI/review, and merge only with no unresolved thread.

