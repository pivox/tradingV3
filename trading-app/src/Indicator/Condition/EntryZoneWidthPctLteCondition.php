<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m'], name: EntryZoneWidthPctLteCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: EntryZoneWidthPctLteCondition::NAME)]
final class EntryZoneWidthPctLteCondition extends AbstractCondition
{
    public const NAME = 'entry_zone_width_pct_lte';

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

        if (!\is_float($value)) {
            return $this->result(self::NAME, false, null, $this->threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $value <= $this->threshold;

        return $this->result(self::NAME, $passed, $value, $this->threshold, $this->baseMeta($context));
    }
}
