# Issue #304 Lot A Canonical Risk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Build the single modern risk authority that compiles explicit units, sizes against cost-inclusive stop loss, applies every notional/leverage cap, quantizes conservatively, and proves the final loss remains within budget.

**Architecture:** Add a new fail-closed `App\TradingCore\Risk\Canonical` boundary beside the unchanged legacy preparatory module. A strict compiler consumes an immutable effective-config snapshot and produces a unit-normalized policy; a pure engine consumes that policy plus an explicit market/account/cost snapshot and returns a fully audited decision or a stable structured rejection. Runtime wiring, EntryZone/net-R, and portfolio reservations remain owned by Lots B and C, so the existing runtime blockers remain until their consumers are complete.

**Tech Stack:** PHP 8.4, PHPUnit 11, Symfony effective-config DTOs, PHPStan.

---

### Task 1: Compile canonical percentages exactly once

**Files:**
- Create: `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskException.php`
- Create: `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskPolicy.php`
- Create: `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskPolicyCompiler.php`
- Create: `trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskPolicyCompilerTest.php`

- [x] **Step 1: Write the failing compiler tests**

Create a synthetic executable `EffectiveTradingConfigSnapshot` fixture containing full decision objects. Assert that `0.4` with unit `percent_equity_per_trade` becomes exactly `0.004`, identity and hash are retained, mode leverage and notional caps are retained, and maker/taker fees are retained. Add data-provider cases for an unresolved decision, `quote_notional`, a non-finite/out-of-range value, a legacy duplicate key, unsafe write gates, and mismatched identity. Every rejection must expose the exact stable `reasonCode`.

```php
$policy = (new CanonicalRiskPolicyCompiler())->compile($this->snapshot(0.4));
self::assertSame(0.004, $policy->riskRate);
self::assertSame('sha256:' . str_repeat('a', 64), $policy->configHash);

$this->expectException(CanonicalRiskException::class);
$this->expectExceptionMessage('canonical_policy_trade_budget_unit_invalid');
(new CanonicalRiskPolicyCompiler())->compile($this->snapshot(0.4, 'quote_notional'));
```

- [x] **Step 2: Run the tests and verify RED**

Run:

```bash
trading-app/vendor/bin/phpunit -c trading-app/phpunit.xml.dist trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskPolicyCompilerTest.php
```

Expected: test loading fails because the canonical classes do not exist.

- [x] **Step 3: Implement the immutable policy and structured exception**

Use these public contracts:

```php
final class CanonicalRiskException extends \RuntimeException
{
    /** @param array<string, int|float|string|bool|null> $evidence */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $evidence = [],
    ) {
        parent::__construct($reasonCode);
    }
}

final readonly class CanonicalRiskPolicy
{
    public function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
        public string $configHash,
        public float $riskRate,
        public float $modeLeverageCap,
        public float $makerFeeRate,
        public float $takerFeeRate,
        public float $exchangeMaxNotional,
        public float $environmentMaxNotional,
    ) {}
}
```

The compiler must require `snapshot->executable === true`, no blockers, exact request/payload identity, all safety gates false/true as frozen by #133, and an exact defined decision:

```php
[
    'state' => 'defined',
    'value' => 0.4,
    'unit' => 'percent_equity_per_trade',
]
```

Convert with `$riskRate = (float) $value / 100.0`. Reject any legacy risk key instead of selecting precedence. Validate all numeric values with `is_finite`, require `0 < riskRate <= 1`, `modeLeverageCap >= 1`, rates in `[0, 1)`, and positive notional caps.

- [x] **Step 4: Run compiler tests and verify GREEN**

Run the Task 1 PHPUnit command. Expected: all compiler cases pass.

- [x] **Step 5: Commit the compiler boundary**

```bash
git add trading-app/src/TradingCore/Risk/Canonical trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskPolicyCompilerTest.php
git commit -m "feat(risk): compile canonical risk policy units"
```

### Task 2: Define explicit cost and calculation snapshots

**Files:**
- Create: `trading-app/src/TradingCore/Risk/Canonical/CanonicalCostSnapshot.php`
- Create: `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskCalculationRequest.php`
- Create: `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskDecision.php`
- Create: `trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskContractTest.php`

- [x] **Step 1: Write failing contract validation tests**

Assert that missing spread, slippage, funding, entry fee, or stop-exit fee is rejected; negative steps/prices/equity are rejected; long/short stop polarity is enforced; and `fundingIntervals=0` with an explicit `fundingRate=0.0` is accepted. Use stable codes such as `canonical_market_cost_unknown`, `canonical_risk_quantity_step_invalid`, and `canonical_risk_stop_side_invalid`.

```php
$this->expectExceptionMessage('canonical_market_cost_unknown');
new CanonicalCostSnapshot(
    entryFeeRate: 0.001,
    stopExitFeeRate: 0.001,
    spreadRate: null,
    slippageRate: 0.0005,
    fundingRate: 0.0,
    fundingIntervals: 0,
);
```

