<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '1h', '1m', '5m'], side: 'short', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class MacdHistSlopeNegCondition extends AbstractCondition
{
    public const NAME = 'macd_hist_slope_neg';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Pente de l'histogramme MACD négative (MACD en dégradation).";
    }

    public function evaluate(array $context): ConditionResult
    {
        $last3 = $context['macd_hist_last3'] ?? null; // oldest..latest
        if (!\is_array($last3) || count($last3) < 2) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $n = count($last3);
        $prev = $last3[$n - 2] ?? null;
        $curr = $last3[$n - 1] ?? null;
        if (!\is_float($prev) || !\is_float($curr)) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $slope = $curr - $prev;
        $passed = $slope < 0.0;
        return $this->result(self::NAME, $passed, $slope, 0.0, $this->baseMeta($context));
    }
}
