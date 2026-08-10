# #306 Day Trading Shadow Baseline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish and run the exact `day_trading@1.1.0` / `day_trading.trend_continuation.long@1.1.0` shadow baseline through the canonical config, rules, order-plan, and portfolio boundaries without a legacy or mainnet fallback.

**Architecture:** Add immutable 1.1.0 contract documents and narrowly version-aware validators, then resolve them through six strict modern layers. A day-trading shadow application service composes the existing #303 evaluator and #304 order-plan/portfolio authorities; it returns stable fail-closed outcomes and selects only Fake, Paper, or backtest adapters.

**Tech Stack:** PHP 8.2, Symfony YAML/DI, Opis JSON Schema, PHPUnit, PHPStan, Brick Math, existing TradingCore canonical contracts.

---

## File map

- `config/trading/mode_contract/day_trading/1.1.0.yaml`: immutable mode decisions.
- `config/trading/setup_contract/day_trading.trend_continuation.long/1.1.0.yaml`: immutable long rule tree and execution policy.
- `config/trading/schema/{mode,setup}-contract.schema.json`: exact 1.1.0 schema branches.
- `src/TradingCore/{Mode,Setup}/*Validator.php`: PHP/schema parity and frozen version/status matrix.
- `config/trading/runtime/**`: isolated modern base, exchange, pair, and environment layers; legacy config files remain untouched.
- `src/TradingCore/Config/TradingConfigLayerLoader.php`: select the isolated modern runtime root.
- `src/TradingCore/Config/EffectiveTradingConfigResolver.php`: shadow capability gate.
- `src/TradingCore/OrderPlan/Canonical/CanonicalOrderPolicy.php`: strict limit timing and live-cost guard value object.
- `src/TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicy.php`: compile the order policy and UTC horizon.
- `src/MtfValidator/Policy/CanonicalSetupRuleRuntime.php`: require the fixed five-timeframe input set.
- `src/TradingCore/DayTrading/*`: application boundary joining rule, plan, reservation, and structured outcome.
- `tests/Fixtures/TradingCore/DayTrading/*.json`: deterministic long/no-trade inputs.
- focused tests under `tests/TradingCore` and `tests/MtfValidator`: TDD coverage and adapter parity.

### Task 1: Publish the exact mode contract

**Files:**
- Create: `trading-app/config/trading/mode_contract/day_trading/1.1.0.yaml`
- Modify: `trading-app/config/trading/schema/mode-contract.schema.json`
- Modify: `trading-app/src/TradingCore/Mode/ModeContract.php`
- Modify: `trading-app/src/TradingCore/Mode/ModeContractValidator.php`
- Modify: `trading-app/tests/TradingCore/Mode/ModeContractLoaderTest.php`

- [ ] **Step 1: Write failing version and invariant tests**

Add a test which loads `day_trading@1.1.0` and asserts:

```php
$contract = (new ModeContractLoader($this->contractRoot))->load('day_trading', '1.1.0');
$mode = $contract->toArray();
self::assertSame('shadow', $contract->lifecycleStatus);
self::assertTrue($contract->isExecutable());
self::assertSame('PT8H', $mode['horizon']['value']['maximum_duration']);
self::assertSame('UTC', $mode['horizon']['value']['daily_boundary_timezone']);
self::assertSame('00:00', $mode['horizon']['value']['daily_boundary_time']);
self::assertSame(['15m'], $mode['timeframes']['execution']);
self::assertSame(['5m', '1m'], $mode['timeframes']['confirmations']);
self::assertSame(5.0, $mode['risk']['trade_budget']['value']);
self::assertSame(4, $mode['risk']['max_concurrent_positions']['value']['limit']);
self::assertTrue($mode['risk']['max_concurrent_positions']['value']['include_pending_entries']);
self::assertSame(100.0, $mode['risk']['mode_exposure_cap']['value']);
self::assertSame(2.0, $mode['leverage']['value']);
```

