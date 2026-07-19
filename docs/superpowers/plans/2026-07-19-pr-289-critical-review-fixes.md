# PR #289 Critical Review Fixes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:executing-plans` to implement this plan inline task-by-task.
> Do not delegate; the repository instructions assign this review correction
> to the current worker.

**Goal:** Correct the three applicable critical review findings on PR #289 by
preserving exact liquidation-fee decimals in certified consumers and by making
liquidation order identity stable per persisted position opening.

**Architecture:** Existing liquidation events remain the single monetary fact.
Ledger ingestion prioritizes their exact decimal string while preserving the
legacy float projection only as a compatibility fallback; the Daily loss cap
requires an exact non-float value and fails closed otherwise. Each new Fake
position receives a deterministic identity derived from its first persisted
opening order ID; liquidation identity combines that position identity with the
versioned calculation identity, so restart/replay is stable while a later
position cannot collide.

**Tech Stack:** PHP 8.4, Symfony 7.1, PHPUnit 11, Brick Math, Psr Clock, existing
Fake/Paper checksum state envelope and atomic transaction boundary.

---

### Task 1: Preserve the exact liquidation fee in fill-cost ledger ingestion

**Files:**

- Modify: `trading-app/tests/Trading/Pnl/FillCostLedgerLiquidationTest.php`
- Modify: `trading-app/src/Trading/Pnl/FillCostLedgerIngestionService.php`

- [x] **Step 1: Write the failing regression test**

  Add a fill whose metadata contains the exact string
  `liquidation_fee_decimal=10159.600000000001` and the rounded float projection
  `liquidation_fee_usdt=10159.6`. Assert the DECIMAL ledger field is exactly
  `10159.600000000001`, and replay remains exact-once.

- [x] **Step 2: Run RED**

  Run:

  ```bash
  vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php \
    --do-not-cache-result \
    tests/Trading/Pnl/FillCostLedgerLiquidationTest.php
  ```

  Expected: the new assertion fails with stored value
  `10159.600000000000`, proving the float cast is the precision-loss boundary.

- [x] **Step 3: Implement the minimal exact-decimal selection**

  In `liquidationFeeUsdt()`, select exact decimal keys before
  `liquidation_fee_usdt`, validate non-negativity with `BigDecimal`, normalize
  to scale 12 with `HALF_EVEN`, and retain the current float projection only
  when no exact key exists. An invalid present exact key must fail closed rather
  than silently fall back.

- [x] **Step 4: Run GREEN**

  Re-run the focused ledger test and retain the replay/conflict assertions.

### Task 2: Consume the exact liquidation fee in the Daily loss cap

**Files:**

- Modify: `trading-app/tests/Exchange/Fake/FakeDailyLossCapGuardTest.php`
- Modify: `trading-app/src/Exchange/Fake/FakeDailyLossCapGuard.php`

- [x] **Step 1: Write failing threshold and legacy tests**

  Add a liquidation event with a rounded float projection and the exact
  `10159.600000000001` string. Set the daily limit so that the exact last
  decimal determines whether the cap is reached, and assert the exact daily
  net. Add a float-only liquidation event and assert `not_computable`, proving
  legacy uncertainty fails closed.

- [x] **Step 2: Run RED**

  Run the focused Daily loss cap test file. Expected: the threshold assertion
  exposes the rounded float path and/or the float-only event is accepted.

- [x] **Step 3: Implement exact-only liquidation fee parsing**

  Prefer `liquidation_fee_decimal`/`liquidation_fee_usdt_decimal`; accept the
  legacy `liquidation_fee_usdt` only when its runtime value is an exact string
  or integer. Reject float-only liquidation facts as
  `liquidation_fee_exact_unknown`. Keep all other fill components unchanged.

- [x] **Step 4: Run GREEN**

  Re-run the focused test file and confirm the monetary event remains counted
  once and `position.closed` remains excluded.

### Task 3: Persist a collision-free position liquidation identity

**Files:**

- Modify: `trading-app/tests/Exchange/Fake/FakeLiquidationIntegrationTest.php`
- Modify: `trading-app/tests/Exchange/Readiness/FakeRuntimeCheckTest.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/src/Provider/Fake/FakeRuntimeCheck.php`

- [x] **Step 1: Write the failing restart/reopen regression test**

  With a fixed clock and filesystem state, open and liquidate a position,
  restart, replay the same mark and assert one unchanged liquidation identity,
  then reopen the same symbol/side/quantity/entry conditions and liquidate at
  the same mark. Assert the second persisted position identity, liquidation
  identity and liquidation client order ID differ, and two liquidations exist.

- [x] **Step 2: Write the failing legacy fail-closed test**

  Remove the persisted position identity from an otherwise valid open
  position. Assert runtime readiness blocks it and liquidation evaluation
  throws the stable unknown-identity reason without changing mark, position,
  orders, events, balance or sequence.

- [x] **Step 3: Run RED**

  Run the liquidation integration and readiness files. Expected: the reopened
  liquidation collides under the current symbol/time/calculation hash and the
  runtime does not detect the missing position identity.

- [x] **Step 4: Implement deterministic persisted identity**

  On the first opening fill, derive `liquidation_position_identity` from the
  persisted opening `exchangeOrderId` plus the model version. Preserve it on
  same-side increases and reductions. Require it for evaluation and runtime
  readiness. Build `liquidation_identity` and `fake-liq-*` from that identity,
  the calculation identity and model version only; use no symbol/time fallback.

- [x] **Step 5: Run GREEN and atomicity regressions**

  Re-run both focused files plus the existing rollback, guard, protection and
  replay tests.

### Task 4: Audit, verify and deliver on the existing PR branch

**Files:**

- Verify every PHP file modified above and this plan.
- Preserve unchanged:
  `TradingV3_OKX_Hyperliquid_demo_testnet_prompts_canoniques_UNIQUE.md`

- [x] **Step 1: Audit adjacent float/double-counting paths**

  Search all producers and consumers of liquidation fee and identity. Confirm
  the exact event field reaches REST/WS ledger ingestion, Daily loss folds only
  `liquidation.filled`, and `position.closed` is not counted again. Make no
  unrelated cost-model refactor.

- [x] **Step 2: Run requested verification**

  Run focused PHPUnit, all relevant Fake suites, PHPStan on every touched PHP,
  `php -l` on every touched PHP, container/YAML lint if dependency/config files
  changed, and `git diff --check`. Never load `trading-app/.env.test`.

- [ ] **Step 3: Review scope and commit**

  Verify the canonical Prompt 8 registry and issue #196 are unchanged. Review
  the complete diff, then commit all review corrections with one factual
  message.

- [ ] **Step 4: Push and prove delivery identity**

  Push normally without force to `issue/196-fake-liquidation-v1`. Verify local
  `HEAD`, `origin/issue/196-fake-liquidation-v1`, and PR #289 `headRefOid` are
  identical. Do not reply to or resolve review threads, request review, merge,
  or change issue #196.
