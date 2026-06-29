<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\ContractDto;

final class OkxMetadataProvider implements ContractProviderInterface
{
    /**
     * @return ContractDto[]
     */
    public function getContracts(): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    public function getContractDetails(string $symbol): ?ContractDto
    {
        throw $this->notImplemented(__METHOD__);
    }

    public function getLastPrice(string $symbol): ?float
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getMarkPriceKline(
        string $symbol,
        int $step = 1,
        int $limit = 1,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getLeverageBrackets(string $symbol): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @param array<string>|null $symbols
     * @return array{upserted: int, total_fetched: int, errors: array<string>}
     */
    public function syncContracts(?array $symbols = null): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    public function healthCheck(): bool
    {
        return false;
    }

    public function getProviderName(): string
    {
        return 'OKX';
    }

    private function notImplemented(string $operation): OkxProviderNotReadyException
    {
        return new OkxProviderNotReadyException('okx_metadata_not_implemented', $operation);
    }
}
