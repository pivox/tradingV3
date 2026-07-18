# Fake/Paper One-Way Conflict Guard v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce deterministic Fake/Paper One-Way entry conflicts before margin/order creation and make golden scenario 19 executable.

**Architecture:** A focused guard reads persisted Fake state at the central matching-engine boundary and evaluates the exact `exchange + market_type + symbol` scope using explicit `positionSide`. Existing rejected-order/event persistence supplies redacted audit, restart, and idempotent replay without adding a schema or state format.

**Tech Stack:** PHP 8.4, Symfony 7.1, PHPUnit 11, existing Fake/Paper matching engine, checksummed state store, golden scenario runner, MkDocs.

---

### Task 1: Observe the Missing One-Way Guard

**Files:**
- Create: `trading-app/tests/Exchange/Fake/FakeOneWayConflictGuardTest.php`

- [ ] **Step 1: Write focused failing behavior tests**

  Build real matching engines and assert LONG blocks SHORT, SHORT blocks LONG,
  reduce-only exits remain accepted, flat permits the opposite side, an active
  opposite entry blocks without a position, exact rejection replay is
  idempotent, restart preserves enforcement/evidence, and BTC/ETH are
  independent. Assert `one_way_position_conflict`, unchanged exposure/margin,
  no active rejected order, one persistent event, and no secret/raw metadata.

- [ ] **Step 2: Run RED**

  Run:

  ```bash
  vendor/bin/phpunit tests/Exchange/Fake/FakeOneWayConflictGuardTest.php
  ```

  Expected: FAIL because opposite entries are currently accepted/opened.

### Task 2: Add the Minimal Central Guard

**Files:**
- Create: `trading-app/src/Exchange/Fake/FakeOneWayConflictGuard.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`

- [ ] **Step 1: Implement exact-scope conflict evaluation**

  `FakeOneWayConflictGuard::conflictMetadata()` returns `null` for reduce-only
  intent. Otherwise it filters open positions and active orders by exact
  exchange, market type and normalized symbol; opposite positions and
  non-reduce-only opposite entries return derived redacted metadata. An
  ambiguous active non-reduce-only order fails closed.

- [ ] **Step 2: Insert the guard before order-book and margin work**

  Preserve request-context/intent checks and exact replay first. For a new
  conflicting intent call the existing `rejectOrder()` with reason
  `one_way_position_conflict`; do not create an active order or reserve/read
  margin before this branch.

- [ ] **Step 3: Run GREEN**

  Run the focused test file and existing adapter/state tests. Require all
  assertions to pass without warnings or risky tests.

### Task 3: Make Golden Scenario 19 Executable

**Files:**
- Modify: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`

- [ ] **Step 1: Write the failing catalogue/golden expectation**

  Change scenario 19 to `executable`, clear its gap, add runner key
  `one_way_conflict`, increment the executable count to 19, and declare exact
  expected facts for rejection, replay, restart, closure, active order, margin,
  redaction and symbol independence.

- [ ] **Step 2: Run RED**

  Run the golden catalogue/execution tests. Expected: FAIL because the runner
  implementation and facts do not yet exist.

- [ ] **Step 3: Implement the controlled scenario runner**

  Exercise real Fake adapter/engine/state objects, persist/reload a temporary
  Paper state file, and return deterministic scalar/list facts only. Use no
  credential or network client.

- [ ] **Step 4: Run GREEN**

  Run focused guard plus golden catalogue/execution tests twice through the
  existing deterministic data provider.

### Task 4: Document Supported One-Way Mode

**Files:**
- Modify: `trading-app/src/Exchange/Fake/README.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`

- [ ] **Step 1: Document the contract and audit**

  State the supported `fake/perpetual` One-Way-only scope, exact key,
  position-side mapping, conflict sources, reduce-only exception, rejection
  ordering, stable reason, persistence/replay/restart behavior, absence of
  netting/hedge fallback, redaction, local-only safety, and rollback.

- [ ] **Step 2: Update golden inventory**

  List 19 executable scenarios and leave only scenario 20 partial. Do not claim
  issue #196 complete.

### Task 5: Proportional Validation and Delivery

**Files:**
- Preserve: `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`
- Add all Task 1-4 files to the atomic PR.

- [ ] **Step 1: Run verification**

  Run focused and broader Fake/Exchange PHPUnit, PHPStan on touched PHP,
  `php bin/console lint:container --no-debug`, `php bin/console lint:yaml config`,
  `python3 -m mkdocs build --strict`, `git diff --check`, and a targeted secret
  scan. No exchange write command is permitted.

- [ ] **Step 2: Review requirements and diff**

  Confirm every Prompt 5 minimum test, no strategy/MTF/EntryZone/frequency
  change, no netting/hedge fallback, no schema migration, no secret, and the
  pre-existing Prompt 4/5 registry edit remains unchanged.

- [ ] **Step 3: Commit and inspect quota**

  Commit atomically, then launch Codex in TTY with profile `worker_complex` and
  verify the displayed five-hour quota is neither exhausted nor within the 3%
  stop gate.

- [ ] **Step 4: Push and open the requested PR**

  Push `issue/196-one-way-conflict-v1`, open a PR against `main` whose body says
  `Part of #196`, keep #196 open, wait about 90 seconds, and comment
  `@codex review` on the pushed HEAD.

- [ ] **Step 5: Observe only**

  Report commit, PR, files, tests, CI and review state. Do not merge and do not
  resolve any review thread.
