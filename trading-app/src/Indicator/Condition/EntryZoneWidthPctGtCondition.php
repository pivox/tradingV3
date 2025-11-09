<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m'], name: EntryZoneWidthPctGtCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: EntryZoneWidthPctGtCondition::NAME)]
final class EntryZoneWidthPctGtCondition extends AbstractCondition
{
    public const NAME = 'entry_zone_width_pct_gt';

    public function __construct(
        private readonly float $threshold = 1.2, // en %
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $value = $context['entry_zone_width_pct'] ?? null;

        // Utiliser le seuil depuis le contexte si disponible, sinon le défaut
        $threshold = isset($context['entry_zone_width_pct_gt_threshold']) && \is_numeric($context['entry_zone_width_pct_gt_threshold'])
            ? (float)$context['entry_zone_width_pct_gt_threshold']
            : $this->threshold;

        if (!\is_float($value)) {
            return $this->result(self::NAME, false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $value > $threshold;

        return $this->result(self::NAME, $passed, $value, $threshold, $this->baseMeta($context));
    }
}
