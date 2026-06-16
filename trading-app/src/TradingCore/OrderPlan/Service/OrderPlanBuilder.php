<?php
declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Service;

use App\TradingCore\Execution\Service\ClientOrderIdFactory;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Dto\OrderPlanBuildRequest;

/**
 * Minimal, strictly preparatory builder: assembles the TradingCore DTOs
 * (TradeCandidate + EntryZone + Risk + Leverage + ProtectionPlan) into an
 * {@see OrderPlan} and validates it through {@see OrderPlanValidator}.
 *
 * It is pure: no Symfony, Doctrine, Messenger, concrete provider, HTTP, nor any
 * side effect. It does not place orders and is not wired into the runtime.
 *
 * Missing inputs are not thrown on: the builder still produces an OrderPlan whose
 * derived values (entry price, quantity, leverage, protection) make it invalid
 * through the validator, so callers can inspect why the plan is not executable.
 */
final readonly class OrderPlanBuilder
{
    public function __construct(
        private OrderPlanValidator $validator = new OrderPlanValidator(),
        private ClientOrderIdFactory $clientOrderIdFactory = new ClientOrderIdFactory(),
    ) {
    }

    public function build(OrderPlanBuildRequest $request): OrderPlan
    {
        $candidate = $request->candidate;

        $idempotencyKey = $request->idempotencyKey !== null && trim($request->idempotencyKey) !== ''
            ? $request->idempotencyKey
            : null;

        $clientOrderId = $request->clientOrderId !== null && trim($request->clientOrderId) !== ''
            ? $request->clientOrderId
            : ($idempotencyKey !== null ? $this->clientOrderIdFactory->fromIdempotencyKey($idempotencyKey) : null);

        $missingInputs = [];
        if ($request->entryZone === null) {
            $missingInputs[] = 'entry_zone';
        }
        if ($request->riskCalculation === null) {
            $missingInputs[] = 'risk_calculation';
        }
        if ($request->leverageCalculation === null) {
            $missingInputs[] = 'leverage_calculation';
        }
        if ($request->protectionPlan === null) {
            $missingInputs[] = 'protection_plan';
        }

        $plan = new OrderPlan(
            symbol: $candidate->symbol,
            profile: $candidate->profile,
            exchange: $candidate->exchange?->value ?? '',
            marketType: $candidate->marketType?->value ?? '',
            side: strtolower($candidate->direction),
            orderType: $request->orderType,
            marginMode: $request->marginMode,
            timeInForce: $request->timeInForce,
            entryPrice: $request->entryZone?->center ?? 0.0,
            quantity: $request->riskCalculation?->quantity ?? 0.0,
            leverage: $request->leverageCalculation?->finalLeverage ?? 0,
            protectionPlan: $request->protectionPlan,
            clientOrderId: $clientOrderId,
            idempotencyKey: $idempotencyKey,
            decisionKey: $idempotencyKey,
            entryZone: $request->entryZone,
            riskCalculation: $request->riskCalculation,
            leverageCalculation: $request->leverageCalculation,
            metadata: $request->metadata + [
                'source' => 'trading_core_order_plan_builder',
                'candidate_direction' => $candidate->direction,
                'execution_timeframe' => $candidate->executionTimeframe,
                'signal_time' => $candidate->signalTime->format(\DateTimeInterface::ATOM),
                'dry_run' => $candidate->dryRun,
                'build_missing_inputs' => $missingInputs,
            ],
            instrument: $candidate->instrument,
        );

        return $plan->withValidation($this->validator->validate($plan));
    }
}
