<?php

namespace App\Indicator\ConditionLoader\Cards\Rule;

interface RuleElementInterface
{
    const ANY_OF = 'any_of';
    const ALL_OF = 'all_of';
    const LT_FIELDS = 'lt_fields';
    const GT_FIELDS = 'gt_fields';
    const INCREASING = 'increasing';
    const DECREASING = 'decreasing';
    const LT = 'lt';
    const GT = 'gt';
    const OP = 'op';
}