Add mutation cases for an extra execution timeframe, local-time boundary,
excluded reservations, leverage above 2, and `regular` as an identifier.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Mode/ModeContractLoaderTest.php
```

Expected: FAIL because `day_trading@1.1.0` is not published.

- [ ] **Step 3: Add the schema/validator branch and contract**

Extend the mode timeframe shape with mandatory `confirmations`, publish only
`day_trading => ['1.0.0', '1.1.0']`, and validate this exact 1.1.0 value shape:

```yaml
lifecycle: { status: shadow, executable: true, rationale: 'Fake/Paper/backtest shadow only; #132 promotion evidence pending.' }
horizon:
  state: defined
  value: { maximum_duration: PT8H, daily_boundary_time: '00:00', daily_boundary_timezone: UTC, close_before_boundary: true }
  unit: holding_horizon_policy
session_policy:
  state: defined
  value: { calendar: continuous_crypto, timezone: UTC }
  unit: session_policy
timeframes:
  regime: ['4h']
  context: ['1h']
  trigger: ['15m']
  execution: ['15m']
  confirmations: ['5m', '1m']
cadence:
  evaluation: { state: defined, value: PT15M, unit: duration, source: 'GitHub #306 decision 2026-08-10', justification: 'Fixed shadow cadence.' }
  validity_window: { state: defined, value: PT15M, unit: duration, source: 'GitHub #306 decision 2026-08-10', justification: 'No implicit carry-forward.' }
risk:
  trade_budget: { state: defined, value: 5.0, unit: percent_equity_per_trade, source: 'config/app/trade_entry.regular.yaml:77-84', justification: 'Versioned historical input.' }
  daily_loss_cap:
    state: defined
    value: { percent_equity: 6.0, absolute_quote: 30.0, quote_currency: USDT, day_timezone: UTC, day_boundary_local: '00:00:00', include_unrealized_loss: true }
    unit: compound_percent_equity_and_quote_per_day
  max_concurrent_positions:
    state: defined
    value: { limit: 4, include_pending_entries: true }
    unit: positions
  mode_exposure_cap: { state: defined, value: 100.0, unit: percent_equity_notional, source: 'GitHub #306 decision 2026-08-10', justification: 'Shadow hypothesis.' }
leverage: { state: defined, value: 2.0, unit: leverage_multiple, source: 'GitHub #306 decision 2026-08-10', justification: 'Mode cap.' }
order_policy:
  state: defined
  value: { margin_mode: isolated, preferred_type: limit, market_fallback: false }
  unit: policy
```

Keep the two frozen compatible setup IDs and add provenance rows for every new
decision. Extend `ModeContract::timeframeRoles()` to return the confirmations
key. Version-branch the PHP/schema value validators so `1.0.0` keeps its exact
legacy shapes while `1.1.0` requires the #304 daily/concurrency shapes above.

- [ ] **Step 4: Run contract and JSON-schema parity tests**

Run the focused test again. Expected: PASS, including Opis and PHP mutation
parity.

- [ ] **Step 5: Commit the mode contract**

```bash
git add trading-app/config/trading/mode_contract/day_trading/1.1.0.yaml trading-app/config/trading/schema/mode-contract.schema.json trading-app/src/TradingCore/Mode/ModeContractValidator.php trading-app/tests/TradingCore/Mode/ModeContractLoaderTest.php
git commit -m "feat: publish day trading 1.1 shadow mode"
```

### Task 2: Publish the executable long setup

**Files:**
- Create: `trading-app/config/trading/setup_contract/day_trading.trend_continuation.long/1.1.0.yaml`
- Create: `trading-app/tests/Fixtures/TradingCore/Setup/day-trading-long-1.1.0-scenarios.json`
- Modify: `trading-app/config/trading/schema/setup-contract.schema.json`
- Modify: `trading-app/src/TradingCore/Setup/SetupContractValidator.php`
- Modify: `trading-app/tests/TradingCore/Setup/SetupContractLoaderTest.php`

- [ ] **Step 1: Write failing publication tests**

Assert exact identity, shadow/executable state, exact mode compatibility,
blocker-free compilation, unchanged blocked short, empty defects, fixed rules,
and the execution decisions:

```php
$setup = (new SetupContractLoader($this->root))->load('day_trading.trend_continuation.long', '1.1.0');
$compiled = (new SetupCompiler())->compile($setup);
self::assertSame('shadow', $setup->status);
self::assertTrue($setup->isExecutable());
self::assertTrue($compiled->publishable);
self::assertSame([], $compiled->blockers);
self::assertSame(['day_trading' => '1.1.0'], $compiled->modeVersions);
self::assertSame('15m', $compiled->ast['execution']['execution_timeframe']['value']);
self::assertSame(['5m', '1m'], $compiled->ast['execution']['mandatory_confirmations']['value']);
self::assertSame(0.30, $compiled->ast['execution']['entry_zone']['value']['atr_multiplier']);
self::assertSame(2.0, $compiled->ast['execution']['targets']['value'][0]['risk_multiple']);
self::assertSame(1.3, $compiled->ast['execution']['minimum_net_r']['value']);
```

Verify the fixture contains `valid_long`, `failed_condition`, `missing_1m`, and
`stale_5m`, with only the first expected to pass rules.

- [ ] **Step 2: Run setup tests and verify RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Setup/SetupContractLoaderTest.php
```

