<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Identity;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperExecutionCell::class)]
final class PaperExecutionCellTest extends TestCase
{
    private const SNAPSHOT_ID = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCellAndAccountNamespaceAreDeterministic(): void
    {
        $cell = self::cell();
        $expectedDigest = hash('sha256', CanonicalJson::encode([
            'schema_version' => 1,
            'network' => 'testnet',
            'market_data_venue' => 'hyperliquid',
            'configuration_snapshot_id' => self::SNAPSHOT_ID,
            'strategy_profile' => 'scalper_micro',
            'run_id' => 'run-001',
        ]));

        self::assertSame('e333edd5a399635988a0b3bf57b4a58a8e8377eda407b3b434230663959bb4cb', $expectedDigest);
        self::assertSame('sha256:e333edd5a399635988a0b3bf57b4a58a8e8377eda407b3b434230663959bb4cb', $cell->id);
        self::assertSame('paper:cell:v1:' . $expectedDigest, $cell->accountNamespace);
        self::assertSame(PaperMarketDataNetwork::TESTNET, $cell->network);
        self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $cell->marketDataVenue);
        self::assertSame(self::SNAPSHOT_ID, $cell->configurationSnapshotId);
        self::assertSame('scalper_micro', $cell->strategyProfile);
        self::assertSame('run-001', $cell->runId);
        self::assertFalse($cell->isModern());
        self::assertNull($cell->modernIdentity);
    }

    public function testModernCellUsesV2DigestWithEveryCanonicalDimension(): void
    {
        $identity = self::modernIdentity();
        $cell = PaperExecutionCell::createModern(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            $identity,
            'modern-run-001',
        );
        $expectedDigest = hash('sha256', CanonicalJson::encode([
            'schema_version' => 2,
            'network' => 'testnet',
            'market_data_venue' => 'hyperliquid',
            'configuration_snapshot_id' => self::SNAPSHOT_ID,
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'side' => $identity->side,
            'config_hash' => $identity->configHash,
            'condition_catalog_hash' => $identity->conditionCatalogHash,
            'run_id' => 'modern-run-001',
        ]));

        self::assertSame('sha256:' . $expectedDigest, $cell->id);
        self::assertSame('paper:cell:v2:' . $expectedDigest, $cell->accountNamespace);
        self::assertTrue($cell->isModern());
        self::assertSame('micro_scalping', $cell->strategyProfile);
        self::assertSame($identity, $cell->modernIdentity);
    }

    public function testModernIdentityCannotBeBoundToAnotherMarketScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_modern_identity_market_scope_mismatch');

        PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            self::modernIdentity(),
            'modern-run-001',
        );
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('identityDimensionProvider')]
    public function testEveryIdentityDimensionChangesTheCell(array $override): void
    {
        $base = self::cell();
        $changed = self::cell(...$override);

        self::assertNotSame($base->id, $changed->id);
        self::assertNotSame($base->accountNamespace, $changed->accountNamespace);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function identityDimensionProvider(): iterable
    {
        yield 'network' => [['network' => PaperMarketDataNetwork::MAINNET]];
        yield 'venue' => [[
            'network' => PaperMarketDataNetwork::MAINNET,
            'venue' => PaperMarketDataVenue::OKX,
        ]];
        yield 'snapshot' => [['snapshotId' => 'sha256:' . str_repeat('b', 64)]];
        yield 'profile' => [['profile' => 'regular']];
        yield 'run' => [['runId' => 'run-002']];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidCellProvider')]
    public function testInvalidOrImplicitCellInputFailsClosed(array $arguments, string $reason): void
    {
        try {
            PaperExecutionCell::create(...$arguments);
            self::fail('Invalid Paper execution cell was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame($reason, $exception->getMessage());
        }
    }

    /** @return iterable<string, array{list<mixed>, string}> */
    public static function invalidCellProvider(): iterable
    {
        yield 'legacy network' => [[
            PaperMarketDataNetwork::LEGACY_UNKNOWN,
            PaperMarketDataVenue::HYPERLIQUID,
            self::SNAPSHOT_ID,
            'regular',
            'run-001',
        ], 'paper_execution_network_unsupported'];
        yield 'OKX testnet' => [[
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::OKX,
            self::SNAPSHOT_ID,
            'regular',
            'run-001',
        ], 'paper_execution_network_venue_unsupported'];
        yield 'invalid snapshot' => [[
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'snapshot-latest',
            'regular',
            'run-001',
        ], 'paper_configuration_snapshot_id_invalid'];
        yield 'profile alias' => [[
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            self::SNAPSHOT_ID,
            'REGULAR',
            'run-001',
        ], 'paper_strategy_profile_unknown'];
        yield 'profile default' => [[
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            self::SNAPSHOT_ID,
            'default',
            'run-001',
        ], 'paper_strategy_profile_unknown'];
        yield 'blank run' => [[
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            self::SNAPSHOT_ID,
            'regular',
            '',
        ], 'paper_execution_run_id_invalid'];
        yield 'trim alias run' => [[
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            self::SNAPSHOT_ID,
            'regular',
            ' run-001 ',
        ], 'paper_execution_run_id_invalid'];
    }

    private static function cell(
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::TESTNET,
        PaperMarketDataVenue $venue = PaperMarketDataVenue::HYPERLIQUID,
        string $snapshotId = self::SNAPSHOT_ID,
        string $profile = 'scalper_micro',
        string $runId = 'run-001',
    ): PaperExecutionCell {
        return PaperExecutionCell::create($network, $venue, $snapshotId, $profile, $runId);
    }

    private static function modernIdentity(): PaperModernStrategyIdentity
    {
        $conditionHash = 'sha256:' . str_repeat('c', 64);
        $payload = ['decision' => ['enabled' => true]];
        $layers = [];
        foreach (['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'] as $type) {
            $layers[] = ['type' => $type, 'name' => $type, 'path' => $type . '.yaml', 'required' => true];
        }
        $snapshot = new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest(
                'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0',
                'hyperliquid', 'testnet', 'long', ShadowExecutionCapability::Paper,
            ),
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $conditionHash),
            $conditionHash,
            $layers,
            ['decision.enabled' => $layers[0]],
        );

        return PaperModernStrategyIdentity::fromResolvedSnapshot(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            $snapshot,
        );
    }
}
