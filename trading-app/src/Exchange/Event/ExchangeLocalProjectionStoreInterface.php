<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeOrderDto;

interface ExchangeLocalProjectionStoreInterface
{
    public function hasOrder(ExchangeOrderDto $order): bool;

    /**
     * @return array<int,array{symbol: string, side: \App\Exchange\Enum\ExchangePositionSide, size: float}>
     */
    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array;

    public function project(ExchangeEventInterface $event): void;
}
