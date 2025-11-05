<?php

declare(strict_types=1);

namespace App\Service\Timeframe;

use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\Service\Dto\Internal\ProcessingContextDto;

interface TimeframeSelectionStrategyInterface
{
    /**
     * @param iterable<TimeframeProcessorInterface> $processors
     *
     * @return list<TimeframeProcessorInterface>
     */
    public function selectProcessors(ProcessingContextDto $context, iterable $processors): array;
}