- [x] **Step 2: Run the contract tests and verify RED**

Run the new test file. Expected: missing classes cause failure.

- [x] **Step 3: Implement exact DTOs with constructor validation**

Use these fields without percentage aliases or multipliers:

```php
final readonly class CanonicalCostSnapshot
{
    public function __construct(
        public ?float $entryFeeRate,
        public ?float $stopExitFeeRate,
        public ?float $spreadRate,
        public ?float $slippageRate,
        public ?float $fundingRate,
        public ?int $fundingIntervals,
    ) {}
}

final readonly class CanonicalRiskCalculationRequest
{
    public function __construct(
        public CanonicalRiskPolicy $policy,
        public string $symbol,
        public string $side,
        public float $equityQuote,
        public float $availableBalanceQuote,
        public float $entryPrice,
        public float $stopPrice,
        public float $contractSize,
        public float $quantityStep,
        public float $minQuantity,
        public float $maxQuantity,
        public ?float $marketMaxQuantity,
        public float $exchangeLeverageCap,
        public ?float $symbolLeverageCap,
        public CanonicalCostSnapshot $costs,
    ) {}
}

final readonly class CanonicalRiskDecision
{
    /** @param list<string> $capsApplied */
    public function __construct(
        public float $riskBudgetQuote,
        public float $quantity,
        public float $positionNotional,
        public int $finalLeverage,
        public float $grossStopLoss,
        public float $entryFee,
        public float $stopExitFee,
        public float $spreadCost,
        public float $slippageCost,
        public float $fundingCost,
        public float $totalStopLoss,
        public float $rawQuantity,
        public float $quantityStep,
        public array $capsApplied,
        public CanonicalRiskPolicy $policy,
    ) {}
}
```

Constructors reject non-finite values and invalid ranges immediately. `side` must exactly match the policy and be `long` or `short`. No `0.4` heuristic is allowed.

- [x] **Step 4: Run contract tests and the existing legacy risk suite**

Run:

```bash
trading-app/vendor/bin/phpunit -c trading-app/phpunit.xml.dist trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskContractTest.php trading-app/tests/TradingCore/Risk
```

Expected: canonical and unchanged legacy tests pass.

- [x] **Step 5: Commit the explicit snapshots**

```bash
git add trading-app/src/TradingCore/Risk/Canonical trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskContractTest.php
git commit -m "feat(risk): add explicit canonical calculation snapshots"
```

### Task 3: Size from total stop-path loss and quantize down

**Files:**
- Create: `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskEngine.php`
- Create: `trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskEngineTest.php`

- [x] **Step 1: Write failing deterministic sizing tests**

Cover long and short examples, non-zero fees/spread/slippage/funding, quantity-step rounding, minimum quantity rejection, max/market quantity caps, exchange/environment notional caps, and explicit zero available balance. Assert the recomputed component sum and `totalStopLoss <= riskBudgetQuote`.

For a no-cost baseline with equity `1000`, risk rate `0.01`, entry `100`, stop `98`, and step `0.001`, assert budget `10`, quantity `5`, notional `500`, and gross/total stop loss `10`.

For a cost-aware case, derive the expected per-unit loss in the test:

```php
$grossPerUnit = abs($entry - $stop) * $contractSize;
$costPerUnit = $entry * $contractSize * ($entryFee + $spread + $slippage + max(0.0, $funding) * $intervals)
    + $stop * $contractSize * $stopExitFee;
$expectedRaw = $riskBudget / ($grossPerUnit + $costPerUnit);
```

- [x] **Step 2: Run engine tests and verify RED**

Run the new engine test. Expected: `CanonicalRiskEngine` is missing.

- [x] **Step 3: Implement cost-inclusive sizing**

The engine must calculate:

```php
$riskBudget = $request->equityQuote * $request->policy->riskRate;
$grossPerQuantity = abs($request->entryPrice - $request->stopPrice) * $request->contractSize;
$entryNotionalPerQuantity = $request->entryPrice * $request->contractSize;
$stopNotionalPerQuantity = $request->stopPrice * $request->contractSize;
$costPerQuantity = $entryNotionalPerQuantity * (
    $costs->entryFeeRate + $costs->spreadRate + $costs->slippageRate
    + max(0.0, $costs->fundingRate) * $costs->fundingIntervals
) + $stopNotionalPerQuantity * $costs->stopExitFeeRate;
$rawQuantity = $riskBudget / ($grossPerQuantity + $costPerQuantity);
```

Intersect that quantity with max quantity, optional market max quantity, exchange/environment notional caps, and leverage capacity before flooring to the quantity step. Never round up to minimum quantity. Recompute every quote component from final quantity and reject `canonical_risk_post_quantization_breach` if the total exceeds budget.

- [x] **Step 4: Run focused and legacy suites and verify GREEN**

