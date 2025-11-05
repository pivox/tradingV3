<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Strategy;

use App\MtfValidator\Strategy\HighConvictionValidation;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class HighConvictionValidationTest extends TestCase
{
    public function testValidateReturnsOkWhenAllConditionsMet(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $strategy = new HighConvictionValidation($logger);

        $ctx = [
            '4h' => ['signal' => 'LONG', 'ema_fast' => 10, 'ema_slow' => 9, 'macd' => ['hist' => 1.2]],
            '1h' => ['signal' => 'LONG', 'ema_fast' => 11, 'ema_slow' => 9, 'macd' => ['hist' => 1.1]],
            '15m' => ['signal' => 'LONG', 'ema_fast' => 12, 'ema_slow' => 10, 'macd' => ['hist' => 1.0], 'vwap' => 100, 'close' => 101],
            '5m' => ['signal' => 'LONG', 'ema_fast' => 13, 'ema_slow' => 11, 'macd' => ['hist' => 0.9], 'vwap' => 100, 'close' => 101],
            '1m' => ['signal' => 'LONG', 'ema_fast' => 14, 'ema_slow' => 12, 'macd' => ['hist' => 0.8], 'vwap' => 100, 'close' => 101],
        ];
        $metrics = [
            'adx_1h' => 30.0,
            'adx_15m' => 35.0,
            'breakout_confirmed' => true,
            'macro_no_event' => true,
            'valid_retest' => true,
            'rr' => 2.5,
            'liq_ratio' => 4.0,
        ];

        $result = $strategy->validate($ctx, $metrics);

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('flags', $result);
        $this->assertTrue($result['flags']['high_conviction']);
    }

    public function testValidateReturnsReasonsWhenChecksFail(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $strategy = new HighConvictionValidation($logger);

        $ctx = [
            '4h' => ['signal' => 'SHORT', 'ema_fast' => 9, 'ema_slow' => 10, 'macd' => ['hist' => -0.2]],
            '1h' => ['signal' => 'LONG', 'ema_fast' => 9, 'ema_slow' => 10, 'macd' => ['hist' => -0.2]],
            '15m' => ['signal' => 'SHORT', 'ema_fast' => 8, 'ema_slow' => 10, 'macd' => ['hist' => -0.5], 'vwap' => 100, 'close' => 99],
            '5m' => ['signal' => 'SHORT', 'ema_fast' => 7, 'ema_slow' => 10, 'macd' => ['hist' => -0.6], 'vwap' => 100, 'close' => 99],
            '1m' => ['signal' => 'SHORT', 'ema_fast' => 6, 'ema_slow' => 10, 'macd' => ['hist' => -0.8], 'vwap' => 100, 'close' => 99],
        ];
        $metrics = [
            'adx_1h' => 10.0,
            'adx_15m' => 8.0,
            'breakout_confirmed' => false,
            'macro_no_event' => false,
            'valid_retest' => false,
            'rr' => 1.0,
            'liq_ratio' => 1.5,
        ];

        $result = $strategy->validate($ctx, $metrics);

        $this->assertFalse($result['ok']);
        $this->assertContains('multi_timeframe_alignment', $result['reasons']);
        $this->assertContains('trend_strength_insufficient', $result['reasons']);
        $this->assertContains('breakout_with_volume_not_confirmed', $result['reasons']);
        $this->assertContains('macro_event_veto', $result['reasons']);
        $this->assertContains('rr_guard', $result['reasons']);
        $this->assertContains('liquidation_guard', $result['reasons']);
    }
}
