<?php

declare(strict_types=1);

namespace App\Indicator\Condition;

use App\Indicator\Attribute\AsIndicatorCondition;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AsIndicatorCondition(timeframes: ['1m'], name: self::NAME)]
#[AutoconfigureTag('app.indicator.condition')]
#[AsTaggedItem(index: self::NAME)]
final class SpreadBpsLteCondition extends AbstractCondition
{
    public const NAME = 'spread_bps_lte';

    public function getName(): string
    {
        return self::NAME;
    }

    /** @param array<string, mixed> $context */
    public function evaluate(array $context): ConditionResult
    {
        $proof = MicrostructureProof::validate($context, 'spread_bps', 'max_spread_bps');
        if ($proof['value'] === null || $proof['threshold'] === null) {
            return $this->result(self::NAME, false, null, null, $this->baseMeta($context, $proof['meta']));
        }

        return $this->result(
            self::NAME,
            $proof['value'] <= $proof['threshold'],
            $proof['value'],
            $proof['threshold'],
            $this->baseMeta($context),
        );
    }
}
