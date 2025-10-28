<?php

declare(strict_types=1);

namespace App\RateLimiter\Service;

use Psr\Log\LoggerInterface;

/**
 * Rate limiter basé sur le token bucket
 * Implémentation temporaire pour permettre au système de fonctionner
 */
class TokenBucketRateLimiter
{
    public function __construct(
        private readonly LoggerInterface $logger
    ) {
    }

    public function canProceed(): bool
    {
        $this->logger->debug('[TokenBucketRateLimiter] Checking if can proceed');
        return true;
    }

    public function consume(int $tokens = 1): bool
    {
        $this->logger->debug('[TokenBucketRateLimiter] Consuming tokens', [
            'tokens' => $tokens,
        ]);
        return true;
    }
}
