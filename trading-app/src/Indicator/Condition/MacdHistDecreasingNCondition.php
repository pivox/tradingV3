<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '1h'], side: 'short', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class MacdHistDecreasingNCondition extends AbstractCondition
{
    public const NAME = 'macd_hist_decreasing_n';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Histogramme MACD strictement décroissant sur N pas (dernier en tête de série).";
    }

    public function evaluate(array $context): ConditionResult
    {
        $series = $context['macd_hist_series'] ?? null; // latest-first
        $n = $context['macd_hist_decreasing_n'] ?? $context['n'] ?? 2;
        $n = is_numeric($n) ? (int) $n : 2;

        if (!\is_array($series) || count($series) < ($n + 1)) {
            return $this->result(self::NAME, false, null, (float)$n, $this->baseMeta($context, ['missing_data' => true]));
        }

        $passed = true;
        for ($i = 0; $i < $n; $i++) {
            $a = $series[$i] ?? null;      // current
            $b = $series[$i + 1] ?? null;  // previous
            if (!\is_float($a) || !\is_float($b) || !($a < $b)) {
                $passed = false;
                break;
            }
        }

        $value = ($series[0] - $series[$n]) / max(1, $n); // average step
        return $this->result(self::NAME, $passed, $value, 0.0, $this->baseMeta($context, [
            'n' => $n,
        ]));
    }
}
