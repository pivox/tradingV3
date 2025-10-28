<?php
declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m','5m','15m','1h','4h'], side: 'short', name: 'macd_hist_lt_0')]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: 'macd_hist_lt_0')]

final class MacdHistLt0Condition extends AbstractCondition
{
    private const NAME = 'macd_hist_lt_0';
    private const TARGET = 0.0;
    private const EPSILON = 1e-12; // optionnel

    public function getName(): string
    {
        return self::NAME;
    }

    /**
     * @param array{
     *   macd?: array{
     *     hist?: float|int|string
     *   }
     * } $context
     */
    public function evaluate(array $context): ConditionResult
    {
        if (!isset($context['macd']) || !is_array($context['macd'])) {
            return $this->result(
                self::NAME,
                false,
                null,
                self::TARGET,
                $this->baseMeta($context, ['missing_data' => 'macd'])
            );
        }

        $histRaw = $context['macd']['hist'] ?? null;
        if (!is_numeric($histRaw)) {
            return $this->result(
                self::NAME,
                false,
                null,
                self::TARGET,
                $this->baseMeta($context, ['missing_data' => 'macd.hist'])
            );
        }

        $hist = (float) $histRaw;

        // < 0 strict, ou bien avec epsilon pour stabilité numérique
        $passed = ($hist < (self::TARGET - self::EPSILON));

        return $this->result(
            self::NAME,
            $passed,
            $hist,
            self::TARGET,
            $this->baseMeta($context, ['source' => 'MACD'])
        );
    }
}
