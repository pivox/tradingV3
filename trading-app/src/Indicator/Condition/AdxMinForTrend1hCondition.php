<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['4h', '1h', '15m', '5m'], name: AdxMinForTrend1hCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: AdxMinForTrend1hCondition::NAME)]
final class AdxMinForTrend1hCondition extends AbstractCondition
{
    public const NAME = 'adx_min_for_trend_1h';

    private float $minAdx;

    public function __construct(float $minAdx = 25.0)
    {
        $this->minAdx = $minAdx;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $tf = $context['timeframe'] ?? null;

        $adx1h = $context['adx_1h'] ?? null;
        if (!is_float($adx1h) && $tf === '1h') {
            $adx1h = $context['adx'] ?? null;
        }

        if (!is_float($adx1h)) {
            return $this->result(self::NAME, false, null, $this->minAdx, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        // Threshold override from context if provided
        $threshold = $this->minAdx;
        $ctxThreshold = $context['adx_1h_min_threshold'] ?? null;
        if (is_numeric($ctxThreshold)) {
            $threshold = (float) $ctxThreshold;
        }

        $passed = $adx1h >= $threshold;

        return $this->result(self::NAME, $passed, $adx1h, $threshold, $this->baseMeta($context, [
            'adx_1h' => $adx1h,
            'threshold_source' => isset($ctxThreshold) ? 'context' : 'default',
        ]));
    }
}
