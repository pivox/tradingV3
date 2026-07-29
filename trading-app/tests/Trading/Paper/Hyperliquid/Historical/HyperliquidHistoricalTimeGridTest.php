<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Historical;

use App\Trading\Paper\Hyperliquid\Historical\HyperliquidHistoricalTimeGrid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidHistoricalTimeGrid::class)]
final class HyperliquidHistoricalTimeGridTest extends TestCase
{
    public function testComputesNonalignedMicrosecondGridWithoutFloats(): void
    {
        $from = new \DateTimeImmutable('2024-01-01T00:00:00.123456Z');
        $to = new \DateTimeImmutable('2024-01-01T00:02:00.654321Z');

        self::assertSame(1_704_067_200_123_456, HyperliquidHistoricalTimeGrid::epochMicroseconds($from));
        self::assertSame(
            1_704_067_260_000,
            HyperliquidHistoricalTimeGrid::firstGridStartMilliseconds($from, 60_000),
        );
        self::assertSame(
            1_704_067_320_655,
            HyperliquidHistoricalTimeGrid::exclusiveToMilliseconds($to),
        );
        self::assertSame(
            2,
            HyperliquidHistoricalTimeGrid::expectedCount(
                1_704_067_260_000,
                1_704_067_320_655,
                60_000,
            ),
        );
    }

    public function testMillisecondUnalignedFromCeilsToNextIntervalBoundary(): void
    {
        self::assertSame(
            1_704_067_260_000,
            HyperliquidHistoricalTimeGrid::firstGridStartMilliseconds(
                new \DateTimeImmutable('2024-01-01T00:00:00.001000Z'),
                60_000,
            ),
        );
    }

    public function testSubMillisecondExclusiveToIncludesFinalGridStart(): void
    {
        $first = HyperliquidHistoricalTimeGrid::firstGridStartMilliseconds(
            new \DateTimeImmutable('2024-01-01T00:00:00.000000Z'),
            60_000,
        );
        $exclusive = HyperliquidHistoricalTimeGrid::exclusiveToMilliseconds(
            new \DateTimeImmutable('2024-01-01T00:01:00.000001Z'),
        );

        self::assertSame(1_704_067_260_001, $exclusive);
        self::assertSame(2, HyperliquidHistoricalTimeGrid::expectedCount(
            $first,
            $exclusive,
            60_000,
        ));
    }

    public function testExpectedCountIsZeroWhenFirstGridIsOutsideRange(): void
    {
        self::assertSame(0, HyperliquidHistoricalTimeGrid::expectedCount(
            300_000,
            300_000,
            60_000,
        ));
    }

    public function testRejectsTimestampAndCountOverflowWithStableInternalReason(): void
    {
        $seconds = intdiv(\PHP_INT_MAX, 1_000_000) + 1;
        $timestamp = new \DateTimeImmutable('@' . $seconds);

        foreach ([
            static fn (): int => HyperliquidHistoricalTimeGrid::epochMicroseconds($timestamp),
            static fn (): int => HyperliquidHistoricalTimeGrid::expectedCount(0, 1, 0),
        ] as $operation) {
            try {
                $operation();
                self::fail('Pathological grid arithmetic must fail.');
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(
                    'hyperliquid_historical_time_grid_invalid',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testPreservesExclusiveToCeilingOverflowGuard(): void
    {
        $seconds = intdiv(\PHP_INT_MAX, 1_000_000);
        $timestamp = \DateTimeImmutable::createFromFormat(
            '!U.u',
            $seconds . '.775807',
            new \DateTimeZone('UTC'),
        );
        self::assertInstanceOf(\DateTimeImmutable::class, $timestamp);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_historical_time_grid_invalid');
        HyperliquidHistoricalTimeGrid::exclusiveToMilliseconds($timestamp);
    }
}