Run the Task 3 test plus all `tests/TradingCore/Risk`. Expected: all pass with no legacy behavior change.

- [x] **Step 5: Commit cost-aware sizing**

```bash
git add trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskEngine.php trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskEngineTest.php
git commit -m "feat(risk): enforce cost-aware post-quantization budget"
```

### Task 4: Prove cap and quantization invariants as properties

**Files:**
- Create: `trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskInvariantTest.php`
- Modify: `trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskEngine.php`

- [x] **Step 1: Add deterministic property loops and verify RED where needed**

Use a fixed seed and at least 500 generated cases spanning both sides, risk rates `0.001..0.1`, stop distances, quantity steps, fee/cost rates, balances, and contradictory mode/exchange/symbol caps. For every accepted result assert:

```php
self::assertLessThanOrEqual($decision->riskBudgetQuote + 1e-9, $decision->totalStopLoss);
self::assertLessThanOrEqual((int) floor(min($modeCap, $exchangeCap, $symbolCap)), $decision->finalLeverage);
self::assertEqualsWithDelta(0.0, fmod($decision->quantity, $step), 1e-9);
```

The first two PHPUnit arguments are expected-value first; these assertions mean actual loss/leverage are less than or equal to their limits. Also assert no request or result property contains `multiplier`, `riskPct`, or `risk_pct_percent`.

- [x] **Step 2: Run invariant tests and capture any counterexample**

Run only `CanonicalRiskInvariantTest`. Expected: any float-boundary or cap-order defect is exposed with the seeded case in the failure message.

- [x] **Step 3: Apply the minimal conservative correction**

Compute the effective integer leverage cap as `floor(min(all configured caps))`. Cap notional to `availableBalanceQuote * effectiveCap`, then quantize quantity down. Derive final leverage as `max(1, ceil(positionNotional / availableBalanceQuote - 1e-12))`. If a floating boundary still breaches risk, subtract exactly one quantity step and recompute all components; reject if the result falls below minimum quantity.

- [x] **Step 4: Run invariant, canonical, and legacy risk suites**

Expected: 500+ invariant cases and all focused tests pass deterministically.

- [x] **Step 5: Commit invariant hardening**

```bash
git add trading-app/src/TradingCore/Risk/Canonical/CanonicalRiskEngine.php trading-app/tests/TradingCore/Risk/Canonical/CanonicalRiskInvariantTest.php
git commit -m "test(risk): prove canonical sizing invariants"
```

### Task 5: Document the boundary and verify Lot A

**Files:**
- Modify: `docs/handbook/technical/risk-and-leverage-module.md`
- Modify: `docs/superpowers/plans/2026-08-10-issue-304-lot-a-canonical-risk.md`

- [x] **Step 1: Document legacy/canonical separation**

Add a `#304 Lot A` section stating that the old `PositionSizer`, `LeverageCalculator`, and `RiskConfigInterpreter` remain legacy-only; the new canonical engine has one percentage source, includes stop-path costs, reapplies caps after quantization, and is not wired until Lots B/C complete. State explicitly that runtime blockers and mainnet write prohibition remain.

- [x] **Step 2: Run complete Lot A verification**

Run:

```bash
trading-app/vendor/bin/phpunit -c trading-app/phpunit.xml.dist trading-app/tests/TradingCore/Risk trading-app/tests/TradeEntry/Policy/CanonicalTradeRuntimePolicyValidatorTest.php
trading-app/vendor/bin/phpstan analyse -c trading-app/phpstan.dist.neon --no-progress trading-app/src/TradingCore/Risk/Canonical trading-app/tests/TradingCore/Risk/Canonical
find trading-app/src/TradingCore/Risk/Canonical trading-app/tests/TradingCore/Risk/Canonical -name '*.php' -print0 | xargs -0 -n1 php -l
git diff --check origin/main...HEAD
```

Expected: PHPUnit and PHPStan report zero failures/errors, every PHP file reports no syntax error, and diff check is clean.

- [x] **Step 3: Inspect scope and leave runtime blockers intact**

Confirm the diff does not modify legacy YAML, `CanonicalTradeRuntimePolicyValidator`, execution ports, or mainnet write gates. Confirm no class in the canonical namespace imports `TradeEntryConfig`, `ExecutionBox`, providers, Doctrine, Messenger, or HTTP clients.

- [x] **Step 4: Mark completed checkboxes and commit documentation**

```bash
git add docs/handbook/technical/risk-and-leverage-module.md docs/superpowers/plans/2026-08-10-issue-304-lot-a-canonical-risk.md
git commit -m "docs(risk): record canonical lot A boundary"
```

- [ ] **Step 5: Prepare review handoff**

Push `codex/issue-304-risk-runtime`, open a draft PR referencing #304, include Lot B/C continuation notes, request a full-diff Codex review, and do not mark ready or merge until review findings and CI are resolved.
