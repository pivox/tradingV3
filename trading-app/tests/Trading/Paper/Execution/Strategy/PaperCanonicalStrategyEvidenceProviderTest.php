<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceInputs;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceProvider;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceSourceInterface;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalStrategyEvidenceUnavailable;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanBuildRequest;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCanonicalStrategyEvidenceProvider::class)]
final class PaperCanonicalStrategyEvidenceProviderTest extends TestCase
{
    private const DATASET = 'paper-modern-dataset';
    private const CHECKSUM = '4444444444444444444444444444444444444444444444444444444444444444';
    private const BUILD = 'paper-dataset-recorder.v2';

    public function testComposesExactConfigLineageDatasetAndRuntimeEvidence(): void
    {
        [$cell, $config] = $this->cell();
        $collector = new RecordingCanonicalStrategyEvidenceSource($cell, $config);
        $provider = new PaperCanonicalStrategyEvidenceProvider(new EffectiveTradingConfigResolver(), $collector);

        $evidence = $provider->evidenceFor($cell, $this->event(), self::DATASET, self::CHECKSUM, self::BUILD);

        self::assertNotNull($evidence);
        self::assertSame($config->toArray(), $evidence->configRequest->toArray());
        self::assertSame(self::DATASET, $evidence->sourceDatasetId);
        self::assertSame(self::CHECKSUM, $evidence->sourceEventsFileSha256);
        self::assertSame(self::BUILD, $evidence->sourceBuildVersion);
        self::assertSame($cell->id, $collector->cell?->id);
        self::assertSame(self::BUILD, $collector->sourceBuildVersion);
        self::assertSame(self::CHECKSUM, $collector->sourceEventsFileSha256);
        self::assertSame(['1m', '5m', '15m', '1h', '4h'], $collector->requestedTimeframes);
        self::assertMatchesRegularExpression('/\Apaper-indicators:[a-f0-9]{64}\z/D', (string) $collector->requestId);
        self::assertMatchesRegularExpression('/\Apaper:[a-f0-9]{64}\z/D', $evidence->decisionKey);
        self::assertSame($evidence->decisionKey, $evidence->lineage->decisionKey);
        self::assertSame($cell->runId, $evidence->lineage->orchestrationRunId);
        self::assertSame('okx', $evidence->lineage->exchange);
        self::assertSame('mainnet', $evidence->lineage->environment);
        self::assertTrue($evidence->lineage->dryRun);
        self::assertSame($collector->inputs?->orderBook, $evidence->orderBook);
        self::assertSame($collector->inputs?->portfolioSnapshot, $evidence->portfolioSnapshot);
        self::assertSame($evidence->orderBook?->spreadBps, $evidence->liveSpreadBps);
        self::assertSame(1.0, $evidence->estimatedSlippageBps);
        $evidence->lineage->assertCanonicalIntegrity()->assertExecutableTradeContract();
    }

    public function testMissingSourceEvidenceReturnsNoEvidenceWithoutDefaults(): void
    {
        [$cell] = $this->cell();
        $source = new class implements PaperCanonicalStrategyEvidenceSourceInterface {
            public function collect(
                PaperExecutionCell $cell,
                PaperMarketEvent $event,
                EffectiveTradingConfigSnapshot $config,
                array $requestedTimeframes,
                string $sourceBuildVersion,
                string $sourceEventsFileSha256,
                string $requestId,
            ): ?PaperCanonicalStrategyEvidenceInputs {
                return null;
            }
        };

        self::assertNull((new PaperCanonicalStrategyEvidenceProvider(
            new EffectiveTradingConfigResolver(),
            $source,
        ))->evidenceFor($cell, $this->event(), self::DATASET, self::CHECKSUM, self::BUILD));
    }

    public function testNonExecutionEventReturnsMissingIndicatorEvidenceWithoutCollectingWindows(): void
    {
        [$cell, $config] = $this->cell();
        $collector = new RecordingCanonicalStrategyEvidenceSource($cell, $config);
        $event = PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::PUBLIC_TRADE,
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            new \DateTimeImmutable('2026-08-10T12:00:00.100000Z'),
            '2',
            ['origin' => 'ws_trades'],
        );

        try {
            (new PaperCanonicalStrategyEvidenceProvider(new EffectiveTradingConfigResolver(), $collector))
                ->evidenceFor($cell, $event, self::DATASET, self::CHECKSUM, self::BUILD);
            self::fail('A public trade must not build indicator windows for a 15m execution setup.');
        } catch (PaperCanonicalStrategyEvidenceUnavailable $exception) {
            self::assertSame('paper_indicator_projection_unavailable', $exception->reasonCode);
        }
        self::assertNull($collector->cell);
    }

    public function testRejectsDurableConfigHashDriftBeforeReadingSources(): void
    {
        [$cell, $config] = $this->cell();
        $drifted = PaperExecutionCell::createModern(
            $cell->network,
            $cell->marketDataVenue,
            $cell->configurationSnapshotId,
            PaperModernStrategyIdentity::fromDurableIdentity(
                $cell->network,
                $cell->marketDataVenue,
                'day_trading',
                '1.1.0',
                'day_trading.trend_continuation.long',
                '1.1.0',
                'long',
                'sha256:' . str_repeat('f', 64),
                (string) (new EffectiveTradingConfigResolver())->resolve($config)->conditionCatalogHash,
            ),
            $cell->runId,
        );
        $collector = new RecordingCanonicalStrategyEvidenceSource($cell, $config);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_canonical_strategy_config_identity_mismatch');
        (new PaperCanonicalStrategyEvidenceProvider(new EffectiveTradingConfigResolver(), $collector))
            ->evidenceFor($drifted, $this->event(), self::DATASET, self::CHECKSUM, self::BUILD);
    }

    /** @return array{PaperExecutionCell, EffectiveTradingConfigRequest} */
    private function cell(): array
    {
        $request = new EffectiveTradingConfigRequest(
            'day_trading',
            '1.1.0',
            'day_trading.trend_continuation.long',
            '1.1.0',
            'okx',
            'mainnet',
            'long',
            ShadowExecutionCapability::Paper,
        );
        $snapshot = (new EffectiveTradingConfigResolver())->resolve($request);

        return [PaperExecutionCell::createModern(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'sha256:' . str_repeat('d', 64),
            PaperModernStrategyIdentity::fromResolvedSnapshot(
                PaperMarketDataNetwork::MAINNET,
                PaperMarketDataVenue::OKX,
                $snapshot,
            ),
            'paper-evidence-provider-run',
        ), $request];
    }

    private function event(): PaperMarketEvent
    {
        return PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::CANDLE_15M,
            new \DateTimeImmutable('2026-08-10T11:59:59Z'),
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            '1',
            ['confirmed' => true, 'bar' => '15m'],
        );
    }
}

