<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class Ema20Over50WithToleranceModerateCondition extends AbstractCondition
{
    private const NAME = 'ema20_over_50_with_tolerance_moderate';
    private const DEFAULT_TOLERANCE = 0.0012; // 0.12%

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $ema = $context['ema'] ?? null;
        $ema20 = is_array($ema) ? ($ema[20] ?? null) : null;
        $ema50 = is_array($ema) ? ($ema[50] ?? null) : null;

        if (!is_float($ema20) || !is_float($ema50)) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }

        $tolerance = $context['ema20_over_50_tolerance_moderate'] ?? self::DEFAULT_TOLERANCE;
        if (!is_float($tolerance)) {
            $tolerance = (float) $tolerance;
        }
        if ($tolerance < 0) {
            $tolerance = abs($tolerance);
        }

        $baseValue = $ema50 !== 0.0 ? (($ema20 / $ema50) - 1.0) : $ema20 - $ema50;
        $threshold = -$tolerance;
        $passed = $baseValue >= $threshold;

        return $this->result(self::NAME, $passed, $baseValue, $threshold, $this->baseMeta($context, [
            'ema20' => $ema20,
            'ema50' => $ema50,
            'ratio' => $ema50 !== 0.0 ? $ema20 / $ema50 : null,
            'tolerance' => $tolerance,
            'source' => 'EMA',
        ]));
    }
}
