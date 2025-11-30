<?php

declare(strict_types=1);

namespace App\Indicator\Message;

final class IndicatorSnapshotProjectionMessage
{
    /**
     * @param array<string,mixed> $values
     */
    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        public readonly string $klineTime,
        public readonly array $values,
        public readonly string $source = 'PHP',
        public readonly ?string $runId = null,
    ) {
    }
}
