<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

interface ExchangeEventInterface
{
    public function eventType(): string;

    public function exchange(): Exchange;

    public function marketType(): MarketType;

    public function symbol(): string;

    public function occurredAt(): \DateTimeImmutable;

    /**
     * @return array<string,mixed>
     */
    public function payload(): array;
}
