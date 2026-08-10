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
use App\TradingCore\Risk\Canonical\CanonicalRiskCalculationRequest;
use App\TradingCore\Risk\Canonical\CanonicalRiskDecision;
use App\TradingCore\Risk\Canonical\CanonicalRiskEngine;
use Symfony\Component\Clock\MockClock;

final class CanonicalOrderPlanPipelineFixture
{
    /**
     * @return array{
     *   policy: CanonicalExecutionPolicy,
     *   zone: CanonicalEntryZone,
     *   protection: CanonicalProtectionDecision,
     *   risk: CanonicalRiskDecision,
     *   netR: CanonicalNetRDecision,
     *   costs: CanonicalExecutionCostSnapshot
     * }
     */
    public static function accepted(string $side = 'long'): array
    {
        $policy = CanonicalExecutionPolicyFixture::policy($side);
        $observed = new \DateTimeImmutable('2026-08-10T11:59:30+00:00');
        $candidate = $side === 'long' ? 100.1 : 100.39;
        $zone = (new CanonicalEntryZoneEngine(new MockClock('2026-08-10T12:00:00+00:00')))->calculate(new CanonicalEntryZoneRequest(
            $policy,
            'BTCUSDT',
            new CanonicalPriceObservation('fake', 'BTCUSDT', 'vwap', '5m', 100.0, $observed, 'sha256:' . str_repeat('1', 64)),
            new CanonicalPriceObservation('fake', 'BTCUSDT', 'atr', '5m', 1.0, $observed, 'sha256:' . str_repeat('2', 64)),
            new CanonicalMarketSnapshot('fake', 'BTCUSDT', 'order_book', $candidate, $observed, 'sha256:' . str_repeat('3', 64)),
            new CanonicalTickSnapshot('fake', 'BTCUSDT', 0.1, $observed, 'sha256:' . str_repeat('4', 64)),
        ));
        $protection = (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest(
            $policy,
            $zone,
            new CanonicalPriceObservation('fake', 'BTCUSDT', 'atr', '5m', 1.0, $observed, 'sha256:' . str_repeat('5', 64)),
            null,
        ));
        $costs = new CanonicalExecutionCostSnapshot(
            'fake',
            'test',
            'BTCUSDT',
            $policy->configHash,
            'taker',
            'taker',
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
            [
                new CanonicalTargetCostSnapshot('tp1', 'order_book', 0.0001, 'execution_model', 0.0001),
                new CanonicalTargetCostSnapshot('tp2', 'order_book', 0.0001, 'execution_model', 0.0001),
            ],
            new \DateTimeImmutable('2026-08-10T11:59:50+00:00'),
            'sha256:' . str_repeat('6', 64),
        );
        $risk = (new CanonicalRiskEngine())->calculate(new CanonicalRiskCalculationRequest(
            $policy->riskPolicy,
            'BTCUSDT',
            $side,
            1000.0,
            1000.0,
            $protection->entryPrice,
            $protection->stopPrice,
            1.0,
            0.001,
            0.001,
            100.0,
            100.0,
            5.0,
            5.0,
            new CanonicalCostSnapshot('taker', 'taker', 0.0001, 0.0001, 0.0001, 0.0001, 0.0001, 1),
        ));
        $netR = (new CanonicalNetREngine())->calculate(new CanonicalNetRRequest($policy, $protection, $risk, $costs));

        return ['policy' => $policy, 'zone' => $zone, 'protection' => $protection, 'risk' => $risk, 'netR' => $netR, 'costs' => $costs];
    }
}
