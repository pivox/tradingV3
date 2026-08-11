# Issue #190 Ledger-backed Certified PnL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `position_trade_analysis_v2` publish certified net PnL from exact persisted fill/cost ledger evidence.

**Architecture:** Add a read-only ledger aggregate view keyed by full trade, venue, and Paper provenance, then compose it into the existing exact FIFO analytics view. The #302 wrapper remains the final canonical-lineage gate; consumers continue reading one v2 authority.

**Tech Stack:** PHP 8.3, Symfony, Doctrine migrations/ORM, PostgreSQL JSONB and aggregate views, PHPUnit 10.

---

### Task 1: Prove the missing ledger integration

**Files:**
- Modify: `trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php`

- [ ] **Step 1: Extend the PostgreSQL fixture schema**

Create `fill_cost_ledger` in the test schema with the production identity,
quantity, price, fee, funding, spread, slippage, borrow, liquidation,
quality, and Paper provenance columns used by the migration. Add
`market_data_venue`, `exchange`, and `market_type` to indicator fixtures.

- [ ] **Step 2: Add a complete canonical long fixture**

Insert two partial entry fills and two partial exit fills for one exact
`internal_trade_id`. Use explicit USDT fees, spread/slippage costs, zero close
contract values for non-applicable other/borrow/liquidation costs, and matching
Paper provenance. Assert:

```php
self::assertSame('complete', $row['cost_completeness']);
self::assertSame('complete', $row['quantity_status']);
self::assertTrue((bool) $row['position_fully_closed']);
self::assertEqualsWithDelta(2.0, (float) $row['entry_qty'], 1e-8);
self::assertEqualsWithDelta(2.0, (float) $row['exit_qty'], 1e-8);
self::assertEqualsWithDelta(0.0, (float) $row['remaining_qty'], 1e-8);
self::assertNotNull($row['net_pnl_usdt']);
self::assertNotNull($row['canonical_net_pnl_usdt']);
self::assertStringNotContainsString('ledger_quantity_aggregate_missing', (string) $row['pnl_quality_flags']);
```

- [ ] **Step 3: Add fail-closed fixtures**

Add focused rows proving that missing exit fills, a missing fee, quantity
mismatch, a ledger quality flag, wrong market-data venue, wrong Paper cell, and
canonical entry/close lineage mismatch each leave canonical net PnL null with a
stable quality flag.

- [ ] **Step 4: Run the focused test and verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/Trading/View/PositionTradeAnalysisViewTest.php
```

Expected: the complete-ledger assertion fails because the current view always
emits `ledger_quantity_aggregate_missing` and `net_pnl_usdt` is null.

- [ ] **Step 5: Commit the red test**

```bash
git add trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php
git commit -m "test(#190): require ledger-backed certified PnL"
```

### Task 2: Aggregate exact ledger evidence

**Files:**
- Create: `trading-app/migrations/Version20260811120000.php`
- Test: `trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php`

- [ ] **Step 1: Create `position_trade_ledger_aggregate_v1`**

The migration creates a read-only view grouped by:

```sql
internal_trade_id,
exchange,
market_type,
symbol,
market_data_venue,
paper_execution_cell_id,
configuration_snapshot_id,
paper_network
```

Use filtered aggregates for `entry` and `exit` roles. Ignore rows whose
`quality_flags` contain `fill_cancelled`, `fill_corrected`, `fill_reversed`, or
`voided`; all other non-empty quality flags set `ledger_quality_valid=false`.
Expose entry/exit first and last timestamps, quantity, notional, VWAP, per-role
fees, total funding, spread, slippage, borrow and liquidation costs, fill counts,
side cardinality, and explicit-component counts.

The quantity state is exactly:

```sql
CASE
  WHEN entry_fill_count = 0 THEN 'missing_entry_fill'
  WHEN exit_fill_count = 0 THEN 'open_position'
  WHEN invalid_quantitative_fill_count > 0 THEN 'invalid_fill_quantity'
  WHEN exit_qty - entry_qty > 0.00000001 THEN 'quantity_mismatch'
  WHEN abs(entry_qty - exit_qty) <= 0.00000001 THEN 'complete'
  ELSE 'partial_exit'
END
```

- [ ] **Step 2: Require explicit cost evidence**

For entry/exit fills, every fee must be non-null and normalized to USDT, and
every spread/slippage cost must be explicitly non-null and non-negative.
Funding is summed with its persisted sign. Borrow and liquidation costs are
summed when ledger settlement rows exist; otherwise the composed view may use
only explicit close-contract values. Never `COALESCE` missing applicability to
zero.

- [ ] **Step 3: Run the focused test**

```bash
cd trading-app
php bin/phpunit tests/Trading/View/PositionTradeAnalysisViewTest.php
```

Expected: still FAIL because the public v2 surface does not yet consume the
aggregate; the helper view assertions pass.

- [ ] **Step 4: Commit the aggregate**

```bash
git add trading-app/migrations/Version20260811120000.php trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php
git commit -m "feat(#190): aggregate exact fill cost ledger evidence"
```

### Task 3: Compose the ledger into the canonical analytics view

**Files:**
- Modify: `trading-app/migrations/Version20260811120000.php`
- Modify: `trading-app/src/Trading/Entity/PositionTradeAnalysisV2.php`
- Modify: `trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php`

- [ ] **Step 1: Preserve and replace the underlying view**

Drop the #302 wrapper, rename `position_trade_analysis_v2_legacy_source` to
`position_trade_analysis_v2_pre_ledger`, and create a new
`position_trade_analysis_v2_legacy_source`. Join the ledger aggregate by every
identity field with `IS NOT DISTINCT FROM` for nullable provenance. Join the
entry and close lifecycle rows to validate matching structured lineage.

Use `jsonb_populate_record` against the pre-ledger row type to preserve the
existing column contract while replacing:

```text
gross_realized_pnl_usdt
entry_fee_usdt
exit_fee_usdt
other_trading_fees_usdt
funding_usdt
spread_cost_usdt
slippage_cost_usdt
borrow_cost_usdt
liquidation_fee_usdt
total_known_cost_usdt
net_pnl_usdt
realized_gross_pnl_r
realized_net_pnl_r
position_fully_closed
pnl_source
pnl_quality_flags
cost_completeness
```

Append `entry_first_fill_at`, `entry_last_fill_at`, `entry_qty`, `entry_vwap`,
`exit_first_fill_at`, `exit_last_fill_at`, `exit_qty`, `exit_vwap`,
`remaining_qty`, and `quantity_status`.

- [ ] **Step 2: Calculate gross and net fail-closed**

Derive gross from fill notionals and canonical side:

```sql
CASE canonical_side
  WHEN 'long' THEN exit_notional - entry_notional
  WHEN 'short' THEN entry_notional - exit_notional
