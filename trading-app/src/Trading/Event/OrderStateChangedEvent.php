<?php

declare(strict_types=1);

namespace App\Trading\Event;

use App\Trading\Dto\OrderDto;
use Symfony\Contracts\EventDispatcher\Event;

final class OrderStateChangedEvent extends Event
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly OrderDto $order,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly ?string $runId = null,
        public readonly ?string $exchange = null,
        public readonly ?string $accountId = null,
        public readonly array $extra = [],
    ) {}
}

