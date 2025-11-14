<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m', '5m', '1m'], name: PriceLteMa21PlusKAtrCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: PriceLteMa21PlusKAtrCondition::NAME)]
final class PriceLteMa21PlusKAtrCondition extends AbstractCondition
{
    public const NAME = 'price_lte_ma21_plus_k_atr';
    private const EPS = 1.0e-8;

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $level = $context['ma_21_plus_k_atr']
            ?? $context['ma_21_plus_1.3atr']
            ?? $context['ma_21_plus_2atr']
            ?? null;

        if (!\is_float($close) || !\is_float($level)) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $close <= $level * (1.0 + self::EPS);
        $value  = $close - $level;

        return $this->result(self::NAME, $passed, $value, 0.0, $this->baseMeta($context, [
            'close' => $close,
            'level' => $level,
        ]));
    }
}
