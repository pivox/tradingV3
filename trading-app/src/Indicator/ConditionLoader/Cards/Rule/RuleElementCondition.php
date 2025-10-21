<?php

namespace App\Indicator\ConditionLoader\Cards\Rule;

use App\Indicator\ConditionLoader\Cards\AbstractCard;
use App\Indicator\ConditionLoader\Cards\ConditionCard;

class RuleElementCondition  extends AbstractCard implements RuleElementInterface
{
    public string $anyOfAllOf;          // 'any_of' | 'all_of'
    /** @var RuleElement[] */
    public array $elements = [];

    public function fill(array|string $data): static
    {
        $this->anyOfAllOf = key($data);
        $payload = current($data);

        if (is_array($payload)) {
            foreach ($payload as $key => $element) {
                if (is_int($key) && is_array($element)) {
                    $ek = key($element);
                    $ev = $element[$ek];
                    $this->elements[] = (new RuleElement())->fill([$ek => $ev]);
                } elseif (is_string($key)) {
                    $this->elements[] = (new RuleElement())->fill([$key => $element]);
                } elseif (is_string($element)) {
                    $this->elements[] = (new RuleElement())->fill($element);
                }
            }
        }
        return $this;
    }
}
