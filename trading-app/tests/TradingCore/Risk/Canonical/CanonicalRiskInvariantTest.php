<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Risk\Canonical;

use App\TradingCore\Risk\Canonical\CanonicalCostSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;
use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use App\TradingCore\Risk\Canonical\CanonicalRiskException;
use App\TradingCore\Risk\Canonical\CanonicalRiskPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalRiskEngine::class)]
final class CanonicalRiskInvariantTest extends TestCase
{
    #[DataProvider('invalidDirectPolicyProvider')]
    public function testDirectCallCannotBypassPolicyCaps(
        float $modeCap,
        float $exchangeNotionalCap,
        float $environmentNotionalCap,
        string $reasonCode,
    ): void {
        $this->expectException(CanonicalRiskException::class);
        $this->expectExceptionMessage($reasonCode);
        (new CanonicalRiskEngine())->calculate($this->request(
            side: 'long',
            policy: $this->policy('long', 0.01, $modeCap, $exchangeNotionalCap, $environmentNotionalCap),
        ));
    }

    /** @return iterable<string, array{float, float, float, string}> */
    public static function invalidDirectPolicyProvider(): iterable
    {
        yield 'mode leverage below one' => [0.9, 1000.0, 500.0, 'canonical_leverage_cap_invalid'];
        yield 'zero exchange notional' => [5.0, 0.0, 500.0, 'canonical_policy_notional_cap_invalid'];
        yield 'negative environment notional' => [5.0, 1000.0, -1.0, 'canonical_policy_notional_cap_invalid'];
        yield 'infinite environment notional' => [5.0, 1000.0, INF, 'canonical_policy_notional_cap_invalid'];
    }

    public function testRiskLeverageAndStepInvariantsAcrossFiveHundredDeterministicCases(): void
    {
        mt_srand(304);
        $engine = new CanonicalRiskEngine();
        $steps = [0.001, 0.01, 0.1];

        for ($case = 0; $case < 500; ++$case) {
            $side = $case % 2 === 0 ? 'long' : 'short';
            $equity = (float) mt_rand(1000, 10000);
            $riskRate = mt_rand(1, 100) / 1000.0;
            $entry = mt_rand(100, 5000) / 10.0;
            $stopRate = mt_rand(5, 100) / 1000.0;
            $stop = $side === 'long' ? $entry * (1.0 - $stopRate) : $entry * (1.0 + $stopRate);
            $step = $steps[$case % count($steps)];
            $available = $equity * mt_rand(5, 100) / 100.0;
            $modeCap = mt_rand(10, 150) / 10.0;
            $exchangeCap = mt_rand(10, 200) / 10.0;
            $symbolCap = mt_rand(10, 120) / 10.0;
            $notionalCeiling = $equity * 20.0;
            $costs = new CanonicalCostSnapshot(
                entryFeeRate: mt_rand(0, 10) / 10000.0,
                stopExitFeeRate: mt_rand(0, 10) / 10000.0,
                spreadRate: mt_rand(0, 20) / 10000.0,
                slippageRate: mt_rand(0, 20) / 10000.0,
                fundingRate: mt_rand(-10, 20) / 10000.0,
                fundingIntervals: mt_rand(0, 3),
            );
            $policy = $this->policy(
                $side,
                $riskRate,
                $modeCap,
                $notionalCeiling,
                $notionalCeiling,
                (float) $costs->entryFeeRate,
                (float) $costs->stopExitFeeRate,
            );
            $request = $this->request(
                side: $side,
                policy: $policy,
                equity: $equity,
                available: $available,
                entry: $entry,
                stop: $stop,
                step: $step,
                exchangeCap: $exchangeCap,
                symbolCap: $symbolCap,
                costs: $costs,
            );

            try {
                $decision = $engine->calculate($request);
            } catch (CanonicalRiskException $exception) {
                self::assertSame(
                    'canonical_risk_quantity_below_minimum',
                    $exception->reasonCode,
                    sprintf('Unexpected rejection in deterministic case %d', $case),
                );
                continue;
            }

            $effectiveLeverageCap = (int) floor(min($modeCap, $exchangeCap, $symbolCap));
            self::assertLessThanOrEqual(
                $decision->riskBudgetQuote + 1.0e-9,
                $decision->totalStopLoss,
                sprintf('Risk breach in deterministic case %d', $case),
            );
            self::assertLessThanOrEqual(
                $effectiveLeverageCap,
                $decision->finalLeverage,
                sprintf('Leverage breach in deterministic case %d', $case),
            );
            self::assertEqualsWithDelta(
                round($decision->quantity / $step),
                $decision->quantity / $step,
                1.0e-9,
                sprintf('Step breach in deterministic case %d', $case),
            );
            self::assertLessThanOrEqual($policy->exchangeMaxNotional + 1.0e-9, $decision->positionNotional);
            self::assertLessThanOrEqual($policy->environmentMaxNotional + 1.0e-9, $decision->positionNotional);
            self::assertSame($side, $decision->policy->side);
        }
    }

