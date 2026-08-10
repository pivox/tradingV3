<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Evaluation;

use App\Indicator\Condition\ConditionInterface;

final readonly class StrictConditionRegistry
{
    /** @var array<string, ConditionInterface> */
    private array $conditions;

    /** @param iterable<ConditionInterface> $conditions */
    public function __construct(iterable $conditions)
    {
        $indexed = [];
        foreach ($conditions as $condition) {
            $name = $condition->getName();
            if ($name === '' || isset($indexed[$name])) {
                throw new \InvalidArgumentException(sprintf('Duplicate or empty strict condition id "%s".', $name));
            }
            $indexed[$name] = $condition;
        }
        ksort($indexed, SORT_STRING);
        $this->conditions = $indexed;
    }

    public function get(string $conditionId): ?ConditionInterface
    {
        return $this->conditions[$conditionId] ?? null;
    }
}
