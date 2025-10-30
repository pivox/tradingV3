<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Dto\{ExecutionResult};
use App\TradeEntry\Policy\{IdempotencyPolicy, MakerOnlyPolicy};

final class ExecutionBox
{
    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly TpSlAttacher $tpSl,
        private readonly MakerOnlyPolicy $makerOnly,
        private readonly IdempotencyPolicy $idempotency,
        private readonly ExecutionLogger $logger,
    ) {}

    public function execute(OrderPlanModel $plan): ExecutionResult
    {
        $this->makerOnly->enforce($plan);
        $this->logger->debug('execution.start', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'entry' => $plan->entry,
            'quantity' => $plan->quantity,
            'leverage' => $plan->leverage,
            'order_type' => $plan->orderType,
            'mode' => $plan->orderMode,
        ]);

        $clientOrderId = $this->idempotency->newClientOrderId();

        $this->logger->debug('execution.leverage_submit', [
            'symbol' => $plan->symbol,
            'leverage' => $plan->leverage,
            'open_type' => $plan->openType,
        ]);
        $leverageResult = $this->providers->getOrderProvider()->submitLeverage($plan->symbol, $plan->leverage, $plan->openType);
        $this->logger->debug('execution.leverage_response', [
            'symbol' => $plan->symbol,
            'result' => $leverageResult,
        ]);

        $payload = $this->tpSl->presetInSubmitPayload($plan, $clientOrderId);
        
        // Mapper side BitMart (1,2,3,4) vers OrderSide enum
        $side = match($payload['side']) {
            1 => OrderSide::BUY,   // open_long
            2 => OrderSide::SELL,  // close_long
            3 => OrderSide::BUY,   // close_short
            4 => OrderSide::SELL,  // open_short
        };
        
        $this->logger->debug('execution.order_submit', $payload);
        $orderResult = $this->providers->getOrderProvider()->placeOrder(
            symbol: $payload['symbol'],
            side: $side,
            type: OrderType::from($payload['type']),
            quantity: (float) $payload['size'],
            price: isset($payload['price']) ? (float) $payload['price'] : null,
            stopPrice: null,
            options: [
                'mode' => $payload['mode'],
                'open_type' => $payload['open_type'],
                'client_order_id' => $payload['client_order_id'],
                'preset_take_profit_price' => $payload['preset_take_profit_price'],
                'preset_take_profit_price_type' => $payload['preset_take_profit_price_type'],
                'preset_stop_loss_price' => $payload['preset_stop_loss_price'],
                'preset_stop_loss_price_type' => $payload['preset_stop_loss_price_type'],
            ]
        );
        $this->logger->debug('execution.order_response', [
            'symbol' => $plan->symbol,
            'result' => $orderResult,
        ]);

        $this->logger->info('trade_entry.order_submitted', [
            'payload' => $payload,
            'leverage' => $leverageResult,
            'order' => $orderResult,
        ]);

        $orderId = $orderResult['data']['order_id'] ?? null;
        $statusCode = (int)($orderResult['code'] ?? 0);

        if ($statusCode !== 1000) {
            $this->logger->error('execution.order_error', [
                'symbol' => $plan->symbol,
                'code' => $statusCode,
                'result' => $orderResult,
            ]);
        }

        return new ExecutionResult(
            clientOrderId: $clientOrderId,
            exchangeOrderId: $orderId,
            status: $statusCode === 1000 ? 'submitted' : 'error',
            raw: ['leverage' => $leverageResult, 'order' => $orderResult],
        );
    }
}
