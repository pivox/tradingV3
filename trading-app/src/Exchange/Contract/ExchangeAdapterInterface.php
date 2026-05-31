<?php

declare(strict_types=1);

namespace App\Exchange\Contract;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;

interface ExchangeAdapterInterface
{
    public function exchange(): Exchange;

    public function marketType(): MarketType;

    public function capabilities(): ExchangeCapabilities;

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array;

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array;

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array;

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult;

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult;

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto;

    public function getOrderBookTop(string $symbol): SymbolBidAskDto;

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool;

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult;
}
