<?php

namespace App\Indicator\Condition;

final class EmaBelow200WithToleranceCondition extends AbstractCondition
{
    private const NAME = 'ema_below_200_with_tolerance';
    private const DEFAULT_TOLERANCE = 0.0015; // 0.15%

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ema = $context['ema'] ?? null;
        $ema200 = is_array($ema) ? ($ema[200] ?? null) : null;

        if (!is_float($close) || !is_float($ema200) || $ema200 === 0.0) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'EMA',
            ]));
        }

        $tolerance = $context['ema200_bear_tolerance'] ?? self::DEFAULT_TOLERANCE;
        if (!is_float($tolerance)) {
            $tolerance = (float) $tolerance;
        }
        if ($tolerance < 0) {
            $tolerance = abs($tolerance);
        }

        $ratio = ($close / $ema200) - 1.0;
        $threshold = $tolerance;
        $passed = $ratio <= $threshold;

        return $this->result(self::NAME, $passed, $ratio, $threshold, $this->baseMeta($context, [
            'close' => $close,
            'ema200' => $ema200,
            'tolerance' => $tolerance,
            'source' => 'EMA',
        ]));
    }
}

