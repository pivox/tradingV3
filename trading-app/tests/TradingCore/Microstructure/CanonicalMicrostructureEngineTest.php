<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Microstructure;

use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\TradingCore\Microstructure\CanonicalMicrostructureEngine;
use App\TradingCore\Microstructure\CanonicalMicrostructureException;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalMicrostructureEngine::class)]
#[CoversClass(CanonicalMicrostructurePolicy::class)]
final class CanonicalMicrostructureEngineTest extends TestCase
{
    public function testBuildsCanonicalSpreadAndAggressorVolumeImbalance(): void
    {
        $snapshot = (new CanonicalMicrostructureEngine())->build(
            new CanonicalMicrostructurePolicy(
                windowSeconds: 60,
                maximumBookAgeSeconds: 2,
                maximumTradeAgeSeconds: 5,
                maximumTradeGapSeconds: 30,
                minimumTradeCount: 3,
            ),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000+00:00'),
            [$this->book('2026-08-14T12:00:59.000000Z')],
            [
                $this->trade('1', '2026-08-14T12:00:10.000000Z', 'buy', '3'),
                $this->trade('2', '2026-08-14T12:00:30.000000Z', 'sell', '1'),
                $this->trade('3', '2026-08-14T12:00:55.000000Z', 'buy', '2'),
            ],
        );

        self::assertSame('canonical-microstructure-snapshot.v1', $snapshot->schemaVersion);
        self::assertSame('200', $snapshot->spreadBps);
        self::assertSame('aggressor_volume_ratio.v1', $snapshot->orderFlowImbalanceDefinition);
        self::assertSame('5', $snapshot->buyQuantity);
        self::assertSame('1', $snapshot->sellQuantity);
        self::assertSame('6', $snapshot->totalQuantity);
        self::assertSame('0.666666666667', $snapshot->orderFlowImbalance);
        self::assertSame(3, $snapshot->tradeCount);
        self::assertSame([
            str_repeat('1', 64),
            str_repeat('2', 64),
            str_repeat('3', 64),
        ], $snapshot->tradeSourceRecordIds);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->inputHash);
        self::assertSame('sha256:a7e30f9c9cf55b5020b90ae3dbd0f29cb149ca3754773e07cb3f6263244f2e04', $snapshot->inputHash);
        self::assertSame($snapshot, $snapshot->verify());
    }

    public function testRejectsMissingBookWithStableReason(): void
    {
        $this->expectException(CanonicalMicrostructureException::class);
        $this->expectExceptionMessage('canonical_microstructure_book_unavailable');

        (new CanonicalMicrostructureEngine())->build(
            $this->policy(),
            $this->evaluatedAt(),
            [],
            $this->trades(),
        );
    }

    public function testRejectsMissingTradesWithStableReason(): void
    {
        $this->expectException(CanonicalMicrostructureException::class);
        $this->expectExceptionMessage('canonical_microstructure_trades_insufficient');

        (new CanonicalMicrostructureEngine())->build(
            $this->policy(),
            $this->evaluatedAt(),
            [$this->book('2026-08-14T12:00:59.000000Z')],
            [],
        );
    }

    public function testRejectsAWindowGap(): void
    {
        $this->expectException(CanonicalMicrostructureException::class);
        $this->expectExceptionMessage('canonical_microstructure_trade_gap');

        (new CanonicalMicrostructureEngine())->build(
            new CanonicalMicrostructurePolicy(60, 2, 5, 10, 2),
            $this->evaluatedAt(),
            [$this->book('2026-08-14T12:00:59.000000Z')],
            [
                $this->trade('1', '2026-08-14T12:00:01.000000Z', 'buy', '1'),
                $this->trade('2', '2026-08-14T12:00:55.000000Z', 'sell', '1'),
            ],
        );
    }

    public function testRejectsForgedSnapshotMetricEvenWithRecomputedHash(): void
    {
        $snapshot = (new CanonicalMicrostructureEngine())->build(
            $this->policy(),
            $this->evaluatedAt(),
            [$this->book('2026-08-14T12:00:59.000000Z')],
            $this->trades(),
        );
        $arguments = [];
        foreach ((new \ReflectionMethod(CanonicalMicrostructureSnapshot::class, '__construct'))->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = $name === 'inputHash' ? null : $snapshot->{$name};
        }
        $arguments['orderFlowImbalance'] = '2';

        $this->expectException(CanonicalMicrostructureException::class);
        $this->expectExceptionMessage('canonical_microstructure_snapshot_invalid');

        new CanonicalMicrostructureSnapshot(...$arguments);
    }

    public function testSnapshotSerializationIsForbidden(): void
    {
        $snapshot = (new CanonicalMicrostructureEngine())->build(
            $this->policy(),
            $this->evaluatedAt(),
            [$this->book('2026-08-14T12:00:59.000000Z')],
            $this->trades(),
        );

        $this->expectException(CanonicalMicrostructureException::class);
        $this->expectExceptionMessage('canonical_microstructure_snapshot_hydration_forbidden');

        serialize($snapshot);
    }

    private function policy(): CanonicalMicrostructurePolicy
    {
        return new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3);
    }

    private function evaluatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-14T12:01:00.000000+00:00');
    }

    /** @return list<NormalizedBacktestPublicTrade> */
    private function trades(): array
    {
        return [
            $this->trade('1', '2026-08-14T12:00:10.000000Z', 'buy', '3'),
            $this->trade('2', '2026-08-14T12:00:30.000000Z', 'sell', '1'),
            $this->trade('3', '2026-08-14T12:00:55.000000Z', 'buy', '2'),
        ];
    }

    private function book(string $time): NormalizedBacktestPublicBook
    {
        return new NormalizedBacktestPublicBook(
            str_repeat('a', 64),
            'sha256:' . str_repeat('f', 64),
            'mainnet',
            'okx',
            'BTCUSDT',
            $time,
            $time,
            '99',
            '10',
            '101',
            '12',
            'contracts',
            '2',
            '3',
            'ws_books',
        );
    }

    private function trade(
        string $id,
        string $time,
        string $side,
        string $quantity,
    ): NormalizedBacktestPublicTrade {
        return new NormalizedBacktestPublicTrade(
            str_repeat($id, 64),
            'sha256:' . str_repeat('f', 64),
            'mainnet',
            'okx',
            'BTCUSDT',
            $id,
            $time,
            $time,
            $side,
            '100',
            $quantity,
            'contracts',
        );
    }
}
