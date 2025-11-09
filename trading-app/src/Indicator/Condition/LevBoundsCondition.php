<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['4h', '1h', '15m', '5m', '1m'], name: LevBoundsCondition::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: LevBoundsCondition::NAME)]
final class LevBoundsCondition extends AbstractCondition
{
    public const NAME = 'lev_bounds';

    public function __construct(
        private readonly float $minLev = 2.0,
        private readonly float $maxLev = 20.0,
    ) {}

    public function getName(): string
    {
        return self::NAME;
    }

    public function evaluate(array $context): ConditionResult
    {
        $lev = $context['leverage'] ?? null;

        if (!is_float($lev)) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, [
                'missing_data' => true,
            ]));
        }

        $passed = ($lev >= $this->minLev) && ($lev <= $this->maxLev);

        return $this->result(self::NAME, $passed, $lev, null, $this->baseMeta($context, [
            'leverage' => $lev,
            'min'      => $this->minLev,
            'max'      => $this->maxLev,
        ]));
    }
}
