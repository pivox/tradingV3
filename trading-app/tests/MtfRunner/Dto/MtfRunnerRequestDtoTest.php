<?php

declare(strict_types=1);

namespace App\Tests\MtfRunner\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\MtfRunner\Dto\MtfRunnerRequestDto;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Lineage\LineageContextException;
use App\Tests\Trading\Lineage\CanonicalSnapshotMetadataFixture;
use App\Tests\Trading\Lineage\CanonicalSnapshotFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MtfRunnerRequestDto::class)]
final class MtfRunnerRequestDtoTest extends TestCase
{
    public function testBuildsCanonicalIdentityFromPythonTradingIdentityWithoutProfileFallback(): void
    {
        $catalogHash = 'sha256:' . str_repeat('b', 64);
        $config = ['trade_entry' => ['defaults' => [], 'entry' => [], 'risk' => [], 'leverage' => [], 'decision' => [], 'fees' => []]];
        $configHash = CanonicalEffectiveConfigSnapshot::calculateConfigHash($config, $catalogHash);
        $dto = MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'run_id' => 'run-1',
            'correlation_run_id' => 'run-1',
            'orchestration_set_id' => 'set-1',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'dry_run' => true,
            'trading_identity' => [
                'mode_id' => 'scalping',
                'mode_version' => '1.0.0',
                'setup_id' => 'scalping.pullback.long',
                'setup_version' => '1.0.0',
                'config_hash' => $configHash,
                'condition_catalog_hash' => $catalogHash,
                'side' => 'LONG',
                'effective_config_reference' => 'effective-config:cfg-1',
                'effective_config_snapshot' => CanonicalSnapshotMetadataFixture::enrich([
                    'request' => ['mode_id' => 'scalping', 'mode_version' => '1.0.0', 'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'exchange' => 'fake', 'environment' => 'test', 'side' => 'long'],
                    'config' => $config, 'config_hash' => $configHash, 'condition_catalog_hash' => $catalogHash,
                    'executable' => true, 'blockers' => [],
                ]),
            ],
        ]);

