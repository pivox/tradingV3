<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

enum FakeExchangeOperation: string
{
    case PlaceOrder = 'place_order';
    case CancelOrder = 'cancel_order';
    case GetBalances = 'get_balances';
    case GetOpenPositions = 'get_open_positions';
    case GetOpenOrders = 'get_open_orders';
    case GetOrdersSnapshot = 'get_orders_snapshot';
    case GetFillsSnapshot = 'get_fills_snapshot';
    case GetOrder = 'get_order';
    case GetOrderBookTop = 'get_order_book_top';
    case SetLeverage = 'set_leverage';
    case Reconcile = 'reconcile';

    public function isMutation(): bool
    {
        return \in_array($this, [self::PlaceOrder, self::CancelOrder, self::SetLeverage], true);
    }
}
