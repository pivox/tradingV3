<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Microstructure;

use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\TradingCore\Microstructure\CanonicalMicrostructureEngine;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\CanonicalMicrostructureRuleInputAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalMicrostructureRuleInputAdapter::class)]
final class CanonicalMicrostructureRuleInputAdapterTest extends TestCase
{
    public function testAdaptsVerifiedSnapshotWithTheStrictestValidityBoundary(): void
    {
        $snapshot = $this->snapshot(new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3));

        $input = (new CanonicalMicrostructureRuleInputAdapter())->adapt($snapshot);

        self::assertSame('1m', $input->timeframe);
        self::assertSame('timestamped_order_book', $input->source);
        self::assertSame('2026-08-14T12:01:00.000000+00:00', $input->observedAt->format('Y-m-d\TH:i:s.uP'));
        self::assertSame('2026-08-14T12:01:00.000000+00:00', $input->validUntil->format('Y-m-d\TH:i:s.uP'));
        self::assertSame(200.0, $input->values['spread_bps']);
        self::assertSame(0.666666666667, $input->values['order_flow_imbalance']);
        self::assertSame('aggressor_volume_ratio.v1', $input->values['order_flow_imbalance_definition']);
        self::assertSame($snapshot->inputHash, $input->values['microstructure_input_hash']);
        self::assertSame($snapshot->sourceChecksum, $input->values['source_checksum']);
        self::assertSame('mainnet', $input->values['source_network']);
        self::assertSame('okx', $input->values['market_data_venue']);
        self::assertSame('perpetual', $input->values['market_type']);
        self::assertSame('BTCUSDT', $input->values['symbol']);
        self::assertSame('contracts', $input->values['quantity_unit']);
        self::assertSame(3, $input->values['microstructure_trade_count']);
        self::assertTrue($input->isValidAt($input->validUntil));
        self::assertFalse($input->isValidAt($input->validUntil->modify('+1 microsecond')));
    }

    public function testValidityNeverExceedsTheFiveSecondCatalogFreshness(): void
    {
        $input = (new CanonicalMicrostructureRuleInputAdapter())->adapt(
            $this->snapshot(new CanonicalMicrostructurePolicy(60, 30, 30, 60, 3)),
        );

        self::assertSame('2026-08-14T12:01:05.000000+00:00', $input->validUntil->format('Y-m-d\TH:i:s.uP'));
    }

    private function snapshot(CanonicalMicrostructurePolicy $policy): \App\TradingCore\Microstructure\CanonicalMicrostructureSnapshot
    {
        return (new CanonicalMicrostructureEngine())->build(
            $policy,
            new \DateTimeImmutable('2026-08-14T12:01:00.000000+00:00'),
            [$this->book()],
            [
                $this->trade('1', '2026-08-14T12:00:10.000000Z', 'buy', '3'),
                $this->trade('2', '2026-08-14T12:00:30.000000Z', 'sell', '1'),
                $this->trade('3', '2026-08-14T12:00:55.000000Z', 'buy', '2'),
            ],
        );
    }

    private function book(): NormalizedBacktestPublicBook
    {
        return new NormalizedBacktestPublicBook(
            str_repeat('a', 64),
            'sha256:' . str_repeat('f', 64),
            'mainnet',
            'okx',
            'BTCUSDT',
            '2026-08-14T12:00:59.000000Z',
            '2026-08-14T12:00:59.000000Z',
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

    private function trade(string $id, string $time, string $side, string $quantity): NormalizedBacktestPublicTrade
    {
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
