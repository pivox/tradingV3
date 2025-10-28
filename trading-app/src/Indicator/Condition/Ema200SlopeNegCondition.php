<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1h', '4h'], side: 'short', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class Ema200SlopeNegCondition extends AbstractCondition
{
    public const NAME = 'ema200_slope_neg';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Pente de l'EMA200 négative (momentum baissier de fond).";
    }

    public function evaluate(array $context): ConditionResult
    {
        $slope = $context['ema_200_slope'] ?? null;
        if (!is_float($slope)) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $passed = $slope < 0.0;
        return $this->result(self::NAME, $passed, $slope, 0.0, $this->baseMeta($context));
    }
}
