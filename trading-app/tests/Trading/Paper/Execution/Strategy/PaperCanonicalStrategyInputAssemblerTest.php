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
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use App\TradingCore\Shadow\ShadowRuntimeRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalStrategyInputAssembler::class)]
final class PaperCanonicalStrategyInputAssemblerTest extends TestCase
{
    public function testAssemblesTheExactCanonicalInputsForTheModernCell(): void
    {
        [$cell, $evidence] = $this->fixture();
        $input = (new PaperCanonicalStrategyInputAssembler($this->provider($evidence)))
            ->assemble($cell, $this->event());

        self::assertNotNull($input);
        self::assertSame('15m', $input->executionTimeframe);
        self::assertSame($evidence->configRequest, $input->request->configRequest);
        self::assertSame($evidence->lineage, $input->request->lineage);
        self::assertSame($evidence->indicatorsByTimeframe, $input->request->indicatorsByTimeframe);
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
            public function evidenceFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PaperCanonicalStrategyEvidence
            {
                return null;
            }
        };

        self::assertNull((new PaperCanonicalStrategyInputAssembler($provider))->assemble($cell, $this->event()));
    }

    public function testRejectsDecisionAndTriggerDriftBeforeTheCanonicalRuntime(): void
    {
        [$cell, $evidence] = $this->fixture();
        $drifted = new PaperCanonicalStrategyEvidence(
            $evidence->configRequest,
            $evidence->lineage,
            $evidence->indicatorsByTimeframe,
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
                ->assemble($cell, $this->event());
            self::fail('Decision drift was accepted.');
        } catch (\LogicException $exception) {
            self::assertSame('paper_canonical_strategy_input_identity_mismatch', $exception->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($evidence)))
            ->assemble($cell, $this->event(PaperMarketDataChannel::CANDLE_1M));
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
            $assembler->assemble($legacy, $this->event());
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
        ));
    }

    public function testRejectsEvidenceFromADifferentExecutionCandle(): void
    {
        [$cell, $evidence] = $this->fixture();
        $indicators = $evidence->indicatorsByTimeframe;
        $indicators['15m']['kline_time'] = '2026-08-10T11:30:00Z';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($this->withIndicators($evidence, $indicators))))
            ->assemble($cell, $this->event());
    }

    public function testRejectsExecutionSnapshotWithDifferentSourceIdentity(): void
    {
        [$cell, $evidence] = $this->fixture();
        $indicators = $evidence->indicatorsByTimeframe;
        $indicators['15m']['snapshot_identity']['exchange'] = 'okx';

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_trigger_mismatch');
        (new PaperCanonicalStrategyInputAssembler($this->provider($this->withIndicators($evidence, $indicators))))
            ->assemble($cell, $this->event());
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
            [
                '15m' => [
                    'snapshot_identity' => [
                        'timeframe' => '15m',
                        'symbol' => 'BTCUSDT',
                        'exchange' => 'hyperliquid',
                        'environment' => 'testnet',
                        'market_type' => 'perpetual',
                    ],
                    'kline_time' => '2026-08-10T11:45:00Z',
                ],
            ],
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

    /** @param array<string, array<string, mixed>> $indicators */
    private function withIndicators(
        PaperCanonicalStrategyEvidence $evidence,
        array $indicators,
    ): PaperCanonicalStrategyEvidence {
        return new PaperCanonicalStrategyEvidence(
            $evidence->configRequest,
            $evidence->lineage,
            $indicators,
            $evidence->orderPlanRequest,
            $evidence->portfolioScope,
            $evidence->portfolioSnapshot,
            $evidence->decisionKey,
            $evidence->liveSpreadBps,
            $evidence->estimatedSlippageBps,
            $evidence->orderBook,
        );
    }

    private function provider(PaperCanonicalStrategyEvidence $evidence): PaperCanonicalStrategyEvidenceProviderInterface
    {
        return new class($evidence) implements PaperCanonicalStrategyEvidenceProviderInterface {
            public function __construct(private readonly PaperCanonicalStrategyEvidence $evidence)
            {
            }

            public function evidenceFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PaperCanonicalStrategyEvidence
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
