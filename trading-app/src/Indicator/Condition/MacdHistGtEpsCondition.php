<?php

namespace App\Indicator\Condition;

final class MacdHistGtEpsCondition extends AbstractCondition
{
    private const NAME = 'macd_hist_gt_eps';
    private const BASE_THRESHOLD = 0.0;
    private const DEFAULT_EPS = 1.0e-6;

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $hist = $context['macd']['hist'] ?? null;
        if (!is_float($hist)) {
            return $this->result(self::NAME, false, null, self::BASE_THRESHOLD, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'MACD',
            ]));
        }

        $eps = $context['eps'] ?? self::DEFAULT_EPS;
        if (!is_float($eps)) {
            $eps = (float) $eps;
        }
        if ($eps < 0) {
            $eps = abs($eps);
        }
        $effectiveThreshold = self::BASE_THRESHOLD - $eps;
        $passed = $hist >= $effectiveThreshold;

        return $this->result(self::NAME, $passed, $hist, self::BASE_THRESHOLD, $this->baseMeta($context, [
            'eps' => $eps,
            'effective_threshold' => $effectiveThreshold,
            'source' => 'MACD',
        ]));
    }
}
