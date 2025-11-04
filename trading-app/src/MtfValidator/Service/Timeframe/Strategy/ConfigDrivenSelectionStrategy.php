<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Timeframe\Strategy;

use App\Config\MtfValidationConfig;
use App\Contract\MtfValidator\TimeframeProcessorInterface;
use App\Service\Dto\Internal\ProcessingContextDto;
use App\Service\Timeframe\TimeframeSelectionStrategyInterface;

final class ConfigDrivenSelectionStrategy implements TimeframeSelectionStrategyInterface
{
    /**
     * @var list<string>
     */
    private const ORDER = ['4h', '1h', '15m', '5m', '1m'];

    public function __construct(private readonly MtfValidationConfig $config)
    {
    }

    public function selectProcessors(ProcessingContextDto $context, iterable $processors): array
    {
        $available = [];
        foreach ($processors as $processor) {
            $available[strtolower($processor->getTimeframeValue())] = $processor;
        }

        $single = $context->currentTimeframe;
        if ($single !== null) {
            $key = strtolower($single);
            if (isset($available[$key])) {
                return [$available[$key]];
            }

            return [];
        }

        $startFrom = strtolower((string)($this->config->getConfig()['validation']['start_from_timeframe'] ?? '4h'));
        $startFound = false;
        $selected = [];

        foreach (self::ORDER as $timeframe) {
            if (!$startFound && $timeframe === $startFrom) {
                $startFound = true;
            }

            if (!$startFound) {
                continue;
            }

            if (isset($available[$timeframe])) {
                $selected[] = $available[$timeframe];
            }
        }

        if (!$startFound) {
            foreach (self::ORDER as $timeframe) {
                if (isset($available[$timeframe])) {
                    $selected[] = $available[$timeframe];
                }
            }
        }

        return $selected;
    }
}
