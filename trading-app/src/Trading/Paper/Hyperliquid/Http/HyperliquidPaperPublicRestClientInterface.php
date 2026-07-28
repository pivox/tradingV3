<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

interface HyperliquidPaperPublicRestClientInterface
{
    /** @return list<array<string, mixed>> */
    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = 1_048_576,
        int $maximumRetries = 5,
    ): array;
}
