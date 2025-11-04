<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Entity\IndicatorSnapshot;
use App\MtfValidator\Service\SnapshotPersister;
use App\MtfValidator\Support\KlineTimeParser;
use App\Repository\IndicatorSnapshotRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SnapshotPersisterTest extends TestCase
{
    public function testPersistSkipsGraceWindow(): void
    {
        $repository = $this->createMock(IndicatorSnapshotRepository::class);
        $repository->expects(self::never())->method('findOneBy');
        $repository->expects(self::never())->method('upsert');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $persister = new SnapshotPersister($repository, $logger, new KlineTimeParser());
        $persister->persist('BTCUSDT', '1m', ['status' => 'GRACE_WINDOW']);
    }

    public function testPersistStoresSnapshot(): void
    {
        $repository = $this->createMock(IndicatorSnapshotRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(self::callback(static function (array $criteria): bool {
                return $criteria['symbol'] === 'BTCUSDT'
                    && $criteria['timeframe']->value === '1m'
                    && $criteria['klineTime'] instanceof \DateTimeImmutable;
            }))
            ->willReturn(null);

        $repository->expects(self::once())
            ->method('upsert')
            ->with(self::callback(static function (IndicatorSnapshot $snapshot): bool {
                return $snapshot->getSymbol() === 'BTCUSDT'
                    && $snapshot->getTimeframe()->value === '1m'
                    && $snapshot->getValues() !== []
                    && $snapshot->getKlineTime() instanceof \DateTimeImmutable;
            }));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $persister = new SnapshotPersister($repository, $logger, new KlineTimeParser());
        $persister->persist(
            'btcusdt',
            '1m',
            [
                'status' => 'VALID',
                'kline_time' => '2024-01-01 00:00:00',
                'indicator_context' => [
                    'rsi' => 55.5,
                    'ema' => ['20' => 123.45],
                    'macd' => ['macd' => 0.1, 'signal' => 0.05, 'hist' => 0.02],
                    'bollinger' => ['upper' => 200, 'middle' => 150, 'lower' => 100],
                    'adx' => ['14' => 20],
                    'ma9' => 99,
                    'ma21' => 101,
                    'close' => 150.12,
                ],
                'atr' => 1.23,
            ]
        );
    }
}
