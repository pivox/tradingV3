<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Historical;

use App\Trading\Paper\Okx\Historical\OkxHistoricalRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(OkxHistoricalRequest::class)]
final class OkxHistoricalRequestTest extends TestCase
{
    public function testCanonicalRequestHasFixedBarsAndStableHash(): void
    {
        $first = new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['ETHUSDT', 'BTCUSDT'],
            from: new \DateTimeImmutable('2026-04-21T12:05:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T12:00:00.000000Z'),
        );
        $second = new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['BTCUSDT', 'ETHUSDT'],
            from: new \DateTimeImmutable('2026-04-21T12:05:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T12:00:00.000000Z'),
        );

        self::assertSame(['BTCUSDT', 'ETHUSDT'], $first->symbols);
        self::assertSame(['1m', '5m', '15m', '1H'], $first->bars);
        self::assertSame($first->requestSha256(), $second->requestSha256());
        self::assertSame(1_000_000, $first->maximumEvents);
        self::assertGreaterThan(0, $first->maximumPages);
    }

    public function testExactlyAvailableThreeMonthTradeRangeIsAccepted(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-04-21T12:05:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T12:00:00.000000Z'),
        );

        $request->assertTradeRangeAvailable(new MockClock('2026-07-21T12:00:00.000000Z'));
        self::addToAssertionCount(1);
    }

    public function testOneMillisecondBeforeAvailableTradeRangeIsRejected(): void
    {
        $request = new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-04-21T12:04:59.999000Z'),
            to: new \DateTimeImmutable('2026-07-21T12:00:00.000000Z'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_history_trades_range_unavailable');

        $request->assertTradeRangeAvailable(new MockClock('2026-07-21T12:00:00.000000Z'));
    }

    public function testRequestChangeChangesCheckpointCompatibilityHash(): void
    {
        $base = new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
        );
        $changed = new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:01:00.000000Z'),
        );

        self::assertNotSame($base->requestSha256(), $changed->requestSha256());
    }

    public function testHalfOpenRangeMustBeNonEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_historical_range_invalid');

        new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
        );
    }

    public function testEventAndPageBoundsAreExplicitAndPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('okx_historical_bound_invalid');

        new OkxHistoricalRequest(
            datasetId: 'okx-history-test-001',
            symbols: ['BTCUSDT'],
            from: new \DateTimeImmutable('2026-07-21T10:00:00.000000Z'),
            to: new \DateTimeImmutable('2026-07-21T11:00:00.000000Z'),
            maximumEvents: 1_000_001,
            maximumPages: 1,
        );
    }
}
