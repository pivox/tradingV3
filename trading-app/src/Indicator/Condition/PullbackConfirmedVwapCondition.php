<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['5m', '15m'], name: PullbackConfirmedVwapCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: PullbackConfirmedVwapCondition::NAME)]
final class PullbackConfirmedVwapCondition extends AbstractCondition
{
    public const NAME = 'pullback_confirmed_vwap';

    private const MAX_DIST_RATIO = 0.003; // 0.3 % (assoupli)

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $vwap  = $context['vwap'] ?? null;

        if (!is_float($close) || !is_float($vwap) || $vwap <= 0.0) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $aboveVwap  = $close > $vwap;
        $distRatio  = abs($close - $vwap) / $vwap;
        $nearVwap   = $distRatio <= self::MAX_DIST_RATIO;

        $passed = $aboveVwap && $nearVwap;

        return $this->result(self::NAME, $passed, $distRatio, self::MAX_DIST_RATIO, $this->baseMeta($context, [
            'close'      => $close,
            'vwap'       => $vwap,
            'above_vwap' => $aboveVwap,
            'near_vwap'  => $nearVwap,
        ]));
    }
}
