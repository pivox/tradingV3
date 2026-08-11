<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZone;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionCostSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalMarketSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetRDecision;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetREngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalNetRRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalPriceObservation;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionDecision;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalTargetCostSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalCostSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalInstrumentSnapshot;
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;
use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use Symfony\Component\Clock\MockClock;

final class CanonicalOrderPlanPipelineFixture
{
    /**
     * @return array{
     *   policy: CanonicalExecutionPolicy,
     *   zoneRequest: CanonicalEntryZoneRequest,
     *   zone: CanonicalEntryZone,
     *   protectionRequest: CanonicalProtectionRequest,
     *   protection: CanonicalProtectionDecision,
     *   riskRequest: CanonicalRiskCalculationRequest,
     *   risk: CanonicalRiskDecision,
     *   netR: CanonicalNetRDecision,
     *   costs: CanonicalExecutionCostSnapshot
     * }
     */
    public static function accepted(
        string $side = 'long',
        string $costObservedAt = '2026-08-10T11:59:50+00:00',
        string $instrumentObservedAt = '2026-08-10T11:59:40+00:00',
        ?CanonicalExecutionPolicy $executionPolicy = null,
        float $equityQuote = 1000.0,
        float $availableBalanceQuote = 1000.0,
    ): array
    {
        $policy = $executionPolicy ?? CanonicalExecutionPolicyFixture::policy($side);
        $observed = new \DateTimeImmutable('2026-08-10T11:59:30+00:00');
        $candidate = $side === 'long' ? 100.1 : 100.39;
        $zoneRequest = new CanonicalEntryZoneRequest(
            $policy,
            'BTCUSDT',
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'perpetual', 'vwap', '5m', 100.0, $observed, 'sha256:' . str_repeat('1', 64)),
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'perpetual', 'atr', '5m', 1.0, $observed, 'sha256:' . str_repeat('2', 64)),
            new CanonicalMarketSnapshot('fake', 'test', 'BTCUSDT', 'perpetual', 'order_book', $candidate, $observed, 'sha256:' . str_repeat('3', 64)),
            new CanonicalTickSnapshot('fake', 'test', 'BTCUSDT', 'perpetual', 0.1, $observed, 'sha256:' . str_repeat('4', 64)),
        );
        $zone = (new CanonicalEntryZoneEngine(new MockClock('2026-08-10T12:00:00+00:00')))->calculate($zoneRequest);
        $protectionRequest = new CanonicalProtectionRequest(
            $policy,
            $zone,
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'perpetual', 'atr', '5m', 1.0, $observed, 'sha256:' . str_repeat('5', 64)),
            null,
        );
        $protection = (new CanonicalProtectionEngine())->calculate($protectionRequest);
        $costs = new CanonicalExecutionCostSnapshot(
            'fake',
            'test',
            'BTCUSDT',
            'perpetual',
            $policy->configHash,
            $policy->costContract->entryLiquidityRole ?? 'taker',
            $policy->costContract->stopLiquidityRole ?? 'taker',
            'order_book',
            0.0001,
            'execution_model',
            0.0001,
            'order_book',
            0.0001,
            'execution_model',
            0.0001,
            'venue_schedule',
            0.0001,
            array_map(
                static fn ($target): CanonicalTargetCostSnapshot => new CanonicalTargetCostSnapshot(
                    $target->id,
                    'order_book',
                    0.0001,
                    'execution_model',
                    0.0001,
                ),
                $protection->targets,
            ),
            new \DateTimeImmutable($costObservedAt),
            'sha256:' . str_repeat('6', 64),
        );
        $riskRequest = new CanonicalRiskCalculationRequest(
            $policy->riskPolicy,
            'BTCUSDT',
            'perpetual',
            'USDT',
            $side,
            $equityQuote,
            $availableBalanceQuote,
            $protection->entryPrice,
            $protection->stopPrice,
            1.0,
            0.001,
            0.001,
            100.0,
            100.0,
            5.0,
            5.0,
            new CanonicalCostSnapshot(
                $policy->costContract->entryLiquidityRole ?? 'taker',
                $policy->costContract->stopLiquidityRole ?? 'taker',
                0.0001,
                0.0001,
                0.0001,
                0.0001,
                0.0001,
                1,
            ),
            new CanonicalInstrumentSnapshot(
                'fake',
                'test',
                'BTCUSDT',
                'perpetual',
                'USDT',
                1.0,
                0.001,
                0.001,
                100.0,
                100.0,
                5.0,
                5.0,
                new \DateTimeImmutable($instrumentObservedAt),
                'sha256:' . str_repeat('7', 64),
            ),
        );
        $risk = (new CanonicalRiskEngine())->calculate($riskRequest);
        $netR = (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));

        return [
            'policy' => $policy,
            'zoneRequest' => $zoneRequest,
            'zone' => $zone,
            'protectionRequest' => $protectionRequest,
            'protection' => $protection,
            'riskRequest' => $riskRequest,
            'risk' => $risk,
            'netR' => $netR,
            'costs' => $costs,
        ];
    }
}
