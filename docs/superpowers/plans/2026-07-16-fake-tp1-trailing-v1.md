# Fake/Paper TP1 Then Trailing v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a deterministic, restart-safe Fake/Paper trailing stop that arms only after an explicitly configured partial TP1 fill.

**Architecture:** A typed Fake-only metadata policy supplies the exact TP1 quantity and fixed absolute offset. The active reduce-only `TRIGGER` order is the sole persisted trailing state, so the existing file transaction atomically covers position reduction, SL replacement, watermark changes, lifecycle events, replay, and rollback.

**Tech Stack:** PHP 8.3, Symfony DTO/services, PHPUnit 11, Brick Math decimal validation, JSON fixtures, MkDocs.

---

## File Structure

- Create `trading-app/src/Exchange/Fake/FakeTp1TrailingPolicy.php`: validate and serialize the trusted Fake-only fixture policy.
- Modify `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`: validate policy use, size TP1, arm/update/trigger the persistent trailing order, and normalize lifecycle transitions.
- Create `trading-app/tests/fixtures/fake-paper/tp1-trailing-v1.json`: explicit long and short prices, quantities, offset, favorable/adverse moves, and gap.
- Create `trading-app/tests/Exchange/Fake/FakeTp1TrailingPolicyTest.php`: policy contract and fail-closed validation.
- Create `trading-app/tests/Exchange/Fake/FakeTp1TrailingTest.php`: focused integration coverage for every mandatory behavior and state restart.
- Modify `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`: promote scenario 13.
- Modify `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`: freeze the executable classification.
- Modify `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`: freeze normalized scenario 13 facts.
- Modify `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`: execute both direction fixtures deterministically.
- Modify `trading-app/src/Exchange/Fake/README.md` and `docs/handbook/technical/fake-paper-gateway.md`: document algorithm, persistence, lifecycle, safety, and rollback.

### Task 1: Typed Policy Contract

- [ ] **Step 1: Write the failing policy tests**

Create `FakeTp1TrailingPolicyTest` with a valid round-trip and invalid providers for missing version, disabled capability, non-decimal TP1 quantity, and non-positive offset. The desired API is:

```php
$policy = new FakeTp1TrailingPolicy('0.4', '100.0');
self::assertEquals($policy, FakeTp1TrailingPolicy::fromMetadata($policy->toMetadata()));
```

- [ ] **Step 2: Run RED**

Run the policy test in the required ephemeral container. Expected: error because `FakeTp1TrailingPolicy` does not exist.

- [ ] **Step 3: Implement the minimum policy value object**

Expose four metadata keys, the fixed version, strict unsigned decimal parsing through `BigDecimal`, `toMetadata()`, `fromMetadata()`, float projections, and explicit `fake_tp1_trailing_policy_invalid` / `fake_tp1_trailing_policy_disabled` failures. Return `null` only when none of the policy keys is present.

- [ ] **Step 4: Run GREEN**

Run the same test. Expected: all policy tests pass.

### Task 2: TP1 Sizing, Atomic SL Replacement, and Persistent Arming

- [ ] **Step 1: Add the long/short JSON fixture and failing integration tests**

The fixture uses total quantity `1.0`, TP1 quantity `0.4`, and absolute offset `100.0`. Add focused tests proving:

- attached TP1 quantity is exactly `0.4` while initial SL is `1.0`;
- filling TP1 leaves position `0.6`, cancels the initial SL, and creates one reduce-only `TRIGGER` of `0.6`;
- TP1 activation emits one `trailing_stop.armed` transition and persists version, active state, watermark, offset, parent, and activation IDs;
- incomplete policy metadata fails explicitly before any order or position mutation;
- replaying the TP1 fill creates no second trailing order or event;
- restart restores the active trigger, watermark, redacted lineage, and event sequence;
- SL-first and TP1-first race orderings are deterministic;
- a forced failure while saving the trigger rolls the TP1 fill, position change, cancellation, and events back together.

- [ ] **Step 2: Run RED**

Run only `FakeTp1TrailingTest`. Expected: failures because TP remains full-size, closes the position, and no trailing trigger or lifecycle state exists.

- [ ] **Step 3: Implement policy propagation and atomic arming**

In the matching engine:

- whitelist only policy keys alongside existing lineage/fallback keys;
- invoke the policy parser during request-intent validation;
- require a non-reduce entry with both attached protections and `0 < tp1 < entry quantity`;
- create opted-in TP as suffix/kind `tp1` with exact configured quantity;
- retain full-size initial SL;
- on a completely filled TP1 with remaining position, cancel the initial SL using `tp1_replaced_by_trailing` and create a deterministic reduce-only `TRIGGER` whose metadata owns versioned state;
- do not apply the legacy partial-trigger sibling cancellation rule to an opted-in TP1 until TP1 is complete;
- derive the trigger client ID from parent and TP1 identities, validate any existing derived child, and fail on conflict;
- append `protection_order.created` and `trailing_stop.armed` for the derived trigger.

