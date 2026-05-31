<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\MtfValidator\ConditionLoader\ConditionRegistry as MtfConditionRegistry;
use App\MtfValidator\ConditionLoader\TimeframeEvaluator as MtfTimeframeEvaluator;
use App\MtfValidator\Service\MtfValidationEngineMetrics;
use App\MtfValidator\Service\Rule\TimeframeRuleEvaluator;
use App\MtfValidator\Service\Rule\YamlRuleEngine;
use App\MtfValidator\Service\TimeframeValidationService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(TimeframeValidationService::class)]
#[CoversClass(MtfValidationEngineMetrics::class)]
final class TimeframeValidationServiceFallbackTest extends TestCase
{
    public function testConditionRegistryFailureIsCountedLoggedAndExposedOnYamlFallbackDecision(): void
    {
        $ruleEngine = new YamlRuleEngine();
        $logger = new class extends AbstractLogger {
            /** @var array<int,array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
        $metrics = new MtfValidationEngineMetrics($logger, fallbackAlertThreshold: 1);

        $conditionRegistry = $this->createMock(MtfConditionRegistry::class);
        $conditionRegistry
            ->expects(self::once())
            ->method('load');

        $conditionEvaluator = $this->createMock(MtfTimeframeEvaluator::class);
        $conditionEvaluator
            ->expects(self::once())
            ->method('evaluate')
            ->with('5m', ['rsi' => 65.0, 'macd_hist' => 0.0012])
            ->willThrowException(new \RuntimeException('compiled condition drift'));

        $indicatorEngine = $this->createMock(IndicatorEngineInterface::class);
        $indicatorEngine
            ->expects(self::once())
            ->method('buildContext')
            ->willReturn(['rsi' => 65.0, 'macd_hist' => 0.0012]);

        $klineProvider = $this->createMock(KlineProviderInterface::class);
        $klineProvider
            ->expects(self::once())
            ->method('getKlines')
            ->with('BTCUSDT', Timeframe::TF_5M, 250)
            ->willReturn([['open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]]);

        $service = new TimeframeValidationService(
            new TimeframeRuleEvaluator($ruleEngine),
            $ruleEngine,
            $logger,
            $conditionEvaluator,
            $conditionRegistry,
            $indicatorEngine,
            $klineProvider,
            null,
            $metrics,
        );

        $decision = $service->validateTimeframe(
            symbol: 'BTCUSDT',
            timeframe: '5m',
            phase: 'context',
            mode: 'scalper',
            mtfConfig: $this->mtfConfig(),
            indicators: ['rsi' => 65.0, 'macd_hist' => 0.0012],
        );

        self::assertTrue($decision->valid);
        self::assertSame('long', $decision->signal);
        self::assertSame(
            1,
            $metrics->snapshot()[MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT],
        );
        self::assertSame(
            MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT,
            $decision->extra['validation_engine_fallback']['metric'] ?? null,
        );
        self::assertSame(1, $decision->extra['validation_engine_fallback']['fallback_count'] ?? null);
        self::assertSame('compiled condition drift', $decision->extra['validation_engine_fallback']['error'] ?? null);
        self::assertNotEmpty(array_filter(
            $logger->records,
            static fn (array $record): bool => $record['level'] === 'critical'
                && ($record['context']['alert'] ?? null) === MtfValidationEngineMetrics::CONDITION_REGISTRY_FALLBACK_COUNT,
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function mtfConfig(): array
    {
        return [
            'rules' => [
                'rsi_lt_70' => [
                    'field' => 'rsi',
                    'lt' => 70.0,
                ],
                'macd_hist_gt_eps' => [
                    'op' => '>',
                    'left' => 'macd_hist',
                    'right' => 0.0,
                    'eps' => 0.000001,
                ],
            ],
            'validation' => [
                'timeframe' => [
                    '5m' => [
                        'long' => [
                            [
                                'all_of' => [
                                    'rsi_lt_70',
                                    'macd_hist_gt_eps',
                                ],
                            ],
                        ],
                        'short' => [],
                    ],
                ],
            ],
            'filters_mandatory' => [],
        ];
    }
}
