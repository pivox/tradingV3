<?php

namespace App\Indicator\ConditionLoader\Cards\Rule;

use App\Indicator\ConditionLoader\Cards\AbstractCard;

class RuleElementField  extends AbstractCard implements RuleElementInterface
{
    public string $op;       // 'lt_fields' | 'gt_fields'
    /** @var string[] */
    public array $fields = [];

    public function fill(array|string $data): static
    {
        $this->op     = key($data);
        $this->fields = (array) current($data);  // ex: ['macd', 'macd_signal']
        return $this;
    }
}
