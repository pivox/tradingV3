<?php

declare(strict_types=1);

namespace App\MtfValidator\Service\Application;

/**
 * Lightweight facade exposing timeframe cache helpers from the run coordinator.
 *
 * This allows controllers/tests to share the same cache semantics without
 * needing to depend on the heavy coordinator directly.
 */
final class TimeframeCacheService
{
    public function __construct(private readonly RunCoordinator $runCoordinator)
    {
    }

    public function buildCacheKey(string $symbol, string $timeframe): string
    {
        return $this->runCoordinator->buildTfCacheKey($symbol, $timeframe);
    }

    public function computeExpiresAt(string $timeframe): \DateTimeImmutable
    {
        return $this->runCoordinator->computeTfExpiresAt($timeframe);
    }

    public function shouldReuseCachedResult(?array $cached, string $timeframe, string $symbol): bool
    {
        return $this->runCoordinator->shouldReuseCachedResult($cached, $timeframe, $symbol);
    }

    public function getCachedResult(string $symbol, string $timeframe, ?bool &$hadRecord = null): ?array
    {
        return $this->runCoordinator->getCachedTfResult($symbol, $timeframe, $hadRecord);
    }

    public function putCachedResult(string $symbol, string $timeframe, array $result): void
    {
        $this->runCoordinator->putCachedTfResult($symbol, $timeframe, $result);
    }

    public function isGraceWindowResult(array $result): bool
    {
        return $this->runCoordinator->isGraceWindowResult($result);
    }

    public function parseKlineTime(mixed $raw): ?\DateTimeImmutable
    {
        return $this->runCoordinator->parseKlineTime($raw);
    }
}
