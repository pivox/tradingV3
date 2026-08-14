# #191 Staged Visible-Fill Runtime Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Execute partial and multi-event visible maker fills with exact exposure chronology and settle the consumed quantity through the PHP cost authority.

**Architecture:** A new pure staged-fill state machine merges positive public fill evidence into deterministic Backtrader bar delivery. The existing bounded bridge builds and verifies one aggregate terminal settlement, a focused projector emits the v3 net outcome, and the runtime orchestrates these boundaries without copying PHP cost formulas.

**Tech Stack:** Python 3.12+, Pydantic 2.13, Decimal, Backtrader 1.9.78.123, pytest 8, PHP 8.4/Symfony command bridge.

---

### Task 1: Pure staged-fill execution chronology

**Files:**
- Modify: `python-orchestrator/app/backtesting/backtrader_execution.py`
- Create: `python-orchestrator/app/backtesting/staged_fill_execution.py`
- Create: `python-orchestrator/tests/test_backtesting_staged_fill_execution.py`

- [ ] **Step 1: Write failing tests for incremental exposure**

Add fixtures for a v2 plan, verified bars and queue traces whose positive fills
arrive in one or several bars. Assert event kinds and exact `quantity_base`:

```python
execution = execute_plan_from_staged_visible_fills(plan, bars, evidence)
assert [event.kind for event in execution.events] == [
    "entry_partially_filled", "entry_filled", "target_filled",
]
assert [event.quantity_base for event in execution.events] == [
    Decimal("1"), Decimal("1.497"), Decimal("2.497"),
]
```

Cover a partial result whose residual is cancelled at deadline, a stop before a
later evidence fill, a stop on the first fill bar, a target ignored on a fill
bar, exact base/contract conservation, and tampered/non-prefix evidence.

- [ ] **Step 2: Verify the tests fail for the missing state machine**

Run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_staged_fill_execution.py -q
```

Expected: collection or assertion failure because the staged state machine and
base-quantity event fields do not exist.

- [ ] **Step 3: Add exact event quantity and the pure state machine**

Extend `BacktestExecutionEvent` without changing legacy serialization:

```python
kind: Literal[
    "entry_partially_filled", "entry_filled", "stop_filled",
    "target_filled", "holding_expired",
]
quantity_base: Decimal | None = None
```

Implement `execute_plan_from_staged_visible_fills()` in the new module. Revalidate
the complete evidence, require the v2 plan identity, consume only positive fills
in order, group fills by delivered bar, apply all fills before a conservative
same-bar stop, suppress a same-bar target, stop consuming the trace after a
terminal event, and reject open exposure at dataset end.

- [ ] **Step 4: Run focused tests green and preserve atomic execution tests**

Run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_staged_fill_execution.py tests/test_backtesting_backtrader_runtime.py -q
```

Expected: all tests pass; existing v1/atomic-v2 assertions remain unchanged.

### Task 2: Build a settlement request from the consumed fill prefix

**Files:**
- Modify: `python-orchestrator/app/backtesting/partial_fill_cost_bridge.py`
- Modify: `python-orchestrator/tests/test_backtesting_partial_fill_cost_bridge.py`

- [ ] **Step 1: Write failing request-builder tests**

Assert the request is derived rather than caller-assembled:

```python
request = canonical_partial_fill_cost_request(plan, evidence, execution)
assert request.filled_quantity_base == "1"
assert request.maker_fill_result_hash == evidence.result_hash
assert request.maker_fill_trace_hash == evidence.trace_hash
assert request.terminal_kind == "stop_filled"
assert request.target_id is None
```

Mutate the event source, time, incremental quantity, cumulative terminal
quantity, target price and evidence prefix; every case must fail closed.

- [ ] **Step 2: Run the focused test and observe the missing builder failure**