- [ ] **Step 4: Run GREEN**

Run `FakeTp1TrailingPolicyTest` and `FakeTp1TrailingTest`. Expected: policy, arming, restart, replay, race, rollback, and redaction tests pass.

### Task 3: Monotone Watermark and Duplicate Price Idempotence

- [ ] **Step 1: Write failing direction-specific tests**

For both JSON fixture cases, assert two favorable movements ratchet the watermark and stop in one direction only. Add separate tests proving an adverse movement leaves the stop unchanged and a duplicate movement leaves both state and `trailing_stop.updated` count unchanged.

- [ ] **Step 2: Run RED**

Run those focused tests. Expected: stop and watermark remain at their activation values because matching does not yet ratchet trailing orders.

- [ ] **Step 3: Implement the minimum ratchet**

Before matching open orders, inspect active versioned trailing triggers. Use `max()` for long and `min()` for short, derive the absolute-offset stop, enforce stop monotonicity, save only favorable changes, and append exactly one `trailing_stop.updated` event per changed watermark. Reject malformed persisted trailing state explicitly.

- [ ] **Step 4: Run GREEN**

Run the focused class. Expected: long/short favorable progression, no loosening, and duplicate idempotence pass.

### Task 4: Gap Trigger, Cleanup, and Single-Count Ledger

- [ ] **Step 1: Write failing terminal tests**

Move each direction through favorable prices, then gap beyond the active stop. Assert:

- fill uses the next available top-of-book price rather than the stop price;
- only the exact `0.6` remainder closes reduce-only;
- no position or protection remains;
- trigger state becomes `triggered` and one `trailing_stop.triggered` event exists;
- repeating the price/fill is a no-op;
- entry plus TP1 plus trailing produce exactly three fills;
- final close payload has `entry_qty=1.0`, `exit_qty=1.0`, `remaining_qty=0.0`, coherent quantity, complete costs, non-null model versions, and no duplicate PnL/fee booking.

- [ ] **Step 2: Run RED**

Run the terminal test filter. Expected: ordinary trigger closure may occur, but terminal trailing state/event assertions fail.

- [ ] **Step 3: Implement terminal normalization**

When a versioned trailing trigger fills, persist status `triggered` and execution price on that order and append one `trailing_stop.triggered` transition after the ordinary fill event. Continue to use the existing fill-cost and position-ledger paths; add no cost defaults.

- [ ] **Step 4: Run GREEN**

Run the full focused trailing class. Expected: gap, cleanup, replay, quantities, fees, and PnL assertions pass for long and short.

### Task 5: Golden Scenario 13

- [ ] **Step 1: Promote catalog expectations before the catalog data**

Change the catalog test expectation for `tp1_then_trailing` to `['executable', []]`, expect 16 runner keys, and add normalized expected facts for long and short terminal paths.

- [ ] **Step 2: Run RED**

Run catalog and execution tests. Expected: scenario 13 is still partial, runner keys differ, and expected facts are absent.

- [ ] **Step 3: Implement fixture catalog and runner**

Promote scenario 13, remove its gap, reference focused evidence, set runner `tp1_then_trailing`, add the runner key/match branch, load the versioned fixture, and return deterministic facts covering configured quantities, offsets, monotone stops, gap fills, cleanup, event counts, restart/replay evidence, and cost completeness.

- [ ] **Step 4: Run GREEN**

Run catalog, execution, parity, policy, and focused tests. Expected: scenario 13 executes twice identically and every golden assertion passes.

### Task 6: Documentation and Final Verification

- [ ] **Step 1: Document the implemented contract**

Update both Fake/Paper documents with policy fields, TP1 transition, long/short formulas, lifecycle names, transaction/restart behavior, duplicate behavior, gap execution, cost lineage, safety boundary, and rollback/quarantine steps. Update golden counts and remove only scenario 13 from remaining gaps.

- [ ] **Step 2: Run proportional verification in a fresh ephemeral container**

Copy this worktree exactly as prescribed and run:

- focused policy/trailing tests;
- Fake adapter, fault, contract, event, runtime, golden, and TradingCore Fake suites;
- PHPStan over every touched PHP file;
- Symfony container lint if DI wiring changed;
- JSON/YAML lint for touched fixtures/config;
- MkDocs strict;
- a repository secret/redaction scan over the diff;
- `git diff --check`.

- [ ] **Step 3: Auto-review the complete diff**

Compare every requirement in the approved design and Prompt 2 against tests and code. Inspect atomic ordering, exact remainder, state validation, client-ID conflicts, long/short monotonicity, lifecycle uniqueness, restart, rollback, costs, lineage whitelist, network isolation, and unchanged `mainnet_write_enabled: false`.

- [ ] **Step 4: Commit coherent changes without integration actions**

Create implementation and documentation commits on `issue/196-fake-tp1-trailing-v1`. Do not push, open a PR, merge, or mark the orchestration registry done.
