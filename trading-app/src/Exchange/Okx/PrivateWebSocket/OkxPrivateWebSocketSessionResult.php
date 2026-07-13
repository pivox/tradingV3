<?php

declare(strict_types=1);

namespace App\Exchange\Okx\PrivateWebSocket;

use App\Exchange\Event\ExchangeEventInterface;

final readonly class OkxPrivateWebSocketSessionResult
{
    /**
     * @param list<array<string, mixed>> $outgoingCommands
     * @param list<ExchangeEventInterface> $normalizedEvents
     */
    public function __construct(
        public array $outgoingCommands = [],
        public array $normalizedEvents = [],
    ) {
    }
}
