# Fake Slippage Cost Model v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Fake/Paper's zero additional slippage placeholder with a deterministic 5 bps taker cost that is propagated through fills, the persistent ledger, close evidence, runtime readiness, and golden scenario 5.

**Architecture:** A small immutable `FakeFillCostModel` owns liquidity classification and monetary calculations. The matching engine records its output once per fill, while REST/WS normalizers and ledger ingestion only transport and validate the explicit metadata. Position metadata aggregates per-fill costs so close evidence subtracts slippage exactly once.

**Tech Stack:** PHP 8.4, Symfony, PHPUnit 11, PHPStan, Brick Math-compatible decimal persistence, existing Fake/Paper state and fill-cost ledger.

---

### Task 1: Add the deterministic fill-cost model

**Files:**
- Create: `trading-app/src/Exchange/Fake/FakeFillCost.php`
- Create: `trading-app/src/Exchange/Fake/FakeFillCostModel.php`
- Create: `trading-app/tests/Exchange/Fake/FakeFillCostModelTest.php`

- [ ] **Step 1: Write failing arithmetic and classification tests**

Cover post-only maker, market taker, crossing-limit taker, protection taker,
contract size scaling, and invalid non-positive inputs. The core assertion is:

```php
$cost = (new FakeFillCostModel())->forFill(
    quantity: 2.0,
    price: 100.0,
    contractSize: 3.0,
    postOnly: false,
);

self::assertSame('taker', $cost->liquidityRole);
self::assertSame(0.3, $cost->slippageCostUsdt);
self::assertSame(0.0, $cost->spreadCostUsdt);
```

- [ ] **Step 2: Run the new test and verify RED**

Run:

```bash
cd trading-app
php vendor/bin/phpunit tests/Exchange/Fake/FakeFillCostModelTest.php
```

Expected: failure because `FakeFillCostModel` does not exist.

- [ ] **Step 3: Implement the minimal immutable model**

Expose:

```php
final readonly class FakeFillCostModel
{
    public const MODEL_VERSION = 'fixed_adverse_slippage_bps_v1';
    public const SPREAD_MODEL_VERSION = 'top_of_book_embedded_spread_v1';
    public const TAKER_SLIPPAGE_BPS = 5.0;

    public function forFill(
        float $quantity,
        float $price,
        float $contractSize,
        bool $postOnly,
    ): FakeFillCost;
}
```

`FakeFillCost` contains `liquidityRole`, `spreadCostUsdt`,
`slippageCostUsdt`, `modelVersion`, and `spreadModelVersion`.

- [ ] **Step 4: Run the test and verify GREEN**

Run the same PHPUnit command. Expected: all tests pass.

- [ ] **Step 5: Commit**

```bash
git add trading-app/src/Exchange/Fake/FakeFillCost.php \
  trading-app/src/Exchange/Fake/FakeFillCostModel.php \
  trading-app/tests/Exchange/Fake/FakeFillCostModelTest.php
git commit -m "feat(fake): add deterministic fill cost model (#196)"
```

### Task 2: Record and aggregate costs in the matching engine

**Files:**
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php`

- [ ] **Step 1: Add failing fill and close-evidence tests**

Add tests proving:

- a market fill keeps the current ask/bid execution price;
- taker metadata reports 5 bps slippage and zero additional spread;
- a post-only maker fill reports explicit zero slippage;
- partial fills aggregate costs per fill;
- contract size scales slippage;
- full-close `recorded_pnl_usdt` subtracts entry/exit fees and total slippage.

Expected close formula:

```php
$expected = $gross
    - $entryFee
    - $exitFee
    - $entrySlippage
    - $exitSlippage;
```

- [ ] **Step 2: Run focused tests and verify RED**

```bash
cd trading-app
php vendor/bin/phpunit tests/Exchange/Adapter/FakeExchangeAdapterTest.php \
  --filter 'Slippage|FillCosts|Certified'
