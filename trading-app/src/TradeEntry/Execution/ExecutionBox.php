<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Dto\{ExecutionResult};
use App\TradeEntry\Policy\{IdempotencyPolicy, OrderModePolicyInterface};
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Psr\Log\LoggerInterface;

final class ExecutionBox
{
    public function __construct(
        private readonly MainProviderInterface $providers,
        private readonly TpSlAttacher $tpSl,
        private readonly OrderModePolicyInterface $orderModePolicy,
        private readonly IdempotencyPolicy $idempotency,
        private readonly ExecutionLogger $logger,
        #[Autowire(service: 'monolog.logger.order_journey')] private readonly LoggerInterface $journeyLogger,
        #[Autowire(service: 'monolog.logger.order')] private readonly LoggerInterface $orderLogger,
    ) {}

    public function execute(OrderPlanModel $plan, ?string $decisionKey = null): ExecutionResult
    {
        $this->orderModePolicy->enforce($plan);
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
        $this->orderLogger->info('order.leverage.submit', [
            'symbol' => $plan->symbol,
            'leverage' => $plan->leverage,
            'open_type' => $plan->openType,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
        ]);
        $leverageResult = $this->providers->getOrderProvider()->submitLeverage($plan->symbol, $plan->leverage, $plan->openType);
        $this->logger->debug('execution.leverage_response', [
            'symbol' => $plan->symbol,
            'result' => $leverageResult,
            'decision_key' => $decisionKey,
        ]);
        $this->orderLogger->info('order.leverage.result', [
            'symbol' => $plan->symbol,
            'leverage' => $plan->leverage,
            'open_type' => $plan->openType,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'success' => $leverageResult,
        ]);

        $payload = $this->tpSl->presetInSubmitPayload($plan, $clientOrderId);

        // Mapper side BitMart (1,2,3,4) vers OrderSide enum
        $side = match($payload['side']) {
            1 => OrderSide::BUY,   // open_long
            2 => OrderSide::SELL,  // close_long
            3 => OrderSide::BUY,   // close_short
            4 => OrderSide::SELL,  // open_short
        };

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
            'enforced_type' => OrderType::LIMIT->value,
            'enforced_mode' => 1,
        ]);

        $orderPayload = $payload;
        $orderPayload['type'] = OrderType::LIMIT->value;
        $orderPayload['mode'] = 1;

        $this->orderLogger->debug('order.submit.payload_prepared', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'side_numeric' => $payload['side'],
            'order_type' => $orderPayload['type'],
            'size' => (int) $payload['size'],
            'price' => $orderPayload['price'] ?? null,
            'mode' => $orderPayload['mode'] ?? null,
            'open_type' => $payload['open_type'],
        ]);
        $this->journeyLogger->info('order_journey.execution.presubmit', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'side_numeric' => $payload['side'],
            'order_type' => $orderPayload['type'],
            'reason' => 'payload_prepared_for_submission',
        ]);

        $attemptLabel = 'limit-mode-1';
        $attemptIndex = 1;
        $attemptTotal = 1;
        $enforcedOrderType = OrderType::LIMIT;
        $orderOptions = $this->extractOrderOptions($orderPayload);
        $orderResult = null;

        $attemptPayload = $orderPayload + [
            'decision_key' => $decisionKey,
            'attempt' => $attemptLabel,
            'attempt_index' => $attemptIndex,
            'attempt_total' => $attemptTotal,
        ];

        $this->logger->debug('execution.order_submit', $attemptPayload);
        $this->orderLogger->info('order.submit.attempt', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'attempt' => $attemptLabel,
            'attempt_index' => $attemptIndex,
            'attempt_total' => $attemptTotal,
            'side_numeric' => $orderPayload['side'],
            'order_type' => $enforcedOrderType->value,
            'mode' => $orderOptions['mode'] ?? null,
            'size' => (float) $orderPayload['size'],
            'price' => isset($orderPayload['price']) ? (float) $orderPayload['price'] : null,
        ]);

        $this->journeyLogger->info('order_journey.execution.attempt', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'attempt' => $attemptLabel,
            'attempt_index' => $attemptIndex,
            'attempt_total' => $attemptTotal,
            'mode' => $orderOptions['mode'] ?? null,
            'order_type' => $enforcedOrderType->value,
            'reason' => 'submit_exchange_order',
        ]);

        $orderResult = $this->providers->getOrderProvider()->placeOrder(
            symbol: $orderPayload['symbol'],
            side: $side,
            type: $enforcedOrderType,
            quantity: (float)$orderPayload['size'],
            price: isset($orderPayload['price']) ? (float)$orderPayload['price'] : null,
            stopPrice: null,
            options: $orderOptions
        );

        $this->logger->debug('execution.order_response', [
            'symbol' => $plan->symbol,
            'result' => $orderResult ? $orderResult->toArray() : null,
            'decision_key' => $decisionKey,
            'attempt' => $attemptLabel,
        ]);

        if ($orderResult !== null) {
            $this->logger->info('positions.order_submit.success', [
                'result' => 'success',
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'attempt_index' => $attemptIndex,
                'attempt_total' => $attemptTotal,
                'order_id' => $orderResult->orderId,
            ]);
            $this->orderLogger->info('order.submit.success', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'order_id' => $orderResult->orderId,
            ]);
            $this->journeyLogger->info('order_journey.execution.order_ack', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'attempt' => $attemptLabel,
                'order_id' => $orderResult->orderId,
                'reason' => 'exchange_returned_order_id',
            ]);
        } else {
            $this->logger->warning('execution.order_attempt_failed', [
                'symbol' => $plan->symbol,
                'attempt' => $attemptLabel,
                'order_type' => $enforcedOrderType->value,
                'mode' => $orderOptions['mode'] ?? null,
                'decision_key' => $decisionKey,
            ]);
            $this->logger->warning('positions.order_submit.fail', [
                'result' => 'fail',
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'attempt_index' => $attemptIndex,
                'attempt_total' => $attemptTotal,
                'order_type' => $enforcedOrderType->value,
                'mode' => $orderOptions['mode'] ?? null,
            ]);
            $this->orderLogger->warning('order.submit.attempt_failed', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'order_type' => $enforcedOrderType->value,
                'mode' => $orderOptions['mode'] ?? null,
                'reason' => 'exchange_returned_null',
            ]);
            $this->journeyLogger->warning('order_journey.execution.attempt_failed', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'attempt' => $attemptLabel,
                'mode' => $orderOptions['mode'] ?? null,
                'reason' => 'exchange_returned_null',
            ]);
        }

        $this->logger->info('trade_entry.order_submitted', [
            'payload' => $orderPayload,
            'leverage' => $leverageResult,
            'order' => $orderResult ? $orderResult->toArray() : null,
            'attempt' => $orderResult !== null ? $attemptLabel : null,
        ]);
        $this->journeyLogger->info('order_journey.execution.summary', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'attempt' => $orderResult !== null ? $attemptLabel : null,
            'order_id' => $orderResult?->orderId,
            'reason' => 'execution_attempts_concluded',
        ]);
        $this->orderLogger->info('order.submit.summary', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'attempt' => $attemptLabel,
            'order_id' => $orderResult?->orderId,
            'status' => $orderResult !== null ? 'submitted' : 'failed',
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
            $this->logger->error('positions.order_submit.error', [
                'result' => 'error',
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'order_id' => $orderResult?->orderId,
                'reason' => 'all_attempts_failed',
            ]);
            $this->orderLogger->error('order.submit.error', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attemptLabel,
                'reason' => 'all_attempts_failed',
            ]);
            $this->journeyLogger->error('order_journey.execution.failed', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'reason' => 'all_attempts_failed',
            ]);
        }

        return new ExecutionResult(
            clientOrderId: $clientOrderId,
            exchangeOrderId: $orderId,
            status: $isOk ? 'submitted' : 'error',
            raw: [
                'leverage' => $leverageResult,
                'order' => $orderResult ? $orderResult->toArray() : null,
                'attempt' => $orderResult !== null ? $attemptLabel : null,
            ],
        );
    }

    /**
     * Prépare les options Bitmart pour l'appel placeOrder.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function extractOrderOptions(array $payload): array
    {
        $options = [
            'side' => $payload['side'],
            'mode' => $payload['mode'] ?? null,
            'open_type' => $payload['open_type'],
            'client_order_id' => $payload['client_order_id'],
        ];

        foreach ([
            'preset_take_profit_price',
            'preset_take_profit_price_type',
            'preset_stop_loss_price',
            'preset_stop_loss_price_type',
        ] as $key) {
            if (isset($payload[$key])) {
                $options[$key] = $payload[$key];
            }
        }

        return array_filter(
            $options,
            static fn($value) => $value !== null && $value !== ''
        );
    }
}
