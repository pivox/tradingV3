<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Http;

use Symfony\Component\RateLimiter\LimiterInterface;

final class OkxPaperPublicRateLimiter
{
    private const MAX_WAIT_SECONDS = 2.0;

    public function __construct(
        private readonly LimiterInterface $historyLimiter,
        private readonly LimiterInterface $snapshotLimiter,
    ) {
    }

    public function acquire(OkxPublicEndpoint $endpoint): void
    {
        $limiter = $endpoint->usesHistoryRateLimit()
            ? $this->historyLimiter
            : $this->snapshotLimiter;

        $limiter->reserve(1, self::MAX_WAIT_SECONDS)->wait();
    }
}
