<?php
declare(strict_types=1);

namespace App\TradeEntry\Workflow;

use App\TradeEntry\Dto\ExecutionResult;
use App\TradeEntry\Execution\ExecutionBox;
use App\TradeEntry\OrderPlan\OrderPlanModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class ExecuteOrderPlan
{
    public function __construct(
        private readonly ExecutionBox $execution,
        #[Autowire(service: 'monolog.logger.positions_flow')] private readonly LoggerInterface $flowLogger,
        #[Autowire(service: 'monolog.logger.positions')] private readonly LoggerInterface $positionsLogger,
    ) {}

    public function __invoke(OrderPlanModel $plan, ?string $decisionKey = null): ExecutionResult
    {
        $this->flowLogger->info('execute_order_plan.start', [
            'symbol' => $plan->symbol,
            'side' => $plan->side->value,
            'order_type' => $plan->orderType,
            'mode' => $plan->orderMode,
            'size' => $plan->size,
            'leverage' => $plan->leverage,
            'entry' => $plan->entry,
            'decision_key' => $decisionKey,
        ]);

        try {
            $result = $this->execution->execute($plan, $decisionKey);

            $context = [
                'symbol' => $plan->symbol,
                'side' => $plan->side->value,
                'status' => $result->status,
                'client_order_id' => $result->clientOrderId,
                'exchange_order_id' => $result->exchangeOrderId,
            ];

            if ($result->status === 'submitted') {
                $this->positionsLogger->info('execute_order_plan.submitted', $context + ['decision_key' => $decisionKey]);
            } else {
                $this->positionsLogger->error('execute_order_plan.failed', $context + ['decision_key' => $decisionKey, 'raw' => $result->raw]);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->positionsLogger->error('execute_order_plan.exception', [
                'symbol' => $plan->symbol,
                'message' => $e->getMessage(),
                'decision_key' => $decisionKey,
            ]);
            throw $e;
        }
    }
}
