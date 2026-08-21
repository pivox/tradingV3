<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use App\Trading\Lineage\LineageContext;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidence;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceProviderInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyInputAssembler;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Shadow\ShadowRuntimeRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalStrategyInputAssembler::class)]
final class PaperCanonicalStrategyInputAssemblerTest extends TestCase
{
    private const SOURCE_DATASET_ID = 'paper-modern-dataset';
    private const SOURCE_EVENTS_FILE_SHA256 = '4444444444444444444444444444444444444444444444444444444444444444';
    private const SOURCE_CHECKSUM = 'sha256:' . self::SOURCE_EVENTS_FILE_SHA256;

    public function testAssemblesTheExactCanonicalInputsForTheModernCell(): void
    {
        [$cell, $evidence] = $this->fixture();
        $input = (new PaperCanonicalStrategyInputAssembler($this->provider($evidence)))
            ->assemble($cell, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);

        self::assertNotNull($input);
        self::assertSame('15m', $input->executionTimeframe);
        self::assertSame($evidence->configRequest, $input->request->configRequest);
        self::assertSame($evidence->lineage, $input->request->lineage);
        self::assertSame(
            $evidence->indicatorProjection->toArray()['snapshots_by_timeframe'],
            $input->request->indicatorsByTimeframe,
        );
        self::assertSame($evidence->orderPlanRequest, $input->request->orderPlanRequest);
        self::assertSame($evidence->portfolioScope, $input->request->portfolioScope);
        self::assertSame($evidence->portfolioSnapshot, $input->request->portfolioSnapshot);
        self::assertSame($evidence->decisionKey, $input->request->decisionKey);
        self::assertSame($evidence->liveSpreadBps, $input->request->liveSpreadBps);
        self::assertSame($evidence->estimatedSlippageBps, $input->request->estimatedSlippageBps);
        self::assertSame($evidence->orderBook, $input->request->orderBook);
    }

    public function testMissingEvidenceMeansNoDecisionWithoutFabricatingDefaults(): void
    {
        [$cell] = $this->fixture();
        $provider = new class implements PaperCanonicalStrategyEvidenceProviderInterface {
            public function evidenceFor(
                PaperExecutionCell $cell,
                PaperMarketEvent $event,
                string $sourceDatasetId,
                string $sourceEventsFileSha256,
            ): ?PaperCanonicalStrategyEvidence
            {
                return null;
            }
        };

        self::assertNull((new PaperCanonicalStrategyInputAssembler($provider))->assemble(
            $cell,
            $this->event(),
            self::SOURCE_DATASET_ID,
            self::SOURCE_EVENTS_FILE_SHA256,
        ));
    }

    public function testRejectsDecisionAndTriggerDriftBeforeTheCanonicalRuntime(): void
    {
        [$cell, $evidence] = $this->fixture();
        $drifted = new PaperCanonicalStrategyEvidence(
            $evidence->configRequest,
            $evidence->lineage,
            $evidence->indicatorProjection,
            $evidence->sourceDatasetId,
            $evidence->sourceEventsFileSha256,
            $evidence->orderPlanRequest,
            $evidence->portfolioScope,
            $evidence->portfolioSnapshot,
            'different-decision',
            $evidence->liveSpreadBps,
            $evidence->estimatedSlippageBps,
            $evidence->orderBook,
        );

        try {
            (new PaperCanonicalStrategyInputAssembler($this->provider($drifted)))
                ->assemble($cell, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
            self::fail('Decision drift was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_strategy_input_identity_mismatch', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($evidence)))
            ->assemble(
                $cell,
                $this->event(PaperMarketDataChannel::CANDLE_1M),
                self::SOURCE_DATASET_ID,
                self::SOURCE_EVENTS_FILE_SHA256,
            );
    }

    public function testRejectsLegacyCellsAndModernMarketScopeDrift(): void
    {
        [, $evidence] = $this->fixture();
        $assembler = new PaperCanonicalStrategyInputAssembler($this->provider($evidence));
        $legacy = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('d', 64),
            'scalper_micro',
            'legacy-run',
        );

        try {
            $assembler->assemble($legacy, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
            self::fail('Legacy cell was accepted by the canonical assembler.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_strategy_cell_identity_missing', $exception->getMessage());
        }

        [$cell] = $this->fixture();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_market_scope_mismatch');
        $assembler->assemble($cell, PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_15M,
            new \DateTimeImmutable('2026-08-10T11:59:59Z'),
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            '1',
            $this->payload('15m'),
        ), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
    }

    public function testRejectsEvidenceFromADifferentExecutionCandle(): void
    {
        [$cell, $evidence] = $this->fixture();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($this->withProjection(
            $evidence,
            $this->projection(klineTime: '2026-08-10T11:30:00Z'),
        ))))
            ->assemble($cell, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
    }