END
```

Publish net only when quantity is complete, the close matches, ledger and Paper
identity match, costs are explicit, sides are coherent, and structured entry and
close lineage agree. Funding is added; all other costs are subtracted.

- [ ] **Step 3: Recreate the #302 wrapper**

Recreate `position_trade_analysis_v2` with the exact canonical columns and
`lineage_classification` rules from `Version20260808114000`. Keep
`canonical_net_pnl_usdt` and `canonical_realized_net_pnl_r` masked unless the
classification is `canonical`.

- [ ] **Step 4: Map fill aggregate fields**

Add nullable Doctrine read-only properties and getters for the ten new fill
aggregate columns. Keep `hasCertifiedNetPnl()` unchanged: exact close, complete
costs, canonical lineage, and non-null net remain mandatory.

- [ ] **Step 5: Run focused view, repository, reporting and outcome tests**

```bash
cd trading-app
php bin/phpunit \
  tests/Trading/View/PositionTradeAnalysisViewTest.php \
  tests/Trading/Reporting/PositionTradeAnalysisReportingServiceTest.php \
  tests/Trading/Service/RunTradeOutcomeServiceTest.php
```

Expected: PASS, including a complete ledger row visible as certified and every
incomplete fixture still masked.

- [ ] **Step 6: Commit the composition**

```bash
git add trading-app/migrations/Version20260811120000.php trading-app/src/Trading/Entity/PositionTradeAnalysisV2.php trading-app/tests/Trading/View/PositionTradeAnalysisViewTest.php
git commit -m "feat(#190): certify v2 net PnL from persisted ledger"
```

### Task 4: Remove the second certification authority and verify

**Files:**
- Modify: `docs/handbook/reports/queries/bad-trades-baseline-v2.sql`
- Modify: `docs/handbook/technical/certified-net-pnl-contract.md`
- Modify: `docs/superpowers/plans/2026-08-11-issue-190-ledger-certified-pnl.md`

- [x] **Step 1: Remove export-side ledger recertification**

Make the bad-trades baseline query consume `canonical_net_pnl_usdt`,
`canonical_realized_net_pnl_r`, `cost_completeness`, and
`lineage_classification` directly from `position_trade_analysis_v2`. Delete its
independent fill aggregation and certification predicate.

- [x] **Step 2: Update the contract documentation**

Document the exact aggregate identity, component applicability rules, new fill
fields, stable quality flags, and that real providers remain partial/unknown
until they persist complete normalized cost evidence.

- [x] **Step 3: Run the required focused verification**

```bash
cd trading-app
php bin/phpunit tests/Trading/View/PositionTradeAnalysisViewTest.php
php bin/phpunit tests/Trading/Reporting/PositionTradeAnalysisReportingServiceTest.php
php bin/phpunit tests/Trading/Service/RunTradeOutcomeServiceTest.php
vendor/bin/phpstan analyse --no-progress --memory-limit=1G \
  src/Trading/Pnl \
  src/Trading/Entity/PositionTradeAnalysisV2.php \
  src/Trading/Reporting \
  src/Trading/Service/RunTradeOutcomeService.php
php -l migrations/Version20260811120000.php
php -l src/Trading/Entity/PositionTradeAnalysisV2.php
php -l tests/Trading/View/PositionTradeAnalysisViewTest.php
```

Result on `0d02ce7a`: 48 tests / 586 assertions, 8 / 51 and 11 / 83;
targeted PHPStan and PHP syntax checks pass. A disposable PostgreSQL database
migrated from scratch through 50 migrations / 973 queries, and the integration
test covers the targeted migration down/up round-trip. The export SQL executed
successfully against that schema.

Separate follow-up: the broader 4,093-test command remains an environment-level
suite follow-up. Its attempted run hit Symfony/PHPUnit child-process temporary
file races (12 failures) and two repository schema-order errors outside this
three-file documentation patch; no failing assertion involved the focused #190
suites above.

- [x] **Step 4: Check the patch**

```bash
git diff --check origin/main...HEAD
git status --short
```

Expected: no whitespace errors and only intended #190 files changed.

- [x] **Step 5: Commit documentation**

```bash
git add docs/handbook/reports/queries/bad-trades-baseline-v2.sql docs/handbook/technical/certified-net-pnl-contract.md docs/superpowers/plans/2026-08-11-issue-190-ledger-certified-pnl.md
git commit -m "docs(#190): make v2 the single certified PnL authority"
```
