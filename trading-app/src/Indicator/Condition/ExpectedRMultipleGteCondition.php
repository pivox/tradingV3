<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['15m'], name: ExpectedRMultipleGteCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: ExpectedRMultipleGteCondition::NAME)]
final class ExpectedRMultipleGteCondition extends AbstractCondition
{
    public const NAME = 'expected_r_multiple_gte';

    public function __construct(
        private readonly float $threshold = 2.0,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $value = $context['expected_r_multiple'] ?? null;

        if (!\is_float($value)) {
            return $this->result(self::NAME, false, null, $this->threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $value >= $this->threshold;

        return $this->result(self::NAME, $passed, $value, $this->threshold, $this->baseMeta($context));
    }
}
