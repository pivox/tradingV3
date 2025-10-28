<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '1m', '5m'], side: 'long', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class CloseAboveVwapOrMa9Condition extends AbstractCondition
{
    public const NAME = 'close_above_vwap_or_ma9';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Close au-dessus du VWAP ou au-dessus de MA9 (EMA9 ici), indiquant un biais haussier local.";
    }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $vwap  = $context['vwap']  ?? null;
        $ema9  = $context['ema'][9] ?? null; // MA9 approximé par EMA9 dans le contexte

        if (!is_float($close) || (!is_float($vwap) && !is_float($ema9))) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = ($vwap !== null && $close > $vwap) || ($ema9 !== null && $close > $ema9);
        $value = null;
        if ($vwap !== null) { $value = ($close / $vwap) - 1.0; }
        if ($value === null && $ema9 !== null) { $value = ($close / $ema9) - 1.0; }

        return $this->result(self::NAME, $passed, $value, 0.0, $this->baseMeta($context, [
            'close' => $close,
            'vwap' => $vwap,
            'ema9' => $ema9,
        ]));
    }
}
