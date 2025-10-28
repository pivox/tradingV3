<?php

namespace App\Indicator\ConditionLoader\Cards\Validation;

use App\Indicator\ConditionLoader\Cards\AbstractCard;
use App\Indicator\ConditionLoader\ConditionRegistry;

class Side extends AbstractCard
{
    const LONG = 'long';
    const SHORT = 'short';

    private string $side = self::LONG; // 'long' | 'short'
    private ListSideElements $elements;

    public function __construct(
        private readonly ConditionRegistry $conditionRegistry
    ) {}

    public function fill(string|array $data): static
    {
        $list = is_array($data) ? $data : [$data];
        $this->elements = (new ListSideElements($this->conditionRegistry))->fill($list);
        return $this;
    }

    public function withSide(string $side): static
    {
        $this->side = $side;
        return $this;
    }

    public function evaluate(array $payload): array
    {
        $evaluation = $this->elements->evaluate($payload, ListSideElements::MODE_ALL);
        $this->isValid = $this->elements->isValid();

        return [
            'side' => $this->side,
            'passed' => $this->isValid,
            'conditions' => $evaluation['items'],
        ];
    }

    public function getElements(): ListSideElements
    {
        return $this->elements;
    }
}
