# #307 Scalping Shadow/Paper Baseline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Publish `scalping@1.1.0` and its three independent setups as one executable, fail-closed Shadow/Paper baseline with canonical rules, order plans, portfolio enforcement, adapter parity, and net evidence.

**Architecture:** Extract #306 orchestration into a mode-neutral Shadow core while preserving the day-trading facade and codes. Add a scalping facade backed by exact identity policies, immutable `1.1.0` contracts, strict runtime layers, and the existing #303/#304 authorities. Keep every legacy `scalper` file quarantined.

**Tech Stack:** PHP 8.4, Symfony 7, PHPUnit 11, YAML/JSON Schema, PHPStan, canonical TradingCore rule/order-plan/portfolio components.

---

## File responsibility map

- `config/trading/mode_contract/scalping/1.1.0.yaml`: immutable mode decisions only.
- `config/trading/setup_contract/scalping.*/1.1.0.yaml`: one independent thesis and its canonical execution policy per file.
- `config/trading/runtime/mode_exchange/scalping.1.1.0.*.yaml`: venue-specific tightening; never an alias.
- `src/TradingCore/Shadow/*`: shared Shadow request/outcome/orchestration and exact identity policy.
- `src/TradingCore/DayTrading/DayTradingShadowRuntime.php`: compatibility facade over the shared core.
- `src/TradingCore/Scalping/*`: scalping facade and its accepted identities.
- `src/MtfValidator/Policy/CanonicalSetupRuleRuntime.php`: contract-driven required timeframe metadata, not mode-name branching.
- `src/TradingCore/Rules/Evaluation/StrictRuleEvaluator.php`: parameter authority and series-order evidence.
- `src/Indicator/Provider/IndicatorProviderService.php`: canonical oldest-to-newest MACD series and pullback-age input.
- `src/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanValidator.php`: exact scalping TTL and holding boundary validation.
- `src/TradingCore/Scalping/ScalpingNetReport.php`: deterministic setup/side-separated implementation report.
- `tests/TradingCore/Scalping/*`: end-to-end scalping runtime, fill, parity, and report fixtures.

### Task 1: Freeze the `scalping@1.1.0` mode contract

**Files:**
- Modify: `trading-app/src/TradingCore/Mode/ModeContractValidator.php`
- Modify: `trading-app/config/trading/schema/mode-contract.schema.json`
- Create: `trading-app/config/trading/mode_contract/scalping/1.1.0.yaml`
- Modify: `trading-app/tests/TradingCore/Mode/ModeContractLoaderTest.php`

- [ ] **Step 1: Write failing contract tests**

Add `testLoadsExecutableScalpingShadowVersionWithExactDecisions()` asserting:

```php
$contract = (new ModeContractLoader($this->contractRoot))->load('scalping', '1.1.0');
$document = $contract->toArray();
self::assertSame('shadow', $contract->lifecycleStatus);
self::assertTrue($contract->isExecutable());
self::assertSame(['5m'], $contract->timeframeRoles()['execution']);
self::assertSame(['1m'], $contract->timeframeRoles()['confirmations']);
self::assertSame('PT2H', $document['horizon']['value']['maximum_duration']);
self::assertSame(2.0, $document['risk']['trade_budget']['value']);
self::assertSame(['limit' => 3, 'include_pending_entries' => true], $document['risk']['max_concurrent_positions']['value']);
self::assertSame(75.0, $document['risk']['mode_exposure_cap']['value']);
self::assertSame(3.0, $document['leverage']['value']);
self::assertFalse($document['order_policy']['value']['market_fallback']);
```

Add mutation cases for `PT2H`, 2-percent risk, concurrency 3, exposure 75,
leverage 3, 5m execution, 1m confirmation, and disabled market fallback. Assert
both PHP validator and JSON Schema reject every mutation.

- [ ] **Step 2: Run the test and confirm RED**

