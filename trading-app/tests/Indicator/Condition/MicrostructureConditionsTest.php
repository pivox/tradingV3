<?php

declare(strict_types=1);

namespace App\Tests\Indicator\Condition;

use App\Indicator\Condition\MicrostructureProof;
use App\Indicator\Condition\OrderFlowImbalanceGteCondition;
use App\Indicator\Condition\OrderFlowImbalanceLteCondition;
use App\Indicator\Condition\SpreadBpsLteCondition;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\TradingCore\Microstructure\CanonicalMicrostructureEngine;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshot;
use App\TradingCore\Rules\Evaluation\RuleMarketIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MicrostructureProof::class)]
#[CoversClass(SpreadBpsLteCondition::class)]
#[CoversClass(OrderFlowImbalanceGteCondition::class)]
#[CoversClass(OrderFlowImbalanceLteCondition::class)]
final class MicrostructureConditionsTest extends TestCase
{
    public function testThresholdsAreInclusiveAndValuesOutsideFailNormally(): void
    {
        $spread = new SpreadBpsLteCondition();
        self::assertTrue($spread->evaluate($this->context(['spread_bps' => 8.0, 'max_spread_bps' => 8.0]))->passed);
        self::assertFalse($spread->evaluate($this->context(['max_spread_bps' => 7.99]))->passed);

        $long = new OrderFlowImbalanceGteCondition();
        self::assertTrue($long->evaluate($this->context(['order_flow_imbalance' => 0.15, 'min_ofi' => 0.15]))->passed);
        self::assertFalse($long->evaluate($this->context(['min_ofi' => 0.151]))->passed);

        $short = new OrderFlowImbalanceLteCondition();
        self::assertTrue($short->evaluate($this->context(['max_ofi' => -0.15], negativeOfi: true))->passed);
        self::assertFalse($short->evaluate($this->context(['max_ofi' => -0.151], negativeOfi: true))->passed);
    }

    public function testHashShapeWithoutCanonicalSnapshotCannotPass(): void
    {
        $context = $this->context();
        unset($context['_input_proof']);

        $result = (new SpreadBpsLteCondition())->evaluate($context);

        self::assertFalse($result->passed);
        self::assertTrue($result->meta['missing_data'] ?? false);
        self::assertSame('microstructure_proof_snapshot_missing', $result->meta['proof_reason'] ?? null);
    }

    public function testHashShapedValueMustEqualTheCanonicalSnapshotCommitment(): void
    {
        $result = (new SpreadBpsLteCondition())->evaluate($this->context([
            'microstructure_input_hash' => 'sha256:' . str_repeat('c', 64),
        ]));

        self::assertFalse($result->passed);
        self::assertSame('microstructure_proof_snapshot_binding_invalid', $result->meta['proof_reason'] ?? null);
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $mutate */
    #[DataProvider('invalidProofProvider')]
    public function testInvalidProofFailsClosed(callable $mutate, string $expectedReason): void
    {
        $result = (new SpreadBpsLteCondition())->evaluate($mutate($this->context()));

        self::assertFalse($result->passed);
        self::assertNull($result->value);
        self::assertNull($result->threshold);
        self::assertTrue($result->meta['missing_data'] ?? false);
        self::assertSame($expectedReason, $result->meta['proof_reason'] ?? null);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function invalidProofProvider(): iterable
    {
        yield 'source' => [self::replace('_input_source', 'indicator_snapshot'), 'microstructure_proof_source_invalid'];
        yield 'timeframe' => [self::replace('timeframe', '5m'), 'microstructure_proof_timeframe_invalid'];
        yield 'definition' => [self::replace('order_flow_imbalance_definition', 'legacy'), 'microstructure_proof_definition_invalid'];
        yield 'input hash' => [self::replace('microstructure_input_hash', 'sha256:nope'), 'microstructure_proof_input_hash_invalid'];
        yield 'checksum' => [self::replace('source_checksum', 'sha256:nope'), 'microstructure_proof_source_checksum_invalid'];
        yield 'network' => [self::replace('source_network', 'sandbox'), 'microstructure_proof_network_invalid'];
        yield 'venue' => [self::replace('market_data_venue', 'bitmart'), 'microstructure_proof_venue_invalid'];
        yield 'market type' => [self::replace('market_type', 'spot'), 'microstructure_proof_market_type_invalid'];
        yield 'symbol' => [self::replace('symbol', 'btc/usdt'), 'microstructure_proof_symbol_invalid'];
        yield 'unit' => [self::replace('quantity_unit', 'base_asset'), 'microstructure_proof_quantity_unit_invalid'];
        yield 'metric' => [self::replace('spread_bps', INF), 'microstructure_proof_metric_invalid'];
        yield 'threshold' => [self::replace('max_spread_bps', NAN), 'microstructure_proof_threshold_invalid'];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function context(array $overrides = [], bool $negativeOfi = false): array
    {
        $snapshot = $this->snapshot($negativeOfi);

        return array_replace([
            '_input_proof' => $snapshot,
            '_input_source' => 'timestamped_order_book',
            '_input_observed_at' => '2026-08-14T12:01:00+00:00',
            '_input_valid_until' => '2026-08-14T12:01:00+00:00',
            '_expected_market_identity' => new RuleMarketIdentity('mainnet', 'okx', 'perpetual', 'BTCUSDT', 'contracts'),
            'timeframe' => '1m',
            'spread_bps' => 8.0,
            'order_flow_imbalance' => (float) $snapshot->orderFlowImbalance,
            'order_flow_imbalance_definition' => 'aggressor_volume_ratio.v1',
            'microstructure_input_hash' => $snapshot->inputHash,
            'source_checksum' => 'sha256:' . str_repeat('b', 64),
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'quantity_unit' => 'contracts',
            'max_spread_bps' => 8.0,
            'min_ofi' => 0.15,
            'max_ofi' => -0.15,
        ], $overrides);
    }

    private function snapshot(bool $negativeOfi = false): CanonicalMicrostructureSnapshot
    {
        $checksum = 'sha256:' . str_repeat('b', 64);

        return (new CanonicalMicrostructureEngine())->build(
            new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000+00:00'),
            [new NormalizedBacktestPublicBook(
                str_repeat('a', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT',
                '2026-08-14T12:00:59.000000Z', '2026-08-14T12:00:59.000000Z',
                '9996', '10', '10004', '12', 'contracts', '2', '3', 'ws_books',
            )],
            [
                new NormalizedBacktestPublicTrade(str_repeat('1', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '1', '2026-08-14T12:00:10.000000Z', '2026-08-14T12:00:10.000000Z', $negativeOfi ? 'sell' : 'buy', '10000', '20', 'contracts'),
                new NormalizedBacktestPublicTrade(str_repeat('2', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '2', '2026-08-14T12:00:30.000000Z', '2026-08-14T12:00:30.000000Z', $negativeOfi ? 'buy' : 'sell', '10000', '17', 'contracts'),
                new NormalizedBacktestPublicTrade(str_repeat('3', 64), $checksum, 'mainnet', 'okx', 'BTCUSDT', '3', '2026-08-14T12:00:55.000000Z', '2026-08-14T12:00:55.000000Z', $negativeOfi ? 'sell' : 'buy', '10000', '3', 'contracts'),
            ],
        );
    }

    /** @return callable(array<string, mixed>): array<string, mixed> */
    private static function replace(string $key, mixed $value): callable
    {
        return static fn (array $context): array => array_replace($context, [$key => $value]);
    }
}
