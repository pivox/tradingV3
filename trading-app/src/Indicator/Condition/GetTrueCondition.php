<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m','5m','1m','1h','4h'], name: GetTrueCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: GetTrueCondition::NAME)]
class GetTrueCondition extends AbstractCondition
{
    public const NAME = 'get_true';

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        // Condition utilitaire: retourne toujours true
        return $this->result(
            name: self::NAME,
            passed: true,
            meta: $this->baseMeta($context),
        );
    }
}