Run:

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Mode/ModeContractLoaderTest.php
```

Expected: failure because `scalping@1.1.0` is not a published version/file.

- [ ] **Step 3: Add the frozen validator shape**

Extend `PUBLISHED_VERSIONS['scalping']` with `1.1.0`. Replace the
day-trading-only boolean with a frozen Shadow identity discriminator and use the
extended horizon/session/timeframe/daily-cap/concurrency/order-policy shapes for
both executable Shadow modes. Add an exact `assertScalpingShadowFrozenValues()`
comparison with:

```php
[
    'horizon' => ['maximum_duration' => 'PT2H', 'daily_boundary_time' => '00:00:00', 'daily_boundary_timezone' => 'UTC', 'close_before_boundary' => true],
    'session' => ['calendar' => 'continuous_crypto', 'timezone' => 'UTC'],
    'timeframes' => ['regime' => ['1h'], 'context' => ['15m'], 'trigger' => ['5m'], 'execution' => ['5m'], 'confirmations' => ['1m']],
    'evaluation' => 'PT5M',
    'validity' => 'PT5M',
    'trade_budget' => 2.0,
    'daily_cap' => ['percent_equity' => 6.0, 'absolute_quote' => 40.0, 'quote_currency' => 'USDT', 'day_timezone' => 'UTC', 'day_boundary_local' => '00:00:00', 'include_unrealized_loss' => true],
    'concurrency' => ['limit' => 3, 'include_pending_entries' => true],
    'exposure' => 75.0,
    'leverage' => 3.0,
    'order_policy' => ['margin_mode' => 'isolated', 'preferred_type' => 'limit', 'market_fallback' => false],
]
```

Mirror the exact `1.1.0` `if/then` constraints in the JSON Schema.

- [ ] **Step 4: Create the immutable YAML contract**

Copy the complete key/provenance structure of
`mode_contract/scalping/1.0.0.yaml`, set `mode_version: '1.1.0'`, make lifecycle
`{status: shadow, executable: true}`, replace every unresolved decision with the
exact values above, add the order-book requirement on 5m, and document these
sources:

```yaml
risk:
  trade_budget: { state: defined, value: 2.0, unit: percent_equity_per_trade, source: 'config/app/trade_entry.scalper.yaml:73-78 pinned by #307', justification: 'The lower explicit fixed-risk authority replaces the conflicting 7-percent request.' }
leverage: { state: defined, value: 3.0, unit: leverage_multiple, source: 'config/trading/mode_exchange/scalper.{okx,hyperliquid}.yaml pinned by #307', justification: 'Conservative venue envelope copied without legacy runtime loading.' }
```

Keep the three setup IDs in their existing catalog order.

- [ ] **Step 5: Run contract tests and schema validation**

Run the Task 1 PHPUnit command and:

```bash
php bin/console lint:yaml config/trading/mode_contract/scalping/1.1.0.yaml
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add trading-app/src/TradingCore/Mode/ModeContractValidator.php trading-app/config/trading/schema/mode-contract.schema.json trading-app/config/trading/mode_contract/scalping/1.1.0.yaml trading-app/tests/TradingCore/Mode/ModeContractLoaderTest.php
git commit -m "feat: freeze scalping shadow mode contract"
```

### Task 2: Publish three independent executable setup contracts

**Files:**
- Modify: `trading-app/src/TradingCore/Setup/SetupContractValidator.php`
- Modify: `trading-app/config/trading/schema/setup-contract.schema.json`
- Create: `trading-app/config/trading/setup_contract/scalping.trend_continuation.long/1.1.0.yaml`
- Create: `trading-app/config/trading/setup_contract/scalping.pullback.long/1.1.0.yaml`
- Create: `trading-app/config/trading/setup_contract/scalping.trend_momentum.short/1.1.0.yaml`
- Create: `trading-app/tests/Fixtures/TradingCore/Setup/scalping-1.1.0-scenarios.json`
- Modify: `trading-app/tests/TradingCore/Setup/SetupContractLoaderTest.php`

- [ ] **Step 1: Write failing setup publication tests**

Load each ID at `1.1.0` and assert `shadow`, executable, publishable, exact side,
exact `scalping => 1.1.0` compatibility, no unresolved path, and:

```php
$execution = (new SetupCompiler())->compile($contract)->ast['execution'];
self::assertSame('5m', $execution['execution_timeframe']['value']);
self::assertSame(['1m'], $execution['mandatory_confirmations']['value']);
self::assertSame(0.22, $execution['entry_zone']['value']['atr_multiplier']);
self::assertSame(150, $execution['entry_zone']['value']['ttl_seconds']);
self::assertSame(1.5, $execution['stop']['value']['atr_multiplier']);
self::assertSame(1.8, $execution['targets']['value'][0]['risk_multiple']);
self::assertSame(1.3, $execution['minimum_net_r']['value']);
self::assertSame(45, $execution['order_policy']['value']['ttl_seconds']);
self::assertSame(75, $execution['order_policy']['value']['cancel_after_seconds']);
self::assertFalse($execution['order_policy']['value']['market_fallback']);
```

Assert the continuation AST contains no `pullback_confirmed`, the pullback AST
contains it, and the short AST has `side: short` with no invented pullback node.

- [ ] **Step 2: Run the test and confirm RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Setup/SetupContractLoaderTest.php
```

Expected: unknown setup version `1.1.0`.

- [ ] **Step 3: Generalize frozen executable-setup validation**

Publish `1.1.0` for the three scalping IDs. Add a shared exact Shadow execution
shape function parameterized by expected timeframe/confirmation and values,
then freeze scalping with:

