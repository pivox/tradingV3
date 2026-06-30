<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Contract\Provider\KlineProviderInterface;
use App\Provider\Context\ExchangeContext;

final class HyperliquidMarketGateway implements KlineProviderInterface
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
        throw $this->notReady(__METHOD__);
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
        throw $this->notReady(__METHOD__);
    }

    public function getLastKline(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): ?KlineDto {
        throw $this->notReady(__METHOD__);
    }

    public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void
    {
        throw $this->notReady(__METHOD__);
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
        throw $this->notReady(__METHOD__);
    }

    public function hasGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): bool {
        throw $this->notReady(__METHOD__);
    }

    /**
     * @return array<int,mixed>
     */
    public function getGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): array {
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
        return new HyperliquidProviderNotReadyException('hyperliquid_market_data_not_ready', $operation);
    }
}