Expected: FAIL because the version/status/execution shape is unsupported.

- [ ] **Step 3: Implement the strict 1.1.0 validator/schema branch**

Represent the version matrix by `(setup_id, setup_version)` rather than changing
the frozen 1.0.0 row. Permit `shadow + executable=true` only for the day-trading
long 1.1.0 tuple. Require these additional decisions only on this tuple:

```php
private const DAY_TRADING_LONG_SHADOW = [
    'setup_id' => 'day_trading.trend_continuation.long',
    'setup_version' => '1.1.0',
    'status' => 'shadow',
    'side' => 'long',
    'mode_id' => 'day_trading',
    'mode_version' => '1.1.0',
];
```

Require `execution_timeframe`, `mandatory_confirmations`, and `order_policy` as
defined decisions. Reject aliases, unknown fields, unresolved decisions,
non-limit order types, market fallback, or any confirmation list other than
`['5m', '1m']`.

- [ ] **Step 4: Create the 1.1.0 setup from the pinned 1.0.0 AST**

Copy the existing file once, preserve the regime/context/trigger/confirmation
nodes byte-for-byte, then set the new identity/status/compatibility and replace
execution with:

```yaml
execution:
  side: long
  execution_timeframe: { state: defined, value: 15m, unit: timeframe, source: 'GitHub #306 decision 2026-08-10', justification: 'Deterministic execution.' }
  mandatory_confirmations: { state: defined, value: ['5m', '1m'], unit: timeframes, source: 'GitHub #306 decision 2026-08-10', justification: 'No descent or fallback.' }
  entry_zone:
    state: defined
    value: { anchor_source: vwap, anchor_timeframe: 5m, atr_timeframe: 5m, atr_multiplier: 0.30, minimum_half_width_rate: 0.0005, maximum_half_width_rate: 0.0100, asymmetry_rate: 0.0, ttl_seconds: 240, maximum_input_age_seconds: 60, quantize_outward: true }
    unit: price_zone_policy
  stop:
    state: defined
    value: { kind: atr, timeframe: 5m, atr_multiplier: 1.5, pivot_id: null, buffer_rate: 0.0 }
    unit: stop_policy
  targets:
    state: defined
    value: [{ id: tp1, risk_multiple: 2.0, liquidity_role: taker }]
    unit: target_policy
  minimum_net_r: { state: defined, value: 1.3, unit: net_r_multiple, source: 'GitHub #306 shadow hypothesis 2026-08-10', justification: 'Net acceptance floor.' }
  invalidation: { state: defined, value: { kind: close_beyond_stop }, unit: invalidation_policy, source: 'GitHub #306 decision 2026-08-10', justification: 'Single falsification boundary.' }
  time_stop: { state: defined, value: PT8H, unit: duration, source: 'GitHub #306 decision 2026-08-10', justification: 'Mode horizon.' }
  cost_contract:
    state: defined
    value: { entry_liquidity_role: maker, stop_liquidity_role: taker, entry_spread_source: order_book, entry_slippage_source: execution_model, stop_spread_source: order_book, stop_slippage_source: execution_model, target_spread_source: order_book, target_slippage_source: execution_model, funding_source: venue_schedule, funding_interval_seconds: 28800 }
    unit: net_cost_model
  order_policy:
    state: defined
    value: { type: limit, liquidity_role: maker, ttl_seconds: 90, cancel_after_seconds: 120, market_fallback: false, maximum_spread_bps: 6.0, maximum_slippage_bps: 8.0 }
    unit: order_policy
```

