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
    /**
     * @var array<string,array<string,mixed>>
     */
    private array $executionsByClientOrderId = [];

    /**
     * @param array<string,mixed> $state
     */
    public function __construct(
        private readonly OrderPlanValidator $validator = new OrderPlanValidator(),
        private readonly ?FakeExecutionScenario $scenario = null,
        array $state = [],
    ) {
        $this->executionsByClientOrderId = $state['executions_by_client_order_id'] ?? [];
    }

    /**
     * @param array<string,mixed> $snapshot
     */
    public static function fromSnapshot(
        array $snapshot,
        ?FakeExecutionScenario $scenario = null,
        ?OrderPlanValidator $validator = null,
    ): self
    {
        return new self(
            validator: $validator ?? new OrderPlanValidator(),
            scenario: $scenario,
            state: $snapshot,
        );
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
                metadata: array_merge($metadata, ['reject_reason' => 'live_not_supported_by_fake_gateway']),
            );
        }

        // Boundary does not trust the validation carried by the DTO: revalidate.
        $validation = $this->validator->validate($plan);
        if (!$validation->isExecutable) {
            return new ExecutionResult(
                status: ExecutionStatus::Rejected,
                clientOrderId: $plan->clientOrderId,
                metadata: array_merge($metadata, [
                    'reject_reason' => 'order_plan_not_executable',
                    'invalid_reasons' => $validation->invalidReasons,
                ]),
            );
        }

        if ($this->scenario !== null) {
            return $this->executeScenario($request, $metadata);
        }

        return new ExecutionResult(
            status: ExecutionStatus::DryRun,
            clientOrderId: $plan->clientOrderId,
            exchangeOrderId: $this->fakeOrderId($plan->clientOrderId),
            metadata: array_merge($metadata, ['dry_run' => true]),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return ['executions_by_client_order_id' => $this->executionsByClientOrderId];
    }

    public function fillCount(): int
    {
        $count = 0;
        foreach ($this->executionsByClientOrderId as $record) {
            $count += count($record['raw']['fills'] ?? []);
        }

        return $count;
    }

    /**
     * @return array<string,mixed>
     */
    public function resyncPosition(string $clientOrderId): array
    {
        $record = $this->executionsByClientOrderId[$clientOrderId] ?? null;
        if ($record === null) {
            return [];
        }

        $fills = $record['raw']['fills'] ?? [];
        $quantity = 0.0;
        foreach ($fills as $fill) {
            $quantity += (float) ($fill['quantity'] ?? 0.0);
        }

        $protectedQuantity = (float) ($record['raw']['protection']['protected_quantity'] ?? 0.0);

        return [
            'client_order_id' => $clientOrderId,
            'exchange_order_id' => $record['exchange_order_id'] ?? null,
            'symbol' => $record['raw']['order']['symbol'] ?? null,
            'side' => $record['raw']['order']['side'] ?? null,
            'quantity' => $quantity,
            'protection_status' => $this->positionProtectionStatus($quantity, $protectedQuantity),
            'source' => 'fake_paper_snapshot',
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function executeScenario(ExecutionRequest $request, array $metadata): ExecutionResult
    {
        \assert($this->scenario instanceof FakeExecutionScenario);

        $plan = $request->orderPlan;
        $clientOrderId = $plan->clientOrderId;
        $exchangeOrderId = $this->fakeOrderId($clientOrderId);

        if ($clientOrderId !== null && isset($this->executionsByClientOrderId[$clientOrderId])) {
            return new ExecutionResult(
                status: ExecutionStatus::Rejected,
                clientOrderId: $clientOrderId,
                metadata: array_merge($metadata, [
                    'dry_run' => true,
                    'scenario' => $this->scenario->name,
                    'reject_reason' => 'duplicate_client_order_id',
                    'previous_exchange_order_id' => $this->executionsByClientOrderId[$clientOrderId]['exchange_order_id'] ?? null,
                    'quality_flags' => ['duplicate_client_order_id'],
                    'simulated_replay_blocked' => true,
                ]),
            );
        }

        $raw = $this->scenarioRaw($request, $exchangeOrderId);
        $qualityFlags = $this->scenario->qualityFlags;
        $resultMetadata = array_merge($metadata, [
            'dry_run' => true,
            'scenario' => $this->scenario->name,
            'quality_flags' => $qualityFlags,
            'demo_recipe_protected' => $this->isFullyProtected($raw),
            'cancelled' => $this->scenario->orderOutcome === 'cancelled',
        ]);

        if ($this->scenario->rejectReason !== null) {
            $resultMetadata['reject_reason'] = $this->scenario->rejectReason;
        }

        $status = $this->scenarioStatus();
        if ($clientOrderId !== null) {
            $this->executionsByClientOrderId[$clientOrderId] = [
                'exchange_order_id' => $exchangeOrderId,
                'raw' => $raw,
                'metadata' => $resultMetadata,
            ];
        }

        return new ExecutionResult(
            status: $status,
            clientOrderId: $clientOrderId,
            exchangeOrderId: $status === ExecutionStatus::Rejected ? null : $exchangeOrderId,
            raw: $raw,
            metadata: $resultMetadata,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function scenarioRaw(ExecutionRequest $request, string $exchangeOrderId): array
    {
        \assert($this->scenario instanceof FakeExecutionScenario);

        $plan = $request->orderPlan;
        $filledQuantity = $this->filledQuantity($plan->quantity, $this->scenario->fillRatio);
        $orderStatus = $this->orderStatus($filledQuantity, $plan->quantity);
        $fills = [];
        if ($filledQuantity > 0.0) {
            $fills[] = [
                'fill_id' => sprintf('FAKE-FILL-%s-001', $plan->clientOrderId ?? 'UNKNOWN'),
                'exchange_fill_id' => sprintf('FAKE-EXFILL-%s-001', $plan->clientOrderId ?? 'UNKNOWN'),
                'quantity' => $filledQuantity,
                'price' => $plan->entryPrice,
                'role' => 'entry',
            ];
        }

        $raw = [
            'order' => [
                'exchange_order_id' => $exchangeOrderId,
                'client_order_id' => $plan->clientOrderId,
                'status' => $orderStatus,
                'symbol' => $plan->symbol,
                'side' => $plan->side,
                'quantity' => $plan->quantity,
                'filled_quantity' => $filledQuantity,
            ],
            'fills' => $fills,
            'protection' => $this->protectionRaw($filledQuantity),
        ];

        if ($this->scenario->failSafeAction !== null) {
            $raw['fail_safe'] = [
                'action' => $this->scenario->failSafeAction,
                'reason' => $this->scenario->protectionOutcome,
            ];
        }

        return $raw;
    }

    /**
     * @return array<string,mixed>
     */
    private function protectionRaw(float $filledQuantity): array
    {
        \assert($this->scenario instanceof FakeExecutionScenario);

        $protectedQuantity = $this->scenario->protectionOutcome === 'attached' ? $filledQuantity : 0.0;

        return [
            'status' => $this->scenario->protectionOutcome,
            'protected_quantity' => $protectedQuantity,
        ];
    }

    private function scenarioStatus(): ExecutionStatus
    {
        \assert($this->scenario instanceof FakeExecutionScenario);

        if ($this->scenario->orderOutcome === 'rejected') {
            return ExecutionStatus::Rejected;
        }

        if ($this->scenario->orderOutcome === 'cancelled') {
            return ExecutionStatus::Skipped;
        }

        if (in_array($this->scenario->protectionOutcome, ['failed', 'rejected'], true)) {
            return ExecutionStatus::Failed;
        }

        return ExecutionStatus::Accepted;
    }

    private function orderStatus(float $filledQuantity, float $requestedQuantity): string
    {
        \assert($this->scenario instanceof FakeExecutionScenario);

        if ($this->scenario->orderOutcome === 'rejected') {
            return 'rejected';
        }

        if ($this->scenario->orderOutcome === 'cancelled') {
            return 'cancelled';
        }

        if ($filledQuantity <= 0.0) {
            return 'accepted';
        }

        if ($filledQuantity < $requestedQuantity) {
            return 'partially_filled';
        }

        return 'filled';
    }

    private function filledQuantity(float $requestedQuantity, float $fillRatio): float
    {
        if ($fillRatio >= 1.0) {
            return $requestedQuantity;
        }

        $filledQuantity = $requestedQuantity * $fillRatio;

        return max(0.0, min($requestedQuantity, $filledQuantity));
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function isFullyProtected(array $raw): bool
    {
        $filledQuantity = (float) ($raw['order']['filled_quantity'] ?? 0.0);
        $protectedQuantity = (float) ($raw['protection']['protected_quantity'] ?? 0.0);

        return $filledQuantity > 0.0 && $protectedQuantity >= $filledQuantity;
    }

    private function positionProtectionStatus(float $quantity, float $protectedQuantity): string
    {
        if ($quantity <= 0.0) {
            return 'flat';
        }

        if ($protectedQuantity >= $quantity) {
            return 'protected';
        }

        if ($protectedQuantity > 0.0) {
            return 'partially_protected';
        }

        return 'unprotected';
    }

    private function fakeOrderId(?string $clientOrderId): string
    {
        $seed = $clientOrderId !== null && trim($clientOrderId) !== '' ? $clientOrderId : 'UNKNOWN';

        return 'FAKE-' . $seed;
    }
}