```php
[
    'execution_timeframe' => '5m',
    'mandatory_confirmations' => ['1m'],
    'entry_zone' => ['anchor_source' => 'vwap', 'anchor_timeframe' => '5m', 'atr_timeframe' => '5m', 'atr_multiplier' => 0.22, 'minimum_half_width_rate' => 0.0004, 'maximum_half_width_rate' => 0.0065, 'asymmetry_rate' => 0.0, 'ttl_seconds' => 150, 'maximum_input_age_seconds' => 30, 'quantize_outward' => true],
    'stop' => ['kind' => 'atr', 'timeframe' => '5m', 'atr_multiplier' => 1.5, 'pivot_id' => null, 'buffer_rate' => 0.0],
    'targets' => [['id' => 'tp1', 'risk_multiple' => 1.8, 'liquidity_role' => 'taker']],
    'minimum_net_r' => 1.3,
    'invalidation' => ['kind' => 'close_beyond_stop'],
    'time_stop' => 'PT2H',
    'cost_contract' => ['entry_liquidity_role' => 'maker', 'stop_liquidity_role' => 'taker', 'entry_spread_source' => 'order_book', 'entry_slippage_source' => 'execution_model', 'stop_spread_source' => 'order_book', 'stop_slippage_source' => 'execution_model', 'target_spread_source' => 'order_book', 'target_slippage_source' => 'execution_model', 'funding_source' => 'venue_schedule', 'funding_interval_seconds' => 28800],
    'order_policy' => ['type' => 'limit', 'liquidity_role' => 'maker', 'ttl_seconds' => 45, 'cancel_after_seconds' => 75, 'market_fallback' => false, 'maximum_spread_bps' => 6.0, 'maximum_slippage_bps' => 8.0],
]
```

Mirror exact setup identities and values in the JSON Schema.

- [ ] **Step 4: Create the three setup YAMLs**

For each setup, copy its complete 1.0.0 rule tree into 1.1.0 without combining
branches. Change status/executable/compatibility and replace all execution
decisions with the exact frozen shape above. Set `validity_window` to PT5M,
`known_defects: []`, and require `[ohlcv_1h, ohlcv_15m, ohlcv_5m, ohlcv_1m,
ema, macd, rsi, atr, vwap, volume_ratio, order_book, fee_schedule,
funding_schedule]`.

The pullback node remains explicit:

```yaml
- { condition: pullback_confirmed, timeframe: 15m, parameters: { validity_bars: 3 }, provenance: 'validations.scalper.yaml:157-161,301' }
```

- [ ] **Step 5: Add exact scenario inventory fixture**

Create a JSON fixture with these records and non-empty evidence requirements:

```json
{
  "schema_version": "scalping-shadow-scenarios.v1",
  "scenarios": [
    {"id":"continuation_long_pass","setup_id":"scalping.trend_continuation.long","side":"long","expectation":"pass"},
    {"id":"pullback_long_pass","setup_id":"scalping.pullback.long","side":"long","expectation":"pass"},
    {"id":"short_momentum_pass","setup_id":"scalping.trend_momentum.short","side":"short","expectation":"pass"},
    {"id":"scenario_a_cannot_rescue_pullback","setup_id":"scalping.pullback.long","side":"long","expectation":"no_trade"},
    {"id":"scenario_b_cannot_rescue_continuation","setup_id":"scalping.trend_continuation.long","side":"long","expectation":"no_trade"},
    {"id":"missing_1m","setup_id":"scalping.trend_momentum.short","side":"short","expectation":"no_trade"}
  ]
}
```

- [ ] **Step 6: Run setup tests and commit**

Run the Task 2 PHPUnit command plus YAML lint for the three files. Expected:
PASS.

```bash
git add trading-app/src/TradingCore/Setup/SetupContractValidator.php trading-app/config/trading/schema/setup-contract.schema.json trading-app/config/trading/setup_contract/scalping.* trading-app/tests/Fixtures/TradingCore/Setup/scalping-1.1.0-scenarios.json trading-app/tests/TradingCore/Setup/SetupContractLoaderTest.php
git commit -m "feat: publish three scalping shadow setups"
```

### Task 3: Make pullback and MACD input authority executable

**Files:**
- Modify: `trading-app/config/trading/condition_catalog/1.0.0.yaml`
- Create: `trading-app/src/Indicator/Context/CanonicalPullbackAgeCalculator.php`
- Modify: `trading-app/src/Indicator/Provider/IndicatorProviderService.php`
- Modify: `trading-app/src/Indicator/Context/IndicatorContextBuilder.php`
- Modify: `trading-app/src/TradingCore/Rules/Evaluation/StrictRuleEvaluator.php`
- Create: `trading-app/tests/Indicator/Context/CanonicalPullbackAgeCalculatorTest.php`
- Modify: `trading-app/tests/Indicator/Provider/IndicatorProviderServiceClosedKlineTest.php`
- Modify: `trading-app/tests/TradingCore/Rules/Evaluation/StrictRuleEvaluatorTest.php`
- Modify: `trading-app/tests/TradingCore/Rules/Compiler/RuleExpressionCompilerTest.php`

- [ ] **Step 1: Write RED tests for chronological series and pullback age**

