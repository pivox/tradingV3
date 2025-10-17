<?php

declare(strict_types=1);

namespace App\Infrastructure\RateLimiter;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class TokenBucketRateLimiter
{
    private int $tokens;
    private \DateTimeImmutable $lastRefill;
    private readonly int $capacity;
    private readonly int $refillRate; // tokens per second
    private readonly int $refillInterval; // milliseconds

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        int $capacity = 6,
        int $refillRate = 6,
        int $refillInterval = 1000 // 1 second
    ) {
        $this->capacity = $capacity;
        $this->refillRate = $refillRate;
        $this->refillInterval = $refillInterval;
        $this->tokens = $capacity;
        $this->lastRefill = $this->clock->now();
    }

    /**
     * Tente de consommer un token
     */
    public function tryConsume(int $tokens = 1): bool
    {
        $this->refill();
        
        if ($this->tokens >= $tokens) {
            $this->tokens -= $tokens;
            $this->logger->debug('[RateLimiter] Token consumed', [
                'tokens_consumed' => $tokens,
                'tokens_remaining' => $this->tokens,
                'capacity' => $this->capacity
            ]);
            return true;
        }
        
        $this->logger->debug('[RateLimiter] Token consumption blocked', [
            'tokens_requested' => $tokens,
            'tokens_available' => $this->tokens,
            'capacity' => $this->capacity
        ]);
        return false;
    }

    /**
     * Attend qu'un token soit disponible
     */
    public function waitForToken(int $tokens = 1, int $maxWaitMs = 5000): bool
    {
        $startTime = microtime(true);
        $maxWaitSeconds = $maxWaitMs / 1000;
        
        while (!$this->tryConsume($tokens)) {
            $elapsed = microtime(true) - $startTime;
            if ($elapsed >= $maxWaitSeconds) {
                $this->logger->warning('[RateLimiter] Max wait time exceeded', [
                    'tokens_requested' => $tokens,
                    'max_wait_ms' => $maxWaitMs,
                    'elapsed_ms' => $elapsed * 1000
                ]);
                return false;
            }
            
            // Attendre un peu avant de réessayer
            usleep(100000); // 100ms
        }
        
        return true;
    }

    /**
     * Remplit le bucket selon le taux de refill
     */
    private function refill(): void
    {
        $now = $this->clock->now();
        $elapsedMs = ($now->getTimestamp() - $this->lastRefill->getTimestamp()) * 1000 + 
                     ($now->format('v') - $this->lastRefill->format('v'));
        
        if ($elapsedMs >= $this->refillInterval) {
            $refillCycles = intval($elapsedMs / $this->refillInterval);
            $tokensToAdd = $refillCycles * $this->refillRate;
            
            $this->tokens = min($this->capacity, $this->tokens + $tokensToAdd);
            $this->lastRefill = $now;
            
            if ($tokensToAdd > 0) {
                $this->logger->debug('[RateLimiter] Bucket refilled', [
                    'tokens_added' => $tokensToAdd,
                    'tokens_total' => $this->tokens,
                    'capacity' => $this->capacity,
                    'elapsed_ms' => $elapsedMs
                ]);
            }
        }
    }

    /**
     * Obtient le nombre de tokens disponibles
     */
    public function getAvailableTokens(): int
    {
        $this->refill();
        return $this->tokens;
    }

    /**
     * Obtient la capacité du bucket
     */
    public function getCapacity(): int
    {
        return $this->capacity;
    }

    /**
     * Obtient le taux de refill
     */
    public function getRefillRate(): int
    {
        return $this->refillRate;
    }

    /**
     * Obtient l'intervalle de refill
     */
    public function getRefillInterval(): int
    {
        return $this->refillInterval;
    }

    /**
     * Obtient le temps jusqu'au prochain refill
     */
    public function getTimeUntilNextRefill(): int
    {
        $now = $this->clock->now();
        $elapsedMs = ($now->getTimestamp() - $this->lastRefill->getTimestamp()) * 1000 + 
                     ($now->format('v') - $this->lastRefill->format('v'));
        
        return max(0, $this->refillInterval - $elapsedMs);
    }

    /**
     * Obtient le statut du rate limiter
     */
    public function getStatus(): array
    {
        $this->refill();
        
        return [
            'tokens_available' => $this->tokens,
            'capacity' => $this->capacity,
            'refill_rate' => $this->refillRate,
            'refill_interval_ms' => $this->refillInterval,
            'time_until_next_refill_ms' => $this->getTimeUntilNextRefill(),
            'utilization_percent' => (($this->capacity - $this->tokens) / $this->capacity) * 100
        ];
    }

    /**
     * Réinitialise le bucket
     */
    public function reset(): void
    {
        $this->tokens = $this->capacity;
        $this->lastRefill = $this->clock->now();
        $this->logger->info('[RateLimiter] Bucket reset', [
            'tokens' => $this->tokens,
            'capacity' => $this->capacity
        ]);
    }

    /**
     * Ajuste la capacité du bucket
     */
    public function adjustCapacity(int $newCapacity): void
    {
        $oldCapacity = $this->capacity;
        $this->tokens = min($newCapacity, $this->tokens);
        
        $this->logger->info('[RateLimiter] Capacity adjusted', [
            'old_capacity' => $oldCapacity,
            'new_capacity' => $newCapacity,
            'tokens' => $this->tokens
        ]);
    }

    /**
     * Ajuste le taux de refill
     */
    public function adjustRefillRate(int $newRefillRate): void
    {
        $oldRefillRate = $this->refillRate;
        
        $this->logger->info('[RateLimiter] Refill rate adjusted', [
            'old_refill_rate' => $oldRefillRate,
            'new_refill_rate' => $newRefillRate
        ]);
    }
}
