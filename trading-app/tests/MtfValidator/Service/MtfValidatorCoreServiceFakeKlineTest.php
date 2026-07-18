<?php

declare(strict_types=1);

namespace App\Tests\MtfValidator\Service;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Config\MtfValidationConfigProvider;
use App\Contract\Indicator\IndicatorEngineInterface;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\MtfRunDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Runtime\AuditLoggerInterface;
use App\MtfValidator\ConditionLoader\ConditionRegistry;
use App\MtfValidator\ConditionLoader\TimeframeEvaluator;
use App\MtfValidator\Service\ContextValidationService;
use App\MtfValidator\Service\Execution\ExecutionSelectorEngineInterface;
use App\MtfValidator\Service\ExecutionSelectionService;
use App\MtfValidator\Service\MtfTimeframeResolver;
use App\MtfValidator\Service\MtfValidatorCoreService;
use App\MtfValidator\Service\Rule\TimeframeRuleEvaluator;
use App\MtfValidator\Service\Rule\YamlRuleEngine;
use App\MtfValidator\Service\TimeframeValidationService;
use App\Provider\Context\ExchangeContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(MtfValidatorCoreService::class)]
#[CoversClass(ContextValidationService::class)]
#[CoversClass(ExecutionSelectionService::class)]
#[CoversClass(TimeframeValidationService::class)]
final class MtfValidatorCoreServiceFakeKlineTest extends TestCase
{
    public function testFakeValidationUsesOnlyFakeKlinesWithTheFakeContext(): void
    {
        $fakeContext = new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL);

        $legacyKlines = $this->createMock(KlineProviderInterface::class);
        $legacyKlines->expects(self::never())->method('getKlines');

        $fakeKlines = $this->createMock(KlineProviderInterface::class);
        $fakeKlines
            ->expects(self::exactly(2))
            ->method('getKlines')
            ->with(
                'BTCUSDT',
                self::anything(),
                251,
                self::callback(static fn (?ExchangeContext $context): bool => $context?->equals($fakeContext) ?? false),
            )
            ->willReturn([]);

        $ruleEngine = new YamlRuleEngine();
        $conditionRegistry = $this->createMock(ConditionRegistry::class);
        $conditionRegistry->expects(self::exactly(2))->method('load');
        $conditionEvaluator = $this->createMock(TimeframeEvaluator::class);
        $conditionEvaluator->expects(self::never())->method('evaluate');
        $indicatorEngine = $this->createMock(IndicatorEngineInterface::class);
        $indicatorEngine->expects(self::never())->method('buildContext');

        $timeframeValidation = $this->timeframeValidationService(
            $ruleEngine,
            $conditionEvaluator,
            $conditionRegistry,
            $indicatorEngine,
            $legacyKlines,
            $fakeKlines,
        );
        $selector = $this->createMock(ExecutionSelectorEngineInterface::class);
        $selector->expects(self::never())->method('select');
        $indicatorProvider = $this->createMock(IndicatorProviderInterface::class);
        $indicatorProvider
            ->expects(self::once())
            ->method('getIndicatorsForSymbolAndTimeframes')
            ->willReturnCallback(static function (
                string $symbol,
                array $timeframes,
                \DateTimeInterface $at,
                ?ExchangeContext $context,
            ) use ($fakeContext): array {
                self::assertSame('BTCUSDT', $symbol);
                self::assertTrue($context?->equals($fakeContext) ?? false);

                return array_fill_keys($timeframes, []);
            });

        $projectDir = dirname(__DIR__, 3);
        $configProvider = new MtfValidationConfigProvider(new ParameterBag([
            'kernel.project_dir' => $projectDir,
            'mode' => [],
        ]));
        $clock = new MockClock('2026-07-18T12:00:00+00:00');

        $service = new MtfValidatorCoreService(
            $configProvider,
            $indicatorProvider,
            new ContextValidationService($timeframeValidation),
            new ExecutionSelectionService($timeframeValidation, $selector),
            $this->createMock(AuditLoggerInterface::class),
            $clock,
            new NullLogger(),
            new MtfTimeframeResolver(),
        );

        $result = $service->validate(new MtfRunDto(
            symbol: 'BTCUSDT',
            profile: 'regular',
            now: $clock->now(),
            dryRun: true,
            options: [
                'dry_run' => true,
                'exchange' => Exchange::FAKE->value,
                'market_type' => MarketType::PERPETUAL->value,
            ],
        ));

        self::assertFalse($result->isTradable);
        self::assertSame('pragmatic_context_has_invalid_timeframes', $result->finalReason);
    }

    public function testFakeExecutionSelectionUsesOnlyFakeKlinesWithTheFakeContext(): void
    {
        $fakeContext = new ExchangeContext(Exchange::FAKE, MarketType::PERPETUAL);

        $legacyKlines = $this->createMock(KlineProviderInterface::class);
        $legacyKlines->expects(self::never())->method('getKlines');

        $fakeKlines = $this->createMock(KlineProviderInterface::class);
        $fakeKlines
            ->expects(self::once())
            ->method('getKlines')
            ->with('BTCUSDT', self::anything(), 251, $fakeContext)
            ->willReturn([]);

        $ruleEngine = new YamlRuleEngine();
        $conditionRegistry = $this->createMock(ConditionRegistry::class);
        $conditionRegistry->expects(self::once())->method('load');
        $conditionEvaluator = $this->createMock(TimeframeEvaluator::class);
        $conditionEvaluator->expects(self::never())->method('evaluate');
        $indicatorEngine = $this->createMock(IndicatorEngineInterface::class);
        $indicatorEngine->expects(self::never())->method('buildContext');
        $selector = $this->createMock(ExecutionSelectorEngineInterface::class);
        $selector
            ->expects(self::once())
            ->method('select')
            ->with(self::callback(static function (array $decisions): bool {
                self::assertSame('NO_KLINES', $decisions['1m']->invalidReason ?? null);

                return true;
            }), [])
            ->willReturn(null);

        $service = new ExecutionSelectionService(
            $this->timeframeValidationService(
                $ruleEngine,
                $conditionEvaluator,
                $conditionRegistry,
                $indicatorEngine,
                $legacyKlines,
                $fakeKlines,
            ),
            $selector,
        );

        $selection = $service->selectExecutionTimeframe(
            symbol: 'BTCUSDT',
            mode: 'scalper',
            executionTimeframes: ['1m'],
            mtfConfig: [
                'rules' => [],
                'validation' => [
                    'timeframe' => [
                        '1m' => ['long' => [], 'short' => []],
                    ],
                ],
                'filters_mandatory' => [],
            ],
            indicatorsByTimeframe: ['1m' => []],
            contextDecision: new ContextDecisionDto(true, null, []),
            exchangeContext: $fakeContext,
        );

        self::assertNull($selection->selectedTimeframe);
        self::assertSame('no_timeframe_selected', $selection->reasonIfNone);
    }

    private function timeframeValidationService(
        YamlRuleEngine $ruleEngine,
        TimeframeEvaluator $conditionEvaluator,
        ConditionRegistry $conditionRegistry,
        IndicatorEngineInterface $indicatorEngine,
        KlineProviderInterface $legacyKlines,
        KlineProviderInterface $fakeKlines,
    ): TimeframeValidationService {
        return new TimeframeValidationService(
            new TimeframeRuleEvaluator($ruleEngine),
            $ruleEngine,
            new NullLogger(),
            $conditionEvaluator,
            $conditionRegistry,
            $indicatorEngine,
            $legacyKlines,
            null,
            null,
            null,
            $fakeKlines,
        );
    }
}
