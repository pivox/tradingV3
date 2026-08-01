<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Identity;

use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
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

        self::assertSame('sha256:' . $expectedDigest, $cell->id);
        self::assertSame('paper:cell:v1:' . $expectedDigest, $cell->accountNamespace);
        self::assertSame(PaperMarketDataNetwork::TESTNET, $cell->network);
        self::assertSame(PaperMarketDataVenue::HYPERLIQUID, $cell->marketDataVenue);
        self::assertSame(self::SNAPSHOT_ID, $cell->configurationSnapshotId);
        self::assertSame('scalper_micro', $cell->strategyProfile);
        self::assertSame('run-001', $cell->runId);
    }

    #[DataProvider('identityDimensionProvider')]
    public function testEveryIdentityDimensionChangesTheCell(array $override): void
    {
        $base = self::cell();
        $changed = self::cell(...$override);

        self::assertNotSame($base->id, $changed->id);
        self::assertNotSame($base->accountNamespace, $changed->accountNamespace);
    }

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
}
