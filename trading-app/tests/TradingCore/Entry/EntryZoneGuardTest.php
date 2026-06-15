<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Entry;

use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\Entry\Enum\EntryZoneStatus;
use App\TradingCore\Entry\Service\EntryZoneGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntryZoneGuard::class)]
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
        self::assertSame(0.01, $decision->zoneDevPct);
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
        self::assertSame(0.10, $decision->zoneDevPct);
        self::assertSame(0.05, $decision->zoneMaxDevPct);
        self::assertSame('zone_far_from_market', $decision->reasonIfRejected);
        self::assertSame('decision-2', $decision->metadata['decision_key']);
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
