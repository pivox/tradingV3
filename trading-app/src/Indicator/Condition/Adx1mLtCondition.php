<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m', '5m'], name: Adx1mLtCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: Adx1mLtCondition::NAME)]
final class Adx1mLtCondition extends AbstractCondition
{
    public const NAME = 'adx_1m_lt';

    public function __construct(
        private readonly float $threshold = 20.0,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $adx = $context['adx_1m'] ?? null;
        $threshold = isset($context['adx_1m_lt_threshold']) && \is_numeric($context['adx_1m_lt_threshold'])
            ? (float)$context['adx_1m_lt_threshold']
            : $this->threshold;

        if (!\is_float($adx)) {
            return $this->result(self::NAME, false, null, $threshold, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = $adx < $threshold;

        return $this->result(self::NAME, $passed, $adx, $threshold, $this->baseMeta($context));
    }
}
