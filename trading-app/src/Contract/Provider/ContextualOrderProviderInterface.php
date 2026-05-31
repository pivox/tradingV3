<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Contract\Provider\Dto\OrderDto;
use App\Provider\Context\ExchangeContext;

interface ContextualOrderProviderInterface extends OrderProviderInterface
{
    public function cancelOrder(string $symbol, string $orderId, ?ExchangeContext $context = null): bool;

    public function getOrder(string $symbol, string $orderId, ?ExchangeContext $context = null): ?OrderDto;

    public function getOpenOrders(?string $symbol = null, ?ExchangeContext $context = null): array;

    public function getOrderHistory(string $symbol, int $limit = 100, ?ExchangeContext $context = null): array;
}