Assert the provider/context emits `series_order: oldest_to_newest`, retains the
closed-kline MACD order, and computes age zero for a current MA9/MA21 cross,
age two for a two-bars-old near-VWAP confirmation, and null when neither exists.
Assert `pullback_confirmed` becomes executable only when `pullback_age_bars` is
present and within `validity_bars`.

Also assert explicit node parameters beat catalogue defaults:

```php
$node = $compiler->compile([
    'condition' => 'rsi_lt_70',
    'timeframe' => '15m',
    'parameters' => ['rsi_lt_70_threshold' => 74.0],
    'provenance' => 'test:explicit',
], 'long');
self::assertSame(74.0, $node->parameters['rsi_lt_70_threshold']);
```

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
cd trading-app
php bin/phpunit tests/Indicator/Context/CanonicalPullbackAgeCalculatorTest.php tests/Indicator/Provider/IndicatorProviderServiceClosedKlineTest.php tests/TradingCore/Rules/Evaluation/StrictRuleEvaluatorTest.php tests/TradingCore/Rules/Compiler/RuleExpressionCompilerTest.php
```

Expected: missing calculator/field and blocked pullback definition.

- [ ] **Step 3: Implement the pullback-age calculator**

Implement a focused readonly service:

```php
final readonly class CanonicalPullbackAgeCalculator
{
    /** @param list<float> $ema9 @param list<float> $ema21 @param list<float> $closes @param list<float> $vwaps */
    public function age(array $ema9, array $ema21, array $closes, array $vwaps, int $validityBars, float $nearVwapTolerance): ?int
    {
        $count = count($closes);
        if ($count < 2 || count($ema9) !== $count || count($ema21) !== $count || count($vwaps) !== $count) return null;
        for ($age = 0; $age <= $validityBars && $count - 1 - $age >= 1; ++$age) {
            $i = $count - 1 - $age;
            $cross = $ema9[$i - 1] <= $ema21[$i - 1] && $ema9[$i] > $ema21[$i];
            $near = $vwaps[$i] > 0.0 && abs(($closes[$i] / $vwaps[$i]) - 1.0) <= $nearVwapTolerance;
            if ($cross || $near) return $age;
        }
        return null;
    }
}
```

Use closed candles in oldest-to-newest order. Generate aligned EMA9, EMA21,
and VWAP series using existing indicator primitives; do not reverse arrays.
Attach `pullback_age_bars` and `series_order` to provider/context output.

- [ ] **Step 4: Activate and trace the canonical conditions**

Change only `pullback_confirmed` from blocked to executable and replace its
provenance with the calculator path plus source YAML. In `StrictRuleEvaluator`,
retain `array_replace($snapshot->values, $node->parameters, authority)` so node
parameters win, and extend trace with:

```php
'parameter_source' => array_fill_keys(array_keys($node->parameters), 'setup_contract'),
'series_order' => $definition->seriesOrder,
'reported_series_order' => $snapshot->values['series_order'] ?? null,
```

For series definitions, return `invalid_series_order` unless reported order is
exactly `oldest_to_newest`.

- [ ] **Step 5: Run focused tests and commit**

Run the Task 3 command. Expected: PASS.

```bash
git add trading-app/config/trading/condition_catalog/1.0.0.yaml trading-app/src/Indicator/Context/CanonicalPullbackAgeCalculator.php trading-app/src/Indicator/Provider/IndicatorProviderService.php trading-app/src/Indicator/Context/IndicatorContextBuilder.php trading-app/src/TradingCore/Rules/Evaluation/StrictRuleEvaluator.php trading-app/tests/Indicator/Context/CanonicalPullbackAgeCalculatorTest.php trading-app/tests/Indicator/Provider/IndicatorProviderServiceClosedKlineTest.php trading-app/tests/TradingCore/Rules/Evaluation/StrictRuleEvaluatorTest.php trading-app/tests/TradingCore/Rules/Compiler/RuleExpressionCompilerTest.php
git commit -m "feat: enforce canonical scalping indicator inputs"
```

### Task 4: Add strict runtime layers and capability resolution

**Files:**
- Create: `trading-app/config/trading/runtime/mode_exchange/scalping.1.1.0.fake.yaml`
- Create: `trading-app/config/trading/runtime/mode_exchange/scalping.1.1.0.okx.yaml`
- Create: `trading-app/config/trading/runtime/mode_exchange/scalping.1.1.0.hyperliquid.yaml`
- Modify: `trading-app/src/TradingCore/Config/EffectiveTradingConfigResolver.php`
- Modify: `trading-app/src/TradingCore/Config/EffectiveTradingConfigComposer.php`
- Modify: `trading-app/tests/TradingCore/Config/EffectiveTradingConfigRuntimeFilesTest.php`
- Modify: `trading-app/tests/TradingCore/Config/EffectiveTradingConfigComposerTest.php`
- Modify: `trading-app/tests/Config/ModernConfigLegacyQuarantineTest.php`

- [ ] **Step 1: Write failing six-layer and capability tests**

For all three setups, test Fake/test, OKX/demo, Hyperliquid/testnet, and
Paper/mainnet public-data resolution. Assert six layer types, exact modern IDs,
25-USDT effective exchange max notional, 3x leverage, disabled writes, and no
serialized `scalper` token. Assert null capability, PrivateMainnet, and backtest
on a non-fake exchange reject before resolution.

- [ ] **Step 2: Run and confirm RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Config/EffectiveTradingConfigRuntimeFilesTest.php tests/Config/ModernConfigLegacyQuarantineTest.php
```

