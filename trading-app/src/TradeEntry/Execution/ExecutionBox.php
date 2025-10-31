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

    public function execute(OrderPlanModel $plan, ?string $decisionKey = null): ExecutionResult
    {
        $this->makerOnly->enforce($plan);
        $this->logger->debug('execution.start', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'entry' => $plan->entry,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'order_type' => $plan->orderType,
            'mode' => $plan->orderMode,
            'decision_key' => $decisionKey,
        ]);

        $clientOrderId = $this->idempotency->newClientOrderId();

        $this->logger->debug('execution.leverage_submit', [
            'symbol' => $plan->symbol,
            'leverage' => $plan->leverage,
            'open_type' => $plan->openType,
            'decision_key' => $decisionKey,
        ]);
        $leverageResult = $this->providers->getOrderProvider()->submitLeverage($plan->symbol, $plan->leverage, $plan->openType);
        $this->logger->debug('execution.leverage_response', [
            'symbol' => $plan->symbol,
            'result' => $leverageResult,
            'decision_key' => $decisionKey,
        ]);

        $payload = $this->tpSl->presetInSubmitPayload($plan, $clientOrderId);
        
        // Mapper side BitMart (1,2,3,4) vers OrderSide enum
        $side = match($payload['side']) {
            1 => OrderSide::BUY,   // open_long
            2 => OrderSide::SELL,  // close_long
            3 => OrderSide::BUY,   // close_short
            4 => OrderSide::SELL,  // open_short
        };
        
        $payloadWithCorrelation = $payload + ['decision_key' => $decisionKey];
        // Extra visibility before submit
        $this->logger->debug('execution.presubmit_check', [
            'symbol' => $plan->symbol,
            'side_enum' => $side->value,
            'side_numeric' => $payload['side'],
            'type' => $payload['type'],
            'size' => (int)$payload['size'],
            'price' => $payload['price'] ?? null,
            'mode' => $payload['mode'],
            'open_type' => $payload['open_type'],
            'client_order_id' => $payload['client_order_id'],
            'decision_key' => $decisionKey,
        ]);
        $this->logger->debug('execution.order_submit', $payloadWithCorrelation);
        $orderResult = $this->providers->getOrderProvider()->placeOrder(
            symbol: $payload['symbol'],
            side: $side,
            type: OrderType::from($payload['type']),
            quantity: (float) $payload['size'],
            price: isset($payload['price']) ? (float) $payload['price'] : null,
            stopPrice: null,
            options: [
                'side' => $payload['side'], // Bitmart numeric side for entry (1=open_long, 4=open_short)
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
            'result' => $orderResult ? $orderResult->toArray() : null,
            'decision_key' => $decisionKey,
        ]);

        $this->logger->info('trade_entry.order_submitted', [
            'payload' => $payload,
            'leverage' => $leverageResult,
            'order' => $orderResult ? $orderResult->toArray() : null,
        ]);

        $orderId = $orderResult?->orderId;
        $isOk = $orderResult !== null;

        if (!$isOk) {
            $this->logger->error('execution.order_error', [
                'symbol' => $plan->symbol,
                'code' => 0,
                'result' => null,
                'decision_key' => $decisionKey,
            ]);
        }

        return new ExecutionResult(
            clientOrderId: $clientOrderId,
            exchangeOrderId: $orderId,
            status: $isOk ? 'submitted' : 'error',
            raw: ['leverage' => $leverageResult, 'order' => $orderResult ? $orderResult->toArray() : null],
        );
    }
}
