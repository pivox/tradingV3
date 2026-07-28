<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Http;

use App\Trading\Paper\Okx\Http\OkxPaperPublicRateLimiter;
use App\Trading\Paper\Okx\Http\OkxPublicEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;

#[CoversClass(OkxPaperPublicRateLimiter::class)]
final class OkxPaperPublicRateLimiterTest extends TestCase
{
    public function testHistoryAndSnapshotEndpointsConsumeTheirSharedBoundedLimiter(): void
    {
        $history = new RecordingLimiter();
        $snapshot = new RecordingLimiter();
        $rateLimiter = new OkxPaperPublicRateLimiter($history, $snapshot);

        $rateLimiter->acquire(OkxPublicEndpoint::HistoryCandles);
        $rateLimiter->acquire(OkxPublicEndpoint::HistoryTrades);
        $rateLimiter->acquire(OkxPublicEndpoint::CurrentCandles);
        $rateLimiter->acquire(OkxPublicEndpoint::RecentTrades);
        $rateLimiter->acquire(OkxPublicEndpoint::OrderBook);

        self::assertSame([[1, 2.0], [1, 2.0]], $history->reservations);
        self::assertSame([[1, 2.0], [1, 2.0], [1, 2.0]], $snapshot->reservations);
    }
}

final class RecordingLimiter implements LimiterInterface
{
    /** @var list<array{int, float|null}> */
    public array $reservations = [];

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        $this->reservations[] = [$tokens, $maxTime];

        return new Reservation(
            microtime(true),
            new RateLimit(100, new \DateTimeImmutable(), true, 100),
        );
    }

    public function consume(int $tokens = 1): RateLimit
    {
        throw new \LogicException('consume_not_expected');
    }

    public function reset(): void
    {
        $this->reservations = [];
    }
}
