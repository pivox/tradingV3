<?php

declare(strict_types=1);

namespace App\Trading\Storage;

use App\Trading\Dto\OrderDto;

interface OrderStateRepositoryInterface
{
    public function findLocalOrder(string $symbol, string $orderId): ?OrderDto;

    /**
     * @param string[]|null $symbols
     * @return OrderDto[]
     */
    public function findLocalOpenOrders(?array $symbols = null): array;

    public function saveOrder(OrderDto $order): void;
}


