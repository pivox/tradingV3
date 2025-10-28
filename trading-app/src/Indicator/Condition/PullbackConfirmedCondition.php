<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['5m'], side: 'long', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class PullbackConfirmedCondition extends AbstractCondition
{
    public const NAME = 'pullback_confirmed';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Pullback haussier confirmé: reprise (MACD hist remonte) et close au-dessus de EMA21.";
    }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ema21 = $context['ema'][21] ?? null;
        $last3 = $context['macd_hist_last3'] ?? null; // oldest..latest
        if (!\is_float($close) || !\is_float($ema21) || !\is_array($last3) || count($last3) < 3) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }

        [$a, $b, $c] = array_values($last3);
        $vShape = ($b < $a) && ($c > $b); // minimum local
        $aboveEma = $close > $ema21;
        $passed = $vShape && $aboveEma;
        $value = ($c - $b);

        return $this->result(self::NAME, $passed, $value, 0.0, $this->baseMeta($context, [
            'ema21' => $ema21,
            'vshape' => $vShape,
        ]));
    }
}
