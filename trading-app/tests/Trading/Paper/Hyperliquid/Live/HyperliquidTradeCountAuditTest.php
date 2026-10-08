<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Live;

use App\Trading\Paper\Hyperliquid\Live\HyperliquidTradeCountAudit;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidTradeCountAudit::class)]
final class HyperliquidTradeCountAuditTest extends TestCase
{
    private const MINUTE = HyperliquidTradeCountAudit::INTERVAL_MILLISECONDS;

    public function testAFullyCoveredMinuteWithFewerRowsThanItsCandleIsAHole(): void
    {
        $audit = new HyperliquidTradeCountAudit();
        $audit->trade('BTC', 500);               // first minute: partial, never compared
        $audit->trade('BTC', self::MINUTE + 10);
        $audit->trade('BTC', self::MINUTE + 20);
        $audit->closedCandle('BTC', 0, 40);
        $audit->closedCandle('BTC', self::MINUTE, 3);

        self::assertSame([], $audit->settle(), 'No trade of a later minute yet.');

        $audit->trade('BTC', 2 * self::MINUTE);

        self::assertSame(
            [['coin' => 'BTC', 'minute_start' => self::MINUTE, 'rows' => 2, 'candle_trade_count' => 3]],
            $audit->settle(),
        );
        self::assertSame([], $audit->settle(), 'A settled minute is forgotten.');
    }

    public function testMoreRowsThanTheCandleCountIsHyperliquidLagAndPasses(): void
    {
        $audit = new HyperliquidTradeCountAudit();
        $audit->trade('ETH', 100);
        foreach ([5, 15, 25, 59_990] as $offset) {
            $audit->trade('ETH', self::MINUTE + $offset);
        }
        $audit->closedCandle('ETH', self::MINUTE, 3);
        $audit->trade('ETH', 2 * self::MINUTE + 1);

        self::assertSame([], $audit->settle());
    }

    public function testAMinuteWithNoRowsAtAllIsAHoleOnceALaterTradeArrives(): void
    {
        $audit = new HyperliquidTradeCountAudit();
        $audit->trade('BTC', 10);
        $audit->closedCandle('BTC', self::MINUTE, 7);
        $audit->trade('BTC', 3 * self::MINUTE);

        self::assertSame(
            [['coin' => 'BTC', 'minute_start' => self::MINUTE, 'rows' => 0, 'candle_trade_count' => 7]],
            $audit->settle(),
        );
    }

    public function testMinutesBeforeOrAtTheCoverageStartAreNeverCompared(): void
    {
        $audit = new HyperliquidTradeCountAudit();
        $audit->trade('BTC', self::MINUTE);       // coverage starts exactly on a boundary
        $audit->closedCandle('BTC', 0, 50);
        $audit->closedCandle('BTC', self::MINUTE, 50);
        $audit->trade('BTC', 5 * self::MINUTE);

        self::assertSame([], $audit->settle());
    }

    public function testCoinsAreIndependent(): void
    {
        $audit = new HyperliquidTradeCountAudit();
        $audit->trade('BTC', 1);
        $audit->trade('ETH', 1);
        $audit->trade('BTC', self::MINUTE + 1);
        $audit->closedCandle('BTC', self::MINUTE, 1);
        $audit->closedCandle('ETH', self::MINUTE, 1);
        $audit->trade('BTC', 2 * self::MINUTE);

        self::assertSame([], $audit->settle(), 'ETH minute is not complete yet.');

        $audit->trade('ETH', 2 * self::MINUTE);

        self::assertSame(
            [['coin' => 'ETH', 'minute_start' => self::MINUTE, 'rows' => 0, 'candle_trade_count' => 1]],
            $audit->settle(),
        );
    }
}
