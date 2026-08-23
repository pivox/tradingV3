<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyFixture;
use App\Tests\TradingCore\OrderPlan\Canonical\CanonicalOrderPlanPipelineFixture;
use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalInstrumentEvidence;
use App\Trading\Paper\Execution\Strategy\PaperCanonicalOrderPlanEvidenceSource;
use App\TradingCore\Backtesting\Indicator\CanonicalIndicatorProjection;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicy;
use App\TradingCore\OrderPlan\Canonical\CanonicalExecutionPolicyCompiler;
use App\TradingCore\OrderPlan\Canonical\CanonicalOrderBookSnapshot;
use App\TradingCore\OrderPlan\Canonical\CanonicalTickSnapshot;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioScope;
use App\TradingCore\Risk\Canonical\Portfolio\CanonicalPortfolioSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(PaperCanonicalOrderPlanEvidenceSource::class)]
final class PaperCanonicalOrderPlanEvidenceSourceTest extends TestCase
{
    public function testBuildsTheCanonicalPipelineFromExactEvidence(): void
    {
        $fixture = CanonicalOrderPlanPipelineFixture::accepted();
        $bid = 100.1;
        $ask = $bid * 20001.0 / 19999.0;
        $book = new CanonicalOrderBookSnapshot(
            'fake',
            'test',
            'BTCUSDT',
            'perpetual',
            'order_book',
            $bid,
            $ask,
            10_000.0 * ($ask - $bid) / (($ask + $bid) / 2.0),
            new \DateTimeImmutable('2026-08-10T11:59:30Z'),
            'sha256:' . str_repeat('3', 64),
        );

        $request = (new PaperCanonicalOrderPlanEvidenceSource(new MockClock('2026-08-10T12:00:00Z')))->build(
            $fixture['policy'],
            $this->projection(),
            $this->instrument($fixture),
            $book,
            $fixture['costs'],
            $this->portfolio($fixture['policy']->riskPolicy->modeId),
        );

        self::assertNotNull($request);
        self::assertSame($bid, $request->zone->entryPrice);
        self::assertSame('vwap', $request->zone->anchorSource);
        self::assertSame(1000.0, $request->riskRequest->equityQuote);
        self::assertSame(1000.0, $request->riskRequest->availableBalanceQuote);
        self::assertSame($fixture['riskRequest']->instrument, $request->riskRequest->instrument);
        self::assertSame($book, $request->orderBook);
        self::assertSame($fixture['costs'], $request->costs);
        self::assertNotEmpty($request->netR->targets);
    }

    public function testReturnsNoPlanWhenTheMakerPriceIsOutsideTheConfiguredZone(): void
    {
        $fixture = CanonicalOrderPlanPipelineFixture::accepted();
        $bid = 120.0;
        $ask = 120.1;
        $book = new CanonicalOrderBookSnapshot(
            'fake', 'test', 'BTCUSDT', 'perpetual', 'order_book',
            $bid, $ask, 10_000.0 * ($ask - $bid) / (($ask + $bid) / 2.0),
            new \DateTimeImmutable('2026-08-10T11:59:30Z'),
            'sha256:' . str_repeat('3', 64),
        );

        self::assertNull((new PaperCanonicalOrderPlanEvidenceSource(new MockClock('2026-08-10T12:00:00Z')))->build(
            $fixture['policy'],
            $this->projection(),
            $this->instrument($fixture),
            $book,
            $fixture['costs'],
            $this->portfolio($fixture['policy']->riskPolicy->modeId),
        ));
    }

    public function testHyperliquidPlanFailsClosedWhenATargetCrossesAPriceMagnitudeBoundary(): void
    {
        $policy = $this->hyperliquidPolicy();
        $fixture = CanonicalOrderPlanPipelineFixture::accepted(
            executionPolicy: $policy,
            exchange: 'hyperliquid',
            environment: 'mainnet',
        );
        $bid = 9_999.8;
        $ask = 9_999.9;
        $book = new CanonicalOrderBookSnapshot(
            'hyperliquid', 'mainnet', 'BTCUSDT', 'perpetual', 'order_book',
            $bid, $ask, 10_000.0 * ($ask - $bid) / (($ask + $bid) / 2.0),
            new \DateTimeImmutable('2026-08-10T11:59:30Z'),
            'sha256:' . str_repeat('3', 64),
            1,
        );

        self::assertNull((new PaperCanonicalOrderPlanEvidenceSource(
            new MockClock('2026-08-10T12:00:00Z'),
        ))->build(
            $policy,
            $this->projection(9_999.8),
            $this->instrument($fixture),
            $book,
            $fixture['costs'],
            $this->portfolio($policy->riskPolicy->modeId, 'hyperliquid', 'mainnet'),
        ));
    }

    private function projection(float $vwap = 100.0): CanonicalIndicatorProjection
    {
        return CanonicalIndicatorProjection::fromValidatedRequest([
            'schema_version' => 'canonical-indicator-projection-request.v1',
            'request_id' => 'paper-order-plan-evidence',
            'evaluated_at' => '2026-08-10T11:59:30.000000Z',
            'environment' => 'test',
            'indicator_engine_version' => 'php_fallback_v1',
            'dataset_binding' => [],
            'symbol' => 'BTCUSDT',
            'requested_timeframes' => ['5m'],
            'candles_by_timeframe' => ['5m' => []],
        ], ['5m' => ['vwap' => $vwap, 'atr' => 1.0]]);
    }

    /** @param array<string, mixed> $fixture */
    private function instrument(array $fixture): PaperCanonicalInstrumentEvidence
    {
        $instrument = $fixture['riskRequest']->instrument;

        return new PaperCanonicalInstrumentEvidence($instrument, new CanonicalTickSnapshot(
            $instrument->exchange,
            $instrument->environment,
            $instrument->symbol,
            $instrument->marketType,
            $fixture['zoneRequest']->tick->tickSize,
            $instrument->observedAt,
            $instrument->inputHash,
        ));
    }

    private function portfolio(
        string $modeId,
        string $exchange = 'fake',
        string $environment = 'test',
    ): CanonicalPortfolioSnapshot
    {
        $scope = new CanonicalPortfolioScope('test', $exchange, $environment, 'paper-account', $modeId, 'USDT');

        return new CanonicalPortfolioSnapshot(
            $scope,
            'paper_canonical_fake_private_portfolio',
            '1.0.0',
            new \DateTimeImmutable('2026-08-10T00:00:00Z'),
            new \DateTimeImmutable('2026-08-11T00:00:00Z'),
            new \DateTimeImmutable('2026-08-10T11:59:30Z'),
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
    }

    private function hyperliquidPolicy(): CanonicalExecutionPolicy
    {
        $payload = CanonicalExecutionPolicyFixture::payload();
        $payload['exchange']['id'] = 'hyperliquid';
        $payload['environment']['id'] = 'mainnet';
        $catalogHash = 'sha256:' . str_repeat('b', 64);
        $snapshot = new EffectiveTradingConfigSnapshot(
            new EffectiveTradingConfigRequest(
                'day_trading',
                '1.0.0',
                'day_trading.trend_continuation.long',
                '1.0.0',
                'hyperliquid',
                'mainnet',
                'long',
            ),
            $payload,
            CanonicalEffectiveConfigSnapshot::calculateConfigHash($payload, $catalogHash),
            $catalogHash,
            [],
            [],
        );

        return (new CanonicalExecutionPolicyCompiler())->compile($snapshot);
    }
}
