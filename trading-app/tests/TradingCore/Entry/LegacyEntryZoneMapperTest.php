<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Entry;

use App\TradeEntry\Dto\EntryZone as LegacyEntryZone;
use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\Entry\Mapper\LegacyEntryZoneMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LegacyEntryZoneMapper::class)]
#[CoversClass(EntryZone::class)]
#[CoversClass(LegacyEntryZone::class)]
final class LegacyEntryZoneMapperTest extends TestCase
{
    public function testMapsLegacyEntryZoneWithoutChangingBoundsOrMetadata(): void
    {
        $legacy = new LegacyEntryZone(
            min: 99.0,
            max: 101.0,
            rationale: 'vwap@1m',
            createdAt: new \DateTimeImmutable('2026-06-15T10:00:00+00:00'),
            ttlSec: 180,
            metadata: [
                'pivot' => 100.0,
                'pivot_source' => 'vwap',
                'atr' => 1.5,
                'width_pct' => 0.02,
            ],
        );

        $zone = (new LegacyEntryZoneMapper())->fromLegacy($legacy);

        self::assertSame(99.0, $zone->low);
        self::assertSame(101.0, $zone->high);
        self::assertSame(100.0, $zone->center);
        self::assertSame(0.02, $zone->widthPct);
        self::assertSame(180, $zone->ttlSec);
        self::assertSame('2026-06-15T10:03:00+00:00', $zone->expiresAt?->format(\DateTimeInterface::ATOM));
        self::assertSame('vwap', $zone->source);
        self::assertSame(1.5, $zone->atrUsed);
        self::assertFalse($zone->quantized);
        self::assertSame('vwap@1m', $zone->metadata['legacy_rationale']);
        self::assertSame(100.0, $zone->metadata['pivot']);
    }
}
