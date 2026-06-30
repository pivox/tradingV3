<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;

final class HyperliquidAccountGateway implements AccountProviderInterface
{
    public function getAccountInfo(): ?AccountDto
    {
        throw $this->notReady(__METHOD__);
    }

    public function getAccountBalance(string $basicCurrency = 'USDT'): float
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositionsOrFail(?string $symbol = null): array
    {
        throw $this->notReady(__METHOD__);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return array<int,mixed>
     */
    public function getTradeHistory(string $symbol, int $limit = 100): array
    {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return array<int,mixed>
     */
    public function getTrades(
        ?string $symbol = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return array<int,mixed>
     */
    public function getTransactionHistory(
        ?string $symbol = null,
        ?int $flowType = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return array<string,mixed>
     */
    public function getTradingFees(string $symbol): array
    {
        throw $this->notReady(__METHOD__);
    }

    public function healthCheck(): bool
    {
        return false;
    }

    public function getProviderName(): string
    {
        return 'Hyperliquid';
    }

    private function notReady(string $operation): HyperliquidProviderNotReadyException
    {
        return new HyperliquidProviderNotReadyException('hyperliquid_account_not_ready', $operation);
    }
}
