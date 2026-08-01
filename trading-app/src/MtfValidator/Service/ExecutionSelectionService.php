<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use App\MtfValidator\Service\Execution\ExecutionSelectorEngineInterface;
use App\MtfValidator\Service\Execution\ExecutionSelectorMetrics;
use App\Trading\Lineage\LineageContext;
use App\Provider\Context\ExchangeContext;
use Symfony\Component\DependencyInjection\Attribute\Lazy;

#[Lazy]
class ExecutionSelectionService
{
    public function __construct(
        private readonly TimeframeValidationService $timeframeValidationService,
        private readonly ExecutionSelectorEngineInterface $selectorEngine,
    ) {
    }

    /**
     * @param string[]                             $executionTimeframes
     * @param array<string,mixed>                  $mtfConfig
     * @param array<string,array<string,mixed>>    $indicatorsByTimeframe
     */
    public function selectExecutionTimeframe(
        string $symbol,
        ?string $mode,
        array $executionTimeframes,
        array $mtfConfig,
        array $indicatorsByTimeframe,
        ContextDecisionDto $contextDecision,
        ?ExchangeContext $exchangeContext = null,
        ?LineageContext $lineageContext = null,
        ?ExecutionSelectorMetrics $selectorMetrics = null,
    ): ExecutionSelectionDto {
        if ($lineageContext?->modeId !== null) {
            $lineageContext->assertTradeBoundary(
                $symbol,
                $lineageContext->side ?? '',
                $exchangeContext?->exchange->value,
                $exchangeContext?->marketType->value,
                false,
            );
            if ($selectorMetrics === null || $selectorMetrics->identity !== $lineageContext || !$selectorMetrics->covers($executionTimeframes)) {
                return new ExecutionSelectionDto(null, null, 'selector_metrics_missing', []);
            }
        }
        $decisions = [];

        foreach ($executionTimeframes as $tf) {
            $tfIndicators = $indicatorsByTimeframe[$tf] ?? [];

            $decisions[$tf] = $this->timeframeValidationService->validateTimeframe(
                symbol: $symbol,
                timeframe: $tf,
                phase: 'execution',
                mode: $mode,
                mtfConfig: $mtfConfig,
                indicators: $tfIndicators,
                exchangeContext: $exchangeContext,
            );
        }

        $selection = $this->selectorEngine->select(
            $decisions,
            $mtfConfig['execution_selector'] ?? []
        );

        if ($selection === null) {
            return new ExecutionSelectionDto(
                selectedTimeframe: null,
                selectedSide: null,
                reasonIfNone: 'no_timeframe_selected',
                timeframeDecisions: \array_values($decisions),
            );
        }

        return new ExecutionSelectionDto(
            selectedTimeframe: $selection['timeframe'],
            selectedSide: $selection['side'],
            reasonIfNone: null,
            timeframeDecisions: \array_values($decisions),
        );
    }
}
