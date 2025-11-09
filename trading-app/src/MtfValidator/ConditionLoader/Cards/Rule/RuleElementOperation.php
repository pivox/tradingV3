<?php

namespace App\MtfValidator\ConditionLoader\Cards\Rule;

use App\MtfValidator\ConditionLoader\Cards\AbstractCard;
use App\MtfValidator\ConditionLoader\Cards\Rule\RuleElementInterface;

class RuleElementOperation extends AbstractCard implements RuleElementInterface
{
    public string $operation;      // 'lt' | 'gt'
    public RuleElement $ruleElement;

    public function fill(array|string $data): static
    {
        $this->operation   = key($data);
        $this->ruleElement = (new RuleElement())->fill($data[$this->operation]);
        return $this;
    }
}