Expected: missing scalping runtime layers/capability gate.

- [ ] **Step 3: Add exact runtime files**

Each file must use this shape, changing only `exchange`:

```yaml
mode_id: scalping
mode_version: '1.1.0'
exchange: fake
overrides:
  exchange.limits.max_notional: 25.0
```

Add `exchange.limits.max_notional` to the composer's exact override allowlist.
Validate it as a positive finite number, require it to be greater than or equal
to the current `exchange.limits.min_notional`, and reject any value above the
base exchange maximum. Add composer tests proving 25 is accepted, 1 is rejected
below the 5-USDT minimum, and 1001 is rejected as a weakening override. The mode
contract already owns the 3x leverage cap and requires no venue override.

- [ ] **Step 4: Generalize the Shadow capability gate**

Replace `$isDayTradingShadow` with an exact allowlist:

```php
$isModernShadow = in_array($request->modeId . '@' . $request->modeVersion, [
    'day_trading@1.1.0',
    'scalping@1.1.0',
], true);
```

Require a capability for both, forbid PrivateMainnet for both, and require fake
exchange for Backtest. Preserve existing day-trading reason messages; introduce
`scalping_shadow_capability_required` and
`scalping_backtest_requires_fake_exchange` for scalping.

- [ ] **Step 5: Run tests, lint, and commit**

Run Task 4 tests plus:

```bash
php bin/console lint:yaml config/trading/runtime
```

Expected: PASS and no `scalper` alias in modern runtime text.

```bash
git add trading-app/config/trading/runtime/mode_exchange/scalping.1.1.0.*.yaml trading-app/src/TradingCore/Config/EffectiveTradingConfigResolver.php trading-app/src/TradingCore/Config/EffectiveTradingConfigComposer.php trading-app/tests/TradingCore/Config/EffectiveTradingConfigRuntimeFilesTest.php trading-app/tests/TradingCore/Config/EffectiveTradingConfigComposerTest.php trading-app/tests/Config/ModernConfigLegacyQuarantineTest.php
git commit -m "feat: resolve scalping shadow runtime layers"
```

### Task 5: Extract the shared Shadow orchestration without regressing #306

**Files:**
- Create: `trading-app/src/TradingCore/Shadow/ShadowRuntimeRequest.php`
- Create: `trading-app/src/TradingCore/Shadow/ShadowRuntimeOutcome.php`
- Create: `trading-app/src/TradingCore/Shadow/ShadowRuntimeIdentityPolicy.php`
- Create: `trading-app/src/TradingCore/Shadow/CanonicalShadowRuntime.php`
- Modify: `trading-app/src/TradingCore/DayTrading/DayTradingShadowRuntime.php`
- Modify: `trading-app/tests/TradingCore/DayTrading/DayTradingShadowRuntimeTest.php`
- Modify: `trading-app/tests/TradingCore/DayTrading/DayTradingShadowDependencyTest.php`
- Create: `trading-app/tests/TradingCore/Shadow/CanonicalShadowRuntimeTest.php`

- [ ] **Step 1: Freeze the existing day-trading observable API**

Add tests asserting `DayTradingShadowRuntime::run()` retains its constructor
facade, `DayTradingShadowRequest`/`Outcome` types, all current reason codes,
evidence keys, hashes, adapter selection, and private-mainnet rejection after
the extraction.

