<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Http;

use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;

#[CoversClass(HyperliquidPaperPublicRateLimiter::class)]
final class HyperliquidPaperPublicRateLimiterTest extends TestCase
{
    public function testReservesOneRequestTokenAndCeilingOfSixtyRowsWithABoundedWait(): void
    {
        $limiter = new HyperliquidRecordingLimiter(0.005);
        $rateLimiter = new HyperliquidPaperPublicRateLimiter($limiter);

        $startedAt = microtime(true);
        $rateLimiter->acquireRequest();
        $rateLimiter->acquireResponseRows(0);
        $rateLimiter->acquireResponseRows(1);
        $rateLimiter->acquireResponseRows(60);
        $rateLimiter->acquireResponseRows(61);

        self::assertSame([[1, 2.0], [1, 2.0], [1, 2.0], [2, 2.0]], $limiter->reservations);
        self::assertGreaterThanOrEqual(0.015, microtime(true) - $startedAt);
    }

    public function testRejectsNegativeResponseRowsWithoutAReservation(): void
    {
        $limiter = new HyperliquidRecordingLimiter();
        $rateLimiter = new HyperliquidPaperPublicRateLimiter($limiter);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hyperliquid_paper_public_response_rows_invalid');

        try {
            $rateLimiter->acquireResponseRows(-1);
        } finally {
            self::assertSame([], $limiter->reservations);
        }
    }
}

final class HyperliquidRecordingLimiter implements LimiterInterface
{
    /** @var list<array{int, float|null}> */
    public array $reservations = [];

    public function __construct(private readonly float $waitSeconds = 0.0)
    {
    }
    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        $this->reservations[] = [$tokens, $maxTime];
        return new Reservation(
            microtime(true) + $this->waitSeconds,
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
