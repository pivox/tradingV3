<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'long', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class EmaAbove200WithToleranceModerateCondition extends AbstractCondition
{
    private const NAME = 'ema_above_200_with_tolerance_moderate';
    private const DEFAULT_TOLERANCE = 0.0020; // 0.20%

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $ema = $context['ema'] ?? null;
        $close = $context['close'] ?? null;
        $ema200 = is_array($ema) ? ($ema[200] ?? null) : null;

        if (!is_float($close) || !is_float($ema200)) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }

        $tolerance = $context['ema_above_200_tolerance_moderate'] ?? self::DEFAULT_TOLERANCE;
        if (!is_float($tolerance)) {
            $tolerance = (float) $tolerance;
        }
        if ($tolerance < 0) {
            $tolerance = abs($tolerance);
        }

        $baseValue = $ema200 !== 0.0 ? (($close / $ema200) - 1.0) : $close - $ema200;
        $threshold = -$tolerance;
        $passed = $baseValue >= $threshold;

        return $this->result(self::NAME, $passed, $baseValue, $threshold, $this->baseMeta($context, [
            'close' => $close,
            'ema200' => $ema200,
            'ratio' => $ema200 !== 0.0 ? $close / $ema200 : null,
            'tolerance' => $tolerance,
            'source' => 'EMA',
        ]));
    }
}
