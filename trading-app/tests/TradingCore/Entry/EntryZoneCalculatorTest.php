<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Entry;

use App\TradingCore\Entry\Dto\EntryZoneRequest;
use App\TradingCore\Entry\Service\EntryZoneCalculator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EntryZoneCalculator::class)]
final class EntryZoneCalculatorTest extends TestCase
{
    public function testCalculatesZoneAroundVwapPivot(): void
    {
        $calculator = new EntryZoneCalculator();

        $zone = $calculator->calculate(new EntryZoneRequest(
            symbol: 'BTCUSDT',
            instrument: null,
            profile: 'scalper',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            executionTimeframe: '1m',
            referencePrice: 100.0,
            currentPrice: 100.2,
            vwap: 100.0,
            atr: 1.0,
            tickSize: null,
            spreadBps: 4.0,
            slippageBps: null,
            config: [
                'anchor' => 'vwap',
                'k_atr' => 0.5,
                'w_min' => 0.001,
                'w_max' => 0.01,
                'ttl_sec' => 180,
            ],
            metadata: ['decision_key' => 'decision-1'],
        ));

        self::assertSame(99.5, $zone->low);
        self::assertSame(100.5, $zone->high);
        self::assertSame(100.0, $zone->center);
        self::assertEqualsWithDelta(0.01, $zone->widthPct, 1e-12);
        self::assertSame(180, $zone->ttlSec);
        self::assertSame('vwap', $zone->source);
        self::assertSame(1.0, $zone->atrUsed);
        self::assertFalse($zone->quantized);
        self::assertSame('decision-1', $zone->metadata['decision_key']);
    }

    public function testRespectsMinimumWidthWithoutChangingConfig(): void
    {
        $calculator = new EntryZoneCalculator();
        $config = [
            'anchor' => 'vwap',
            'k_atr' => 0.1,
            'w_min' => 0.002,
            'w_max' => 0.01,
            'ttl_sec' => 240,
        ];

        $zone = $calculator->calculate(new EntryZoneRequest(
            symbol: 'ETHUSDT',
            instrument: null,
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'short',
            executionTimeframe: '5m',
            referencePrice: 100.0,
            currentPrice: 100.0,
            vwap: 100.0,
            atr: 0.5,
            tickSize: null,
            spreadBps: null,
            slippageBps: null,
            config: $config,
        ));

        self::assertSame(99.8, $zone->low);
        self::assertSame(100.2, $zone->high);
        self::assertSame($config, [
            'anchor' => 'vwap',
            'k_atr' => 0.1,
            'w_min' => 0.002,
            'w_max' => 0.01,
            'ttl_sec' => 240,
        ]);
    }

    public function testRespectsMaximumWidth(): void
    {
        $calculator = new EntryZoneCalculator();

        $zone = $calculator->calculate(new EntryZoneRequest(
            symbol: 'SOLUSDT',
            instrument: null,
            profile: 'scalper_micro',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            executionTimeframe: '1m',
            referencePrice: 100.0,
            currentPrice: 100.0,
            vwap: 100.0,
            atr: 100.0,
            tickSize: null,
            spreadBps: null,
            slippageBps: null,
            config: [
                'anchor' => 'vwap',
                'k_atr' => 0.5,
                'w_min' => 0.001,
                'w_max' => 0.003,
                'ttl_sec' => 240,
            ],
        ));

        self::assertSame(99.7, $zone->low);
        self::assertSame(100.3, $zone->high);
        self::assertEqualsWithDelta(0.006, $zone->widthPct, 1e-12);
    }

    public function testQuantizesZoneBoundsWhenTickSizeIsAvailable(): void
    {
        $calculator = new EntryZoneCalculator();

        $zone = $calculator->calculate(new EntryZoneRequest(
            symbol: 'XRPUSDT',
            instrument: null,
            profile: 'regular',
            exchange: 'bitmart',
            marketType: 'futures',
            direction: 'long',
            executionTimeframe: '5m',
            referencePrice: 100.0,
            currentPrice: 100.0,
            vwap: 100.0,
            atr: 0.37,
            tickSize: 0.05,
            spreadBps: null,
            slippageBps: null,
            config: [
                'anchor' => 'vwap',
                'k_atr' => 1.0,
                'w_min' => 0.001,
                'w_max' => 0.01,
                'ttl_sec' => 120,
                'quantize_to_exchange_step' => true,
            ],
        ));

        self::assertSame(99.6, $zone->low);
        self::assertSame(100.4, $zone->high);
        self::assertTrue($zone->quantized);
    }
}
