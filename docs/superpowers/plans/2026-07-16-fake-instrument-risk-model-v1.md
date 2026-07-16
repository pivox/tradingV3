# Fake Instrument Risk Model v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add deterministic Fake/Paper instrument metadata, exact precision validation, balance/margin checks, and persistent per-symbol leverage for issue #196.

**Architecture:** A versioned catalog and exact validator sit in front of the existing matching engine. Margin availability is derived from persisted active orders and positions, while leverage settings are an additive persisted state field. Legacy providers and runtime readiness read from the same model.

**Tech Stack:** PHP 8.4, Symfony DI, Brick Math, PHPUnit, PHPStan, versioned serialized Fake state.

---

### Task 1: Versioned instrument catalog and exact validation

**Files:**
- Create: `trading-app/src/Exchange/Fake/FakeInstrument.php`
- Create: `trading-app/src/Exchange/Fake/FakeInstrumentCatalog.php`
- Create: `trading-app/src/Exchange/Fake/FakeOrderValidator.php`
- Create: `trading-app/tests/Exchange/Fake/FakeInstrumentCatalogTest.php`
- Create: `trading-app/tests/Exchange/Fake/FakeOrderValidatorTest.php`

- [ ] Write catalog tests asserting the complete BTCUSDT/ETHUSDT fixture shape and model versions.
- [ ] Run the tests and verify they fail because the catalog classes do not exist.
- [ ] Implement immutable instrument definitions with decimal strings and exact Brick Math multiple checks.
- [ ] Write validator tests for unknown symbol, market, order type, price/stop/quantity precision, minimum quantity/notional, leverage cap, margin mode, and no implicit rounding.
- [ ] Run the validator tests and verify the missing validator failure.
- [ ] Implement ordered, stable validation results without mutating request values.
- [ ] Run both test files and verify they pass.

### Task 2: Matching-engine rejection and derived margin

**Files:**
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeStateStore.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php`

- [ ] Add failing adapter tests proving structured rejects, no order ID reuse ambiguity, insufficient balance, margin consumption, and margin release after cancel.
- [ ] Run the focused tests and verify the first new assertion fails for missing validation.
- [ ] Add state helpers that derive used/available margin from active orders and positions.
- [ ] Inject the validator into the matching engine and persist a rejected order plus `order.rejected` event for invalid requests.
- [ ] Expose derived balance metadata from the adapter without duplicating mutable accounting state.
- [ ] Run the focused adapter tests and existing contract tests until green.

### Task 3: Persistent per-symbol leverage

**Files:**
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeStateStore.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php`

- [ ] Add failing tests for unknown symbols, unsupported margin modes, leverage caps, successful updates, and restart recovery.
- [ ] Run the focused tests and verify leverage is currently accepted without persistence.
- [ ] Persist an additive `leverageSettings` map, default missing legacy/version-1 payload fields safely, and include it in transactional snapshots.
- [ ] Make `setLeverage()` validate through the catalog and persist the accepted setting.
- [ ] Run adapter and state recovery tests until green.

### Task 4: Operational providers and readiness

**Files:**
- Modify: `trading-app/src/Provider/Fake/FakeContractProvider.php`
- Modify: `trading-app/src/Provider/Fake/FakeAccountProvider.php`
- Modify: `trading-app/src/Provider/Fake/FakeOrderProvider.php`
- Modify: `trading-app/src/Provider/Fake/FakeRuntimeCheck.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/tests/Provider/Fake/FakeProvidersTest.php`
- Modify: `trading-app/tests/Exchange/Readiness/FakeRuntimeCheckTest.php`
- Modify: `trading-app/config/services.yaml`

- [ ] Replace empty-provider expectations with failing tests for catalog, balance, order-book, and leverage reads/delegation.
- [ ] Add failing readiness assertions for loaded metadata and precision versions.
- [ ] Wire providers to catalog/state/adapter and map existing provider DTOs without exposing raw state payloads.
- [ ] Report non-null model versions from the adapter and preserve all dry-run/live guards.
- [ ] Run provider, registry, controller, and runtime-check tests until green.

### Task 5: Golden scenarios and documentation

**Files:**
- Modify: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`
- Create: `trading-app/docs/exchange/fake-instrument-risk-model-v1.md`

- [ ] Add failing catalog assertions that scenarios 6-8 are executable with explicit evidence methods.
- [ ] Implement deterministic runners for insufficient balance, precision reject, and leverage cap reject.
- [ ] Verify each scenario twice from fresh state and compare exact normalized evidence.
- [ ] Document fixtures, rejection reasons, margin formula, persistence compatibility, unsupported daily-loss/liquidation gaps, and rollback.

### Task 6: Verification and delivery

**Files:**
- Review all files changed by Tasks 1-5.

- [ ] Run focused PHPUnit suites for Fake adapter, providers, readiness, recovery, and golden scenarios.
- [ ] Run the complete Exchange PHPUnit suite.
- [ ] Run PHPStan on every touched PHP file.
- [ ] Run `php bin/console lint:container --no-debug` and `php bin/console lint:yaml config`.
- [ ] Run strict documentation build and `git diff --check`.
- [ ] Review the diff for secrets, network access, live mutations, implicit rounding, and unrelated changes.
- [ ] Commit only intended files, check the current Codex five-hour quota, push, and open the issue-linked PR.
