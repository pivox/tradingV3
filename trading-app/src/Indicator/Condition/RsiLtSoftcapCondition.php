<?php

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['5m'], side: 'long', name: 'rsi_lt_softcap')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'rsi_lt_softcap')]

final class RsiLtSoftcapCondition extends AbstractCondition
{
    private const NAME = 'rsi_lt_softcap';
    private const DEFAULT_THRESHOLD = 78.0;

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

        $threshold = $context['rsi_softcap_threshold'] ?? self::DEFAULT_THRESHOLD;
        if (!is_float($threshold)) {
            $threshold = (float) $threshold;
        }

        $passed = $rsi < $threshold;

        return $this->result(self::NAME, $passed, $rsi, $threshold, $this->baseMeta($context, [
            'source' => 'RSI',
        ]));
    }
}
