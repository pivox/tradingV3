<?php

declare(strict_types=1);

namespace App\Exchange\Ws;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final readonly class ExchangeWsIngestionResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public ?string $symbol,
        public int $rawEventsRead,
        public int $eventsProjected,
        public array $metadata = [],
    ) {
    }
}
