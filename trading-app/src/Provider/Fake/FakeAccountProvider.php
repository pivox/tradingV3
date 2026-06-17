<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;

/**
 * Minimal fake account provider modelling an empty exchange account.
 *
 * Every read returns a neutral/empty value (zero balance, no positions,
 * no trades). Nothing throws. This makes the FAKE context safe for the
 * orchestrator demo path (exchange=fake) where no live state exists.
 */
final class FakeAccountProvider implements AccountProviderInterface
{
    public function getAccountInfo(): ?AccountDto
    {
        // No account on the fake exchange.
        return null;
    }

    public function getAccountBalance(string $basicCurrency = 'USDT'): float
    {
        return 0.0;
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return [];
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        return null;
    }

    /**
     * @return array<int, mixed>
     */
    public function getTradeHistory(string $symbol, int $limit = 100): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getTrades(?string $symbol = null, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getTransactionHistory(?string $symbol = null, ?int $flowType = null, int $limit = 100, ?int $startTime = null, ?int $endTime = null): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTradingFees(string $symbol): array
    {
        return [];
    }

    /**
     * Convenience health probe consumed by MainProvider's detailed health check.
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
