<?php

namespace App\Indicator\Condition;


use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m','5m','1m','1h','4h'], name: ExpectedRMultipleLtCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: ExpectedRMultipleLtCondition::NAME)]
class GetFalseCondition extends AbstractCondition
{
    const NAME = 'get_false';

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        return $this->result(
            name: self::NAME,
            passed: (bool) ($context['get_false'] ?? false),
            meta: $this->baseMeta($context),
        );
    }
}
