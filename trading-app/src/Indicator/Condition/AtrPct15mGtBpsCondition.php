<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m'], name: AtrPct15mGtBpsCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: AtrPct15mGtBpsCondition::NAME)]
final class AtrPct15mGtBpsCondition extends AbstractCondition
{
    public const NAME = 'atr_pct_15m_gt_bps';

    public function __construct(
        private readonly float $thresholdBps = 120.0,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $valueBps = $context['atr_pct_15m_bps'] ?? null;

        if (!\is_float($valueBps)) {
            return $this->result(self::NAME, false, null, $this->thresholdBps, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $valueBps > $this->thresholdBps;

        return $this->result(self::NAME, $passed, $valueBps, $this->thresholdBps, $this->baseMeta($context));
    }
}
