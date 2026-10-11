<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The close is strictly below the EMA200 of the same timeframe.
 *
 * Accepts integer and float inputs: canonical JSON normalizes integral floats to
 * integers, so a whole-number close must not read as missing data.
 */
#[AsIndicatorCondition(timeframes: ['4h'], side: 'short', name: 'close_lt_ema_200')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'close_lt_ema_200')]
final class CloseLtEma200Condition extends AbstractCondition
{
    public function getName(): string
    {
        return 'close_lt_ema_200';
    }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $close = $context['close'] ?? null;
        $ema200 = $context['ema'][200] ?? $context['ema']['200'] ?? null;
        if (!self::finite($close) || !self::finite($ema200)) {
            return $this->result($this->getName(), false, null, null, $this->baseMeta($context, ['missing_data' => true]));
        }
        $close = (float) $close;
        $ema200 = (float) $ema200;

        return $this->result($this->getName(), $close < $ema200, $close - $ema200, $ema200, $this->baseMeta($context, [
            'close' => $close,
            'ema200' => $ema200,
        ]));
    }

    private static function finite(mixed $value): bool
    {
        return (\is_int($value) || \is_float($value)) && \is_finite((float) $value);
    }
}
