<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\ExchangeExecutionService;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use App\Logging\Dto\LifecycleContextBuilder;
use App\Provider\Context\ExchangeContext;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExecuteOrderPlan
{
    public function __construct(
        private readonly ExecutionBox $execution,
        private readonly ExchangeExecutionService $exchangeExecution,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

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

        try {
            $result = $this->shouldUseApiFirstExecution($plan)
                ? $this->exchangeExecution->execute($plan, $decisionKey, $mode, $executionTf)
                : $this->execution->execute($plan, $decisionKey, $contextBuilder, $mode, $executionTf);

            $context = [
                'symbol' => $plan->symbol,
                'side' => $plan->side->value,
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
}
