<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\Indicator\Condition\OrderFlowImbalanceGteCondition;
use App\Indicator\Condition\SpreadBpsLteCondition;
use App\MtfValidator\ConditionLoader\ConditionRegistry as MtfConditionRegistry;
use App\MtfValidator\ConditionLoader\TimeframeEvaluator as MtfTimeframeEvaluator;
use App\MtfValidator\Service\Rule\TimeframeRuleEvaluator;
use App\MtfValidator\Service\Rule\YamlRuleEngine;
use App\MtfValidator\Service\TimeframeValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Live (legacy MTF) path: the 1m context is enriched from an untimestamped REST order book. That yields a spread and a
 * displayed-depth imbalance, never the canonical order flow imbalance (`aggressor_volume_ratio.v1`) nor its proof.
 * The proof-bound conditions must therefore fail closed, and the depth imbalance must not be labelled as order flow.
 */
#[CoversClass(TimeframeValidationService::class)]
final class TimeframeValidationServiceLiveMicrostructureTest extends TestCase
{
    public function testLiveRestBookIsExposedAsDepthImbalanceAndMicrostructureGuardsFailClosed(): void
    {
        $captured = null;
        $conditionEvaluator = $this->createMock(MtfTimeframeEvaluator::class);
        $conditionEvaluator->expects(self::once())->method('evaluate')
            ->willReturnCallback(static function (string $timeframe, array $context) use (&$captured): array {
                $captured = $context;

                return ['passed' => ['long' => false, 'short' => false], 'long' => ['conditions' => []], 'short' => ['conditions' => []]];
            });
        $conditionRegistry = $this->createMock(MtfConditionRegistry::class);
        $indicatorEngine = $this->createMock(IndicatorEngineInterface::class);
        $indicatorEngine->method('buildContext')->willReturn(['close' => 100.0]);
        $klineProvider = $this->createMock(KlineProviderInterface::class);
        $klineProvider->method('getKlines')->with('BTCUSDT', Timeframe::TF_1M, 251)->willReturn([[
            'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1,
            'open_time' => new \DateTimeImmutable('2026-05-31 00:00:00', new \DateTimeZone('UTC')),
        ]]);
        $contractProvider = $this->createMock(ContractProviderInterface::class);
        $contractProvider->method('getOrderBook')->with('BTCUSDT', 20)->willReturn([
            'bids' => [[99.99, 30.0], [99.98, 10.0]],
            'asks' => [[100.01, 5.0], [100.02, 5.0]],
        ]);
        $mainProvider = $this->createMock(MainProviderInterface::class);
        $mainProvider->method('getContractProvider')->willReturn($contractProvider);
        $ruleEngine = new YamlRuleEngine();

        $service = new TimeframeValidationService(
            new TimeframeRuleEvaluator($ruleEngine),
            $ruleEngine,
            null,
            $conditionEvaluator,
            $conditionRegistry,
            $indicatorEngine,
            $klineProvider,
            $mainProvider,
        );

        $service->validateTimeframe(
            symbol: 'BTCUSDT',
            timeframe: '1m',
            phase: 'execution',
            mode: 'scalper_micro',
            mtfConfig: ['rules' => [], 'validation' => ['timeframe' => ['1m' => ['long' => [], 'short' => []]]], 'filters_mandatory' => []],
            indicators: [],
        );

        self::assertIsArray($captured);
        self::assertArrayNotHasKey('order_flow_imbalance', $captured, 'a displayed-depth snapshot must not be presented as order flow');
        self::assertEqualsWithDelta((40.0 - 10.0) / 50.0, $captured['book_depth_imbalance_20'], 1e-12);
        self::assertEqualsWithDelta(2.0, $captured['spread_bps'], 1e-6);
        self::assertArrayNotHasKey('_input_proof', $captured);

        // The proof-bound conditions, given this live context, fail closed with the proof reason.
        $ofi = (new OrderFlowImbalanceGteCondition())->evaluate($captured + ['min_ofi' => 0.15]);
        $spread = (new SpreadBpsLteCondition())->evaluate($captured + ['max_spread_bps' => 8.0]);
        self::assertFalse($ofi->passed);
        self::assertFalse($spread->passed);
        self::assertSame('microstructure_proof_snapshot_missing', $ofi->meta['proof_reason'] ?? null);
        self::assertSame('microstructure_proof_snapshot_missing', $spread->meta['proof_reason'] ?? null);
    }
}
