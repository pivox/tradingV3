<?php

namespace App\Indicator\Condition;

abstract class AbstractCondition implements ConditionInterface
{
    protected function result(string $name, bool $passed, ?float $value = null, ?float $threshold = null, array $meta = []): ConditionResult
    {
        return new ConditionResult($name, $passed, $value, $threshold, $meta);
    }

    protected function baseMeta(array $context, array $extra = []): array
    {
        return array_filter([
            'symbol' => $context['symbol'] ?? null,
            'timeframe' => $context['timeframe'] ?? null,
        ]) + $extra;
    }
}
