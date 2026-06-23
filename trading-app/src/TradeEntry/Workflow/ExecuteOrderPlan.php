<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\Entity\OrderIntent;
use App\Exchange\Adapter\BitmartLegacyOrderMapper;
use App\Service\OrderIntentManager;
use App\Trading\Lineage\TradeLineageManager;
use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\TradeEntry\Policy\IdempotencyPolicy;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Provider\Context\ExchangeContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExecuteOrderPlan
{
    private readonly BitmartLegacyOrderMapper $bitmartOrders;

    public function __construct(
        private readonly ExecutionBox $execution,
        private readonly ExchangeExecutionService $exchangeExecution,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
        private readonly ?OrderIntentManager $orderIntentManager = null,
        private readonly ?IdempotencyPolicy $idempotency = null,
        private readonly ?TradeLineageManager $tradeLineageManager = null,
        ?BitmartLegacyOrderMapper $bitmartOrders = null,
    ) {
        $this->bitmartOrders = $bitmartOrders ?? new BitmartLegacyOrderMapper();
    }

    public function __invoke(
        OrderPlanModel $plan,
        ?string $decisionKey = null,
        ?LifecycleContextBuilder $contextBuilder = null,
        ?string $mode = null,
        ?string $executionTf = null
    ): ExecutionResult
    {
        $this->positionsLogger->info('execute_order_plan.start', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'order_type' => $plan->orderType,
            'order_mode' => $plan->orderMode,
            'mode' => $plan->orderMode,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'entry' => $plan->entry,
            'decision_key' => $decisionKey,
            'reason' => 'send_plan_to_execution_box',
        ]);

        $intent = null;
        $clientOrderId = null;

        try {
            $useApiFirstExecution = $this->shouldUseApiFirstExecution($plan);
            $executionPlan = $useApiFirstExecution
                ? $this->exchangeExecution->preparePlan($plan, $mode, $executionTf, $decisionKey)
                : $this->execution->preparePlan($plan, $mode, $executionTf, $decisionKey);

            if ($decisionKey !== null && trim($decisionKey) !== '' && $this->orderIntentManager !== null) {
                $clientOrderId = ($this->idempotency ?? new IdempotencyPolicy())->newClientOrderId($decisionKey);
                $reservation = $this->orderIntentManager->reserveIntent(
                    orderParams: $this->intentOrderParams($executionPlan, $decisionKey, $clientOrderId),
                    quantization: $this->intentQuantization($executionPlan),
                    rawInputs: [
                        'source' => 'execute_order_plan',
                        'decision_key' => $decisionKey,
                        'mode' => $mode,
                        'execution_tf' => $executionTf,
                        'plan' => $this->intentPlanPayload($executionPlan),
                    ],
                );

                if ($reservation->blocked) {
                    $this->positionsLogger->warning('execute_order_plan.idempotent_replay_blocked', [
                        'symbol' => $executionPlan->symbol,
                        'decision_key' => $decisionKey,
                        'client_order_id' => $reservation->intent->getClientOrderId(),
                        'exchange_order_id' => $reservation->intent->getExchangeOrderId() ?? $reservation->intent->getOrderId(),
                        'order_intent_id' => $reservation->intent->getId(),
                        'status' => $reservation->intent->getStatus(),
                        'reason' => $reservation->reason,
                    ]);

                    return new ExecutionResult(
                        clientOrderId: $reservation->intent->getClientOrderId(),
                        exchangeOrderId: $reservation->intent->getExchangeOrderId() ?? $reservation->intent->getOrderId(),
                        status: ExecutionResult::STATUS_SKIPPED,
                        raw: [
                            'reason' => $reservation->reason ?? 'idempotent_replay',
                            'decision_key' => $decisionKey,
                            'order_intent_id' => $reservation->intent->getId(),
                            'existing_status' => $reservation->intent->getStatus(),
                        ] + $reservation->metadata,
                    );
                }

                $intent = $reservation->intent;
                if ($this->tradeLineageManager !== null) {
                    $lineage = $this->tradeLineageManager->ensureForIntent($intent, $contextBuilder?->toArray() ?? []);
                    $contextBuilder?->merge($this->tradeLineageManager->lifecycleExtra($lineage));
                }

                $validationErrors = $this->orderIntentManager->validateOrderParams($this->intentOrderParams($executionPlan, $decisionKey, $clientOrderId));
                if (!$this->orderIntentManager->validateIntent($intent, $validationErrors)) {
                    return new ExecutionResult(
                        clientOrderId: $intent->getClientOrderId(),
                        exchangeOrderId: null,
                        status: ExecutionResult::STATUS_SKIPPED,
                        raw: [
                            'reason' => 'order_intent_validation_failed',
                            'decision_key' => $decisionKey,
                            'order_intent_id' => $intent->getId(),
                            'validation_errors' => $validationErrors,
                        ],
                    );
                }

                $this->orderIntentManager->markReadyToSend($intent);
            }

            $result = $useApiFirstExecution
                ? $this->exchangeExecution->execute($executionPlan, $decisionKey, $mode, $executionTf, $clientOrderId, $intent?->getId(), true)
                : $this->execution->execute($executionPlan, $decisionKey, $contextBuilder, $mode, $executionTf, $clientOrderId, $intent?->getId(), true);

            if ($intent instanceof OrderIntent && $this->orderIntentManager !== null) {
                $this->syncIntentAfterExecution($intent, $result);
            }

            $context = [
                'symbol' => $executionPlan->symbol,
                'side' => $executionPlan->side->value,
                'status' => $result->status,
                'client_order_id' => $result->clientOrderId,
                'exchange_order_id' => $result->exchangeOrderId,
            ];

            if ($this->isSubmitSuccess($result->status)) {
                $this->positionsLogger->info('execute_order_plan.submitted', $context + ['decision_key' => $decisionKey]);
            } elseif ($result->status === ExecutionResult::STATUS_ENTRY_SUBMITTED) {
                $this->positionsLogger->info('execute_order_plan.entry_submitted', $context + ['decision_key' => $decisionKey, 'raw' => $result->raw]);
            } elseif ($result->status === ExecutionResult::STATUS_SKIPPED) {
                $this->positionsLogger->warning('execute_order_plan.skipped', $context + ['decision_key' => $decisionKey, 'raw' => $result->raw]);
            } else {
                $this->positionsLogger->error('execute_order_plan.failed', $context + ['decision_key' => $decisionKey, 'raw' => $result->raw]);
            }

            $this->positionsLogger->info('execute_order_plan.result', $context + [
                'decision_key' => $decisionKey,
                'reason' => 'execution_box_finished',
            ]);

            return $result;
        } catch (\Throwable $e) {
            if ($intent instanceof OrderIntent && $this->orderIntentManager !== null) {
                $this->markIntentFailedAfterException($intent, $e);
            }

            $this->positionsLogger->error('execute_order_plan.exception', [
                'symbol' => $plan->symbol,
                'message' => $e->getMessage(),
                'decision_key' => $decisionKey,
                'reason' => 'execution_box_threw',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function shouldUseApiFirstExecution(OrderPlanModel $plan): bool
    {
        $context = ExchangeContext::resolve($plan->exchangeContext);

        return $plan->exchangeContext !== null || !$context->isLegacyDefault();
    }

    private function isSubmitSuccess(string $status): bool
    {
        return \in_array($status, [
            ExecutionResult::STATUS_SUBMITTED,
            ExecutionResult::STATUS_SUBMITTED_PROTECTED,
        ], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function intentOrderParams(OrderPlanModel $plan, string $decisionKey, string $clientOrderId): array
    {
        $context = ExchangeContext::resolve($plan->exchangeContext);

        return [
            'exchange' => $context->exchange->value,
            'market_type' => $context->marketType->value,
            'decision_key' => $decisionKey,
            'symbol' => $plan->symbol,
            'timeframe' => $this->parsedDecisionKeyPart($decisionKey, 3),
            'candle_open_ts' => $this->parsedDecisionKeyPart($decisionKey, 4),
            'strategy_profile' => $this->parsedDecisionKeyPart($decisionKey, 6),
            'strategy_version' => $this->parsedDecisionKeyPart($decisionKey, 7),
        ] + $this->bitmartOrders->orderIntentExecutionParams($plan, $clientOrderId);
    }

    /**
     * @return array<string,mixed>
     */
    private function intentQuantization(OrderPlanModel $plan): array
    {
        return [
            'price_precision' => $plan->pricePrecision,
            'contract_size' => $plan->contractSize,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function intentPlanPayload(OrderPlanModel $plan): array
    {
        return [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'order_type' => $plan->orderType,
            'open_type' => $plan->openType,
            'order_mode' => $plan->orderMode,
            'entry' => $plan->entry,
            'stop' => $plan->stop,
            'take_profit' => $plan->takeProfit,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
        ];
    }

    private function parsedDecisionKeyPart(string $decisionKey, int $index): ?string
    {
        $parts = explode(':', $decisionKey, 8);

        return $parts[$index] ?? null;
    }

    private function syncIntentAfterExecution(OrderIntent $intent, ExecutionResult $result): void
    {
        if ($this->orderIntentManager === null) {
            return;
        }

        try {
            $this->syncLineageAfterExecution($intent, $result);

            if ($result->exchangeOrderId !== null && $this->shouldMarkIntentSent($result)) {
                $this->orderIntentManager->markAsSent($intent, $result->exchangeOrderId);
                return;
            }

            if ($result->exchangeOrderId !== null && $this->shouldMarkIntentCancelled($result)) {
                $this->orderIntentManager->markAsCancelled($intent);
                return;
            }

            if ($result->status === ExecutionResult::STATUS_SKIPPED || $result->status === ExecutionResult::STATUS_ERROR) {
                $this->orderIntentManager->markAsFailed(
                    $intent,
                    (string)($result->raw['reason'] ?? $result->status),
                );
            }
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('execute_order_plan.intent_status_sync_failed', [
                'order_intent_id' => $intent->getId(),
                'client_order_id' => $intent->getClientOrderId(),
                'status' => $result->status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function syncLineageAfterExecution(OrderIntent $intent, ExecutionResult $result): void
    {
        if ($this->tradeLineageManager === null) {
            return;
        }

        try {
            $lineage = $this->tradeLineageManager->ensureForIntent($intent);
            $this->tradeLineageManager->attachExchangeOrderId($lineage, $result->exchangeOrderId);
        } catch (\Throwable $e) {
            $this->positionsLogger->warning('execute_order_plan.lineage_sync_failed', [
                'order_intent_id' => $intent->getId(),
                'client_order_id' => $intent->getClientOrderId(),
                'exchange_order_id' => $result->exchangeOrderId,
                'status' => $result->status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markIntentFailedAfterException(OrderIntent $intent, \Throwable $e): void
    {
        if ($this->orderIntentManager === null) {
            return;
        }

        try {
            $this->orderIntentManager->markAsFailed(
                $intent,
                substr('execution_exception: ' . $e->getMessage(), 0, 500),
            );
        } catch (\Throwable $syncError) {
            $this->positionsLogger->warning('execute_order_plan.intent_exception_sync_failed', [
                'order_intent_id' => $intent->getId(),
                'client_order_id' => $intent->getClientOrderId(),
                'error' => $syncError->getMessage(),
                'execution_error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldMarkIntentSent(ExecutionResult $result): bool
    {
        return \in_array($result->status, [
            ExecutionResult::STATUS_SUBMITTED,
            ExecutionResult::STATUS_SUBMITTED_PROTECTED,
            ExecutionResult::STATUS_ENTRY_SUBMITTED,
            ExecutionResult::STATUS_FAILED_UNPROTECTED_CLOSED,
            ExecutionResult::STATUS_CRITICAL_UNPROTECTED_POSITION,
        ], true);
    }

    private function shouldMarkIntentCancelled(ExecutionResult $result): bool
    {
        if ($result->status !== ExecutionResult::STATUS_ERROR) {
            return false;
        }

        return \in_array((string)($result->raw['reason'] ?? ''), [
            'entry_pending_cancelled_without_fill',
            'entry_closed_without_fill',
        ], true);
    }
}