    public function testRejectsExecutionSnapshotWithDifferentSourceIdentity(): void
    {
        [$cell, $evidence] = $this->fixture();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($this->withProjection(
            $evidence,
            $this->projection(snapshotExchange: 'okx'),
        ))))
            ->assemble($cell, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
    }

    public function testRejectsIndicatorProjectionDatasetSourceDrift(): void
    {
        [$cell, $evidence] = $this->fixture();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($this->withProjection(
            $evidence,
            $this->projection(sourceVenue: 'okx'),
        ))))
            ->assemble($cell, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
    }

    public function testRejectsIndicatorProjectionFromADifferentReplayChecksum(): void
    {
        [$cell, $evidence] = $this->fixture();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_dataset_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($this->withProjection(
            $evidence,
            $this->projection(sourceChecksum: 'sha256:' . str_repeat('5', 64)),
        ))))
            ->assemble(
                $cell,
                $this->event(),
                self::SOURCE_DATASET_ID,
                self::SOURCE_EVENTS_FILE_SHA256,
            );
    }

    public function testRejectsEvidenceSelectedForADifferentReplayDatasetId(): void
    {
        [$cell, $evidence] = $this->fixture();
        $foreign = new PaperCanonicalStrategyEvidence(
            $evidence->configRequest,
            $evidence->lineage,
            $evidence->indicatorProjection,
            'paper-foreign-dataset',
            $evidence->sourceEventsFileSha256,
            $evidence->orderPlanRequest,
            $evidence->portfolioScope,
            $evidence->portfolioSnapshot,
            $evidence->decisionKey,
            $evidence->liveSpreadBps,
            $evidence->estimatedSlippageBps,
            $evidence->orderBook,
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_dataset_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($foreign)))
            ->assemble($cell, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
    }

    #[DataProvider('nonCanonicalSnapshotTimestamps')]
    public function testRejectsNonCanonicalExecutionSnapshotTimestamp(string $timestamp): void
    {
        [$cell, $evidence] = $this->fixture();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($this->withProjection(
            $evidence,
            $this->projection(klineTime: $timestamp),
        ))))
            ->assemble($cell, $this->event(), self::SOURCE_DATASET_ID, self::SOURCE_EVENTS_FILE_SHA256);
    }

    /** @return iterable<string, array{string}> */
    public static function nonCanonicalSnapshotTimestamps(): iterable
    {
        yield 'relative epoch syntax' => ['@1786362300'];
        yield 'overflowing seconds normalized by PHP' => ['2026-08-10T11:44:60Z'];
    }

    /** @return array{PaperExecutionCell, PaperCanonicalStrategyEvidence} */
    private function fixture(): array
    {
        $config = new EffectiveTradingConfigRequest(
            'day_trading',
            '1.1.0',
            'day_trading.trend_continuation.long',
            '1.1.0',
            'hyperliquid',
            'testnet',
            'long',
            ShadowExecutionCapability::Paper,
        );
        $snapshot = (new EffectiveTradingConfigResolver())->resolve($config);
        $cell = PaperExecutionCell::createModern(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('d', 64),
            PaperModernStrategyIdentity::fromResolvedSnapshot(
                PaperMarketDataNetwork::TESTNET,
                PaperMarketDataVenue::HYPERLIQUID,
                $snapshot,
            ),
            'paper-modern-run',
        );
        $snapshotData = $snapshot->toArray();
        $decisionKey = 'paper-modern-decision';
        $lineage = LineageContext::fromOrchestratorPayload([
            'origin' => 'orchestrator',
            'orchestration_run_id' => $cell->runId,
            'correlation_run_id' => $cell->runId,
            'orchestration_set_id' => 'paper-modern-set',
            'mode_id' => $config->modeId,
            'mode_version' => $config->modeVersion,
            'setup_id' => $config->setupId,
            'setup_version' => $config->setupVersion,
            'config_hash' => $snapshot->configHash,
            'condition_catalog_hash' => $snapshot->conditionCatalogHash,
            'side' => 'LONG',
            'exchange' => $config->exchange,
            'environment' => $config->environment,
            'market_type' => 'perpetual',
            'symbol' => 'BTCUSDT',
            'decision_key' => $decisionKey,
            'dry_run' => true,
            'effective_config_reference' => 'effective-config-snapshot:' . $snapshotData['snapshot_hash'],
            'effective_config_snapshot' => $snapshotData,
        ]);
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
        $scope = new CanonicalPortfolioScope(
            'testnet',
            'hyperliquid',
            'testnet',
            $cell->accountNamespace,
            'day_trading',
            'USDT',
        );
        $portfolio = new CanonicalPortfolioSnapshot(
            $scope,
            'paper_fake_state',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00Z'),
            new \DateTimeImmutable('2026-08-11T00:00:00Z'),
            new \DateTimeImmutable('2026-08-10T11:59:50Z'),
            1000.0,
            0.0,
            0.0,
            0,
            0,
            0.0,
            0.0,
            0.0,
            [],
            1,
            'sha256:' . str_repeat('8', 64),
        );

        return [$cell, new PaperCanonicalStrategyEvidence(
            $config,
            $lineage,
            $this->projection(),
            self::SOURCE_DATASET_ID,
            self::SOURCE_EVENTS_FILE_SHA256,
            new CanonicalOrderPlanBuildRequest(...CanonicalOrderPlanPipelineFixture::accepted(
                executionPolicy: $policy,
                exchange: 'hyperliquid',
                environment: 'testnet',
            )),
            $scope,
            $portfolio,
            $decisionKey,
            1.0,
            1.0,
        )];
    }

    private function withProjection(
        PaperCanonicalStrategyEvidence $evidence,
        CanonicalIndicatorProjection $projection,
    ): PaperCanonicalStrategyEvidence {
        return new PaperCanonicalStrategyEvidence(
            $evidence->configRequest,
            $evidence->lineage,
            $projection,
            $evidence->sourceDatasetId,
            $evidence->sourceEventsFileSha256,
            $evidence->orderPlanRequest,
            $evidence->portfolioScope,
            $evidence->portfolioSnapshot,
            $evidence->decisionKey,
            $evidence->liveSpreadBps,
            $evidence->estimatedSlippageBps,
            $evidence->orderBook,
        );
    }

    private function projection(
        string $klineTime = '2026-08-10T11:45:00Z',
        string $snapshotExchange = 'fake',
        string $snapshotEnvironment = 'test',
        string $sourceNetwork = 'testnet',
        string $sourceVenue = 'hyperliquid',
        string $sourceChecksum = self::SOURCE_CHECKSUM,
    ): CanonicalIndicatorProjection {
        $datasetChecksum = 'sha256:' . str_repeat('1', 64);

        return CanonicalIndicatorProjection::fromValidatedRequest([
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'paper-modern-indicators',
            'evaluated_at' => '2026-08-10T12:00:00.000000Z',
            'environment' => $snapshotEnvironment,
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => [
                'dataset_id' => 'backtest-dataset-' . substr($datasetChecksum, 7),
                'dataset_checksum' => $datasetChecksum,
                'candles_checksum' => 'sha256:' . str_repeat('2', 64),
                'quality_report_checksum' => 'sha256:' . str_repeat('3', 64),
                'source_checksum' => $sourceChecksum,
                'source_network' => $sourceNetwork,
                'market_data_venue' => $sourceVenue,
                'market_type' => 'perpetual',
            ],
            'symbol' => 'BTCUSDT',
            'requested_timeframes' => ['15m'],
            'candles_by_timeframe' => ['15m' => []],
        ], [
            '15m' => [
                'snapshot_identity' => [
                    'timeframe' => '15m',
                    'symbol' => 'BTCUSDT',
                    'exchange' => $snapshotExchange,
                    'environment' => $snapshotEnvironment,
                    'market_type' => 'perpetual',
                ],
                'kline_time' => $klineTime,
            ],
        ]);
    }

    private function provider(PaperCanonicalStrategyEvidence $evidence): PaperCanonicalStrategyEvidenceProviderInterface
    {
        return new class($evidence) implements PaperCanonicalStrategyEvidenceProviderInterface {
            public function __construct(private readonly PaperCanonicalStrategyEvidence $evidence)
            {
            }

            public function evidenceFor(
                PaperExecutionCell $cell,
                PaperMarketEvent $event,
                string $sourceDatasetId,
                string $sourceEventsFileSha256,
            ): ?PaperCanonicalStrategyEvidence
            {
                return $this->evidence;
            }
        };
    }

    private function event(PaperMarketDataChannel $channel = PaperMarketDataChannel::CANDLE_15M): PaperMarketEvent
    {
        $timeframe = match ($channel) {
            PaperMarketDataChannel::CANDLE_1M => '1m',
            PaperMarketDataChannel::CANDLE_15M => '15m',
            default => throw new \InvalidArgumentException('Unsupported test channel.'),
        };

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            $channel,
            new \DateTimeImmutable('2026-08-10T11:59:59Z'),
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            '1',
            $this->payload($timeframe),
        );
    }

    /** @return array<string, bool|string> */
    private function payload(string $timeframe): array
    {
        return [
            'interval' => $timeframe,
            'start_time' => '1786362300000',
            'open' => '100',
            'high' => '101',
            'low' => '99',
            'close' => '100',
            'volume' => '5',
            'confirmed' => true,
        ];
    }
}
