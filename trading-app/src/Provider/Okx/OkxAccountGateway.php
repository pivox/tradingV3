<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Contract\Provider\AccountProviderInterface;
use App\Contract\Provider\Dto\AccountDto;
use App\Contract\Provider\Dto\PositionDto;

final class OkxAccountGateway implements AccountProviderInterface
{
    public function __construct(
        private readonly OkxPositionGateway $positions = new OkxPositionGateway(),
    ) {
    }

    public function getAccountInfo(): ?AccountDto
    {
        throw $this->notImplemented(__METHOD__);
    }

    public function getAccountBalance(string $basicCurrency = 'USDT'): float
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->positions->getOpenPositions($symbol);
    }

    /**
     * @return PositionDto[]
     */
    public function getOpenPositionsOrFail(?string $symbol = null): array
    {
        return $this->positions->getOpenPositionsOrFail($symbol);
    }

    public function getPosition(string $symbol): ?PositionDto
    {
        return $this->positions->getPosition($symbol);
    }

    /**
     * @return array<int, mixed>
     */
    public function getTradeHistory(string $symbol, int $limit = 100): array
    {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getTrades(
        ?string $symbol = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return array<int, mixed>
     */
    public function getTransactionHistory(
        ?string $symbol = null,
        ?int $flowType = null,
        int $limit = 100,
        ?int $startTime = null,
        ?int $endTime = null,
    ): array {
        throw $this->notImplemented(__METHOD__);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTradingFees(string $symbol): array
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
        return new OkxProviderNotReadyException('okx_account_not_implemented', $operation);
    }
}