Run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_partial_fill_cost_bridge.py -q
```

- [ ] **Step 3: Implement exact prefix validation and request construction**

Add `canonical_partial_fill_cost_request(envelope, evidence, execution)`. It
must revalidate all inputs, match entry events one-for-one to the positive trace
prefix, sum exact base quantities, require a stop or declared target terminal,
and return the existing frozen request model. Add a public
`partial_fill_settlement_matches_request(result, request)` helper and use it in
the bridge.

- [ ] **Step 4: Run bridge tests and the real local PHP command**

Run the focused suite, then invoke `PartialFillCostBridge().settle(request)`
against `app:backtest:partial-fill-cost:settle`. Expected: the returned request
hash, result hash and exact filled quantity reconcile.

### Task 3: Project the authenticated partial-fill net outcome

**Files:**
- Create: `python-orchestrator/app/backtesting/partial_fill_net_outcome.py`
- Create: `python-orchestrator/tests/test_backtesting_partial_fill_net_outcome.py`

- [ ] **Step 1: Write failing projector and mutation tests**

The golden output must include:

```python
assert outcome["schema_version"] == "canonical-backtest-partial-fill-net-outcome.v1"
assert outcome["filled_quantity_base"] == Decimal("1")
assert outcome["cancelled_residual_quantity_base"] == Decimal("1.497")
assert outcome["partial_fill_cost_result_hash"] == settlement.result_hash
assert outcome["costs_are_certified"] is False
assert outcome["result_is_live_proof"] is False
```

Test forged execution replay, settlement identity/hash, target id, fill prefix,
terminal candle, quantity conservation and non-deterministic ordering.

- [ ] **Step 2: Run tests red**

Run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_partial_fill_net_outcome.py -q
```

- [ ] **Step 3: Implement a formula-free projector**

Revalidate and replay the staged execution, rebuild the canonical request,
verify the settlement, copy its PHP-computed Decimal components, bind the queue
and terminal lineage, and hash the sorted canonical document. Do not reference
fee rates, spread rates, slippage rates or funding formulas in this module.

- [ ] **Step 4: Run projector and authority-boundary tests green**

Run the new projector tests together with existing planned/historical net
outcome tests. Expected: all pass and v1/v2 outcome bytes are unchanged.

### Task 4: Integrate staged fills into the Backtrader runtime

**Files:**
- Modify: `python-orchestrator/app/backtesting/backtrader_runtime.py`
- Modify: `python-orchestrator/tests/test_backtesting_backtrader_runtime.py`

- [ ] **Step 1: Replace rejection tests with failing v3 runtime tests**

Pass a real `PartialFillCostBridge` test double using fixed child argv. Assert
partial-at-deadline and staged-full traces execute, invoke PHP-compatible request
bytes once, expose each fill event, close only cumulative quantity, emit
`canonical-backtrader-result.v3`, and remain byte deterministic.

Also assert stable failures for a missing/wrong bridge, a bridge on atomic or
unfilled evidence, staged fills combined with historical funding, and forged
settlement identity.

- [ ] **Step 2: Run runtime tests red**

Run:

```bash
cd python-orchestrator
python3 -m pytest tests/test_backtesting_backtrader_runtime.py -q
```

- [ ] **Step 3: Orchestrate the staged boundaries**

Add `partial_fill_cost_bridge: PartialFillCostBridge | None`. Select staged
execution only when `requires_partial_fill_authority(evidence)` is true; require
the exact bridge type; forbid unnecessary authority injection and staged
historical funding; settle a closed execution; project the authenticated partial
outcome; serialize exact `quantity_base`; and bind queue plus settlement hashes
into v3 input/result hashes.

- [ ] **Step 4: Run the complete Backtrader surface**

Run all `tests/test_backtesting_*` tests. Expected: v1, atomic v2, staged v3,
historical funding and bridge tests pass together.

### Task 5: Documentation, full verification and delivery

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`

- [ ] **Step 1: Document the v3 chronology and remaining boundary**

Describe incremental fills, conservative same-bar stop, target suppression,
residual cancellation, aggregate PHP settlement, historical-funding exclusion,
non-certified flags and stable failure behavior. Remove the obsolete statement
that every partial/staged fill is rejected.

- [ ] **Step 2: Run fresh complete verification**

Run:

```bash
cd python-orchestrator
python3 -m pytest --cov=app --cov-report=term-missing --cov-fail-under=95
cd ..
python3 -m compileall -q python-orchestrator/app python-orchestrator/tests
python3 -m mkdocs build --strict
git diff --check
```

Expected: all tests pass, coverage is at least 95%, compilation and strict docs
exit zero.

- [ ] **Step 3: Review, commit and publish the ready PR**

Review the full diff for formula duplication, altered legacy bytes and private
venue paths. Commit only intended files, push the branch, open a ready PR
referencing #191, request `@codex` review, address actionable feedback, and merge
only after all checks are green and no blocking thread remains.
