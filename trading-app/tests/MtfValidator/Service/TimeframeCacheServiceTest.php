<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\MtfValidator\Service\TimeframeCacheService;
use App\MtfValidator\Support\KlineTimeParser;
use App\Repository\ValidationCacheRepository;
use App\Runtime\Cache\DbValidationCache;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class TimeframeCacheServiceTest extends TestCase
{
    public function testShouldReuseCachedResultInvalidatesExpiredCache(): void
    {
        $repository = $this->createMock(ValidationCacheRepository::class);
        $repository
            ->expects(self::once())
            ->method('invalidateCache')
            ->with('mtf_tf_state_BTCUSDT_1m');

        $dbCache = $this->createMock(DbValidationCache::class);
        $dbCache
            ->expects(self::once())
            ->method('delete')
            ->with('mtf_tf_state_BTCUSDT_1m');

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2024-01-01T00:05:00Z'));

        $logger = $this->createMock(LoggerInterface::class);

        $service = new TimeframeCacheService(
            $repository,
            new KlineTimeParser(),
            $clock,
            $logger,
            $dbCache,
        );

        $cached = [
            'status' => 'VALID',
            'kline_time' => new \DateTimeImmutable('2024-01-01T00:00:00Z'),
        ];

        self::assertFalse($service->shouldReuseCachedResult($cached, '1m', 'btcusdt'));
    }

    public function testShouldReuseCachedResultKeepsValidCache(): void
    {
        $repository = $this->createMock(ValidationCacheRepository::class);
        $repository->expects(self::never())->method('invalidateCache');

        $dbCache = $this->createMock(DbValidationCache::class);
        $dbCache->expects(self::never())->method('delete');

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2024-01-01T00:00:30Z'));

        $logger = $this->createMock(LoggerInterface::class);

        $service = new TimeframeCacheService(
            $repository,
            new KlineTimeParser(),
            $clock,
            $logger,
            $dbCache,
        );

        $cached = [
            'status' => 'VALID',
            'kline_time' => new \DateTimeImmutable('2024-01-01T00:00:00Z'),
        ];

        self::assertTrue($service->shouldReuseCachedResult($cached, '1m', 'btcusdt'));
    }
}
