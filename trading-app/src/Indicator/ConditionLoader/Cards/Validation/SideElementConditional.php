<?php

namespace App\Indicator\ConditionLoader\Cards\Validation;

use App\Indicator\ConditionLoader\Cards\AbstractCard;

class SideElementConditional extends AbstractCard implements SideElementInterface
{
    const CONDITION_ANY_OF = 'any_of';
    const CONDITION_ALL_OF = 'all_of';

    private string $conditionType = self::CONDITION_ALL_OF;
    private ListSideElements $elements;

    /**
     * @throws \Exception
     */
    public function fill(string|array $data): static
    {
        $this->conditionType = (string) array_key_first($data);
        $payload = current($data);
        $payload = is_array($payload) ? $payload : [$payload];
        $this->elements = (new ListSideElements())->fill($payload);
        return $this;
    }

    public function evaluate(array $payload): array
    {
        $mode = $this->conditionType === self::CONDITION_ANY_OF
            ? ListSideElements::MODE_ANY
            : ListSideElements::MODE_ALL;

        $evaluation = $this->elements->evaluate($payload, $mode);
        $this->isValid = $this->elements->isValid();

        return [
            'type' => $this->conditionType,
            'mode' => $mode,
            'passed' => $this->isValid,
            'items' => $evaluation['items'],
        ];
    }
}
