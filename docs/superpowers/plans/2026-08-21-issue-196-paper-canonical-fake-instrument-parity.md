# Paper canonical Fake instrument parity implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make modern Paper orders execute in Fake with the exact instrument units authenticated by their canonical order plan, including across restart.

**Architecture:** A strict descriptor converts canonical plan fields plus explicitly versioned Fake simulation fields into one hash-bound `FakeInstrument`. A Paper-only registry restores active descriptors from private Fake state and is shared by the adapter and matching engine; the canonical dispatcher binds and persists the descriptor before submission. Legacy Paper and generic Fake construction stay unchanged.

**Tech Stack:** PHP 8.4, Symfony, PHPUnit, Brick Math, existing Paper replay/Fake exchange/canonical OrderPlan contracts.

---

### Task 1: Specify and implement the canonical Paper/Fake instrument descriptor

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptor.php`
- Create: `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptorTest.php`
- Modify: `trading-app/tests/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanPipelineFixture.php`
- Modify: `trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodecTest.php`

- [ ] **Step 1: Add a non-unit contract-size option to the canonical test fixture**

Add `float $contractSize = 1.0` to `CanonicalOrderPlanPipelineFixture::accepted()` and use it in its `CanonicalInstrumentSnapshot`. Add the same optional argument to `PaperCanonicalPreparedEffectCodecTest::fixture()` and pass it through. Existing callers retain byte-compatible defaults.

- [ ] **Step 2: Write failing descriptor tests**

Cover this behavior:

```php
$effect = PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01);
$cell = $this->cell($effect->provenance);
$descriptor = PaperCanonicalFakeInstrumentDescriptor::fromPlan($cell, $effect->plan);

self::assertSame('0.01', $descriptor->instrument()->contractSize);
self::assertSame('0.1', $descriptor->instrument()->priceTick);
self::assertSame($descriptor->encoded(),
    PaperCanonicalFakeInstrumentDescriptor::decode($descriptor->encoded())->encoded());
```

Also decode a JSON document whose `descriptor_hash` or `contract_size` was changed and assert `paper_canonical_fake_instrument_descriptor_invalid`. Assert cross-cell venue/network, invalid plan hash and unsupported fixture symbol fail closed.

- [ ] **Step 3: Run the focused test and confirm RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptorTest.php
```

Expected: failure because `PaperCanonicalFakeInstrumentDescriptor` does not exist.

- [ ] **Step 4: Implement the descriptor**

Implement a readonly value object with:

```php
public const METADATA_KEY = 'paper_canonical_instrument_descriptor';
private const SCHEMA = 'paper-canonical-fake-instrument.v1';

public static function fromPlan(
    PaperExecutionCell $cell,
    CanonicalOrderPlan $plan,
    FakeInstrumentCatalog $fixtures = new FakeInstrumentCatalog(),
): self;

public static function decode(string $encoded): self;
public function encoded(): string;
public function instrument(): FakeInstrument;
public function identityHash(): string;
public function cellId(): string;
public function symbol(): string;
```

`fromPlan()` must verify `planHash === expectedPlanHash()`, modern cell scope,
public venue/environment parity, perpetual market, fixture symbol/quote/settle
identity, positive exact decimal conversion, and `floor(exchangeLeverageCap) >=
1 && finalLeverage <= floor(exchangeLeverageCap)`. Use
`CanonicalOrderPlanDecimal::fromFloat()` for float-to-decimal conversion.

The hash payload contains exactly:

```php
[
    'schema' => self::SCHEMA,
    'paper_cell_id' => $cell->id,
    'paper_network' => $cell->network->value,
    'public_venue' => $cell->marketDataVenue->value,
    'symbol' => $plan->symbol,
    'market_type' => $plan->marketType,
    'base_asset' => $fixture->baseAsset,
    'quote_asset' => $plan->quoteCurrency,
    'settle_asset' => $fixture->settleAsset,
    'price_tick' => $decimal($plan->tickSize),
    'quantity_step' => $decimal($plan->quantityStep),
    'min_quantity' => $decimal($plan->minQuantity),
    'min_notional' => $decimal($plan->exchangeMinNotional),
    'contract_size' => $decimal($plan->contractSize),
    'max_leverage' => (int) floor($plan->exchangeLeverageCap),
    'maintenance_margin_rate' => $fixture->maintenanceMarginRate,
    'allowed_order_types' => array_map(
        static fn (ExchangeOrderType $type): string => $type->value,
        $fixture->allowedOrderTypes,
    ),
    'fixture_version' => $fixtures->metadataFixtureVersion(),
    'precision_model_version' => $fixtures->precisionModelVersion(),
]
```

Compute `descriptor_hash` as `sha256:` plus SHA-256 of
`CanonicalJson::encode($payload)`. `encoded()` returns canonical JSON of payload
plus hash. `decode()` requires the exact field set, exact schema/model versions,
recomputes the hash and reconstructs `FakeInstrument`; all failures collapse to
`paper_canonical_fake_instrument_descriptor_invalid`.

