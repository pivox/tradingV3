<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\Contract\Provider\MainProviderInterface;
use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use Psr\Log\LoggerInterface;
 
final class ExecutionBox
{
    public function __construct(
        private MainProviderInterface $mainProvider,
        private LoggerInterface $logger
    ) {}

    /**
     * Exécute réellement l'ordre via le MainProvider
     */
    public function execute(OrderPlanModel $plan): ExecutionResult
    {
        try {
            $this->logger->info('TradeEntry: Starting order execution', [
                'symbol' => $plan->symbol,
                'side' => $plan->side->value,
                'price' => $plan->entryPrice,
                'quantity' => $plan->quantity
            ]);

            // Conversion du Side TradeEntry vers OrderSide
            $orderSide = match ($plan->side->value) {
                'long' => OrderSide::BUY,
                'short' => OrderSide::SELL,
            };

            // Placement de l'ordre principal
            $orderDto = $this->mainProvider->getOrderProvider()->placeOrder(
                symbol: $plan->symbol,
                side: $orderSide,
                type: OrderType::LIMIT,
                quantity: $plan->quantity,
                price: $plan->entryPrice,
                options: [
                    'sl_price' => $plan->slPrice,
                    'tp1_price' => $plan->tp1Price,
                    'tp1_size_pct' => $plan->tp1SizePct,
                ]
            );

            if ($orderDto === null) {
                $this->logger->error('TradeEntry: Failed to place order', [
                    'symbol' => $plan->symbol,
                    'side' => $plan->side->value
                ]);
                return ExecutionResult::cancelled('order_placement_failed');
            }

            $this->logger->info('TradeEntry: Order placed successfully', [
                'order_id' => $orderDto->orderId,
                'symbol' => $orderDto->symbol,
                'status' => $orderDto->status->value
            ]);

            return ExecutionResult::orderOpened([
                'order_id'   => $orderDto->orderId,
                'symbol'     => $orderDto->symbol,
                'side'       => $orderDto->side->value,
                'price'      => $orderDto->price?->toFloat(),
                'quantity'   => $orderDto->quantity->toFloat(),
                'sl_price'   => $plan->slPrice,
                'tp1_price'  => $plan->tp1Price,
                'tp1_size_pct' => $plan->tp1SizePct,
                'status'     => $orderDto->status->value,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('TradeEntry: Execution failed', [
                'error' => $e->getMessage(),
                'symbol' => $plan->symbol,
                'side' => $plan->side->value
            ]);

            return ExecutionResult::cancelled('execution_error', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
