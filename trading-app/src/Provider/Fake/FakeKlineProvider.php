<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Context\ExchangeContext;

/**
 * Minimal fake kline provider returning empty kline sets.
 *
 * The fake exchange has no market data. Reads return empty/neutral values and
 * saves are no-ops. In practice this provider is never exercised on the demo
 * path because the fake contract provider resolves 0 symbols, but it remains a
 * valid, non-throwing implementation of the interface.
 */
final class FakeKlineProvider implements KlineProviderInterface
{
    /**
     * @return KlineDto[]
     */
    public function getKlines(
        string $symbol,
        Timeframe $timeframe,
        int $limit = 490,
        ?ExchangeContext $context = null,
    ): array {
        return [];
    }

    /**
     * @return KlineDto[]
     */
    public function getKlinesInWindow(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit = 500,
        ?ExchangeContext $context = null,
    ): array {
        return [];
    }

    public function getLastKline(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): ?KlineDto {
        return null;
    }

    public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
    {
        // No-op: the fake exchange does not persist klines.
    }

    /**
     * @param KlineDto[] $klines
     */
    public function saveKlines(
        array $klines,
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): void {
        // No-op: the fake exchange does not persist klines.
    }

    public function hasGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): bool {
        return false;
    }

    /**
     * @return array<int, mixed>
     */
    public function getGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): array {
        return [];
    }

    /**
     * Convenience health probe consumed by MainProvider's health check.
     */
    public function healthCheck(): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return 'Fake';
    }
}