Set `validity_window` to `PT15M`, remove the historical selector defect, and add
exact provenance for all execution fields. The runtime must never read the
historical source file.

- [ ] **Step 5: Run tests and commit**

Expected: setup tests PASS and the short 1.0.0 remains blocked.

```bash
git add trading-app/config/trading/setup_contract/day_trading.trend_continuation.long/1.1.0.yaml trading-app/tests/Fixtures/TradingCore/Setup/day-trading-long-1.1.0-scenarios.json trading-app/config/trading/schema/setup-contract.schema.json trading-app/src/TradingCore/Setup/SetupContractValidator.php trading-app/tests/TradingCore/Setup/SetupContractLoaderTest.php
git commit -m "feat: publish day trading long shadow setup"
```

### Task 3: Add isolated modern runtime layers

**Files:**
- Create: `trading-app/config/trading/runtime/base.yaml`
- Create: `trading-app/config/trading/runtime/exchange/{fake,okx,hyperliquid}.yaml`
- Create: `trading-app/config/trading/runtime/mode_exchange/day_trading.1.1.0.{fake,okx,hyperliquid}.yaml`
- Create: `trading-app/config/trading/runtime/env/{test,paper,backtest}.yaml`
- Modify: `trading-app/src/TradingCore/Config/TradingConfigLayerLoader.php`
- Modify: `trading-app/tests/TradingCore/Config/EffectiveTradingConfigRuntimeFilesTest.php`

- [ ] **Step 1: Write failing real-file resolver tests**

For each supported target resolve the exact request and assert ordered layers,
hashes, allowlists, `write_enabled=false`, fee source, and no `regular` path:

```php
$snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
    'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
    $exchange, $environment, 'long',
));
self::assertSame(['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'], $snapshot->orderedLayers());
self::assertFalse($snapshot->payload()['environment']['write_enabled']);
self::assertStringNotContainsString('regular', json_encode([$snapshot->payload(), $snapshot->provenance()], JSON_THROW_ON_ERROR));
```

Use cells `fake/test`, `okx/paper`, `hyperliquid/paper`, and `fake/backtest`.
Assert `prod`, missing pair files, aliases, and unlisted exchanges reject.

- [ ] **Step 2: Run and verify RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/EffectiveTradingConfigRuntimeFilesTest.php
```

Expected: FAIL on the missing isolated modern layers.

- [ ] **Step 3: Point the loader at `config/trading/runtime`**

Change only the default layer root; contract loaders retain their existing
roots:

```php
private function root(): string
{
    return $this->configRoot ?? dirname(__DIR__, 3) . '/config/trading/runtime';
}
```

Populate every document with the exact v2 shapes already enforced by
`EffectiveTradingConfigComposer`. Pair layers contain exact identities and
empty `overrides: {}`. Environment allowlists are explicit, dry-run is true,
write is false, kill switch and stop loss are true. Venue fees/funding are
explicit; no generic fee fallback is supplied.

- [ ] **Step 4: Run config suites and commit**

```bash
php bin/phpunit tests/TradingCore/Config
git add trading-app/config/trading/runtime trading-app/src/TradingCore/Config/TradingConfigLayerLoader.php trading-app/tests/TradingCore/Config/EffectiveTradingConfigRuntimeFilesTest.php
git commit -m "feat: resolve day trading through modern runtime layers"
```

Expected: all Config tests PASS.

### Task 4: Enforce shadow runtime capabilities

**Files:**
- Create: `trading-app/src/TradingCore/Execution/Enum/ShadowExecutionCapability.php`
- Modify: `trading-app/src/TradingCore/Config/EffectiveTradingConfigRequest.php`
- Modify: `trading-app/src/TradingCore/Config/EffectiveTradingConfigResolver.php`
- Modify: `trading-app/tests/TradingCore/Config/EffectiveTradingConfigResolverTest.php`
- Modify: `trading-app/tests/TradingCore/Config/EffectiveTradingConfigRuntimeFilesTest.php`

- [ ] **Step 1: Write failing capability tests**

Add a required capability to requests and cover Fake, Paper, backtest, private
mainnet, and a mismatched environment. The mainnet assertion is:

```php
$this->expectException(TradingConfigException::class);
$this->expectExceptionMessage('private_mainnet_execution_forbidden');
$resolver->resolve($request->withCapability(ShadowExecutionCapability::PrivateMainnet));
```

- [ ] **Step 2: Run and verify RED**

Run both Config test files. Expected: FAIL because capability is not represented.

- [ ] **Step 3: Implement the closed enum and resolver gate**

```php
enum ShadowExecutionCapability: string
{
    case Fake = 'fake';
    case Paper = 'paper';
    case Backtest = 'backtest';
    case PrivateMainnet = 'private_mainnet';

