# Backtrader Authenticated Net Outcome Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce a hash-bound exact planned net outcome for canonical Backtrader stop and target executions using PHP plan-bound cost components.

**Architecture:** A focused Python projection module consumes the strict `CanonicalBacktestOrderPlan`, immutable execution result and verified feed. It selects the plan-bound stop or target cost branch, validates dataset/event lineage and arithmetic, and serializes exact decimal components while explicitly declaring them uncertified. Runtime integration adds the planned outcome only for supported closed traces and fails closed for arbitrary-price exits.

**Tech Stack:** Python 3.12, Pydantic 2.13, Decimal, pytest 8, Backtrader 1.9.78.123.

---

### Task 1: Exact plan-bound projection contract

**Files:**
- Create: `python-orchestrator/app/backtesting/backtrader_net_outcome.py`
- Create: `python-orchestrator/tests/test_backtesting_backtrader_net_outcome.py`

- [ ] Write failing target tests asserting exact gross PnL, every fee/spread/slippage component, planned adverse funding, net PnL, net R, target id, lineage and deterministic outcome hash.
- [ ] Run `python3 -m pytest -q tests/test_backtesting_backtrader_net_outcome.py -k target` and verify the module/API is missing.
- [ ] Implement `project_plan_bound_net_outcome(plan, execution, feed)` by selecting the matching target values bound by `plan_hash`; do not multiply any economic rate and declare the projection uncertified.
- [ ] Run the target tests and commit the green slice.

### Task 2: Stop, rejection and exact-decimal boundaries

**Files:**
- Modify: `python-orchestrator/app/backtesting/backtrader_net_outcome.py`
- Modify: `python-orchestrator/tests/test_backtesting_backtrader_net_outcome.py`

- [ ] Write failing tests for stop loss signs, long/short polarity, unsupported holding expiry, non-executed traces, unknown target, forged dataset/plan/config/quantity/stop lineage and non-reconciling plan-bound totals.
- [ ] Run the focused tests and verify each missing guard fails for the intended reason.
- [ ] Add stable fail-closed validation, exact Decimal conversion and exact JSON-number serialization with schema/cost-basis/outcome hashes.
- [ ] Add a source-boundary test forbidding fee/rate/notional multiplication in this Python module, rerun focused coverage at 95% or better, then commit.

### Task 3: Runtime integration and golden evidence

**Files:**
- Modify: `python-orchestrator/app/backtesting/backtrader_runtime.py`
- Modify: `python-orchestrator/tests/test_backtesting_backtrader_runtime.py`
- Modify: `python-orchestrator/tests/fixtures/backtesting/backtrader-runtime-result.json`
- Modify: `docs/handbook/technical/backtesting-engine.md`

- [ ] Write a failing integration test requiring a `net_outcome` object in supported closed runtime results and a fail-closed holding-expiry test.
- [ ] Integrate the settlement adapter after execution, preserve canonical byte determinism and regenerate the committed golden.
- [ ] Document that funding is a plan provision rather than historical settlement and list unsupported terminal paths.
- [ ] Run all Backtrader tests, the complete Python suite, compileall, strict docs and diff checks; commit.

### Task 4: Pull request delivery

**Files:**
- Modify only files required by concrete review feedback.

- [ ] Review `origin/main...HEAD` for cost-authority duplication, exact arithmetic, lineage, scope and mainnet prohibition.
- [ ] Push and open a ready PR referencing #191 with local evidence and explicit deferred historical funding/partial-fill scope.
- [ ] Request one Codex review, address concrete feedback with TDD, require all checks green and no unresolved threads, then merge.
- [ ] Update #191 with the merge SHA and next remaining lot: partial fills/maker-taker fallback or historical funding authority, whichever dependency evidence makes executable first.
