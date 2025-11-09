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

        // Utiliser le seuil depuis le contexte si disponible, sinon le défaut
        $threshold = isset($context['spread_bps_gt_threshold']) && \is_numeric($context['spread_bps_gt_threshold'])
            ? (float)$context['spread_bps_gt_threshold']
            : $this->thresholdBps;

        if (!\is_float($spreadBps)) {
            return $this->result(self::NAME, false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $spreadBps > $threshold;

        return $this->result(self::NAME, $passed, $spreadBps, $threshold, $this->baseMeta($context));
    }
}
