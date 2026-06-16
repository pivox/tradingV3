<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Okx;

use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Port\ExecutionPortInterface;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;

/**
 * OKX dry-run / preview implementation of {@see ExecutionPortInterface}.
 *
 * PR11 keeps OKX strictly `dry-run only`: this port simulates what an OKX order
 * submission would look like, but it NEVER routes anything to the exchange.
 *
 * It is a pure TradingCore preview, NOT an exchange execution:
 * - no HTTP call, ever;
 * - it never touches {@see \App\Exchange\Okx\OkxExchangeAdapter} or
 *   {@see \App\Exchange\Okx\OkxRestClient} (so no `privatePost`, no order placement);
 * - no Symfony, Doctrine, Messenger, Temporal nor any concrete runtime provider.
 *
 * Live mode is always refused. A real OKX live activation requires a dedicated,
 * separately reviewed readiness PR.
 *
 * Like {@see \App\TradingCore\Execution\Fake\FakeExecutionPort}, it is a pure
 * function of its input: the same plan always yields the same dry-run order id,
 * and the input ExecutionRequest is never mutated.
 */
final class OkxDryRunExecutionPort implements ExecutionPortInterface
{
    private const EXCHANGE = 'okx';
    private const MARKET_TYPE = 'perpetual';

    public function __construct(
        private readonly OrderPlanValidator $validator = new OrderPlanValidator(),
    ) {
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $plan = $request->orderPlan;

        // Preserve incoming audit metadata (run_id, correlation_id, schedule_id, ...)
        // while keeping the gateway's own descriptors authoritative: a caller must not
        // be able to spoof gateway/simulated/no_http/no_private_post.
        $metadata = array_merge(
            $request->metadata,
            [
                'gateway' => self::EXCHANGE,
                'mode' => $request->mode->value,
                'simulated' => true,
                'no_http' => true,
                'no_private_post' => true,
                'requested_at' => $request->requestedAt->format(\DateTimeInterface::ATOM),
                'client_order_id' => $plan->clientOrderId,
                'idempotency_key' => $plan->idempotencyKey,
                'order_type' => $plan->orderType,
                'side' => $plan->side,
                'symbol' => $plan->symbol,
                'entry_price' => $plan->entryPrice,
                'quantity' => $plan->quantity,
                'leverage' => $plan->leverage,
                'protection_present' => $plan->protectionPlan !== null,
            ],
        );

        // PR11 hard gate: OKX live is forbidden. This port has no live venue to route to.
        if ($request->mode === ExecutionMode::Live) {
            return $this->reject($plan->clientOrderId, $metadata, 'live_not_supported_by_okx_dry_run');
        }

        // Routing gate: this port only previews OKX plans.
        if (strtolower(trim($plan->exchange)) !== self::EXCHANGE) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['plan_exchange' => $plan->exchange]),
                'wrong_exchange_for_okx_dry_run',
            );
        }

        // Routing gate: OKX preview is scoped to perpetual futures in PR11.
        if (strtolower(trim($plan->marketType)) !== self::MARKET_TYPE) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['plan_market_type' => $plan->marketType]),
                'market_type_not_supported_by_okx_dry_run',
            );
        }

        // Boundary does not trust the validation carried by the DTO: revalidate.
        $validation = $this->validator->validate($plan);
        if (!$validation->isExecutable) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['invalid_reasons' => $validation->invalidReasons]),
                'order_plan_not_executable',
            );
        }

        return new ExecutionResult(
            status: ExecutionStatus::DryRun,
            clientOrderId: $plan->clientOrderId,
            exchangeOrderId: $this->dryRunOrderId($plan->clientOrderId),
            metadata: $metadata,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function reject(?string $clientOrderId, array $metadata, string $reason): ExecutionResult
    {
        return new ExecutionResult(
            status: ExecutionStatus::Rejected,
            clientOrderId: $clientOrderId,
            // Result reason is authoritative: a caller cannot spoof reject_reason via metadata.
            metadata: array_merge($metadata, ['reject_reason' => $reason]),
        );
    }

    private function dryRunOrderId(?string $clientOrderId): string
    {
        $seed = $clientOrderId !== null && trim($clientOrderId) !== '' ? $clientOrderId : 'UNKNOWN';

        return 'OKX-DRYRUN-' . $seed;
    }
}
