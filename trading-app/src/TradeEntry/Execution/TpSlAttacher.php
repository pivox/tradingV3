<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Types\Side;

final class TpSlAttacher
{
    public function presetInSubmitPayload(OrderPlanModel $plan, string $clientOrderId): array
    {
        $payload = [
            'symbol' => $plan->symbol,
            'side' => $plan->side === Side::Long ? 1 : 4,
            'type' => $plan->orderType,
            'mode' => $plan->orderMode,
            'open_type' => $plan->openType,
            'size' => $plan->size,
            'client_order_id' => $clientOrderId,
            'preset_take_profit_price' => (string)$plan->takeProfit,
            'preset_take_profit_price_type' => 1,
            'preset_stop_loss_price' => (string)$plan->stop,
            'preset_stop_loss_price_type' => 1,
        ];

        if ($plan->orderType === 'limit') {
            $payload['price'] = (string)$plan->entry;
        }

        return $payload;
    }
}