- [ ] **Step 2: Run day-trading tests as the pre-refactor baseline**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/DayTrading
```

Expected: PASS before edits.

- [ ] **Step 3: Introduce shared immutable DTOs and policy**

`ShadowRuntimeRequest` carries the same nine canonical inputs currently held by
`DayTradingShadowRequest`. `ShadowRuntimeOutcome` permits only `planned` and
`no_trade`. `ShadowRuntimeIdentityPolicy` contains exact accepted rows:

```php
/** @param list<array{mode_id:string,mode_version:string,setup_id:string,setup_version:string,side:string}> $identities */
public function __construct(public string $reasonPrefix, public array $identities) {}
public function accepts(EffectiveTradingConfigRequest $request): bool;
public function reason(string $suffix): string { return $this->reasonPrefix . '_' . $suffix; }
```

- [ ] **Step 4: Move orchestration into `CanonicalShadowRuntime`**

Move capability selection, config resolution, complete lineage match,
setup-rule evaluation, policy compilation, spread/slippage/cost identity guard,
limit-only plan construction, portfolio admission, and evidence assembly from
the day runtime. The entry point is:

```php
public function run(ShadowRuntimeRequest $request, ShadowRuntimeIdentityPolicy $policy): ShadowRuntimeOutcome
```

Build every reason through `$policy->reason(...)`, except domain reason codes
already owned by canonical exceptions.

- [ ] **Step 5: Convert day trading into an adapter facade**

Its `run()` converts the request to the shared DTO, calls the core with one
accepted long identity, and converts the shared outcome back. Use prefix
`day_trading_shadow` so every existing code stays byte-for-byte stable.

- [ ] **Step 6: Run tests and commit**

```bash
php bin/phpunit tests/TradingCore/Shadow tests/TradingCore/DayTrading
php bin/console lint:container
```

Expected: PASS; container lint may emit only the pre-existing
`EntryZoneStatsCommand` PHP 8.4 deprecation.

```bash
git add trading-app/src/TradingCore/Shadow trading-app/src/TradingCore/DayTrading/DayTradingShadowRuntime.php trading-app/tests/TradingCore/Shadow trading-app/tests/TradingCore/DayTrading
git commit -m "refactor: share canonical shadow orchestration"
```

### Task 6: Add the scalping facade and contract-driven rule metadata

**Files:**
- Create: `trading-app/src/TradingCore/Scalping/ScalpingShadowRequest.php`
- Create: `trading-app/src/TradingCore/Scalping/ScalpingShadowOutcome.php`
- Create: `trading-app/src/TradingCore/Scalping/ScalpingShadowRuntime.php`
- Modify: `trading-app/src/MtfValidator/Policy/CanonicalSetupRuleRuntime.php`
- Create: `trading-app/tests/TradingCore/Scalping/ScalpingShadowRuntimeTest.php`
- Create: `trading-app/tests/TradingCore/Scalping/ScalpingShadowDependencyTest.php`
- Modify: `trading-app/tests/MtfValidator/Policy/CanonicalSetupRuleRuntimeTest.php`

- [ ] **Step 1: Write failing scalping runtime tests**

Use one fixture factory parameterized by setup ID and side. Assert each exact
identity plans successfully with full lineage. Assert cross-setup rescue,
wrong side/version, missing/stale 1m, config hash mismatch, excessive costs,
market plan, and PrivateMainnet all return `no_trade` with a
`scalping_shadow_*` reason and no reservation.

- [ ] **Step 2: Run and confirm RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Scalping tests/MtfValidator/Policy/CanonicalSetupRuleRuntimeTest.php
```

Expected: scalping classes absent and rule runtime lacks 5m/1m metadata.

- [ ] **Step 3: Make required timeframes contract-driven**

After loading the setup contract, derive execution and confirmations from its
defined execution decisions. For the three scalping contracts the trace must be:

```php
'execution_timeframe' => '5m',
'mandatory_confirmations' => ['1m'],
```

Require the union of mode roles for executable Shadow contracts. Return
`critical_timeframe_missing` or `critical_timeframe_stale` without a
mode-specific branch. Keep day-trading trace unchanged.

- [ ] **Step 4: Implement the scalping DTOs/facade**

Mirror the typed day facade shape but delegate to `CanonicalShadowRuntime` with:

```php
new ShadowRuntimeIdentityPolicy('scalping_shadow', [
    ['mode_id' => 'scalping', 'mode_version' => '1.1.0', 'setup_id' => 'scalping.trend_continuation.long', 'setup_version' => '1.1.0', 'side' => 'long'],
    ['mode_id' => 'scalping', 'mode_version' => '1.1.0', 'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.1.0', 'side' => 'long'],
    ['mode_id' => 'scalping', 'mode_version' => '1.1.0', 'setup_id' => 'scalping.trend_momentum.short', 'setup_version' => '1.1.0', 'side' => 'short'],
]);
```

- [ ] **Step 5: Run tests and commit**

Run Task 6 tests and container lint. Expected: PASS.

```bash
git add trading-app/src/TradingCore/Scalping trading-app/src/MtfValidator/Policy/CanonicalSetupRuleRuntime.php trading-app/tests/TradingCore/Scalping trading-app/tests/MtfValidator/Policy/CanonicalSetupRuleRuntimeTest.php
git commit -m "feat: execute three scalping shadow setups"
```

### Task 7: Enforce scalping plan, holding, notional, and portfolio boundaries

**Files:**
- Modify: `trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanValidator.php`
- Modify: `trading-app/tests/TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicyCompilerTest.php`
- Modify: `trading-app/tests/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanBuilderTest.php`
- Modify: `trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskPolicyTest.php`
- Modify: `trading-app/tests/TradingCore/Risk/Canonical/Portfolio/CanonicalPortfolioAdmissionEngineTest.php`
- Modify: `trading-app/tests/TradingCore/Scalping/ScalpingShadowRuntimeTest.php`

- [ ] **Step 1: Write failing boundary tests**

Compile each scalping snapshot and assert 2-percent risk, daily min(6%, 40),
concurrency 3 including pending entries, exposure 75 percent, leverage 3, and
25-USDT notional. Build plans and assert:

