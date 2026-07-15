<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

interface FakeInstrumentProviderInterface
{
    public function find(string $symbol): ?FakeInstrument;
}
