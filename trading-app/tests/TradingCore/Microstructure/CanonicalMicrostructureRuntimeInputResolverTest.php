<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Microstructure;

use App\Trading\Lineage\LineageContext;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicBook;
use App\Trading\Paper\Backtesting\NormalizedBacktestPublicTrade;
use App\TradingCore\Microstructure\CanonicalMicrostructureEngine;
use App\TradingCore\Microstructure\CanonicalMicrostructurePolicy;
use App\TradingCore\Microstructure\CanonicalMicrostructureRuntimeInputResolver;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshot;
use App\TradingCore\Microstructure\CanonicalMicrostructureSnapshotProviderInterface;
use App\TradingCore\Rules\Catalog\ConditionCatalogLoader;
use App\Tests\Trading\Lineage\CanonicalSnapshotMetadataFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalMicrostructureRuntimeInputResolver::class)]
final class CanonicalMicrostructureRuntimeInputResolverTest extends TestCase
{
    public function testResolvesAuthenticatedInputAgainstRuntimeOwnedIdentity(): void
    {
        $snapshot = $this->snapshot('BTCUSDT');
        $provider = $this->provider($snapshot);

        $resolved = (new CanonicalMicrostructureRuntimeInputResolver($provider))->resolve(
            $this->lineage('okx', 'mainnet'),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
        );

        self::assertSame('ready', $resolved->status);
        self::assertNotNull($resolved->ruleInput);
        self::assertSame('timestamped_order_book', $resolved->ruleInput->source);
        self::assertSame($snapshot->inputHash, $resolved->trace['input_hash']);
        self::assertSame((float) $snapshot->bestBid, $resolved->trace['best_bid']);
        self::assertSame((float) $snapshot->bestAsk, $resolved->trace['best_ask']);
        self::assertSame((float) $snapshot->spreadBps, $resolved->trace['spread_bps']);
        self::assertSame($snapshot->bookHappenedAt, $resolved->trace['book_observed_at']);
        self::assertSame([
            'source_network' => 'mainnet',
            'market_data_venue' => 'okx',
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'quantity_unit' => 'contracts',
        ], $resolved->marketIdentity?->toArray());
        self::assertSame($resolved->marketIdentity->toArray(), $resolved->trace['expected_market_identity']);
    }

    public function testProviderAbsenceAndUnsupportedFakeIdentityAreExplicit(): void
    {
        $absent = (new CanonicalMicrostructureRuntimeInputResolver())->resolve(
            $this->lineage('okx', 'mainnet'),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
        );
        $calls = 0;
        $provider = new class($calls) implements CanonicalMicrostructureSnapshotProviderInterface {
            public function __construct(private int &$calls) {}
            public function snapshotFor(LineageContext $identity, \DateTimeImmutable $evaluatedAt): ?CanonicalMicrostructureSnapshot
            {
                ++$this->calls;
                return null;
            }
        };
        $unsupported = (new CanonicalMicrostructureRuntimeInputResolver($provider))->resolve(
            $this->lineage('fake', 'test'),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
        );

        self::assertSame('provider_unavailable', $absent->status);
        self::assertNull($absent->ruleInput);
        self::assertSame('identity_unavailable', $unsupported->status);
        self::assertSame(0, $calls);
    }

    public function testNullFailureAndCrossMarketProofAllFailClosed(): void
    {
        $now = new \DateTimeImmutable('2026-08-14T12:01:00.000000Z');
        $null = (new CanonicalMicrostructureRuntimeInputResolver($this->provider(null)))->resolve(
            $this->lineage('okx', 'mainnet'),
            $now,
        );
        $failureProvider = new class implements CanonicalMicrostructureSnapshotProviderInterface {
            public function snapshotFor(LineageContext $identity, \DateTimeImmutable $evaluatedAt): ?CanonicalMicrostructureSnapshot
            {
                throw new \RuntimeException('secret provider detail');
            }
        };
        $failure = (new CanonicalMicrostructureRuntimeInputResolver($failureProvider))->resolve(
            $this->lineage('okx', 'mainnet'),
            $now,
        );
        $crossed = (new CanonicalMicrostructureRuntimeInputResolver($this->provider($this->snapshot('ETHUSDT'))))->resolve(
            $this->lineage('okx', 'mainnet'),
            $now,
        );

        self::assertSame('input_unavailable', $null->status);
        self::assertSame('input_rejected', $failure->status);
        self::assertSame(\RuntimeException::class, $failure->trace['exception_class']);
        self::assertStringNotContainsString('secret provider detail', json_encode($failure->trace, JSON_THROW_ON_ERROR));
        self::assertSame('identity_mismatch', $crossed->status);
        self::assertNull($crossed->ruleInput);
    }

