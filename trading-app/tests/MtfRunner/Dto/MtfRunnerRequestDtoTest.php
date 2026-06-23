<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerRequestDto::class)]
final class MtfRunnerRequestDtoTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: Exchange}>
     */
    public static function exchangeProvider(): iterable
    {
        yield 'bitmart' => ['bitmart', Exchange::BITMART];
        yield 'binance' => ['binance', Exchange::BINANCE];
        yield 'fake' => ['fake', Exchange::FAKE];
        yield 'okx' => ['okx', Exchange::OKX];
        yield 'hyperliquid' => ['hyperliquid', Exchange::HYPERLIQUID];
        yield 'trimmed uppercase okx' => [' OKX ', Exchange::OKX];
    }

    #[DataProvider('exchangeProvider')]
    public function testFromArrayNormalizesAllExchangeEnumValues(string $input, Exchange $expected): void
    {
        $request = MtfRunnerRequestDto::fromArray(['exchange' => $input]);

        self::assertSame($expected, $request->exchange);
    }

    public function testFromArrayRejectsUnknownExchange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported exchange "unknown"');

        MtfRunnerRequestDto::fromArray(['exchange' => 'unknown']);
    }

    /**
     * @return iterable<string, array{0: string, 1: MarketType}>
     */
    public static function marketTypeProvider(): iterable
    {
        yield 'perpetual' => ['perpetual', MarketType::PERPETUAL];
        yield 'futures alias' => ['futures', MarketType::PERPETUAL];
        yield 'future alias' => ['future', MarketType::PERPETUAL];
        yield 'perp alias' => ['perp', MarketType::PERPETUAL];
        yield 'spot' => ['spot', MarketType::SPOT];
    }

    #[DataProvider('marketTypeProvider')]
    public function testFromArrayNormalizesMarketTypeAliases(string $input, MarketType $expected): void
    {
        $request = MtfRunnerRequestDto::fromArray(['market_type' => $input]);

        self::assertSame($expected, $request->marketType);
    }

    public function testFromArrayParsesOpenStateSnapshot(): void
    {
        $request = MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => [
                'open_positions' => [['symbol' => 'BTCUSDT']],
                'open_orders' => [['symbol' => 'ETHUSDT']],
            ],
        ]);

        self::assertNotNull($request->openStateSnapshot);
        self::assertSame('BTCUSDT', $request->openStateSnapshot['open_positions'][0]['symbol']);
        self::assertSame('ETHUSDT', $request->openStateSnapshot['open_orders'][0]['symbol']);
    }

    public function testFromArrayDefaultsOpenStateSnapshotToNull(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray([])->openStateSnapshot);
    }

    public function testFromArrayKeepsWellFormedEmptyOpenStateSnapshot(): void
    {
        // Snapshot vide mais bien formé : l'orchestrateur a interrogé l'exchange,
        // rien n'était ouvert. Reste une source fiable (les deux clés sont des tableaux).
        $request = MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => ['open_positions' => [], 'open_orders' => []],
        ]);

        self::assertNotNull($request->openStateSnapshot);
        self::assertSame([], $request->openStateSnapshot['open_positions']);
        self::assertSame([], $request->openStateSnapshot['open_orders']);
    }

    public function testFromArrayRejectsPartialOpenStateSnapshot(): void
    {
        // Clé open_orders manquante => snapshot mal formé => null, pour que le garde
        // fail-closed en live ne soit pas contourné par un payload incomplet.
        self::assertNull(MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => ['open_positions' => [['symbol' => 'BTCUSDT']]],
        ])->openStateSnapshot);
    }

    public function testFromArrayRejectsEmptyObjectOpenStateSnapshot(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray(['open_state_snapshot' => []])->openStateSnapshot);
    }

    public function testFromArrayRejectsNonArraySnapshotKeys(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray([
            'open_state_snapshot' => ['open_positions' => 'nope', 'open_orders' => []],
        ])->openStateSnapshot);
    }

    public function testFromArrayIgnoresNonArrayOpenStateSnapshot(): void
    {
        self::assertNull(MtfRunnerRequestDto::fromArray(['open_state_snapshot' => 'nope'])->openStateSnapshot);
    }

    // --- OBS-003 : lineage d'orchestration --------------------------------------

    public function testFromArrayParsesOrchestrationLineage(): void
    {
        $dto = MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'run_id' => 'run_dashA_20260617',
            'correlation_run_id' => 'run_dashA_20260617',
            'orchestration_dashboard_id' => 'dashA',
            'orchestration_set_id' => 's1',
        ]);

        self::assertSame('run_dashA_20260617', $dto->originalRunId);
        self::assertSame('run_dashA_20260617', $dto->correlationRunId);
        self::assertSame('dashA', $dto->dashboardId);
        self::assertSame('s1', $dto->setId);
    }

    public function testFromArrayAcceptsShortDashboardAndSetAliases(): void
    {
        $dto = MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'dashboard_id' => 'dashB',
            'set_id' => 's2',
        ]);

        self::assertSame('dashB', $dto->dashboardId);
        self::assertSame('s2', $dto->setId);
    }

    public function testLegacyRequestHasNullLineage(): void
    {
        $dto = MtfRunnerRequestDto::fromArray(['symbols' => ['BTCUSDT']]);

        self::assertNull($dto->originalRunId);
        self::assertNull($dto->correlationRunId);
        self::assertNull($dto->dashboardId);
        self::assertNull($dto->setId);
    }

    public function testBlankLineageValuesAreNormalisedToNull(): void
    {
        $dto = MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'run_id' => '   ',
            'set_id' => '',
        ]);

        self::assertNull($dto->originalRunId);
        self::assertNull($dto->setId);
    }

    public function testToArrayRoundTripsLineage(): void
    {
        $array = MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'run_id' => 'orig',
            'correlation_run_id' => 'corr',
            'dashboard_id' => 'd',
            'set_id' => 's',
        ])->toArray();

        self::assertSame('orig', $array['run_id']);
        self::assertSame('corr', $array['correlation_run_id']);
        self::assertSame('d', $array['dashboard_id']);
        self::assertSame('s', $array['set_id']);
    }

    public function testToArrayRoundTripsReplayLineageContext(): void
    {
        $array = MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'run_id' => 'run-replay',
            'set_id' => 'set-a',
            'dashboard_id' => 'dash-a',
            'origin' => 'replay',
            'replay_of_run_id' => 'run-source',
            'replay_of_correlation_id' => 'corr-source',
            'attempt_number' => 2,
            'config_hash' => 'cfg-replay',
        ])->toArray();

        $roundTrip = MtfRunnerRequestDto::fromArray($array);

        self::assertSame('replay', $roundTrip->lineageContext->origin);
        self::assertSame('run-source', $roundTrip->lineageContext->replayOfRunId);
        self::assertSame('corr-source', $roundTrip->lineageContext->replayOfCorrelationId);
        self::assertSame(2, $roundTrip->lineageContext->attemptNumber);
        self::assertSame('cfg-replay', $roundTrip->lineageContext->configHash);
    }

    public function testCreatesTypedLineageContextForOrchestratorAndLegacy(): void
    {
        $orchestrated = MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'run_id' => 'run-a',
            'correlation_run_id' => 'run-a',
            'set_id' => 'set-a',
            'dashboard_id' => 'dash-a',
            'profile' => 'scalper_micro',
            'exchange' => 'bitmart',
            'market_type' => 'perpetual',
            'dry_run' => true,
            'attempt_number' => 3,
        ]);

        self::assertSame('orchestrator', $orchestrated->lineageContext->origin);
        self::assertSame('run-a', $orchestrated->lineageContext->orchestrationRunId);
        self::assertSame('set-a', $orchestrated->lineageContext->orchestrationSetId);
        self::assertSame(3, $orchestrated->lineageContext->attemptNumber);

        $legacy = MtfRunnerRequestDto::fromArray(['symbols' => ['ETHUSDT']]);

        self::assertSame('legacy', $legacy->lineageContext->origin);
        self::assertNull($legacy->lineageContext->orchestrationSetId);
    }
}
