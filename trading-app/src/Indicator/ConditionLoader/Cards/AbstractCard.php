<?php

namespace App\Indicator\ConditionLoader\Cards;

use App\Indicator\Condition\ConditionInterface;

abstract class AbstractCard
{
    protected bool $isValid = false;
    protected ?ConditionInterface $condition = null;
    public  abstract function fill(string|array $data): static;

    public function isValid(): bool
    {
        return $this->isValid;
    }

}
