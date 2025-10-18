<?php

namespace App\Indicator\Condition;

final class RsiGtSoftfloorCondition extends AbstractCondition
{
    private const NAME = 'rsi_gt_softfloor';
    private const DEFAULT_THRESHOLD = 22.0;

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $rsi = $context['rsi'] ?? null;
        if (!is_float($rsi)) {
            return $this->result(self::NAME, false, null, self::DEFAULT_THRESHOLD, $this->baseMeta($context, [
                'missing_data' => true,
                'source' => 'RSI',
            ]));
        }

        $threshold = $context['rsi_softfloor_threshold'] ?? self::DEFAULT_THRESHOLD;
        if (!is_float($threshold)) {
            $threshold = (float) $threshold;
        }

        $passed = $rsi > $threshold;

        return $this->result(self::NAME, $passed, $rsi, $threshold, $this->baseMeta($context, [
            'source' => 'RSI',
        ]));
    }
}

