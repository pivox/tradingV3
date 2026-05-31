<?php

declare(strict_types=1);

namespace App\Exchange\Event;

interface ExchangeEventNormalizerInterface
{
    public function supports(mixed $event): bool;

    /**
     * @return ExchangeEventInterface[]
     */
    public function normalize(mixed $event): array;
}
