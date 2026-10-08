<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

interface OkxDemoTripInterface
{
    public function isTripped(): bool;

    public function reason(): ?string;

    public function trip(string $reason): void;
}
