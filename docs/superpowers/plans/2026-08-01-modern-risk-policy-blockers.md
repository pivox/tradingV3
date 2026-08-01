# Modern Risk Policy Blockers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reject every modern trade before provider or order work while #304-owned risk and fallback policies lack canonical runtime consumers.

**Architecture:** Add a focused typed-policy validator used at the preparation/service boundary, with stable ordered blocker metadata. Retain local builder, leverage, daily-loss, and end-of-zone guards so direct callers cannot re-enter legacy defaults.

**Tech Stack:** PHP 8.4, PHPUnit 11, PHPStan, Symfony trade-entry services.

---

### Task 1: Local risk and daily-loss guards

**Files:**
- Modify: `trading-app/tests/TradeEntry/CanonicalRiskCapPropagationTest.php`
- Modify: `trading-app/tests/TradeEntry/CanonicalTradeIdentityTest.php`
- Modify: `trading-app/src/TradeEntry/Builder/TradeEntryRequestBuilder.php`
- Modify: `trading-app/src/TradeEntry/Service/Leverage/DynamicLeverageService.php`
- Modify: `trading-app/src/TradeEntry/Policy/DailyLossGuard.php`

- [x] Add tests asserting exact `canonical_risk_pct_pending_304` from modern builder/leverage and `canonical_daily_loss_policy_pending_304` from modern daily guard, with uninitialized legacy dependencies proving no resolver/provider read.
- [x] Run the focused tests and confirm failures expose the current 2%/5% defaults and partial absolute-cap enforcement.
- [x] Add minimal explicit canonical-call guards; retain legacy defaults when no typed canonical config is supplied.
- [x] Re-run the focused tests and confirm they pass.

### Task 2: Ordered modern preflight blockers

**Files:**
- Create: `trading-app/src/TradeEntry/Policy/CanonicalTradeRuntimePolicyValidator.php`
- Create: `trading-app/src/Trading/Lineage/CanonicalRuntimePolicyException.php`
- Create: `trading-app/tests/TradeEntry/Policy/CanonicalTradeRuntimePolicyValidatorTest.php`
- Modify: `trading-app/src/TradeEntry/Service/TradeEntryPreparationService.php`
- Modify: `trading-app/src/TradeEntry/Service/TradeEntryService.php`

- [x] Add tests expecting ordered blocker rows for risk percent, daily loss, max concurrent positions, exposure cap, and minimum net R; assert preflight/planner/provider mocks are never called.
- [x] Run tests and confirm failure because the validator and early boundary do not exist.
- [x] Implement an immutable exception carrying `list<array{code:string,path:string}>` and a validator that reads the typed config without calculating policy values.
- [x] Invoke the validator immediately after canonical config construction and before daily guard/preflight/provider calls; leave legacy flow unchanged.
- [x] Re-run tests and confirm the exact blocker order and zero-call assertions.

### Task 3: Disable unowned end-of-zone fallback

**Files:**
- Modify: `trading-app/src/Trading/Lineage/CanonicalTradeEntryConfigFactory.php`
- Modify: `trading-app/src/TradeEntry/Service/TradeEntryPreparationService.php`
- Modify: `trading-app/src/TradeEntry/Service/TradeEntryService.php`
- Modify: `trading-app/tests/TradeEntry/Policy/CanonicalTradeRuntimePolicyValidatorTest.php`

- [x] Add a failing test that modern typed config returns an explicitly disabled fallback and that any supplied LIMIT-to-MARKET decision raises `canonical_end_of_zone_fallback_pending_304` instead of being swallowed.
- [x] Add `fallback_end_of_zone.enabled=false` to the modern view and branch rewrite handling by contract kind; do not alter legacy catch behavior.
- [x] Run focused tests and confirm modern rewrite rejection and legacy fallback regressions remain green.

### Task 4: Documentation and verification

**Files:**
- Modify: `docs/superpowers/specs/2026-08-01-modern-risk-policy-blockers-design.md`
- Modify: `docs/handbook/technical/adr-310-crash-short-operational-classification.md`

- [x] State that numeric policies are propagated but deliberately unapplied until #304 and Lot2B is excluded.
- [x] Run focused PHPUnit, PHPStan, PHP lint, `git diff --check`, and inspect the final diff.
- [x] Commit the implementation with a scoped message.