    public function permitsShadow(): bool
    {
        return $this !== self::PrivateMainnet;
    }
}
```

Make capability an explicit `EffectiveTradingConfigRequest` constructor field,
include it in identity serialization, and reject it before loading mutable
runtime layers when `permitsShadow()` is false. Update every existing request
construction explicitly; do not add a default that hides the caller choice.

- [ ] **Step 4: Run tests and commit**

```bash
php bin/phpunit tests/TradingCore/Config
git add trading-app/src/TradingCore/Execution/Enum/ShadowExecutionCapability.php trading-app/src/TradingCore/Config trading-app/tests/TradingCore/Config
git commit -m "feat: gate shadow config by runtime capability"
```

### Task 5: Compile order timing, live-cost guards, and UTC horizon

**Files:**
- Create: `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalOrderPolicy.php`
- Create: `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalHoldingBoundary.php`
- Modify: `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicy.php`
- Modify: `trading-app/tests/TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicyCompilerTest.php`
- Modify: `trading-app/tests/TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicyFixture.php`

- [ ] **Step 1: Write failing compiler and boundary tests**

Assert the real 1.1.0 snapshot compiles to limit/maker, TTL 90, cancel 120,
no market fallback, spread 6 bps, slippage 8 bps, and that the admissible expiry
is the earlier of `createdAt + PT8H` and next midnight UTC. Cover 23:59:59 UTC
and creation exactly at the boundary.

- [ ] **Step 2: Run and verify RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicyCompilerTest.php
```

Expected: FAIL because the canonical execution policy rejects `order_policy`.

- [ ] **Step 3: Add strict immutable values**

```php
final readonly class CanonicalOrderPolicy
{
    public function __construct(
        public string $type,
        public string $liquidityRole,
        public int $ttlSeconds,
        public int $cancelAfterSeconds,
        public bool $marketFallback,
        public float $maximumSpreadBps,
        public float $maximumSlippageBps,
    ) {
        if ($type !== 'limit' || $liquidityRole !== 'maker' || $marketFallback
            || $ttlSeconds !== 90 || $cancelAfterSeconds !== 120
            || $maximumSpreadBps !== 6.0 || $maximumSlippageBps !== 8.0) {
            throw new CanonicalOrderPlanException('canonical_day_trading_order_policy_invalid');
        }
    }
}
```

`CanonicalHoldingBoundary::expiresAt()` receives only an injected UTC instant,
duration seconds, and the mode horizon value. It rejects a non-UTC boundary and
returns `min(created + 28800 seconds, next 00:00 UTC)`; zero remaining time is
`canonical_holding_window_expired`.

- [ ] **Step 4: Compile both fields with exact keys and units**

Extend the strict execution-key list with `execution_timeframe`,
`mandatory_confirmations`, and `order_policy`. Compile horizon from `mode`, not
from PHP defaults. Reject extra keys, wrong units, values outside the guards,
and discrepancies between mode `PT8H` and setup time stop.

- [ ] **Step 5: Run canonical OrderPlan tests and commit**

```bash
php bin/phpunit tests/TradingCore/OrderPlan/Canonical
git add trading-app/src/TradingCore/OrderPlan/Canonical trading-app/tests/TradingCore/OrderPlan/Canonical
git commit -m "feat: compile day trading shadow execution guards"
```

### Task 6: Make the MTF decision fixed and fail-closed

**Files:**
- Modify: `trading-app/src/MtfValidator/Policy/CanonicalSetupRuleRuntime.php`
- Modify: `trading-app/src/MtfValidator/Policy/CanonicalSetupRuleRuntimeResult.php`
- Modify: `trading-app/tests/MtfValidator/Policy/CanonicalSetupRuleRuntimeTest.php`

