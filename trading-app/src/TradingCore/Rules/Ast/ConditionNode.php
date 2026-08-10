<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Ast;

final readonly class ConditionNode implements RuleNode
{
    /** @param array<string, bool|int|float|string> $parameters */
    public function __construct(
        public string $conditionId,
        public string $timeframe,
        public string $side,
        public array $parameters,
        public string $provenance,
    ) {
    }

    public function toArray(): array
    {
        return [
            'kind' => 'condition',
            'condition_id' => $this->conditionId,
            'timeframe' => $this->timeframe,
            'side' => $this->side,
            'parameters' => $this->parameters,
            'provenance' => $this->provenance,
        ];
    }
}
