# Issue #308 Typed Microstructure Conditions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Feed authenticated microstructure snapshots into the strict rule evaluator and publish exact catalog version 1.2.0 with three executable spread/OFI conditions.

**Architecture:** A focused adapter converts only a verified `CanonicalMicrostructureSnapshot` into a five-second-or-shorter `RuleInputSnapshot`. Three autoconfigured condition services share a proof validator and are exposed only by the new immutable catalog version; setup execution stays blocked.

**Tech Stack:** PHP 8.4, PHPUnit 11, Symfony DI attributes, Brick Math, YAML condition contracts, PHPStan.

---

### Task 1: Canonical rule-input adapter

**Files:**
- Create: `trading-app/src/TradingCore/Microstructure/CanonicalMicrostructureRuleInputAdapter.php`
- Create: `trading-app/tests/TradingCore/Microstructure/CanonicalMicrostructureRuleInputAdapterTest.php`

- [ ] **Step 1: Write the failing nominal adapter test**

Build the existing golden snapshot, call:

```php
$input = (new CanonicalMicrostructureRuleInputAdapter())->adapt($snapshot);
```

Assert `1m`, `timestamped_order_book`, exact observed/valid-until timestamps,
finite `spread_bps=200.0`, `order_flow_imbalance=0.666666666667`, definition,
hashes and source identity.

- [ ] **Step 2: Verify RED**

Run:

```bash
php bin/phpunit tests/TradingCore/Microstructure/CanonicalMicrostructureRuleInputAdapterTest.php
```

Expected: error because the adapter class does not exist.

- [ ] **Step 3: Implement the minimal adapter**

Parse canonical UTC timestamps strictly, calculate:

```text
valid_until = min(
  book_happened_at + maximum_book_age_seconds,
  last_trade_happened_at + maximum_trade_age_seconds,
  last_trade_happened_at + maximum_trade_gap_seconds
)
```

Reject invalid/tampered snapshots and non-finite float conversions with stable
`canonical_microstructure_rule_input_*` reasons.

- [ ] **Step 4: Verify GREEN**

Run the focused test and expect PASS.

- [ ] **Step 5: Add RED/GREEN boundary cycles**

Add one failing test at a time for expired validity, forged snapshot hash,
non-canonical timestamp and an unrepresentable decimal; implement only the
guard required by each test and rerun after every change.

### Task 2: Typed condition services

**Files:**
- Create: `trading-app/src/Indicator/Condition/MicrostructureProof.php`
- Create: `trading-app/src/Indicator/Condition/SpreadBpsLteCondition.php`
- Create: `trading-app/src/Indicator/Condition/OrderFlowImbalanceGteCondition.php`
- Create: `trading-app/src/Indicator/Condition/OrderFlowImbalanceLteCondition.php`
- Create: `trading-app/tests/Indicator/Condition/MicrostructureConditionsTest.php`

- [ ] **Step 1: Write failing pass/fail threshold tests**

Instantiate each service with a context copied from the adapter values plus:

```php
['_input_source' => 'timestamped_order_book', 'timeframe' => '1m']
```

Assert inclusive boundaries: spread 8 passes max 8, OFI 0.15 passes min 0.15,
and OFI -0.15 passes max -0.15. Assert values just outside fail normally.

- [ ] **Step 2: Verify RED**

Run the new condition test and expect missing classes.

- [ ] **Step 3: Implement proof validator and services**

`MicrostructureProof::validate()` returns either the finite metric/threshold or
a metadata map containing `missing_data=true` and a stable `proof_reason`.
Each service uses `#[AsIndicatorCondition]`, `#[AutoconfigureTag]`, and
`#[AsTaggedItem]` with its exact catalog ID.

- [ ] **Step 4: Verify GREEN**

Run the focused condition test and expect PASS.

- [ ] **Step 5: Add RED/GREEN proof mutation tests**

Mutate one field per test: source, timeframe, definition, input hash, source
checksum, network, venue, market type, symbol, quantity unit, value, threshold.
Require `passed=false`, `value=null`, and `missing_data=true`; then implement the
smallest corresponding validation.