```

Expected: missing slippage metadata or unchanged recorded PnL.

- [ ] **Step 3: Inject and apply `FakeFillCostModel`**

Calculate cost once in `fillOrder()`, add its fields to the fill event, and pass
the same cost to entry/exit ledger helpers. Aggregate:

```php
'entry_slippage_cost_usdt' => previous + $cost->slippageCostUsdt,
'exit_slippage_cost_usdt' => previous + $cost->slippageCostUsdt,
'spread_cost_usdt' => 0.0,
```

Expose total slippage and model versions in `certifiedClosePayload()`. Keep fill
prices unchanged.

- [ ] **Step 4: Publish runtime metadata from the same model**

Replace the zero-slippage literal in `FakeExchangeAdapter::runtimeModelMetadata()`
with the model constants and add `slippage_bps` plus `spread_model`.

- [ ] **Step 5: Run adapter tests and verify GREEN**

```bash
php vendor/bin/phpunit tests/Exchange/Adapter/FakeExchangeAdapterTest.php
```

- [ ] **Step 6: Commit**

```bash
git add trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php \
  trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php \
  trading-app/tests/Exchange/Adapter/FakeExchangeAdapterTest.php
git commit -m "feat(fake): propagate fill slippage costs (#196)"
```

### Task 3: Preserve cost parity through REST, WS, and the ledger

**Files:**
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeEventNormalizer.php`
- Modify: `trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php`
- Modify: `trading-app/src/Trading/Pnl/FillCostLedgerIngestionService.php`
- Modify: `trading-app/tests/Exchange/Event/FakeExchangeEventNormalizerTest.php`
- Modify: `trading-app/tests/Trading/Pnl/FillCostLedgerIngestionServiceTest.php`

- [ ] **Step 1: Add failing REST/WS parity tests**

Assert both normalized WS and REST snapshots expose:

```php
$expectedSlippage = $fill->quantity * $fill->price * 5.0 / 10_000.0;

self::assertSame('taker', $fill->metadata['liquidity_role']);
self::assertSame(0.0, $fill->metadata['spread_cost_usdt']);
self::assertEqualsWithDelta(
    $expectedSlippage,
    $fill->metadata['slippage_cost_usdt'],
    0.000000000001,
);
self::assertSame(
    'fixed_adverse_slippage_bps_v1',
    $fill->metadata['cost_model_version'],
);
```

Also assert `spread_model_version=top_of_book_embedded_spread_v1`.

- [ ] **Step 2: Run focused tests and verify RED**

```bash
php vendor/bin/phpunit \
  tests/Exchange/Event/FakeExchangeEventNormalizerTest.php \
  tests/Trading/Pnl/FillCostLedgerIngestionServiceTest.php
```

Expected: metadata keys absent and ledger cost columns `NULL`.

- [ ] **Step 3: Copy explicit event costs into `ExchangeFillDto` metadata**

Both REST and WS mapping must use the same payload keys. Do not recompute costs
in normalizers.

- [ ] **Step 4: Ingest only valid explicit costs**

Add a helper that returns `NULL` when absent, stores finite non-negative decimal
values when present, and adds:

```text
spread_cost_invalid
slippage_cost_invalid
```

for invalid values. Keep other providers' missing values `NULL`.

- [ ] **Step 5: Prove replay and conflict behavior**

Test exact replay remains idempotent, while the same exchange fill ID with a
different slippage payload raises `FillCostLedgerIngestionConflict`.

- [ ] **Step 6: Run focused tests and verify GREEN**

Run the command from Step 2.

- [ ] **Step 7: Commit**

```bash
git add trading-app/src/Exchange/Fake/FakeExchangeEventNormalizer.php \
  trading-app/src/Exchange/Adapter/FakeExchangeAdapter.php \
  trading-app/src/Trading/Pnl/FillCostLedgerIngestionService.php \
  trading-app/tests/Exchange/Event/FakeExchangeEventNormalizerTest.php \
  trading-app/tests/Trading/Pnl/FillCostLedgerIngestionServiceTest.php
git commit -m "feat(fake): persist explicit slippage costs (#196)"
```

### Task 4: Make runtime and golden scenario 5 executable

**Files:**
- Modify: `trading-app/src/Provider/Fake/FakeRuntimeCheck.php`
- Modify: `trading-app/tests/Exchange/Readiness/FakeRuntimeCheckTest.php`
- Modify: `trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php`
- Modify: `trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php`

- [ ] **Step 1: Add failing runtime assertions**

Assert the new model/version/rate are present and
`fake_paper_slippage_model_zero` is absent. A malformed, zero, or unsupported
runtime model must add `fake_paper_slippage_model_not_ready`.

- [ ] **Step 2: Add failing golden catalog assertions**

Change `market_with_slippage` to:

```json
{
  "gap_codes": [],
  "runner": "market_with_slippage",
  "status": "executable"
}
```

