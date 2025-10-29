<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\TradeEntry\Dto\OrderPlanModel;

final class LiquidationGuard
{
    public function __construct(private readonly float $minRatio = 3.0) {}

    public function assertSafe(OrderPlanModel $plan): void
    {
        $distance = abs($plan->entry - $plan->stop);
        if ($distance <= 0.0) {
            throw new \RuntimeException('Distance SL nulle');
        }

        // TODO: brancher formule exacte de liquidation si disponible via provider/contract specs
    }
}
