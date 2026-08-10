<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Catalog;

final readonly class ConditionDefinition
{
    /**
     * @param list<string> $timeframes
     * @param list<string> $sides
     * @param array<string, ConditionParameterDefinition> $parameters
     */
    public function __construct(
        public string $id,
        public string $implementation,
        public string $metric,
        public string $unit,
        public string $valueType,
        public array $timeframes,
        public array $sides,
        public string $contextSource,
        public string $seriesOrder,
        public string $missingDataPolicy,
        public array $parameters,
        public string $provenance,
        public string $status,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $parameters = [];
        foreach ($this->parameters as $name => $definition) {
            $parameters[$name] = $definition->toArray();
        }
        ksort($parameters, SORT_STRING);

        return [
            'id' => $this->id,
            'implementation' => $this->implementation,
            'metric' => $this->metric,
            'unit' => $this->unit,
            'value_type' => $this->valueType,
            'timeframes' => $this->timeframes,
            'sides' => $this->sides,
            'context_source' => $this->contextSource,
            'series_order' => $this->seriesOrder,
            'missing_data_policy' => $this->missingDataPolicy,
            'parameters' => $parameters,
            'provenance' => $this->provenance,
            'status' => $this->status,
        ];
    }
}