- [ ] **Step 5: Run descriptor and existing codec tests GREEN**

```bash
cd trading-app
php bin/phpunit \
  tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptorTest.php \
  tests/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodecTest.php
```

Expected: all tests pass.

- [ ] **Step 6: Commit the descriptor slice**

```bash
git add trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptor.php \
  trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptorTest.php \
  trading-app/tests/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanPipelineFixture.php \
  trading-app/tests/Trading/Paper/Execution/Strategy/PaperCanonicalPreparedEffectCodecTest.php
git commit -m "feat(paper): bind canonical fake instrument descriptor"
```

### Task 2: Specify and implement the restart-safe Paper instrument registry

**Files:**
- Create: `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentRegistry.php`
- Create: `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentRegistryTest.php`

- [ ] **Step 1: Write failing registry tests**

Construct a modern cell and private `FakeExchangeStateStore`. Prove:

```php
$registry = new PaperCanonicalFakeInstrumentRegistry($cell, $state);
self::assertNull($registry->find('BTCUSDT'));
$encoded = $registry->bind($effect->plan);
self::assertSame('0.01', $registry->find('BTCUSDT')?->contractSize);
self::assertSame($encoded, $registry->bind($effect->plan));
```

Persist an active canonical order whose metadata contains the encoded
descriptor, recreate the registry and assert the same instrument is restored.
Persist a canonical active order with no descriptor, a forged descriptor, a
foreign cell descriptor and two conflicting active descriptors; each must fail
with `paper_canonical_fake_instrument_state_invalid`. Bind a changed valid plan
while canonical state is active and assert
`paper_canonical_fake_instrument_drift`; with no active canonical state the new
descriptor replaces the old one.

- [ ] **Step 2: Run registry test and confirm RED**

```bash
cd trading-app
php bin/phpunit tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentRegistryTest.php
```

Expected: class-not-found failure.

- [ ] **Step 3: Implement the registry**

Implement `FakeInstrumentProviderInterface`:

```php
final class PaperCanonicalFakeInstrumentRegistry implements FakeInstrumentProviderInterface
{
    /** @var array<string, PaperCanonicalFakeInstrumentDescriptor> */
    private array $descriptors = [];

    public function __construct(
        private readonly PaperExecutionCell $cell,
        private readonly FakeExchangeStateStore $state,
    ) {
        if (!$cell->isModern()) {
            throw new \LogicException('paper_canonical_fake_instrument_cell_invalid');
        }
        $this->restoreActiveState();
    }

    public function bind(CanonicalOrderPlan $plan): string;
    public function find(string $symbol): ?FakeInstrument;
}
```

`restoreActiveState()` scans only `getOpenOrders()` and `getOpenPositions()`
whose `canonical_dispatch_source` is `paper_canonical_fake_dispatcher`. It
requires a scalar descriptor metadata value, decodes it, requires exact cell
identity and merges only identical descriptor hashes for a symbol. It wraps
all malformed/conflicting state as
`paper_canonical_fake_instrument_state_invalid`.

`bind()` creates a descriptor from the plan. It is idempotent on equal hashes.
If the hash changed, rescan active canonical orders/positions for the symbol:
reject drift when any exist, otherwise replace the in-memory descriptor.
`find()` normalizes only canonical uppercase symbols and never delegates to
`FakeInstrumentCatalog`.

- [ ] **Step 4: Run registry tests GREEN and commit**

```bash
cd trading-app
php bin/phpunit \
  tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptorTest.php \
  tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentRegistryTest.php
cd ..
git add trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentRegistry.php \
  trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentRegistryTest.php
git commit -m "feat(paper): restore canonical fake instruments"
```

### Task 3: Wire canonical Paper dispatch to the shared registry

**Files:**
- Modify: `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeRuntimeFactory.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Fake/PaperFakeRuntime.php`
- Modify: `trading-app/src/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcher.php`
- Modify: `trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php`
- Modify: `trading-app/tests/Trading/Paper/Execution/Fake/PaperFakeRuntimeFactoryTest.php`
- Modify: `trading-app/tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php`

- [ ] **Step 1: Write failing wiring and economic-parity tests**

For a modern cell, reflect or exercise the runtime and prove the same registry
feeds adapter and matching engine. For a legacy cell, assert existing default
Fake behavior remains available.

Dispatch `PaperCanonicalPreparedEffectCodecTest::fixture(contractSize: 0.01)`.
Assert the entry metadata contains:

```php
self::assertSame('0.01', $entry->metadata['margin_contract_size']);
self::assertIsString($entry->metadata[PaperCanonicalFakeInstrumentDescriptor::METADATA_KEY]);
```

Move the book to fill the entry and assert position margin, fill fee, spread
and slippage equal the plan quantity multiplied by fill price and `0.01`, not
the static unit contract. Create a fresh factory on the same private root while
the entry is still open, then fill through the restored runtime and assert the
same values and descriptor.

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
cd trading-app
php bin/phpunit \
  tests/Trading/Paper/Execution/Fake/PaperFakeRuntimeFactoryTest.php \
  tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php
