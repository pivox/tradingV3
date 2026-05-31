<?php

namespace App\Indicator\Condition;


use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m','5m','1m','1h','4h'], name: GetFalseCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: GetFalseCondition::NAME)]
class GetFalseCondition extends AbstractCondition
{
    const NAME = 'get_false';

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        // GetFalseCondition retourne toujours false (utilisé pour empêcher de rester en 15m en mode scalper)
        return $this->result(
            name: self::NAME,
            passed: false,
            meta: $this->baseMeta($context),
        );
    }
}