### Task 3: Catalog 1.2 and strict evaluator integration

**Files:**
- Create: `trading-app/config/trading/condition_catalog/1.2.0.yaml`
- Modify: `trading-app/src/TradingCore/Rules/Catalog/ConditionCatalogLoader.php`
- Modify: `trading-app/tests/TradingCore/Rules/Catalog/ConditionCatalogLoaderTest.php`
- Modify: `trading-app/tests/TradingCore/Rules/Catalog/ConditionCatalogRuntimeIntegrationTest.php`
- Create: `trading-app/tests/TradingCore/Microstructure/CanonicalMicrostructureRuleEvaluationTest.php`

- [ ] **Step 1: Write failing exact-version tests**

Assert loader support for `1.2.0`, immutable hashes for `1.0.0` and `1.1.0`,
and exactly these 1.2 definitions:

```text
spread_bps_lte -> condition_service:spread_bps_lte, executable
order_flow_imbalance_gte -> condition_service:order_flow_imbalance_gte, executable
order_flow_imbalance_lte -> condition_service:order_flow_imbalance_lte, executable
```

- [ ] **Step 2: Verify RED**

Run the catalog loader test and expect unsupported version 1.2.0.

- [ ] **Step 3: Publish the immutable catalog**

Copy 1.1.0 mechanically, change the version and only the three rows above,
including provenance to the new condition classes. Add 1.2.0 to the exact
loader allow-list; do not change old files.

- [ ] **Step 4: Verify catalog GREEN**

Run catalog loader tests and pin the new file SHA-256 plus stable catalog hash.

- [ ] **Step 5: Write and run failing runtime integration**

Make the DI integration iterate every supported catalog version, then write an
end-to-end strict evaluation from canonical snapshot → adapter → rule context
→ each condition. Verify missing/expired input returns strict evaluator reasons
`missing_timeframe_snapshot` or `stale_input`.

- [ ] **Step 6: Implement and verify integration GREEN**

Register via existing autoconfiguration only, update the integration loop, and
run Rules, Microstructure and condition suites.

### Task 4: Documentation and full verification

**Files:**
- Modify: `docs/handbook/technical/backtesting-engine.md`
- Modify: `docs/handbook/technical/mtf-dtos-and-trade-candidate.md`

- [ ] **Step 1: Document the strict bridge and remaining blocker**

Record catalog 1.2, adapter validity calculation, proof fields, and that no
micro setup is executable until a later version resolves order/risk/cost policy.

- [ ] **Step 2: Run fresh verification**

```bash
cd trading-app
php bin/phpunit tests/Indicator/Condition/MicrostructureConditionsTest.php tests/TradingCore/Microstructure tests/TradingCore/Rules
vendor/bin/phpstan analyse src/Indicator/Condition/MicrostructureProof.php src/Indicator/Condition/SpreadBpsLteCondition.php src/Indicator/Condition/OrderFlowImbalanceGteCondition.php src/Indicator/Condition/OrderFlowImbalanceLteCondition.php src/TradingCore/Microstructure tests/Indicator/Condition/MicrostructureConditionsTest.php tests/TradingCore/Microstructure --no-progress --memory-limit=1G
php bin/console lint:container
cd ..
python3 -m mkdocs build --strict
git diff --check
```

Expected: all commands exit 0; only the known baseline deprecation may remain.

- [ ] **Step 3: Review and commit**

Inspect the complete diff for old catalog mutation, raw/legacy scalar access,
fallbacks, private mainnet ports and unrelated changes. Commit the scoped files.

### Task 5: GitHub delivery

**Files:** no repository files.

- [ ] **Step 1: Push and open a ready PR linked to #308**

Include exact tests, stable hashes, non-goals and the next unresolved contract
lot.

- [ ] **Step 2: Request Codex review and wait for real feedback**

Ask specifically about proof bypass, validity math, catalog immutability and DI.
A thumbs-up counts as approval; do not manufacture review cycles.

- [ ] **Step 3: Address actionable feedback and merge**

Use RED/GREEN for every code correction. Merge only with green CI and no
blocking thread, update #308, fetch `origin/main`, then select the next lot.
