<?php
declare(strict_types=1);

namespace App\TradeEntry\Execution;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderType;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Dto\{ExecutionResult};
use App\TradeEntry\Policy\{IdempotencyPolicy, OrderModePolicyInterface};
use App\TradeEntry\Message\CancelOrderMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
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
        private readonly MessageBusInterface $messageBus,
        #[Autowire('%trade_entry.order_timeout_seconds%')]
        private readonly int $orderTimeoutSeconds,
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
        ]);
        $this->orderLogger->debug('order.submit.payload_prepared', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'side_numeric' => $payload['side'],
            'order_type' => $payload['type'],
            'size' => (int) $payload['size'],
            'price' => $payload['price'] ?? null,
            'mode' => $payload['mode'] ?? null,
            'open_type' => $payload['open_type'],
        ]);
        $this->journeyLogger->info('order_journey.execution.presubmit', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'side_numeric' => $payload['side'],
            'order_type' => $payload['type'],
            'reason' => 'payload_prepared_for_submission',
        ]);

        $attempts = [];
        $attempts[] = [
            'label' => 'maker-limit',
            'payload' => $payload,
            'order_type' => OrderType::from($payload['type']),
            'price' => isset($payload['price']) ? (float) $payload['price'] : null,
            'options' => $this->extractOrderOptions($payload),
            'schedule_timeout' => $plan->orderType === 'limit',
        ];

        if ($plan->orderType === 'limit' && $plan->orderMode === 4) {
            $fallbackPayload = $payload;
            $fallbackPayload['type'] = OrderType::MARKET->value;
            $fallbackPayload['mode'] = 1; // Bitmart GTC (taker) pour fallback
            unset($fallbackPayload['price']);

            $attempts[] = [
                'label' => 'taker-market-fallback',
                'payload' => $fallbackPayload,
                'order_type' => OrderType::MARKET,
                'price' => null,
                'options' => $this->extractOrderOptions($fallbackPayload),
                'schedule_timeout' => false,
            ];
        }

        $attemptCount = count($attempts);
        $orderResult = null;
        $selectedAttempt = null;

        foreach ($attempts as $index => $attempt) {
            $attemptPayload = $attempt['payload'] + [
                'decision_key' => $decisionKey,
                'attempt' => $attempt['label'],
                'attempt_index' => $index + 1,
                'attempt_total' => $attemptCount,
            ];
            $this->logger->debug('execution.order_submit', $attemptPayload);
            $this->orderLogger->info('order.submit.attempt', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attempt['label'],
                'attempt_index' => $index + 1,
                'attempt_total' => $attemptCount,
                'side_numeric' => $attempt['payload']['side'],
                'order_type' => $attempt['order_type']->value,
                'mode' => $attempt['options']['mode'] ?? null,
                'size' => (float) $attempt['payload']['size'],
                'price' => $attempt['payload']['price'] ?? null,
            ]);

            $this->journeyLogger->info('order_journey.execution.attempt', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'attempt' => $attempt['label'],
                'attempt_index' => $index + 1,
                'attempt_total' => $attemptCount,
                'mode' => $attempt['options']['mode'] ?? null,
                'order_type' => $attempt['order_type']->value,
                'reason' => 'submit_exchange_order',
            ]);

            $currentResult = $this->providers->getOrderProvider()->placeOrder(
                symbol: $attempt['payload']['symbol'],
                side: $side,
                type: $attempt['order_type'],
                quantity: (float)$attempt['payload']['size'],
                price: $attempt['price'],
                stopPrice: null,
                options: $attempt['options']
            );

            $this->logger->debug('execution.order_response', [
                'symbol' => $plan->symbol,
                'result' => $currentResult ? $currentResult->toArray() : null,
                'decision_key' => $decisionKey,
                'attempt' => $attempt['label'],
            ]);

            if ($currentResult !== null) {
                $orderResult = $currentResult;
                $selectedAttempt = $attempt;
                $this->logger->info('positions.order_submit.success', [
                    'result' => 'success',
                    'symbol' => $plan->symbol,
                    'decision_key' => $decisionKey,
                    'client_order_id' => $clientOrderId,
                    'attempt' => $attempt['label'],
                    'attempt_index' => $index + 1,
                    'attempt_total' => $attemptCount,
                    'order_id' => $currentResult->orderId,
                ]);
                $this->orderLogger->info('order.submit.success', [
                    'symbol' => $plan->symbol,
                    'decision_key' => $decisionKey,
                    'client_order_id' => $clientOrderId,
                    'attempt' => $attempt['label'],
                    'order_id' => $currentResult->orderId,
                ]);
                $this->journeyLogger->info('order_journey.execution.order_ack', [
                    'symbol' => $plan->symbol,
                    'decision_key' => $decisionKey,
                    'attempt' => $attempt['label'],
                    'order_id' => $currentResult->orderId,
                    'reason' => 'exchange_returned_order_id',
                ]);
                break;
            }

            $this->logger->warning('execution.order_attempt_failed', [
                'symbol' => $plan->symbol,
                'attempt' => $attempt['label'],
                'order_type' => $attempt['order_type']->value,
                'mode' => $attempt['options']['mode'] ?? null,
                'decision_key' => $decisionKey,
            ]);
            $this->logger->warning('positions.order_submit.fail', [
                'result' => 'fail',
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attempt['label'],
                'attempt_index' => $index + 1,
                'attempt_total' => $attemptCount,
                'order_type' => $attempt['order_type']->value,
                'mode' => $attempt['options']['mode'] ?? null,
            ]);
            $this->orderLogger->warning('order.submit.attempt_failed', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $attempt['label'],
                'order_type' => $attempt['order_type']->value,
                'mode' => $attempt['options']['mode'] ?? null,
                'reason' => 'exchange_returned_null',
            ]);
            $this->journeyLogger->warning('order_journey.execution.attempt_failed', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'attempt' => $attempt['label'],
                'mode' => $attempt['options']['mode'] ?? null,
                'reason' => 'exchange_returned_null',
            ]);
        }

        $this->logger->info('trade_entry.order_submitted', [
            'payload' => $selectedAttempt['payload'] ?? $payload,
            'leverage' => $leverageResult,
            'order' => $orderResult ? $orderResult->toArray() : null,
            'attempt' => $selectedAttempt['label'] ?? null,
        ]);
        $this->journeyLogger->info('order_journey.execution.summary', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'attempt' => $selectedAttempt['label'] ?? null,
            'order_id' => $orderResult?->orderId,
            'reason' => 'execution_attempts_concluded',
        ]);
        $this->orderLogger->info('order.submit.summary', [
            'symbol' => $plan->symbol,
            'decision_key' => $decisionKey,
            'client_order_id' => $clientOrderId,
            'attempt' => $selectedAttempt['label'] ?? null,
            'order_id' => $orderResult?->orderId,
            'status' => $orderResult !== null ? 'submitted' : 'failed',
        ]);

        $orderId = $orderResult?->orderId;
        $isOk = $orderResult !== null;

        if ($isOk && $orderId !== null && $selectedAttempt !== null && $selectedAttempt['schedule_timeout']) {
            $delayMs = max(1_000, $this->orderTimeoutSeconds * 1_000);
            try {
                $this->messageBus->dispatch(
                    new CancelOrderMessage(
                        symbol: $plan->symbol,
                        exchangeOrderId: $orderId,
                        clientOrderId: $clientOrderId,
                        decisionKey: $decisionKey
                    ),
                    [new DelayStamp($delayMs)]
                );
                $this->logger->debug('execution.timeout_scheduled', [
                    'symbol' => $plan->symbol,
                    'exchange_order_id' => $orderId,
                    'client_order_id' => $clientOrderId,
                    'delay_ms' => $delayMs,
                    'decision_key' => $decisionKey,
                ]);
                $this->journeyLogger->debug('order_journey.execution.timeout_programmed', [
                    'symbol' => $plan->symbol,
                    'decision_key' => $decisionKey,
                    'order_id' => $orderId,
                    'delay_ms' => $delayMs,
                    'reason' => 'auto_cancel_timeout_scheduled',
                ]);
            } catch (\Throwable $dispatchError) {
                $this->logger->error('execution.timeout_schedule_failed', [
                    'symbol' => $plan->symbol,
                    'exchange_order_id' => $orderId,
                    'client_order_id' => $clientOrderId,
                    'decision_key' => $decisionKey,
                    'error' => $dispatchError->getMessage(),
                ]);
                $this->journeyLogger->error('order_journey.execution.timeout_schedule_failed', [
                    'symbol' => $plan->symbol,
                    'decision_key' => $decisionKey,
                    'order_id' => $orderId,
                    'reason' => 'failed_to_schedule_timeout',
                    'error' => $dispatchError->getMessage(),
                ]);
            }
        }

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
                'attempt' => $selectedAttempt['label'] ?? null,
                'order_id' => $orderResult?->orderId,
                'reason' => 'all_attempts_failed',
            ]);
            $this->orderLogger->error('order.submit.error', [
                'symbol' => $plan->symbol,
                'decision_key' => $decisionKey,
                'client_order_id' => $clientOrderId,
                'attempt' => $selectedAttempt['label'] ?? null,
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
                'attempt' => $selectedAttempt['label'] ?? null,
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
