# Lot2A Early Rejection and Intent Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reject canonical policy gaps before MTF providers and enforce structured intent identity through retry and execution synchronization.

**Architecture:** `TradingDecisionHandler` converts only typed canonical-policy exceptions into a stable rejection payload, while Messenger explicitly acknowledges that payload. `TradeLineageManager` uses `OrderIntent::getIntentId()` and typed lineage exceptions; `ExecuteOrderPlan` propagates those exceptions at every sync boundary.

**Tech Stack:** PHP 8.4, Symfony Messenger, Doctrine ORM, PHPUnit 11, PHPStan.

---

### Task 1: Stable early MTF rejection

**Files:**
- Modify: `trading-app/src/MtfValidator/Service/TradingDecisionHandler.php`
- Modify: `trading-app/src/MtfValidator/MessageHandler/MtfTradingDecisionMessageHandler.php`
- Create: `trading-app/tests/MtfValidator/Service/CanonicalPolicyRejectionTest.php`

- [x] Add tests whose modern snapshot returns the exact ordered blocker payload headed by `canonical_risk_pct_pending_304`; require zero indicator, request-builder, and trade-entry calls.
- [x] Add a Messenger test proving the same rejection is explicitly acknowledged and the handler returns normally.
- [x] Run the focused tests and confirm provider/downstream calls currently occur or the policy exception escapes.
- [x] Invoke `CanonicalTradeRuntimePolicyValidator::assertReady()` immediately after canonical config resolution; catch only `CanonicalRuntimePolicyException`, log/audit it, and return `status=rejected`, `reason`, and `blockers`.
- [x] Teach Messenger to recognize the stable rejection payload, log the acknowledgement, and return without throwing.
- [x] Re-run the focused tests and confirm synchronous/Messenger parity.

### Task 2: Structured retry intent identity

**Files:**
- Modify: `trading-app/src/Trading/Lineage/TradeLineageManager.php`
- Modify: `trading-app/src/TradeEntry/Workflow/ExecuteOrderPlan.php`
- Modify: `trading-app/tests/Trading/Lineage/TradeLineageManagerTest.php`
- Modify: `trading-app/tests/Trading/Lineage/ExecuteOrderPlanLineageSyncTest.php`

- [x] Add retry tests for missing persisted/requested structured intent ID, exact equality despite a different Doctrine PK, and exact mismatch.
- [x] Add pre-submit, post-execution, and intent-status sync tests expecting typed `LineageContextException` propagation before further execution/status work.
- [x] Run focused tests and confirm the numeric PK comparison and broad catches fail the new expectations.
- [x] Compare `OrderIntent::getIntentId()` with `LineageContext::intentId`, require both values for modern retry, and emit typed incomplete/mismatch exceptions.
- [x] In each ExecuteOrderPlan sync catch, immediately rethrow `LineageContextException`; preserve existing warning-only behavior for other exceptions.
- [x] Re-run focused lineage/execution tests.

### Task 3: Verification and commit

**Files:**
- Modify: `docs/superpowers/plans/2026-08-01-lot2a-early-rejection-intent-identity.md`

- [x] Run focused PHPUnit, focused PHPStan, PHP lint, and `git diff --check`.
- [x] Inspect the final diff for zero provider/exchange calls before rejection and no Doctrine PK canonical comparison.
- [x] Commit with a scoped Lot2A message and preserve the worktree.