        self::assertSame('scalping', $dto->lineageContext->modeId);
        self::assertSame('scalping.pullback.long', $dto->lineageContext->setupId);
        self::assertNull($dto->lineageContext->symbol);
        self::assertSame('set-1', $dto->lineageContext->orchestrationSetId);
        self::assertArrayNotHasKey('profile', $dto->lineageContext->toArray());
    }

    public function testBuildsUnboundCanonicalIdentityWhenActiveUniverseRequestOmitsSymbols(): void
    {
        $tradingIdentity = self::canonicalTradingIdentity();

        $dto = MtfRunnerRequestDto::fromArray([
            'run_id' => 'run-fixture',
            'orchestration_set_id' => 'set-fixture',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'dry_run' => true,
            'trading_identity' => $tradingIdentity,
        ]);

        self::assertSame([], $dto->symbols);
        self::assertTrue($dto->lineageContext->isModern());
        self::assertNull($dto->lineageContext->symbol);
    }

    public function testRejectsForbiddenCanonicalIdentityFieldsInSortedOrder(): void
    {
        $tradingIdentity = self::canonicalTradingIdentity();
        $tradingIdentity['symbol'] = 'sensitive-symbol';
        $tradingIdentity['exchange'] = 'sensitive-exchange';

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_forbidden:exchange');

        MtfRunnerRequestDto::fromArray(self::canonicalRequest($tradingIdentity));
    }

    /** @param array<string,mixed> $tradingIdentity */
    #[DataProvider('forbiddenCanonicalIdentityFields')]
    public function testRejectsEveryServerOwnedCanonicalIdentityField(
        array $tradingIdentity,
        string $expectedErrorCode,
    ): void {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage($expectedErrorCode);

        MtfRunnerRequestDto::fromArray(self::canonicalRequest($tradingIdentity));
    }

    /** @return iterable<string, array{array<string,mixed>, string}> */
    public static function forbiddenCanonicalIdentityFields(): iterable
    {
        foreach (['orchestration_run_id', 'set_id', 'exchange', 'symbol'] as $field) {
            $tradingIdentity = self::canonicalTradingIdentity();
            $tradingIdentity[$field] = 'sensitive-' . $field;

            yield $field => [$tradingIdentity, 'canonical_identity_forbidden:' . $field];
        }
    }

    #[DataProvider('malformedTradingIdentityInputs')]
    public function testRejectsEveryExplicitMalformedTradingIdentity(
        mixed $tradingIdentity,
        string $expectedErrorCode,
    ): void {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage($expectedErrorCode);

        MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'trading_identity' => $tradingIdentity,
        ]);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function malformedTradingIdentityInputs(): iterable
    {
        yield 'null' => [null, 'canonical_identity_invalid:trading_identity'];
        yield 'string' => ['sensitive-non-array-identity', 'canonical_identity_invalid:trading_identity'];
        yield 'empty object' => [[], 'canonical_identity_invalid:trading_identity'];
        yield 'non-canonical allowed field only' => [
            ['requested_mode_id' => 'scalping'],
            'canonical_identity_invalid:trading_identity',
        ];
        yield 'mode only' => [
            ['mode_id' => 'scalping'],
            'canonical_identity_missing:setup_id',
        ];
        yield 'setup only' => [
            ['setup_id' => 'scalping.pullback.long'],
            'canonical_identity_missing:mode_id',
        ];
    }

    public function testRejectsMalformedTradingIdentityEvenWhenLineageContextIsAlsoPresent(): void
    {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_invalid:trading_identity');

        MtfRunnerRequestDto::fromArray([
            'lineage_context' => ['origin' => 'legacy', 'contract_kind' => 'legacy'],
            'trading_identity' => [],
        ]);
    }

    #[DataProvider('malformedLineageContextInputs')]
    public function testRejectsEveryExplicitMalformedLineageContext(mixed $lineageContext): void
    {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_invalid:lineage_context');

        MtfRunnerRequestDto::fromArray(['lineage_context' => $lineageContext]);
    }

    /** @return iterable<string,array{mixed}> */
    public static function malformedLineageContextInputs(): iterable
    {
        yield 'null' => [null];
        yield 'string' => ['truncated'];
        yield 'empty' => [[]];
        yield 'truncated object' => [['truncated' => true]];
    }

    public function testNormalizesModernTopLevelRuntimeFieldsFromExplicitEnvelope(): void
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        unset($data['symbol']);
        $data['dry_run'] = true;

        $dto = MtfRunnerRequestDto::fromArray(['lineage_context' => $data]);

        self::assertTrue($dto->dryRun);
        self::assertSame(Exchange::FAKE, $dto->exchange);
        self::assertSame(MarketType::PERPETUAL, $dto->marketType);
        self::assertSame('scalping', $dto->profile);
        self::assertSame('run-fixture', $dto->originalRunId);
        self::assertSame('run-fixture', $dto->correlationRunId);
        self::assertSame('set-fixture', $dto->setId);
    }

    public function testRejectsTopLevelRuntimeFieldsThatConflictWithExplicitEnvelope(): void
    {
        $data = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();
        unset($data['symbol']);
        $data['dry_run'] = false;

        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_mismatch:dry_run');

        MtfRunnerRequestDto::fromArray([
            'dry_run' => true,
            'exchange' => 'bitmart',
            'market_type' => 'spot',
            'mtf_profile' => 'regular',
            'lineage_context' => $data,
        ]);
    }

    public function testFullyValidatesPartialCanonicalIdentityBeforeLegacyLineageContext(): void
    {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_missing:mode_version');

        MtfRunnerRequestDto::fromArray([
            'symbols' => ['BTCUSDT'],
            'run_id' => 'run-fixture',
            'orchestration_set_id' => 'set-fixture',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'lineage_context' => ['origin' => 'legacy', 'contract_kind' => 'legacy'],
            'trading_identity' => [
                'mode_id' => 'scalping',
                'setup_id' => 'scalping.pullback.long',
            ],
        ]);
    }

    public function testKeepsCanonicalRequestIdentityUnboundAfterValidatingSymbols(): void
    {
        $dto = MtfRunnerRequestDto::fromArray(self::canonicalRequest(
            self::canonicalTradingIdentity(),
            ['  btcusdt  '],
        ));

        self::assertNull($dto->lineageContext->symbol);
    }

    public function testNormalizesBlankCanonicalBindingSymbolToNull(): void
    {
        $dto = MtfRunnerRequestDto::fromArray(self::canonicalRequest(
            self::canonicalTradingIdentity(),
            ['   '],
        ));

        self::assertNull($dto->lineageContext->symbol);
    }

    public function testRejectsMalformedCanonicalBindingSymbolAfterNormalization(): void
    {
        $this->expectException(LineageContextException::class);
        $this->expectExceptionMessage('canonical_identity_invalid:symbol');

        MtfRunnerRequestDto::fromArray(self::canonicalRequest(
            self::canonicalTradingIdentity(),
            ['  btc/usdt  '],
        ));
    }
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

    /** @return array<string,mixed> */
    private static function canonicalTradingIdentity(): array
    {
        $identity = CanonicalSnapshotFixture::lineage(CanonicalSnapshotFixture::config())->toArray();

        return array_intersect_key($identity, array_flip([
            'mode_id',
            'mode_version',
            'setup_id',
            'setup_version',
            'config_hash',
            'condition_catalog_hash',
            'side',
            'effective_config_reference',
            'effective_config_snapshot',
        ]));
    }

    /**
     * @param array<string,mixed> $tradingIdentity
     * @param string[] $symbols
     * @return array<string,mixed>
     */
    private static function canonicalRequest(array $tradingIdentity, array $symbols = ['BTCUSDT']): array
    {
        return [
            'symbols' => $symbols,
            'run_id' => 'run-fixture',
            'orchestration_set_id' => 'set-fixture',
            'exchange' => 'fake',
            'market_type' => 'perpetual',
            'dry_run' => true,
            'trading_identity' => $tradingIdentity,
        ];
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