```php
self::assertSame('2026-08-10T12:00:45+00:00', $plan->expiresAt->format(DATE_ATOM));
self::assertSame('2026-08-10T12:01:15+00:00', $plan->cancelAfterAt?->format(DATE_ATOM));
self::assertSame('2026-08-10T14:00:00+00:00', $plan->holdingExpiresAt?->format(DATE_ATOM));
self::assertSame(1.8, $plan->targets[0]->riskMultiple);
self::assertGreaterThanOrEqual(1.3, $plan->targets[0]->netR);
```

Add rejection fixtures at 25.01 USDT, fourth concurrent reservation, leverage
above 3, exposure above 75 percent, and daily loss at either cap.

- [ ] **Step 2: Run focused tests and confirm RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/OrderPlan/Canonical/CanonicalExecutionPolicyCompilerTest.php tests/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanBuilderTest.php tests/TradingCore/Risk/Canonical tests/TradingCore/Scalping/ScalpingShadowRuntimeTest.php
```

Expected: validator rejects or fails to freeze scalping deadlines/caps.

- [ ] **Step 3: Add exact scalping deadline validation**

In `CanonicalOrderPlanValidator`, add the exact `scalping@1.1.0` branch. Require
entry expiry no later than +45 seconds, cancellation no later than +75 seconds
and not before entry expiry, and holding expiry exactly
`CanonicalHoldingBoundary::expiresAt(createdAt, 7200, PT2H/UTC policy)`.

Do not add default TTLs or infer mode by prefix. Keep legacy plans unable to
carry modern deadlines.

- [ ] **Step 4: Make the tests pass through existing #304 authorities**

Use `CanonicalExecutionPolicy::fromSnapshot()` and
`CanonicalPortfolioPolicy::fromSnapshot()` unchanged where they already compile
the contract values. Change production code only where a RED assertion proves a
missing cap. Never introduce a second sizing computation.

- [ ] **Step 5: Run tests and commit**

Run Task 7 tests. Expected: PASS.

```bash
git add trading-app/src/TradingCore/OrderPlan/Canonical/CanonicalOrderPlanValidator.php trading-app/tests/TradingCore/OrderPlan/Canonical trading-app/tests/TradingCore/Risk/Canonical trading-app/tests/TradingCore/Scalping/ScalpingShadowRuntimeTest.php
git commit -m "feat: enforce scalping canonical boundaries"
```

### Task 8: Prove maker non-fill, partial fill, fallback rejection, and adapter parity

**Files:**
- Create: `trading-app/tests/TradingCore/Scalping/ScalpingShadowFillLifecycleTest.php`
- Create: `trading-app/tests/TradingCore/Scalping/ScalpingShadowAdapterParityTest.php`
- Modify: `trading-app/tests/TradingCore/Scalping/ScalpingShadowRuntimeTest.php`

- [ ] **Step 1: Add lifecycle fixtures**

Test these exact transitions through every selected adapter:

```text
maker full fill before +45s -> filled reservation
no fill at +45s -> cancelResidual -> cancelled
partial fill before +45s -> cancel residual at +75s -> partially_filled
holding deadline after partial/full fill -> holding_expired + close_position
market OrderPlan request -> scalping_shadow_non_limit_plan_forbidden
```

Assert applied fill hashes, transition hashes, config/plan/admission hashes, and
net costs remain identical for equal observations.

- [ ] **Step 2: Run and confirm failures expose missing behavior only**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Scalping/ScalpingShadowFillLifecycleTest.php tests/TradingCore/Scalping/ScalpingShadowAdapterParityTest.php
```

Expected: initial failures only where facade fixtures or deadline wiring are
incomplete; no adapter-specific policy implementation is permitted.

- [ ] **Step 3: Make minimal fixture/runtime corrections**

Route every transition through `CanonicalPortfolioAdapterInterface` methods
`applyFill`, `cancelResidual`, and `enforceHoldingDeadline`. Do not add a
scalping-specific reservation state or fallback order.

- [ ] **Step 4: Run tests and commit**

Expected: PASS with identical normalized decisions across Fake, Paper, and
backtest.

```bash
git add trading-app/tests/TradingCore/Scalping
git commit -m "test: prove scalping shadow execution parity"
```

### Task 9: Add a deterministic net report separated by setup and side

**Files:**
- Create: `trading-app/src/TradingCore/Scalping/ScalpingNetReport.php`
- Create: `trading-app/src/TradingCore/Scalping/ScalpingNetReportCell.php`
- Create: `trading-app/tests/TradingCore/Scalping/ScalpingNetReportTest.php`
- Create: `trading-app/tests/Fixtures/TradingCore/Scalping/scalping-net-report.json`

- [ ] **Step 1: Write failing grouping tests**

Feed accepted outcomes for two long setup IDs and the short setup. Assert three
cells, exact keys `setup_id|setup_version|side`, no cross-setup aggregation,
canonical gross/net R and cost totals, and `certified: false`.

