<?php

namespace App\MtfValidator\ConditionLoader\Cards\Validation;

interface SideElementInterface
{
    public function evaluate(array $payload): array;
}