- [ ] **Step 1: Write failing long and rejection fixture tests**

Load the 1.1.0 fixture and assert the valid long passes with
`execution_timeframe=15m`; missing/stale 4h, 1h, 15m, 5m, or 1m returns a stable
reason and complete identity. Assert that providing only 1m never substitutes
another timeframe.

- [ ] **Step 2: Run and verify RED**

```bash
cd trading-app
php bin/phpunit tests/MtfValidator/Policy/CanonicalSetupRuleRuntimeTest.php
```

Expected: FAIL because the runtime silently omits missing snapshots and has no
fixed-timeframe preflight.

- [ ] **Step 3: Add exact preflight and structured identity**

Before compiling/evaluating, derive required roles from the resolved mode and
setup payload and compare them with timestamped inputs:

```php
private const DAY_TRADING_1_1_REQUIRED = ['4h', '1h', '15m', '5m', '1m'];

foreach (self::DAY_TRADING_1_1_REQUIRED as $timeframe) {
    if (!isset($indicatorsByTimeframe[$timeframe])) {
        return $this->reject($identity, 'critical_timeframe_missing', ['timeframe' => $timeframe]);
    }
}
```

Let the #303 freshness evaluator reject stale observations, but normalize the
top-level reason to `critical_timeframe_stale`. Every result trace includes mode
and setup IDs/versions, side, config hash, setup hash, catalog hash, evaluated
time, selected `15m`, and all section traces.

- [ ] **Step 4: Run MTF suites and commit**

```bash
php bin/phpunit tests/MtfValidator/Policy tests/TradingCore/Rules
git add trading-app/src/MtfValidator/Policy trading-app/tests/MtfValidator/Policy
git commit -m "feat: evaluate day trading on fixed mtf inputs"
```

### Task 7: Add the day-trading shadow application boundary

**Files:**
- Create: `trading-app/src/TradingCore/DayTrading/DayTradingShadowRequest.php`
- Create: `trading-app/src/TradingCore/DayTrading/DayTradingShadowOutcome.php`
- Create: `trading-app/src/TradingCore/DayTrading/DayTradingShadowRuntime.php`
- Create: `trading-app/tests/TradingCore/DayTrading/DayTradingShadowRuntimeTest.php`

- [ ] **Step 1: Write failing end-to-end application tests**

Use real 1.1.0 resolution, valid rule inputs, #304 canonical market/risk/cost
requests, and in-memory reservations. Assert valid long yields a plan plus an
active reservation. Assert rule rejection, live spread above 6 bps, slippage
above 8 bps, unknown costs, expired zone, insufficient net R, daily loss,
concurrency, exposure, and leverage each return `no_trade` without a reservation.

- [ ] **Step 2: Run and verify RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/DayTrading/DayTradingShadowRuntimeTest.php
```

Expected: FAIL because the application boundary does not exist.

- [ ] **Step 3: Define the immutable outcome**

```php
final readonly class DayTradingShadowOutcome
{
    public function __construct(
        public string $status,
        public string $reasonCode,
        public LineageContext $lineage,
        public ?CanonicalOrderPlan $orderPlan,
        public ?CanonicalPortfolioReservation $reservation,
        public array $evidence,
    ) {
        if (!in_array($status, ['planned', 'no_trade'], true)) {
            throw new \InvalidArgumentException('day_trading_shadow_status_invalid');
        }
    }
}
```

- [ ] **Step 4: Implement the single canonical orchestration path**

`DayTradingShadowRuntime::run()` performs, in order: explicit capability gate,
config resolution, rule evaluation, live spread/slippage guard, canonical policy
compile, `CanonicalOrderPlanAuthority` verification/build, UTC horizon check,
and adapter reservation. Catch only known domain exceptions and map them to
stable `no_trade` codes; unexpected errors remain visible. Never construct a
legacy DTO or select a market order.

Require an injected `CanonicalPortfolioAdapterInterface`; callers choose the
existing Fake, Paper, or backtest adapter. Verify the adapter class matches the
request capability before admission.

- [ ] **Step 5: Run tests and commit**

```bash
php bin/phpunit tests/TradingCore/DayTrading tests/TradingCore/OrderPlan/Canonical tests/TradingCore/Risk/Canonical
git add trading-app/src/TradingCore/DayTrading trading-app/tests/TradingCore/DayTrading
git commit -m "feat: run day trading through canonical shadow authority"
```

### Task 8: Prove adapter parity and mainnet isolation

**Files:**
- Create: `trading-app/tests/TradingCore/DayTrading/DayTradingShadowAdapterParityTest.php`
- Create: `trading-app/tests/TradingCore/DayTrading/DayTradingShadowDependencyTest.php`
- Modify: `trading-app/tests/Config/ModernConfigLegacyQuarantineTest.php`

- [ ] **Step 1: Write parity and dependency tests**

Run the same long/no-trade fixtures through Fake, Paper, and backtest adapters
and compare normalized decisions, plan hashes, caps, and reservation amounts.
Allow only adapter identity/timestamps to differ. Reflect over the new namespace
and assert no dependency on legacy `TradeEntryConfig`, legacy
`OrderPlanBuilder`, `ExecutionBox`, provider config, Doctrine, Messenger, or a
private venue port.

Assert all new YAML and compiled payload text is free of `regular`, `scalper`,
`scalper_micro`, `market_fallback: true`, and mainnet write enablement.

- [ ] **Step 2: Run and verify failures before tightening any leak**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/DayTrading/DayTradingShadowAdapterParityTest.php tests/TradingCore/DayTrading/DayTradingShadowDependencyTest.php tests/Config/ModernConfigLegacyQuarantineTest.php
```