Reject outcomes whose lineage/hash is incomplete or whose net values are
non-finite.

- [ ] **Step 2: Run and confirm RED**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Scalping/ScalpingNetReportTest.php
```

Expected: report classes absent.

- [ ] **Step 3: Implement immutable report cells**

Use this public shape:

```php
final readonly class ScalpingNetReportCell
{
    public function __construct(
        public string $setupId,
        public string $setupVersion,
        public string $side,
        public int $sampleCount,
        public float $grossR,
        public float $netR,
        public float $costQuote,
        public bool $certified = false,
    ) {}
}
```

`ScalpingNetReport::fromOutcomes(array $outcomes)` groups only exact complete
modern lineage and derives costs from canonical plans; it never accepts a caller
supplied aggregate key.

- [ ] **Step 4: Freeze deterministic JSON evidence and commit**

Serialize sorted cells to the fixture with schema
`scalping-net-report.v1`, `tuning_applied: false`, and `certified: false`.

```bash
php bin/phpunit tests/TradingCore/Scalping/ScalpingNetReportTest.php
git add trading-app/src/TradingCore/Scalping/ScalpingNetReport.php trading-app/src/TradingCore/Scalping/ScalpingNetReportCell.php trading-app/tests/TradingCore/Scalping/ScalpingNetReportTest.php trading-app/tests/Fixtures/TradingCore/Scalping/scalping-net-report.json
git commit -m "feat: report scalping net evidence by setup"
```

Expected: PASS.

### Task 10: Complete regression verification and ready PR workflow

**Files:**
- Modify only if a verification failure identifies a defect in #307 scope.

- [ ] **Step 1: Run the complete modern TradingCore gate**

```bash
cd trading-app
php bin/phpunit tests/TradingCore/Mode tests/TradingCore/Setup tests/TradingCore/Config tests/TradingCore/Rules tests/MtfValidator/Policy tests/TradingCore/OrderPlan/Canonical tests/TradingCore/Risk/Canonical tests/TradingCore/DayTrading tests/TradingCore/Shadow tests/TradingCore/Scalping tests/Trading/Controller/Api/EffectiveTradingConfigApiControllerTest.php tests/Config/ModernConfigLegacyQuarantineTest.php tests/Indicator/Context/CanonicalPullbackAgeCalculatorTest.php tests/Indicator/Provider/IndicatorProviderServiceClosedKlineTest.php
```

Expected: all tests pass; only already-recorded PHPUnit deprecations are allowed.

- [ ] **Step 2: Run static and configuration checks**

```bash
vendor/bin/phpstan analyse --no-progress --memory-limit=512M src/TradingCore/Shadow src/TradingCore/Scalping src/TradingCore/DayTrading src/TradingCore/Mode src/TradingCore/Setup src/TradingCore/Rules src/TradingCore/OrderPlan/Canonical src/MtfValidator/Policy tests/TradingCore/Scalping
php bin/console lint:yaml config/trading config/services.yaml
php bin/console lint:container
git diff --check origin/main...HEAD
```

Expected: no PHPStan errors, all YAML valid, container valid, clean diff. The
known `EntryZoneStatsCommand` nullable-parameter deprecation may still print.

- [ ] **Step 3: Audit forbidden behavior**

```bash
rg -n "\bscalper\b|market_fallback: true|write_enabled: true|mainnet_write_enabled: true" trading-app/config/trading/runtime trading-app/src/TradingCore/Scalping trading-app/src/TradingCore/Shadow
```

Expected: no match. Provenance references to legacy files are allowed only in
the immutable contracts, outside runtime/source execution paths.

- [ ] **Step 4: Review the complete diff and commit verification-only fixes**

Use `git diff origin/main...HEAD` and ensure every changed production file is
covered by a listed test. If a defect is found, write a failing regression test,
fix it minimally, rerun its focused suite and the full Task 10 gate, then commit
with a specific `fix:` message.

- [ ] **Step 5: Push and open one ready PR for #307**

```bash
git push -u origin codex/issue-307-scalping
gh pr create --base main --head codex/issue-307-scalping --title "#307: publish executable scalping shadow baseline" --body-file /tmp/issue-307-pr.md
gh pr ready
```

The PR body must list the three identities, immutable policy decisions,
verification commands/results, mainnet prohibition, deterministic report caveat,
and `Closes #307`.

- [ ] **Step 6: Complete three Codex review cycles and merge**

For each cycle, comment `@codex review`, wait for completion, inspect unresolved
threads via GraphQL, validate each finding technically, fix all actionable
findings test-first, reply inline, resolve threads, push, and wait for checks.
After the third clean/corrected cycle and green required checks:

```bash
gh pr merge --squash --delete-branch
git fetch origin main
```

Confirm the PR state is `MERGED` even if local branch cleanup reports that
`main` belongs to the primary worktree. Do not alter the primary checkout.
