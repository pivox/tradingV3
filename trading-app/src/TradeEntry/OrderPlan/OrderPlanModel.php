<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\TradeEntry\Types\Side;

final class OrderPlanModel
{
    public function __construct(
        public string $symbol,
        public Side $side,
        public float $entryPrice,
        public float $quantity,
        public float $slPrice,
        public float $tp1Price,
        public int $tp1SizePct
    ) {}
}
