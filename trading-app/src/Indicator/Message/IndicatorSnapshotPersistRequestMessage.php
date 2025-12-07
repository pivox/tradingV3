<?php

declare(strict_types=1);

namespace App\Indicator\Message;

final class IndicatorSnapshotPersistRequestMessage
{
    /**
     * @param string[] $symbols
     * @param string[] $timeframes
     */
    public function __construct(
        public readonly array $symbols,
        public readonly array $timeframes,
        public readonly ?string $runId = null,
        public readonly ?string $profile = null,
        public readonly ?string $requestedAt = null,
    ) {
    }
}
