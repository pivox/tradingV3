<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\Contract\MtfValidator\Dto\ContextDecisionDto;
use App\Contract\MtfValidator\Dto\ExecutionSelectionDto;
use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;
use App\MtfValidator\Service\Execution\ExecutionSelectorEngineInterface;
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
    ): ExecutionSelectionDto {
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