    private function provider(?CanonicalMicrostructureSnapshot $snapshot): CanonicalMicrostructureSnapshotProviderInterface
    {
        return new class($snapshot) implements CanonicalMicrostructureSnapshotProviderInterface {
            public function __construct(private readonly ?CanonicalMicrostructureSnapshot $snapshot) {}
            public function snapshotFor(LineageContext $identity, \DateTimeImmutable $evaluatedAt): ?CanonicalMicrostructureSnapshot
            {
                return $this->snapshot;
            }
        };
    }

    private function lineage(string $exchange, string $environment): LineageContext
    {
        $catalogHash = 'sha256:' . (new ConditionCatalogLoader())->loadVersion('1.0.0')->stableHash();
        $config = [
            'schema_version' => 'effective-trading-config.v2',
            'mode' => ['mode_id' => 'micro_scalping', 'mode_version' => '1.0.0'],
            'setup' => ['setup_id' => 'micro_scalping.momentum_ofi.long', 'setup_version' => '1.0.0'],
            'exchange' => ['id' => $exchange],
            'environment' => ['id' => $environment],
        ];
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, $catalogHash);
        $request = [
            'mode_id' => 'micro_scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'micro_scalping.momentum_ofi.long',
            'setup_version' => '1.0.0',
            'exchange' => $exchange,
            'environment' => $environment,
            'side' => 'long',
        ];
        $snapshot = CanonicalSnapshotMetadataFixture::enrich([
            'request' => $request,
            'config' => $config,
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'executable' => false,
            'blockers' => ['micro_scalping_contract_blocked'],
        ]);

        return LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => 'run-micro-runtime-input',
            'orchestration_set_id' => 'set-micro-runtime-input',
            'mode_id' => 'micro_scalping',
            'mode_version' => '1.0.0',
            'setup_id' => 'micro_scalping.momentum_ofi.long',
            'setup_version' => '1.0.0',
            'config_hash' => $configHash,
            'condition_catalog_hash' => $catalogHash,
            'side' => 'LONG',
            'exchange' => $exchange,
            'environment' => $environment,
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'dry_run' => true,
            'effective_config_reference' => 'effective-config:micro-runtime-input',
            'effective_config_snapshot' => $snapshot,
        ]);
    }

    private function snapshot(string $symbol): CanonicalMicrostructureSnapshot
    {
        $checksum = 'sha256:' . str_repeat('f', 64);
        return (new CanonicalMicrostructureEngine())->build(
            new CanonicalMicrostructurePolicy(60, 2, 5, 30, 3),
            new \DateTimeImmutable('2026-08-14T12:01:00.000000Z'),
            [new NormalizedBacktestPublicBook(
                str_repeat('a', 64), $checksum, 'mainnet', 'okx', $symbol,
                '2026-08-14T12:00:59.000000Z', '2026-08-14T12:00:59.000000Z',
                '99', '10', '101', '12', 'contracts', '2', '3', 'ws_books',
            )],
            [
                $this->trade('1', '2026-08-14T12:00:10.000000Z', 'buy', '3', $checksum, $symbol),
                $this->trade('2', '2026-08-14T12:00:30.000000Z', 'sell', '1', $checksum, $symbol),
                $this->trade('3', '2026-08-14T12:00:55.000000Z', 'buy', '2', $checksum, $symbol),
            ],
        );
    }

    private function trade(string $id, string $time, string $side, string $quantity, string $checksum, string $symbol): NormalizedBacktestPublicTrade
    {
        return new NormalizedBacktestPublicTrade(
            str_repeat($id, 64), $checksum, 'mainnet', 'okx', $symbol, $id,
            $time, $time, $side, '100', $quantity, 'contracts',
        );
    }
}
