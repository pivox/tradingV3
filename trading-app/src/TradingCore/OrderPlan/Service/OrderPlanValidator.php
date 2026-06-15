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
        if (trim($plan->instrument) === '') {
            $invalidReasons[] = 'instrument_missing';
        }
        if (trim($plan->profile) === '') {
            $invalidReasons[] = 'profile_missing';
        }
        if (trim($plan->exchange) === '') {
            $invalidReasons[] = 'exchange_missing';
        }
        $marketType = strtolower(trim($plan->marketType));
        if ($marketType === '') {
            $invalidReasons[] = 'market_type_missing';
        } elseif (!\in_array($marketType, ['perpetual', 'spot'], true)) {
            $invalidReasons[] = 'market_type_invalid';
        }
        if (!\in_array(strtolower($plan->side), ['long', 'short'], true)) {
            $invalidReasons[] = 'side_invalid';
        }
        if (!\in_array(strtolower($plan->orderType), ['limit', 'market'], true)) {
            $invalidReasons[] = 'order_type_invalid';
        }
        $marginMode = strtolower(trim($plan->marginMode));
        if ($marginMode === '') {
            $invalidReasons[] = 'margin_mode_missing';
        } elseif (!\in_array($marginMode, ['isolated', 'cross'], true)) {
            $invalidReasons[] = 'margin_mode_invalid';
        }
        $timeInForce = strtolower(trim($plan->timeInForce));
        if ($timeInForce === '') {
            $invalidReasons[] = 'time_in_force_missing';
        } elseif (!\in_array($timeInForce, ['gtc', 'fok', 'ioc', 'post_only'], true)) {
            $invalidReasons[] = 'time_in_force_invalid';
        }
        if ($plan->entryPrice <= 0.0 || !\is_finite($plan->entryPrice)) {
            $invalidReasons[] = 'entry_price_not_positive';
        }
        if ($plan->quantity <= 0.0 || !\is_finite($plan->quantity)) {
            $invalidReasons[] = 'quantity_not_positive';
        }
        if ($plan->contractSize !== null && ($plan->contractSize <= 0.0 || !\is_finite($plan->contractSize))) {
            $invalidReasons[] = 'contract_size_not_positive';
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
                if ($stopLoss->stopPrice <= 0.0 || !\is_finite($stopLoss->stopPrice)) {
                    $invalidReasons[] = 'stop_price_not_positive';
                }
                if ($stopLoss->stopPct <= 0.0 || !\is_finite($stopLoss->stopPct)) {
                    $invalidReasons[] = 'stop_pct_not_positive';
                }
                if ($stopLoss->stopDistance <= 0.0 || !\is_finite($stopLoss->stopDistance)) {
                    $invalidReasons[] = 'stop_distance_not_positive';
                }
                $side = strtolower(trim($plan->side));
                if (
                    ($side === 'long' && $stopLoss->stopPrice >= $plan->entryPrice)
                    || ($side === 'short' && $stopLoss->stopPrice <= $plan->entryPrice)
                ) {
                    $invalidReasons[] = 'stop_loss_side_invalid';
                }
            }

            if ($marketType === 'perpetual') {
                if ($protection->liquidationCheck === null) {
                    $invalidReasons[] = 'liquidation_guard_missing';
                } elseif (!$protection->liquidationCheck->isSafe) {
                    $invalidReasons[] = 'liquidation_guard_unsafe';
                } else {
                    $liquidationCheck = $protection->liquidationCheck;
                    if (
                        $liquidationCheck->liquidationPrice === null
                        || $liquidationCheck->liquidationDistancePct === null
                        || $liquidationCheck->stopToLiquidationRatio === null
                        || $liquidationCheck->liquidationPrice <= 0.0
                        || $liquidationCheck->liquidationDistancePct <= 0.0
                        || $liquidationCheck->stopToLiquidationRatio <= 0.0
                        || !\is_finite($liquidationCheck->liquidationPrice)
                        || !\is_finite($liquidationCheck->liquidationDistancePct)
                        || !\is_finite($liquidationCheck->stopToLiquidationRatio)
                    ) {
                        $invalidReasons[] = 'liquidation_guard_data_invalid';
                    }

                    if ($stopLoss !== null) {
                        $side = strtolower(trim($plan->side));
                        if (
                            ($side === 'long' && $liquidationCheck->liquidationPrice !== null && $liquidationCheck->liquidationPrice >= $stopLoss->stopPrice)
                            || ($side === 'short' && $liquidationCheck->liquidationPrice !== null && $liquidationCheck->liquidationPrice <= $stopLoss->stopPrice)
                        ) {
                            $invalidReasons[] = 'liquidation_price_not_beyond_stop';
                        }
                    }
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
