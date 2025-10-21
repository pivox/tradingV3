<?php

namespace App\Indicator\ConditionLoader\Cards\Validation;

interface SideElementInterface
{
    public function evaluate(array $payload): array;
}
