<?php

declare(strict_types=1);

namespace App\Service\Trading\Opening\Leverage;

final class LeveragePlan
{
    public function __construct(
        public readonly int $target,
        public readonly int $current,
        public readonly int $sizingFloor
    ) {
    }
}
