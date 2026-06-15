<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Entry;

use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\Entry\Dto\EntryZoneDecision;
use App\TradingCore\Entry\Enum\EntryZoneStatus;
use App\TradingCore\Entry\Service\EntryZoneGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntryZoneGuard::class)]
#[CoversClass(EntryZone::class)]
#[CoversClass(EntryZoneDecision::class)]
#[CoversClass(EntryZoneStatus::class)]
final class EntryZoneGuardTest extends TestCase
{
    public function testAcceptsCandidateInsideZone(): void
    {
        $guard = new EntryZoneGuard();
        $zone = new EntryZone(
            low: 99.0,
            high: 101.0,
            center: 100.0,
            widthPct: 0.02,
            ttlSec: 180,
            expiresAt: null,
            source: 'vwap',
            atrUsed: 1.0,
            quantized: false,
        );

        $decision = $guard->decide(
            entryZone: $zone,
            candidatePrice: 100.5,
            referencePrice: 100.0,
            zoneMaxDevPct: 0.03,
        );

        self::assertSame(EntryZoneStatus::Accepted, $decision->status);
        self::assertSame($zone, $decision->entryZone);
        self::assertSame(100.5, $decision->candidatePrice);
        self::assertEqualsWithDelta(0.01, $decision->zoneDevPct, 1e-12);
        self::assertSame(0.03, $decision->zoneMaxDevPct);
        self::assertNull($decision->reasonIfRejected);
    }

    public function testRejectsCandidateOutsideZoneWithoutRelaxingThreshold(): void
    {
        $guard = new EntryZoneGuard();
        $zone = new EntryZone(
            low: 90.0,
            high: 91.0,
            center: 90.5,
            widthPct: 0.011049723756906077,
            ttlSec: 180,
            expiresAt: null,
            source: 'vwap',
            atrUsed: 1.0,
            quantized: false,
        );

        $decision = $guard->decide(
            entryZone: $zone,
            candidatePrice: 100.0,
            referencePrice: 100.0,
            zoneMaxDevPct: 0.05,
            reasonIfRejected: 'zone_far_from_market',
            metadata: ['decision_key' => 'decision-2'],
        );

        self::assertSame(EntryZoneStatus::Rejected, $decision->status);
        self::assertEqualsWithDelta(0.10, $decision->zoneDevPct, 1e-12);
        self::assertSame(0.05, $decision->zoneMaxDevPct);
        self::assertSame('zone_far_from_market', $decision->reasonIfRejected);
        self::assertSame('decision-2', $decision->metadata['decision_key']);
    }

    public function testRejectsCandidateBecauseZoneIsTooFarFromMarketEvenThoughPriceIsInsideZone(): void
    {
        $guard = new EntryZoneGuard();
        $zone = new EntryZone(
            low: 100.0,
            high: 101.0,
            center: 100.5,
            widthPct: 0.01,
            ttlSec: 180,
            expiresAt: null,
            source: 'vwap',
            atrUsed: 1.0,
            quantized: false,
        );

        // candidatePrice is inside zone bounds — contains() returns true.
        // But the zone is anchored around 100–101 while the market reference is 50.
        // zoneDevPct = max(|100-50|, |101-50|) / 50 = 51/50 = 1.02, which exceeds zoneMaxDevPct=0.05.
        $decision = $guard->decide(
            entryZone: $zone,
            candidatePrice: 100.5,
            referencePrice: 50.0,
            zoneMaxDevPct: 0.05,
            reasonIfRejected: 'zone_far_from_market',
        );

        self::assertSame(EntryZoneStatus::Rejected, $decision->status);
        self::assertEqualsWithDelta(1.02, $decision->zoneDevPct, 1e-12);
        self::assertSame(0.05, $decision->zoneMaxDevPct);
        self::assertSame('zone_far_from_market', $decision->reasonIfRejected);
    }

    public function testDefaultsToFarFromMarketReasonWhenZoneDeviationFails(): void
    {
        $guard = new EntryZoneGuard();
        $zone = new EntryZone(
            low: 100.0,
            high: 101.0,
            center: 100.5,
            widthPct: 0.01,
            ttlSec: 180,
            expiresAt: null,
            source: 'vwap',
            atrUsed: 1.0,
            quantized: false,
        );

        $decision = $guard->decide(
            entryZone: $zone,
            candidatePrice: 100.5,
            referencePrice: 50.0,
            zoneMaxDevPct: 0.05,
        );

        self::assertSame(EntryZoneStatus::Rejected, $decision->status);
        self::assertSame('zone_far_from_market', $decision->reasonIfRejected);
    }

    public function testAcceptsCandidateInsideLegacyOutsideTolerance(): void
    {
        $guard = new EntryZoneGuard();
        $zone = new EntryZone(
            low: 99.0,
            high: 101.0,
            center: 100.0,
            widthPct: 0.02,
            ttlSec: 180,
            expiresAt: null,
            source: 'vwap',
            atrUsed: 1.0,
            quantized: false,
            metadata: ['outside_tolerance_pct' => 0.012],
        );

        $decision = $guard->decide(
            entryZone: $zone,
            candidatePrice: 101.5,
            referencePrice: 100.0,
            zoneMaxDevPct: 0.03,
        );

        self::assertSame(EntryZoneStatus::Accepted, $decision->status);
        self::assertNull($decision->reasonIfRejected);
    }

    public function testNormalizesPercentThresholdButDoesNotChangeSemanticValue(): void
    {
        $guard = new EntryZoneGuard();
        $zone = new EntryZone(
            low: 99.0,
            high: 101.0,
            center: 100.0,
            widthPct: 0.02,
            ttlSec: 180,
            expiresAt: null,
            source: 'vwap',
            atrUsed: 1.0,
            quantized: false,
        );

        $decision = $guard->decide(
            entryZone: $zone,
            candidatePrice: 100.0,
            referencePrice: 100.0,
            zoneMaxDevPct: 3.0,
        );

        self::assertSame(EntryZoneStatus::Accepted, $decision->status);
        self::assertSame(0.03, $decision->zoneMaxDevPct);
    }
}
