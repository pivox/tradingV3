<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Fake;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Port\ExecutionPortInterface;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;

/**
 * In-memory Fake / Paper gateway: the canonical, test-only implementation of
 * {@see ExecutionPortInterface}. It simulates an execution with no side effect:
 * no HTTP, no provider, no Symfony / Doctrine / Messenger. It never places a real
 * order and refuses live mode entirely.
 *
 * It is distinct from the legacy provider-level fake exchange (App\Exchange\Fake\*)
 * which simulates an exchange at the adapter / websocket level.
 *
 * The gateway is a pure function of its input: the same plan always yields the
 * same fake exchange order id, and the input ExecutionRequest is never mutated.
 */
final class FakeExecutionPort implements ExecutionPortInterface
{
    public function __construct(
        private readonly OrderPlanValidator $validator = new OrderPlanValidator(),
    ) {
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $plan = $request->orderPlan;

        // Preserve incoming audit metadata (run_id, decision_key, correlation_id,
        // schedule_id, profile, ...) while keeping the gateway's own fields authoritative:
        // a caller must not be able to spoof gateway/simulated/client_order_id.
        $metadata = array_merge(
            $request->metadata,
            [
                'gateway' => 'fake_paper',
                'mode' => $request->mode->value,
                'requested_at' => $request->requestedAt->format(\DateTimeInterface::ATOM),
                'client_order_id' => $plan->clientOrderId,
                'idempotency_key' => $plan->idempotencyKey,
                'simulated' => true,
            ],
        );

        // The fake gateway never executes live: it has no real venue to route to.
        if ($request->mode === ExecutionMode::Live) {
            return new ExecutionResult(
                status: ExecutionStatus::Rejected,
                clientOrderId: $plan->clientOrderId,
                metadata: $metadata + ['reject_reason' => 'live_not_supported_by_fake_gateway'],
            );
        }

        // Boundary does not trust the validation carried by the DTO: revalidate.
        $validation = $this->validator->validate($plan);
        if (!$validation->isExecutable) {
            return new ExecutionResult(
                status: ExecutionStatus::Rejected,
                clientOrderId: $plan->clientOrderId,
                metadata: $metadata + [
                    'reject_reason' => 'order_plan_not_executable',
                    'invalid_reasons' => $validation->invalidReasons,
                ],
            );
        }

        return new ExecutionResult(
            status: ExecutionStatus::DryRun,
            clientOrderId: $plan->clientOrderId,
            exchangeOrderId: $this->fakeOrderId($plan->clientOrderId),
            metadata: $metadata + ['dry_run' => true],
        );
    }

    private function fakeOrderId(?string $clientOrderId): string
    {
        $seed = $clientOrderId !== null && trim($clientOrderId) !== '' ? $clientOrderId : 'UNKNOWN';

        return 'FAKE-' . $seed;
    }
}
