<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\OrderPlan\Canonical;

use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZone;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalEntryZoneRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalMarketSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanException;
use App\TradingCore\OrderPlan\Canonical\CanonicalPriceObservation;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionEngine;
use App\TradingCore\OrderPlan\Canonical\CanonicalProtectionRequest;
use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(CanonicalProtectionEngine::class)]
#[CoversClass(CanonicalProtectionRequest::class)]
final class CanonicalProtectionEngineTest extends TestCase
{
    public function testCalculatesLongAtrStopAndTargetsWithConservativeQuantization(): void
    {
        [$policy, $zone] = $this->policyAndZone('long');
        $atr = $this->observation('atr', 1.0);

        $decision = (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest($policy, $zone, $atr, null));

        self::assertSame(100.1, $decision->entryPrice);
        self::assertSame(98.4, $decision->stopPrice);
        self::assertSame(1.7, $decision->riskDistance);
        self::assertSame(102.6, $decision->targets[0]->price);
        self::assertSame(103.5, $decision->targets[1]->price);
        self::assertSame('tp1', $decision->targets[0]->id);
        self::assertSame($policy->configHash, $decision->configHash);
        self::assertContains($atr->inputHash, $decision->inputHashes);
    }

    public function testCalculatesShortAtrStopAndTargetsWithCorrectPolarity(): void
    {
        [$policy, $zone] = $this->policyAndZone('short');

        $decision = (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest($policy, $zone, $this->observation('atr', 1.0), null));

        self::assertSame(100.3, $decision->entryPrice);
        self::assertSame(102.0, $decision->stopPrice);
        self::assertSame(97.8, $decision->targets[0]->price);
        self::assertSame(96.9, $decision->targets[1]->price);
    }

    public function testCalculatesPivotStopFromExactRequestedPivot(): void
    {
        [$policy, $zone] = $this->policyAndZone('long', 'pivot');

        $decision = (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest($policy, $zone, null, $this->observation('s1', 99.0)));

        self::assertSame(98.9, $decision->stopPrice);
    }

    public function testRejectsMissingAtrWithoutPivotFallback(): void
    {
        [$policy, $zone] = $this->policyAndZone('long');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_protection_atr_required');
        (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest($policy, $zone, null, $this->observation('s1', 99.0)));
    }

    public function testRejectsWrongPivotWithoutAtrFallback(): void
    {
        [$policy, $zone] = $this->policyAndZone('long', 'pivot');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_protection_pivot_mismatch');
        (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest($policy, $zone, null, $this->observation('s2', 99.0)));
    }

    public function testRejectsStaleStopInput(): void
    {
        [$policy, $zone] = $this->policyAndZone('long');

        $this->expectException(CanonicalOrderPlanException::class);
        $this->expectExceptionMessage('canonical_protection_input_stale');
        (new CanonicalProtectionEngine())->calculate(new CanonicalProtectionRequest(
            $policy,
            $zone,
            $this->observation('atr', 1.0, '2026-08-10T11:58:59+00:00'),
            null,
        ));
    }

    /** @return array{0: CanonicalExecutionPolicy, 1: CanonicalEntryZone} */
    private function policyAndZone(string $side, string $stopKind = 'atr'): array
    {
        $policy = CanonicalExecutionPolicyFixture::policy($side, $stopKind);
        $observed = new \DateTimeImmutable('2026-08-10T11:59:30+00:00');
        $candidate = $side === 'long' ? 100.1 : 100.39;
        $zone = (new CanonicalEntryZoneEngine(new MockClock('2026-08-10T12:00:00+00:00')))->calculate(new CanonicalEntryZoneRequest(
            $policy,
            'BTCUSDT',
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'vwap', '5m', 100.0, $observed, 'sha256:' . str_repeat('1', 64)),
            new CanonicalPriceObservation('fake', 'test', 'BTCUSDT', 'atr', '5m', 1.0, $observed, 'sha256:' . str_repeat('2', 64)),
            new CanonicalMarketSnapshot('fake', 'test', 'BTCUSDT', 'perpetual', 'order_book', $candidate, $observed, 'sha256:' . str_repeat('3', 64)),
            new CanonicalTickSnapshot('fake', 'test', 'BTCUSDT', 0.1, $observed, 'sha256:' . str_repeat('4', 64)),
        ));

        return [$policy, $zone];
    }

    private function observation(string $source, float $value, string $observedAt = '2026-08-10T11:59:30+00:00'): CanonicalPriceObservation
    {
        return new CanonicalPriceObservation(
            'fake',
            'test',
            'BTCUSDT',
            $source,
            '5m',
            $value,
            new \DateTimeImmutable($observedAt),
            'sha256:' . hash('sha256', $source . $value . $observedAt),
        );
    }
}
