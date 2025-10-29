<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\TradeEntry\Types\Side;

final class OrderPlanModel
{
    public function __construct(
        public readonly string $symbol,
        public readonly Side $side,
        public readonly string $orderType,
        public readonly string $openType,
        public readonly int $orderMode,
        public readonly float $entry,
        public readonly float $stop,
        public readonly float $takeProfit,
        public readonly int $size,
        public readonly int $leverage,
        public readonly int $pricePrecision,
        public readonly float $contractSize
    ) {}
}
