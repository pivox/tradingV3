<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: 'macd_hist_increasing_n')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'macd_hist_increasing_n')]

final class MacdHistIncreasingNCondition extends AbstractCondition
{
    public function __construct(private int $defaultN = 2) {}

    public function getName(): string { return 'macd_hist_increasing_n'; }

    public function evaluate(array $context): ConditionResult
    {
        $series = $context['macd_hist_last3'] ?? null;
        $n = $context['macd_hist_increasing_n'] ?? $this->defaultN;
        if (!is_array($series)) {
            return $this->result($this->getName(), false, null, (float) $n, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'MACD',
            ]));
        }
        $count = count($series);
        if ($count < 2) {
            return $this->result($this->getName(), false, null, (float) $n, $this->baseMeta($context, [
                'insufficient_points' => true,
            ]));
        }
        // Vérifier les dernières n hausses consécutives
        $required = max(1, (int) $n);
        $inc = 0;
        for ($i = $count - 2; $i >= 0 && $inc < $required; $i--) {
            if (!is_float($series[$i]) || !is_float($series[$i+1])) {
                break;
            }
            if ($series[$i+1] > $series[$i]) {
                $inc++;
            } else {
                break;
            }
        }
        $passed = ($inc >= $required);
        $value = $count >= 2 && is_float($series[$count-1]) && is_float($series[$count-2]) ? ($series[$count-1] - $series[$count-2]) : null;
        return $this->result($this->getName(), $passed, $value, (float) $required, $this->baseMeta($context, [
            'points_considered' => $count,
            'required_increases' => $required,
        ]));
    }
}