```

Expected: assertions expose `margin_contract_size=1` or an unbound registry.

- [ ] **Step 3: Wire modern runtimes only**

In `PaperFakeRuntimeFactory::create()`:

```php
$registry = $cell->isModern()
    ? new PaperCanonicalFakeInstrumentRegistry($cell, $state)
    : null;
$engine = new FakeExchangeMatchingEngine(
    $state,
    $book,
    $clock,
    instruments: $registry,
);
$adapter = new FakeExchangeAdapter($state, $book, $engine, $clock, $registry);
```

For legacy cells, omit/null injection so both classes retain their current
`FakeInstrumentCatalog` defaults. Add the nullable registry to
`PaperFakeRuntime` and expose:

```php
public function bindCanonicalInstrument(CanonicalOrderPlan $plan): string
{
    if (!$this->cell->isModern() || $this->canonicalInstruments === null) {
        throw new \LogicException('paper_canonical_fake_instrument_registry_unavailable');
    }
    return $this->canonicalInstruments->bind($plan);
}
```

- [ ] **Step 4: Bind before leverage/submission and persist the descriptor**

In `PaperCanonicalFakeEffectDispatcher::dispatch()`, keep scope, duplicate and
expiry checks first. Before `setLeverage()`, call
`$runtime->bindCanonicalInstrument($effect->plan)` and pass the returned string
to `request()`. Add it under
`PaperCanonicalFakeInstrumentDescriptor::METADATA_KEY` in request metadata.

Add that metadata key to
`FakeExchangeMatchingEngine::LINEAGE_METADATA_KEYS`. Its value is canonical JSON
stored as a scalar string, so the existing lineage copier propagates it to
entry/protection orders and positions without admitting arbitrary arrays.

- [ ] **Step 5: Run focused and restart tests GREEN**

```bash
cd trading-app
php bin/phpunit \
  tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentDescriptorTest.php \
  tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeInstrumentRegistryTest.php \
  tests/Trading/Paper/Execution/Fake/PaperFakeRuntimeFactoryTest.php \
  tests/Trading/Paper/Execution/Fake/PaperCanonicalFakeEffectDispatcherTest.php
```

Expected: all tests pass and the non-unit contract remains exact after restart.

- [ ] **Step 6: Commit wiring**

```bash
git add trading-app/src/Trading/Paper/Execution/Fake \
  trading-app/src/Exchange/Fake/FakeExchangeMatchingEngine.php \
  trading-app/tests/Trading/Paper/Execution/Fake
git commit -m "feat(paper): execute with canonical instrument units"
```

### Task 4: Verify compatibility and delivery scope

**Files:**
- Modify only if a real failure exposes an in-scope defect.

- [ ] **Step 1: Run adjacent behavior suites**

```bash
cd trading-app
php bin/phpunit \
  tests/Trading/Paper/Execution/Fake \
  tests/Trading/Paper/Execution/Strategy \
  tests/Exchange/Adapter/FakeExchangeAdapterTest.php \
  tests/Exchange/Fake/FakeInstrumentCatalogTest.php \
  tests/Exchange/Fake/FakeFillCostModelTest.php \
  tests/Exchange/Fake/FakeFundingModelTest.php \
  tests/Exchange/Fake/FakeLiquidationCalculatorTest.php \
  tests/Exchange/Fake/FakeLiquidationIntegrationTest.php
```

Expected: all tests pass; pre-existing deprecation notices are recorded but do
not count as failures.

- [ ] **Step 2: Run static and configuration verification**

```bash
cd trading-app
vendor/bin/phpstan analyse --no-progress \
  src/Trading/Paper/Execution/Fake \
  src/Exchange/Fake/FakeExchangeMatchingEngine.php \
  tests/Trading/Paper/Execution/Fake
php bin/console lint:container
php bin/console lint:yaml config
cd ..
git diff --check origin/main...HEAD
git status --short
```

Expected: PHPStan, container/YAML lint and diff check pass; worktree contains
only the intentional committed files.

- [ ] **Step 3: Perform local contract review**

Inspect `git diff --stat origin/main...HEAD` and
`git diff origin/main...HEAD`. Confirm no generic Fake fixture mutation, legacy
fallback change, private exchange client, credential, strategy tuning,
coordinator wiring or mainnet mutation was introduced.

- [ ] **Step 4: Push and open a ready PR**

```bash
git push -u origin codex/issue-196-paper-instrument-parity
gh pr create --base main --head codex/issue-196-paper-instrument-parity \
  --title "feat(paper): bind canonical Fake instrument units" \
  --body-file /tmp/issue-196-paper-instrument-parity-pr.md
```

The PR body must link #196, summarize the unit mismatch and restart contract,
list exact verification counts, state that the full provider remains unwired,
and confirm no real/mainnet execution. Merge only after required CI is green
and no real blocking review thread remains.