final class RecordingCanonicalStrategyEvidenceSource implements PaperCanonicalStrategyEvidenceSourceInterface
{
    public ?PaperExecutionCell $cell = null;
    /** @var list<string> */ public array $requestedTimeframes = [];
    public ?string $sourceBuildVersion = null;
    public ?string $sourceEventsFileSha256 = null;
    public ?string $requestId = null;
    public ?PaperCanonicalStrategyEvidenceInputs $inputs = null;

    public function __construct(
        private readonly PaperExecutionCell $expectedCell,
        private readonly EffectiveTradingConfigRequest $expectedRequest,
    ) {
    }

    public function collect(
        PaperExecutionCell $cell,
        PaperMarketEvent $event,
        EffectiveTradingConfigSnapshot $config,
        array $requestedTimeframes,
        string $sourceBuildVersion,
        string $sourceEventsFileSha256,
        string $requestId,
    ): ?PaperCanonicalStrategyEvidenceInputs {
        Assert::assertSame($this->expectedCell->id, $cell->id);
        Assert::assertSame($this->expectedRequest->toArray(), $config->request->toArray());
        $this->cell = $cell;
        $this->requestedTimeframes = $requestedTimeframes;
        $this->sourceBuildVersion = $sourceBuildVersion;
        $this->sourceEventsFileSha256 = $sourceEventsFileSha256;
        $this->requestId = $requestId;
        $policy = (new CanonicalExecutionPolicyCompiler())->compile($config);
        $fixture = CanonicalOrderPlanPipelineFixture::accepted(
            executionPolicy: $policy,
            exchange: 'okx',
            environment: 'mainnet',
        );
        $portfolio = $this->portfolio($cell, $policy->riskPolicy->modeId);
        $book = $this->book();
        $projection = $this->projection($requestedTimeframes, $event->symbol);
        $this->inputs = new PaperCanonicalStrategyEvidenceInputs(
            $projection,
            new CanonicalOrderPlanBuildRequest(
                $fixture['policy'],
                $fixture['zoneRequest'],
                $fixture['zone'],
                $fixture['protectionRequest'],
                $fixture['protection'],
                $fixture['riskRequest'],
                $fixture['risk'],
                $fixture['netR'],
                $fixture['costs'],
                $book,
            ),
            $portfolio,
            $book,
        );

        return $this->inputs;
    }

    /** @param list<string> $timeframes */
    private function projection(array $timeframes, string $symbol): CanonicalIndicatorProjection
    {
        $snapshots = [];
        $candles = [];
        foreach ($timeframes as $timeframe) {
            $snapshots[$timeframe] = ['vwap' => 100.0, 'atr' => 1.0];
            $candles[$timeframe] = [];
        }

        return CanonicalIndicatorProjection::fromValidatedRequest([
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'paper-provider-fixture',
            'evaluated_at' => '2026-08-10T12:00:00.000000Z',
            'environment' => 'test',
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => [],
            'symbol' => $symbol,
            'requested_timeframes' => $timeframes,
            'candles_by_timeframe' => $candles,
        ], $snapshots);
    }

    private function book(): CanonicalOrderBookSnapshot
    {
        $bid = 100.1;
        $ask = 100.2;

        return new CanonicalOrderBookSnapshot(
            'okx', 'mainnet', 'BTCUSDT', 'perpetual', 'order_book',
            $bid, $ask, 10_000.0 * ($ask - $bid) / (($ask + $bid) / 2.0),
            new \DateTimeImmutable('2026-08-10T11:59:50Z'),
            'sha256:' . str_repeat('3', 64),
        );
    }

    private function portfolio(PaperExecutionCell $cell, string $modeId): CanonicalPortfolioSnapshot
    {
        $scope = new CanonicalPortfolioScope('mainnet', 'okx', 'mainnet', $cell->accountNamespace, $modeId, 'USDT');

        return new CanonicalPortfolioSnapshot(
            $scope,
            'paper_canonical_fake_private_portfolio',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00Z'),
            new \DateTimeImmutable('2026-08-11T00:00:00Z'),
            new \DateTimeImmutable('2026-08-10T12:00:00Z'),
            1000.0, 0.0, 0.0, 0, 0, 0.0, 0.0, 0.0, [], 1,
            'sha256:' . str_repeat('8', 64),
        );
    }
}
