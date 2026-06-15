<?php
declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Service;

use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Dto\OrderPlanValidationResult;
use App\TradingCore\OrderPlan\Enum\OrderPlanStatus;

final class OrderPlanValidator
{
    public function validate(OrderPlan $plan): OrderPlanValidationResult
    {
        $invalidReasons = [];
        $warnings = [];

        if (trim($plan->symbol) === '') {
            $invalidReasons[] = 'symbol_missing';
        }
        if (!\in_array(strtolower($plan->side), ['long', 'short'], true)) {
            $invalidReasons[] = 'side_invalid';
        }
        if (!\in_array(strtolower($plan->orderType), ['limit', 'market'], true)) {
            $invalidReasons[] = 'order_type_invalid';
        }
        if (trim($plan->marginMode) === '') {
            $invalidReasons[] = 'margin_mode_missing';
        }
        if (trim($plan->timeInForce) === '') {
            $invalidReasons[] = 'time_in_force_missing';
        }
        if ($plan->entryPrice <= 0.0) {
            $invalidReasons[] = 'entry_price_not_positive';
        }
        if ($plan->quantity <= 0.0) {
            $invalidReasons[] = 'quantity_not_positive';
        }
        if ($plan->leverage <= 0) {
            $invalidReasons[] = 'leverage_not_positive';
        }
        if ($plan->clientOrderId === null || trim($plan->clientOrderId) === '') {
            $invalidReasons[] = 'client_order_id_missing';
        }
        if ($plan->idempotencyKey === null || trim($plan->idempotencyKey) === '') {
            $invalidReasons[] = 'idempotency_key_missing';
        }

        $protection = $plan->protectionPlan;
        if ($protection === null) {
            $invalidReasons[] = 'protection_plan_missing';
        } else {
            $warnings = array_values(array_unique(array_merge($warnings, $protection->warnings)));
            if (!$protection->isValid) {
                $invalidReasons[] = 'protection_plan_invalid';
                $invalidReasons = array_merge($invalidReasons, $protection->invalidReasons);
            }

            $stopLoss = $protection->stopLoss;
            if ($stopLoss === null) {
                $invalidReasons[] = 'stop_loss_missing';
            } else {
                if (!$stopLoss->isFullSize) {
                    $invalidReasons[] = 'stop_loss_not_full_size';
                }
                if ($stopLoss->stopPct <= 0.0) {
                    $invalidReasons[] = 'stop_pct_not_positive';
                }
            }
        }

        $invalidReasons = array_values(array_unique($invalidReasons));
        $status = $invalidReasons === [] ? OrderPlanStatus::Valid : OrderPlanStatus::Invalid;

        return new OrderPlanValidationResult(
            status: $status,
            isExecutable: $status === OrderPlanStatus::Valid,
            invalidReasons: $invalidReasons,
            warnings: $warnings,
        );
    }
}
