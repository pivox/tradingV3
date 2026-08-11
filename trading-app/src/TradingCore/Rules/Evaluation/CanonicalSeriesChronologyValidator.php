<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

use App\Common\Enum\Timeframe;
use App\TradingCore\Rules\Catalog\ConditionDefinition;

final readonly class CanonicalSeriesChronologyValidator
{
    public function requiresProof(ConditionDefinition $definition): bool
    {
        return str_starts_with($definition->valueType, 'series<');
    }

    /** @param array<string, mixed> $context */
    public function isCanonical(ConditionDefinition $definition, array $context, string $timeframe): bool
    {
        if (!$this->requiresProof($definition)) {
            return true;
        }
        if (($context['series_order'] ?? null) !== $definition->seriesOrder) {
            return false;
        }

        $series = $context[$definition->metric] ?? null;
        $timestamps = $context[$definition->metric . '_timestamps'] ?? null;
        if (!is_array($series)
            || !array_is_list($series)
            || count($series) < 2
            || !is_array($timestamps)
            || !array_is_list($timestamps)
            || count($timestamps) !== count($series)
        ) {
            return false;
        }

        $timeframeValue = Timeframe::tryFrom($timeframe);
        if ($timeframeValue === null) {
            return false;
        }
        $step = $timeframeValue->getStepInSeconds();
        foreach ($timestamps as $index => $timestamp) {
            if (!is_int($timestamp)) {
                return false;
            }
            if ($index > 0 && $timestamp - $timestamps[$index - 1] !== $step) {
                return false;
            }
        }

        return true;
    }
}
