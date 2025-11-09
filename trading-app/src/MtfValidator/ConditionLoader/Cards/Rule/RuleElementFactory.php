<?php

namespace App\MtfValidator\ConditionLoader\Cards\Rule;

class RuleElementFactory
{
    public static function make(array|string $data): RuleElementInterface
    {
        // $data = ['rsi_lt_70' => ['lt' => 70]]  OU  ['any_of' => [ ... ]]
        $condition = key($data);
        $spec      = current($data);

        $firstKey = is_array($spec) ? array_key_first($spec) : null;

        return match ($firstKey) {
            RuleElementInterface::ANY_OF, RuleElementInterface::ALL_OF, 'any_of', 'all_of'
            => (new RuleElementCondition())->fill([$firstKey => $spec[$firstKey]]),

            RuleElementInterface::LT_FIELDS, RuleElementInterface::GT_FIELDS, 'lt_fields', 'gt_fields'
            => (new RuleElementField())->fill([$firstKey => $spec[$firstKey]]),

            RuleElementInterface::INCREASING, RuleElementInterface::DECREASING, 'increasing', 'decreasing'
            => (new RuleElementTrend())->fill([$firstKey => $spec[$firstKey]]),

            RuleElementInterface::LT, RuleElementInterface::GT, 'lt', 'gt'
            => (new RuleElementOperation())->fill([$firstKey => $spec[$firstKey]]),

            RuleElementInterface::OP, 'op'
            => (new RuleElementCustomOp())->fill($spec),

            default => (new RuleElement())->fill($data), // fallback: simple alias/nom
        };
    }
}
