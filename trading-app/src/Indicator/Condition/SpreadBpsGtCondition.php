<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['5m', '15m'], name: SpreadBpsGtCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: SpreadBpsGtCondition::NAME)]
final class SpreadBpsGtCondition extends AbstractCondition
{
    public const NAME = 'spread_bps_gt';

    public function __construct(
        private readonly float $thresholdBps = 8.0,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $spreadBps = $context['spread_bps'] ?? null;

        if (!\is_float($spreadBps)) {
            return $this->result(self::NAME, false, null, $this->thresholdBps, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $spreadBps > $this->thresholdBps;

        return $this->result(self::NAME, $passed, $spreadBps, $this->thresholdBps, $this->baseMeta($context));
    }
}
