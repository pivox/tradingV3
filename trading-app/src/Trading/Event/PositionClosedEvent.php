<?php

declare(strict_types=1);

namespace App\Trading\Event;

use App\Trading\Dto\PositionHistoryEntryDto;
use Symfony\Contracts\EventDispatcher\Event;

final class PositionClosedEvent extends Event
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly PositionHistoryEntryDto $positionHistory,
        public readonly ?string $runId = null,
        public readonly ?string $exchange = null,
        public readonly ?string $accountId = null,
        public readonly ?string $reasonCode = null,
        public readonly array $extra = [],
    ) {}
}

