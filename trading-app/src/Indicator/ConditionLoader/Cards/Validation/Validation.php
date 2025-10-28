<?php

namespace App\Indicator\ConditionLoader\Cards\Validation;

use App\Indicator\ConditionLoader\Cards\AbstractCard;
use App\Indicator\ConditionLoader\Cards\Rule\Rules;
use App\Indicator\ConditionLoader\ConditionRegistry;

class Validation extends AbstractCard
{
    const START_FROM_TIMEFRAME = 'start_from_timeframe';
    const TIMEFRAME = 'timeframe';
    const VALIDATION = 'validation';
    const RULES = 'rules';
    public const DEFAULT_START_TIMEFRAME = TimeframeValidation::TF_4H;
    private ?Rules $rules = null;
    private string $startFromTimeframe = self::DEFAULT_START_TIMEFRAME;
    /** @var array<string,TimeframeValidation> */
    private array $timeframes = [];

    public function __construct(
        ?Rules $rules = null,
        private readonly ?ConditionRegistry $conditionRegistry = null
    ) {
        $this->rules = $rules;
    }

    public function withRules(?Rules $rules): static
    {
        $this->rules = $rules;
        return $this;
    }

    public function fill(string|array $payload): static
    {
        // supporte: racine -> mtf_validation -> validation
        $node = $payload['mtf_validation']['validation'] ?? $payload['validation'] ?? $payload;

        $start = $node[self::START_FROM_TIMEFRAME] ?? self::DEFAULT_START_TIMEFRAME;
        $this->startFromTimeframe = is_string($start) ? strtolower($start) : self::DEFAULT_START_TIMEFRAME;

        $data = $node[self::TIMEFRAME] ?? [];
        $this->timeframes = [];

        foreach ($data as $tf => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $timeframe = strtolower((string) $tf);
            $this->timeframes[$timeframe] = (new TimeframeValidation($this->conditionRegistry))
                ->withTimeframe($timeframe)
                ->fill($definition);
        }

        return $this;
    }

    public function evaluate(array $contextsByTimeframe): array
    {
        $order = [
            TimeframeValidation::TF_4H,
            TimeframeValidation::TF_1H,
            TimeframeValidation::TF_15M,
            TimeframeValidation::TF_5M,
            TimeframeValidation::TF_1M,
        ];

        $startIndex = array_search($this->startFromTimeframe, $order, true);
        if ($startIndex === false) {
            $startIndex = 0;
        }

        $evaluationOrder = array_slice($order, $startIndex);
        $results = [];

        foreach ($evaluationOrder as $tf) {
            if (!isset($this->timeframes[$tf])) {
                continue;
            }
            $context = $contextsByTimeframe[$tf] ?? [];
            $results[$tf] = $this->timeframes[$tf]->evaluate(is_array($context) ? $context : []);
        }

        return $results;
    }

    public function getStartFromTimeframe(): string
    {
        return $this->startFromTimeframe;
    }

    /**
     * @return array<string,TimeframeValidation>
     */
    public function getTimeframes(): array
    {
        return $this->timeframes;
    }

}
