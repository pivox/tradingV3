<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['5m', '15m'], name: Adx5mLtCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: Adx5mLtCondition::NAME)]
final class Adx5mLtCondition extends AbstractCondition
{
    public const NAME = 'adx_5m_lt';

    public function __construct(
        private readonly float $threshold = 20.0,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $adx5m = $context['adx_5m'] ?? null;

        if (!\is_float($adx5m)) {
            return $this->result(self::NAME, false, null, $this->threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $adx5m < $this->threshold;

        return $this->result(self::NAME, $passed, $adx5m, $this->threshold, $this->baseMeta($context));
    }
}
