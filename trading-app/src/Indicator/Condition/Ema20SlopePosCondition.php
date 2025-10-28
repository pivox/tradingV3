<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '5m'], side: 'long', name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class Ema20SlopePosCondition extends AbstractCondition
{
    public const NAME = 'ema_20_slope_pos';

    public function getName(): string { return self::NAME; }

    protected function getDefaultDescription(): string
    {
        return "Pente EMA20 positive (accélération haussière de court terme).";
    }

    public function evaluate(array $context): ConditionResult
    {
        $ema = $context['ema'][20] ?? null;
        $emaPrev = $context['ema_prev'][20] ?? null;
        if (!is_float($ema) || !is_float($emaPrev)) {
            return $this->result(self::NAME, false, null, 0.0, $this->baseMeta($context, ['missing_data' => true]));
        }
        $slope = $ema - $emaPrev;
        $passed = $slope > 0.0;
        return $this->result(self::NAME, $passed, $slope, 0.0, $this->baseMeta($context, [
            'ema20' => $ema,
            'ema20_prev' => $emaPrev,
        ]));
    }
}
