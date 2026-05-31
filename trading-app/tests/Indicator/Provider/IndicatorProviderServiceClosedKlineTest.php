<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Provider;

use App\Common\Dto\IndicatorSnapshotDto;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Indicator\Core\AtrCalculator;
use App\Indicator\Core\Momentum\Macd;
use App\Indicator\Core\Momentum\Rsi;
use App\Indicator\Core\Momentum\StochRsi;
use App\Indicator\Core\Trend\Adx;
use App\Indicator\Core\Trend\Ema;
use App\Indicator\Core\Trend\Sma;
use App\Indicator\Core\Volatility\Bollinger;
use App\Indicator\Core\Volume\Vwap;
use App\Indicator\Provider\IndicatorProviderService;
use App\Indicator\Registry\ConditionRegistry;
use App\Repository\IndicatorSnapshotRepository;
use Brick\Math\BigDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ServiceProviderInterface;

#[CoversClass(IndicatorProviderService::class)]
final class IndicatorProviderServiceClosedKlineTest extends TestCase
{
    public function testSnapshotCalculatesIndicatorsFromClosedWindowOnly(): void
    {
        $now = new \DateTimeImmutable('2026-05-31 12:05:30', new \DateTimeZone('UTC'));
        $klines = $this->klinesEndingWithCurrentCandle($now);

        $klineProvider = $this->createMock(KlineProviderInterface::class);
        $klineProvider
            ->expects(self::once())
            ->method('getKlines')
            ->with('BTCUSDT', Timeframe::TF_1M, 251, self::anything())
            ->willReturn($klines);

        $snapshotRepository = $this->createMock(IndicatorSnapshotRepository::class);
        $snapshotRepository
            ->expects(self::once())
            ->method('findLastBySymbolAndTimeframe')
            ->willReturn(null);
        $snapshotRepository
            ->expects(self::once())
            ->method('upsert');

        $snapshot = $this->service($klineProvider, $snapshotRepository, $now)
            ->getSnapshot('BTCUSDT', '1m');

        self::assertSame('2026-05-31 12:04:00', $snapshot->klineTime->format('Y-m-d H:i:s'));
        self::assertEqualsWithDelta(246.0, $snapshot->ma9?->toFloat(), 0.000001);
    }

    public function testMtfMappingUsesLastClosedClose(): void
    {
        $now = new \DateTimeImmutable('2026-05-31 12:05:30', new \DateTimeZone('UTC'));
        $klines = $this->klinesEndingWithCurrentCandle($now);

        $klineProvider = $this->createMock(KlineProviderInterface::class);
        $klineProvider
            ->expects(self::exactly(2))
            ->method('getKlines')
            ->with('BTCUSDT', Timeframe::TF_1M, 251, self::anything())
            ->willReturn($klines);

        $snapshotRepository = $this->createMock(IndicatorSnapshotRepository::class);
        $snapshotRepository
            ->expects(self::exactly(2))
            ->method('findLastBySymbolAndTimeframe')
            ->willReturn(null);
        $snapshotRepository
            ->expects(self::once())
            ->method('upsert');

        $result = $this->service($klineProvider, $snapshotRepository, $now)
            ->getIndicatorsForSymbolAndTimeframes('BTCUSDT', ['1m'], $now);

        self::assertSame(250.0, $result['1m']['close'] ?? null);
        self::assertSame('2026-05-31 12:04:00', $result['1m']['kline_time'] ?? null);
    }

    public function testSnapshotFreshnessRequiresExpectedClosedCandle(): void
    {
        $now = new \DateTimeImmutable('2026-05-31 12:05:30', new \DateTimeZone('UTC'));
        $service = $this->service(
            $this->createMock(KlineProviderInterface::class),
            $this->createMock(IndicatorSnapshotRepository::class),
            $now,
        );
        $method = new \ReflectionMethod($service, 'isSnapshotStale');

        self::assertFalse($method->invoke(
            $service,
            $this->snapshotAt('2026-05-31 12:04:00'),
            $now,
            Timeframe::TF_1M,
        ));
        self::assertTrue($method->invoke(
            $service,
            $this->snapshotAt('2026-05-31 12:05:00'),
            $now,
            Timeframe::TF_1M,
        ));
        self::assertTrue($method->invoke(
            $service,
            $this->snapshotAt('2026-05-31 12:03:00'),
            $now,
            Timeframe::TF_1M,
        ));

        $afterCurrentCandleCloses = new \DateTimeImmutable('2026-05-31 12:06:30', new \DateTimeZone('UTC'));
        self::assertTrue($method->invoke(
            $service,
            $this->snapshotAt('2026-05-31 12:05:00', '2026-05-31 12:05:30'),
            $afterCurrentCandleCloses,
            Timeframe::TF_1M,
        ));
        self::assertFalse($method->invoke(
            $service,
            $this->snapshotAt('2026-05-31 12:05:00', '2026-05-31 12:06:01'),
            $afterCurrentCandleCloses,
            Timeframe::TF_1M,
        ));
    }

    /**
     * @return KlineDto[]
     */
    private function klinesEndingWithCurrentCandle(\DateTimeImmutable $now): array
    {
        $currentOpenTs = intdiv($now->getTimestamp(), 60) * 60;
        $currentOpen = (new \DateTimeImmutable('@' . $currentOpenTs))->setTimezone(new \DateTimeZone('UTC'));
        $firstOpen = $currentOpen->modify('-250 minutes');

        $klines = [];
        for ($i = 0; $i <= 250; $i++) {
            $close = $i === 250 ? 10000.0 : (float) ($i + 1);
            $klines[] = new KlineDto(
                symbol: 'BTCUSDT',
                timeframe: Timeframe::TF_1M,
                openTime: $firstOpen->modify("+{$i} minutes"),
                open: BigDecimal::of((string) $close),
                high: BigDecimal::of((string) ($close + 1.0)),
                low: BigDecimal::of((string) max(0.0, $close - 1.0)),
                close: BigDecimal::of((string) $close),
                volume: BigDecimal::of('100'),
            );
        }

        return $klines;
    }

    private function snapshotAt(string $klineTime, ?string $updatedAt = null): IndicatorSnapshotDto
    {
        return new IndicatorSnapshotDto(
            symbol: 'BTCUSDT',
            timeframe: Timeframe::TF_1M,
            klineTime: new \DateTimeImmutable($klineTime, new \DateTimeZone('UTC')),
            updatedAt: $updatedAt !== null
                ? new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'))
                : null,
        );
    }

    private function service(
        KlineProviderInterface $klineProvider,
        IndicatorSnapshotRepository $snapshotRepository,
        \DateTimeImmutable $now,
    ): IndicatorProviderService {
        $logger = new NullLogger();

        return new IndicatorProviderService(
            $klineProvider,
            new ConditionRegistry([], $this->emptyLocator(), $logger),
            $snapshotRepository,
            new Rsi(),
            new Ema(),
            new Macd(),
            new Adx(),
            new Bollinger(),
            new Vwap(),
            new AtrCalculator($logger),
            new StochRsi(),
            new Sma(),
            $this->fixedClock($now),
            $logger,
        );
    }

    private function fixedClock(\DateTimeImmutable $now): ClockInterface
    {
        return new class($now) implements ClockInterface {
            public function __construct(private readonly \DateTimeImmutable $now)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }

    /**
     * @return ContainerInterface&ServiceProviderInterface
     */
    private function emptyLocator(): ContainerInterface
    {
        return new class implements ContainerInterface, ServiceProviderInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException(sprintf('Service "%s" is not available in this test locator.', $id));
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function getProvidedServices(): array
            {
                return [];
            }
        };
    }
}