Update catalog expectations and require deterministic positive cost evidence.

- [ ] **Step 3: Run runtime and golden tests and verify RED**

```bash
php vendor/bin/phpunit \
  tests/Exchange/Readiness/FakeRuntimeCheckTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php
```

- [ ] **Step 4: Implement runtime validation and golden runner**

The runner submits one market order, reads its fill, and returns normalized
price, notional, liquidity role, basis points, spread cost, slippage cost, and
model versions. It runs only against fresh in-memory Fake state.

- [ ] **Step 5: Run tests and verify GREEN**

Run the command from Step 3.

- [ ] **Step 6: Commit**

```bash
git add trading-app/src/Provider/Fake/FakeRuntimeCheck.php \
  trading-app/tests/Exchange/Readiness/FakeRuntimeCheckTest.php \
  trading-app/tests/fixtures/fake-paper/golden-scenarios-v1.json \
  trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php \
  trading-app/tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php
git commit -m "test(fake): execute market slippage golden scenario (#196)"
```

### Task 5: Documentation and final verification

**Files:**
- Modify: `trading-app/docs/exchange/fake-instrument-risk-model-v1.md`
- Modify: `trading-app/src/Exchange/Fake/README.md`
- Modify: `docs/handbook/technical/fake-paper-gateway.md`
- Modify: `docs/handbook/technical/certified-net-pnl-contract.md`

- [ ] **Step 1: Update operator and contract documentation**

Document model versions, 5 bps taker cost, explicit maker zero, embedded spread,
ledger propagation, PnL formula, rollback, and the reduced golden gap list.

- [ ] **Step 2: Run focused and broad PHPUnit**

```bash
cd trading-app
php vendor/bin/phpunit \
  tests/Exchange/Fake \
  tests/Exchange/Adapter/FakeExchangeAdapterTest.php \
  tests/Exchange/Event/FakeExchangeEventNormalizerTest.php \
  tests/Exchange/Readiness/FakeRuntimeCheckTest.php \
  tests/Trading/Pnl
php vendor/bin/phpunit tests/Exchange tests/Provider/Fake tests/TradeEntry/Execution
```

- [ ] **Step 3: Run static and framework validation**

```bash
php vendor/bin/phpstan analyse \
  src/Exchange/Fake/FakeFillCost.php \
  src/Exchange/Fake/FakeFillCostModel.php \
  src/Exchange/Fake/FakeExchangeMatchingEngine.php \
  src/Exchange/Fake/FakeExchangeEventNormalizer.php \
  src/Exchange/Adapter/FakeExchangeAdapter.php \
  src/Provider/Fake/FakeRuntimeCheck.php \
  src/Trading/Pnl/FillCostLedgerIngestionService.php \
  tests/Exchange/Fake/FakeFillCostModelTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioCatalogTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioExecutionTest.php \
  tests/Exchange/Fake/FakePaperGoldenScenarioRunner.php \
  tests/Exchange/Adapter/FakeExchangeAdapterTest.php \
  tests/Exchange/Event/FakeExchangeEventNormalizerTest.php \
  tests/Exchange/Readiness/FakeRuntimeCheckTest.php \
  tests/Trading/Pnl/FillCostLedgerIngestionServiceTest.php \
  --memory-limit=512M
php -d error_reporting=8191 bin/console lint:container --no-debug
php -d error_reporting=8191 bin/console lint:yaml config
```

- [ ] **Step 4: Run documentation and diff checks**

```bash
cd ..
python -m mkdocs build --strict
git diff --check
git status --short
```

- [ ] **Step 5: Review safety and commit docs**

Confirm no credential, network call, exchange write, strategy change, silent
zero for absent generic-provider costs, or unrelated file is present.

```bash
git add trading-app/docs/exchange/fake-instrument-risk-model-v1.md \
  trading-app/src/Exchange/Fake/README.md \
  docs/handbook/technical/fake-paper-gateway.md \
  docs/handbook/technical/certified-net-pnl-contract.md \
  docs/superpowers/plans/2026-07-16-fake-slippage-cost-model-v1.md
git commit -m "docs(fake): document slippage cost model (#196)"
```

- [ ] **Step 6: Delivery**

Check Codex quota after the commit, push the branch, open a PR with `Part of
#196`, request one Codex review after CI starts, address every thread, and merge
only after green CI plus explicit Codex approval.
