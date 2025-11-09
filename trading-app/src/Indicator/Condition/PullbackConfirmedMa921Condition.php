<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['5m', '15m'], name: PullbackConfirmedMa921Condition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: PullbackConfirmedMa921Condition::NAME)]
final class PullbackConfirmedMa921Condition extends AbstractCondition
{
    public const NAME = 'pullback_confirmed_ma9_21';

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ma9   = $context['ma'][9] ?? $context['ema'][9] ?? null;
        $ma21  = $context['ma'][21] ?? $context['ema'][21] ?? null;

        if (!is_float($close) || !is_float($ma9) || !is_float($ma21)) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $aboveMa21 = $close > $ma21;
        $ma9Above  = $ma9 > $ma21;

        $passed = $aboveMa21 && $ma9Above;
        $value  = $ma9 - $ma21;

        return $this->result(self::NAME, $passed, $value, 0.0, $this->baseMeta($context, [
            'close'      => $close,
            'ma9'        => $ma9,
            'ma21'       => $ma21,
            'above_ma21' => $aboveMa21,
            'ma9_above'  => $ma9Above,
        ]));
    }
}