    public function testCanonicalPublicContractsExposeNoAmbiguousPercentageOrMultiplierNames(): void
    {
        foreach ([CanonicalRiskCalculationRequest::class, CanonicalRiskDecision::class, CanonicalRiskPolicy::class] as $class) {
            $names = array_map(
                static fn (\ReflectionProperty $property): string => strtolower($property->getName()),
                (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC),
            );
            foreach ($names as $name) {
                self::assertStringNotContainsString('multiplier', $name);
                self::assertStringNotContainsString('riskpct', $name);
                self::assertStringNotContainsString('risk_pct', $name);
            }
        }
    }

    private function request(
        string $side,
        CanonicalRiskPolicy $policy,
        float $equity = 1000.0,
        float $available = 100.0,
        float $entry = 100.0,
        ?float $stop = null,
        float $step = 0.001,
        float $exchangeCap = 20.0,
        ?float $symbolCap = 10.0,
        ?CanonicalCostSnapshot $costs = null,
    ): CanonicalRiskCalculationRequest {
        return new CanonicalRiskCalculationRequest(
            policy: $policy,
            symbol: 'BTCUSDT',
            side: $side,
            equityQuote: $equity,
            availableBalanceQuote: $available,
            entryPrice: $entry,
            stopPrice: $stop ?? ($side === 'long' ? $entry * 0.98 : $entry * 1.02),
            contractSize: 1.0,
            quantityStep: $step,
            minQuantity: $step,
            maxQuantity: 1_000_000.0,
            marketMaxQuantity: 1_000_000.0,
            exchangeLeverageCap: $exchangeCap,
            symbolLeverageCap: $symbolCap,
            costs: $costs ?? new CanonicalCostSnapshot(0.0, 0.0, 0.0, 0.0, 0.0, 0),
        );
    }

    private function policy(
        string $side,
        float $riskRate,
        float $modeLeverageCap,
        float $exchangeMaxNotional,
        float $environmentMaxNotional,
        float $makerFeeRate = 0.0,
        float $takerFeeRate = 0.0,
    ): CanonicalRiskPolicy {
        return new CanonicalRiskPolicy(
            modeId: 'day_trading',
            modeVersion: '1.0.0',
            setupId: 'day_trading.trend_continuation.' . $side,
            setupVersion: '1.0.0',
            exchange: 'fake',
            environment: 'test',
            side: $side,
            configHash: 'sha256:' . str_repeat('a', 64),
            riskRate: $riskRate,
            modeLeverageCap: $modeLeverageCap,
            makerFeeRate: $makerFeeRate,
            takerFeeRate: $takerFeeRate,
            exchangeMinNotional: 1.0,
            exchangeMaxNotional: $exchangeMaxNotional,
            environmentMaxNotional: $environmentMaxNotional,
        );
    }
}
