<?php

declare(strict_types=1);

namespace App\TradingCore\Rules\Ast;

final readonly class ConditionNode implements RuleNode
{
    /** @var array<string, bool|int|float|string> */
    public array $parameters;

    /** @var array<string, 'setup_contract'|'condition_catalog_default'> */
    public array $parameterSources;

    /**
     * @param array<string, bool|int|float|string> $parameters
     * @param array<string, 'setup_contract'|'condition_catalog_default'>|null $parameterSources
     */
    public function __construct(
        public string $conditionId,
        public string $timeframe,
        public string $side,
        array $parameters,
        public string $provenance,
        ?array $parameterSources = null,
    ) {
        ksort($parameters, SORT_STRING);
        $parameterSources ??= array_fill_keys(array_keys($parameters), 'setup_contract');
        ksort($parameterSources, SORT_STRING);
        if (array_keys($parameters) !== array_keys($parameterSources)) {
            throw new \InvalidArgumentException('Condition parameter sources must exactly match parameter keys.');
        }
        foreach ($parameterSources as $source) {
            if (!in_array($source, ['setup_contract', 'condition_catalog_default'], true)) {
                throw new \InvalidArgumentException('Unknown condition parameter source.');
            }
        }
        $this->parameters = $parameters;
        $this->parameterSources = $parameterSources;
    }

    public function toArray(): array
    {
        return [
            'kind' => 'condition',
            'condition_id' => $this->conditionId,
            'timeframe' => $this->timeframe,
            'side' => $this->side,
            'parameters' => $this->parameters,
            'parameter_sources' => $this->parameterSources,
            'provenance' => $this->provenance,
        ];
    }
}
