<?php

declare(strict_types=1);

namespace App\Trading\Event;

use App\Trading\Dto\PositionDto;
use Symfony\Contracts\EventDispatcher\Event;

final class PositionOpenedEvent extends Event
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly PositionDto $position,
        public readonly ?string $runId = null,
        public readonly ?string $exchange = null,
        public readonly ?string $accountId = null,
        public readonly array $extra = [],
    ) {}
}

