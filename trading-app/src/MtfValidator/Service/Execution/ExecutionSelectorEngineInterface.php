<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Execution;

use App\Contract\MtfValidator\Dto\TimeframeDecisionDto;

interface ExecutionSelectorEngineInterface
{
    /**
     * @param array<string,TimeframeDecisionDto> $decisionsByTf
     * @param array<string,mixed>                $selectorConfig mtfConfig['execution_selector']
     *
     * @return array{timeframe:string,side:string}|null
     */
    public function select(array $decisionsByTf, array $selectorConfig): ?array;
}
