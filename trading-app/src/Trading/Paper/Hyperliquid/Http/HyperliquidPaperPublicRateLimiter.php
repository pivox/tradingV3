<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

use Symfony\Component\RateLimiter\LimiterInterface;

final class HyperliquidPaperPublicRateLimiter
{
    private const MAX_WAIT_SECONDS = 2.0;

    public function __construct(private readonly LimiterInterface $limiter)
    {
    }

    public function acquireRequest(): void
    {
        $this->acquire(1);
    }

    public function acquireResponseRows(int $rows): void
    {
        if ($rows < 0) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_response_rows_invalid');
        }
        if ($rows === 0) {
            return;
        }

        $this->acquire((int) ceil($rows / 60));
    }

    private function acquire(int $tokens): void
    {
        $this->limiter->reserve($tokens, self::MAX_WAIT_SECONDS)->wait();
    }
}
