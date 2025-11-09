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

        // Utiliser le seuil depuis le contexte si disponible, sinon le défaut
        $threshold = isset($context['atr_pct_15m_gt_bps_threshold']) && \is_numeric($context['atr_pct_15m_gt_bps_threshold'])
            ? (float)$context['atr_pct_15m_gt_bps_threshold']
            : $this->thresholdBps;

        if (!\is_float($valueBps)) {
            return $this->result(self::NAME, false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $valueBps > $threshold;

        return $this->result(self::NAME, $passed, $valueBps, $threshold, $this->baseMeta($context));
    }
}
