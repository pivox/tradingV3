<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m', '5m', '15m'], name: EndOfZoneFallbackCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: EndOfZoneFallbackCondition::NAME)]
final class EndOfZoneFallbackCondition extends AbstractCondition
{
    public const NAME = 'end_of_zone_fallback';

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $flag = $context['end_of_zone_fallback'] ?? null;

        if (!\is_bool($flag)) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        return $this->result(self::NAME, $flag, $flag ? 1.0 : 0.0, null, $this->baseMeta($context));
    }
}
