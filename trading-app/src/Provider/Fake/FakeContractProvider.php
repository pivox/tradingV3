<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\ContractDto;

/**
 * Minimal fake contract provider exposing NO contracts/symbols.
 *
 * Because the fake exchange advertises zero active contracts, an MTF run on the
 * FAKE context resolves 0 symbols and completes as a trivial success — the
 * intended behaviour for the orchestrator demo (exchange=fake). All reads
 * return empty/neutral values and never throw.
 */
final class FakeContractProvider implements ContractProviderInterface
{
    /**
     * @return array<int, mixed>
     */
    public function getContracts(): array
    {
        return [];
    }

    public function getContractDetails(string $symbol): ?ContractDto
    {
        return null;
    }

    public function getLastPrice(string $symbol): ?float
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        return [];
    }

    /**
     * @return array<int, mixed>
     */
    public function getMarkPriceKline(string $symbol, int $step = 1, int $limit = 1, ?int $startTime = null, ?int $endTime = null): array
    {
        return [];
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getLeverageBrackets(string $symbol): array
    {
        return [];
    }

    /**
     * No-op sync: the fake exchange has no contracts to fetch or upsert.
     *
     * @param array<string>|null $symbols
     * @return array{upserted: int, total_fetched: int, errors: array<string>}
     */
    public function syncContracts(?array $symbols = null): array
    {
        return [
            'upserted' => 0,
            'total_fetched' => 0,
            'errors' => [],
        ];
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