Expected: PASS only when all three adapters are behaviorally equivalent and
the dependency/mainnet scans are clean.

- [ ] **Step 3: Verify the canonical ownership boundary**

Use reflection assertions listing the only permitted constructor dependencies:
`EffectiveTradingConfigResolverInterface`, `CanonicalSetupRuleRuntime`,
`CanonicalExecutionPolicyCompiler`, `CanonicalOrderPlanBuilder`,
`CanonicalPortfolioAdapterInterface`, and `ClockInterface`. Expected: the test
passes with no adapter-specific policy and no legacy dependency. A failure
returns implementation to Task 7; the allowed list is not expanded.

- [ ] **Step 4: Commit parity proof**

```bash
git add trading-app/tests/TradingCore/DayTrading trading-app/tests/Config/ModernConfigLegacyQuarantineTest.php trading-app/src/TradingCore/DayTrading
git commit -m "test: prove day trading shadow runtime parity"
```

### Task 9: Full verification and delivery checkpoint

**Files:**
- Modify only files required by concrete verification failures.

- [ ] **Step 1: Run focused feature suites**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Mode tests/TradingCore/Setup tests/TradingCore/Config tests/TradingCore/Rules tests/MtfValidator/Policy tests/TradingCore/OrderPlan/Canonical tests/TradingCore/Risk/Canonical tests/TradingCore/DayTrading tests/Config/ModernConfigLegacyQuarantineTest.php
```

Expected: all tests PASS.

- [ ] **Step 2: Run project verification**

```bash
composer test
composer phpstan
php bin/console lint:yaml config/trading
php bin/console lint:container
```

Expected: zero failures/errors. Record pre-existing deprecations separately;
do not call them new success.

- [ ] **Step 3: Audit the diff**

```bash
git diff --check origin/main...HEAD
git status --short
rg -n "regular|scalper|scalper_micro|mainnet_write_enabled: true|market_fallback: true" trading-app/config/trading/mode_contract/day_trading/1.1.0.yaml trading-app/config/trading/setup_contract/day_trading.trend_continuation.long/1.1.0.yaml trading-app/config/trading/runtime trading-app/src/TradingCore/DayTrading
```

Expected: `git diff --check` is silent, status is clean after commits, and the
scan has no forbidden runtime dependency/value (provenance prose may mention a
historical source only where explicitly asserted by tests).

- [ ] **Step 4: Request code review before PR delivery**

Use the `requesting-code-review` skill, address only evidence-backed findings,
rerun affected suites, and commit fixes separately.

- [ ] **Step 5: Open a draft PR**

Push `codex/issue-306-day-trading` and open a draft PR linked to #306. Include
contract identities, supported shadow capabilities, test evidence, explicit
short/mainnet exclusions, and the remaining #132 promotion/certification work.
