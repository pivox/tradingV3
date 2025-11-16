<?php

declare(strict_types=1);

namespace App\Trading\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class SymbolSkippedEvent extends Event
{
    /**
     * @param array<string,mixed> $extra
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $reasonCode,
        public readonly ?string $runId = null,
        public readonly ?string $timeframe = null,
        public readonly ?string $configProfile = null,
        public readonly ?string $configVersion = null,
        public readonly array $extra = [],
    ) {}
}

