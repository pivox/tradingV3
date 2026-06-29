<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Okx;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Okx\OkxActionFactory;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use App\TradingCore\Execution\Dto\ExecutionRequest;
use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionMode;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use App\TradingCore\Execution\Port\ExecutionPortInterface;
use App\TradingCore\Execution\Safety\DemoTradingSafetyPolicy;
use App\TradingCore\Execution\Safety\DemoTradingSafetyPolicyEvaluator;
use App\TradingCore\Execution\Safety\ExchangeRuntimeEnvironment;
use App\TradingCore\OrderPlan\Dto\OrderPlan;
use App\TradingCore\OrderPlan\Service\OrderPlanValidator;

/**
 * OKX dry-run / preview implementation of {@see ExecutionPortInterface}.
 *
 * PR11 keeps OKX strictly `dry-run only`: this port simulates what an OKX order
 * submission would look like, but it NEVER routes anything to the exchange.
 *
 * It is a pure TradingCore preview, NOT an exchange execution:
 * - no HTTP call, ever;
 * - it never touches {@see \App\Exchange\Adapter\OkxExchangeAdapter} or
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
        private readonly OkxActionFactory $actionFactory = new OkxActionFactory(),
        private readonly DemoTradingSafetyPolicyEvaluator $safetyPolicyEvaluator = new DemoTradingSafetyPolicyEvaluator(),
        private readonly ExchangePrivateObservabilityPolicy $privateObservabilityPolicy = new ExchangePrivateObservabilityPolicy(),
    ) {
    }

    public function execute(ExecutionRequest $request): ExecutionResult
    {
        $plan = $request->orderPlan;
        $incomingMetadata = self::redact($request->metadata);
        \assert(\is_array($incomingMetadata));
        $environment = $this->environment($request->metadata);

        // Preserve incoming audit metadata (run_id, correlation_id, schedule_id, ...)
        // while keeping the gateway's own descriptors authoritative: a caller must not
        // be able to spoof gateway/simulated/no_http/no_private_post.
        $metadata = array_merge(
            $incomingMetadata,
            [
                'gateway' => self::EXCHANGE,
                'mode' => $request->mode->value,
                'environment' => $environment->value,
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
                'notional' => $this->notional($plan),
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

        if ($environment === ExchangeRuntimeEnvironment::MAINNET) {
            return $this->reject($plan->clientOrderId, $metadata, 'mainnet_environment_forbidden_for_okx_dry_run');
        }

        $maxLeverage = $this->positiveIntMetadata($request->metadata, 'max_leverage');
        if ($maxLeverage !== null && $plan->leverage > $maxLeverage) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['max_leverage' => $maxLeverage]),
                'leverage_cap_exceeded',
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

        $safetyDecision = $this->safetyPolicyEvaluator->evaluate($this->safetyPolicy($plan, $environment, $request->metadata));
        $metadata['safety_decision'] = $safetyDecision->toRedactedArray();
        $dryRunGuardErrors = $this->dryRunGuardErrors($plan, $request->metadata);
        if ($dryRunGuardErrors !== []) {
            $metadata['safety_decision']['blocking_errors'] = array_values(array_unique(array_merge(
                $metadata['safety_decision']['blocking_errors'] ?? [],
                $dryRunGuardErrors,
            )));

            return $this->reject($plan->clientOrderId, $metadata, 'demo_trading_safety_blocked');
        }
        if (!$safetyDecision->allowed) {
            return $this->reject($plan->clientOrderId, $metadata, 'demo_trading_safety_blocked');
        }

        $observabilityDecision = $this->privateObservabilityPolicy->evaluate(
            $this->privateObservabilityStatus($request->metadata, $environment),
            dryRun: true,
            expectedExchange: Exchange::OKX,
            expectedEnvironment: $environment->value,
        );
        $metadata['private_observability_decision'] = $observabilityDecision->toArray();
        $metadata['local_dry_run_ready'] = true;
        $metadata['readiness_level'] = 'local_dry_run_ready';

        $raw = [
            'okx_dry_run' => [
                'no_http' => true,
                'no_private_post' => true,
                'redacted' => true,
                'requests' => $this->serializedRequests($plan),
            ],
        ];

        return new ExecutionResult(
            status: ExecutionStatus::DryRun,
            clientOrderId: $plan->clientOrderId,
            exchangeOrderId: $this->dryRunOrderId($plan->clientOrderId),
            raw: $raw,
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

    /**
     * @param array<string,mixed> $metadata
     */
    private function environment(array $metadata): ExchangeRuntimeEnvironment
    {
        $candidate = strtolower(trim((string)($metadata['environment'] ?? $metadata['runtime_environment'] ?? 'demo')));

        return match ($candidate) {
            'local_dry_run', 'local-dry-run', 'dry_run', 'dry-run' => ExchangeRuntimeEnvironment::LOCAL_DRY_RUN,
            'mainnet', 'live', 'production', 'prod' => ExchangeRuntimeEnvironment::MAINNET,
            'testnet' => ExchangeRuntimeEnvironment::TESTNET,
            default => ExchangeRuntimeEnvironment::DEMO,
        };
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function safetyPolicy(OrderPlan $plan, ExchangeRuntimeEnvironment $environment, array $metadata): DemoTradingSafetyPolicy
    {
        $allowedSymbols = $this->stringListMetadata($metadata, 'allowed_symbols');
        if ($allowedSymbols === []) {
            $allowedSymbols = [$plan->symbol];
        }

        return new DemoTradingSafetyPolicy(
            environment: $environment,
            dryRun: true,
            mainnetWriteEnabled: (bool)($metadata['mainnet_write_enabled'] ?? false),
            demoTestnetWriteEnabled: (bool)($metadata['demo_testnet_write_enabled'] ?? false),
            killSwitchEnabled: (bool)($metadata['kill_switch_enabled'] ?? true),
            requireStopLoss: true,
            allowedSymbols: $allowedSymbols,
            allowedMarkets: $this->stringListMetadata($metadata, 'allowed_markets'),
            maxNotional: $this->positiveFloatMetadata($metadata, 'max_notional'),
            requestedSymbol: $plan->symbol,
            requestedMarket: $plan->marketType,
            requestedNotional: $this->notional($plan),
            stopLossPresent: $plan->protectionPlan?->stopLoss !== null,
            auditContext: $metadata,
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function privateObservabilityStatus(array $metadata, ExchangeRuntimeEnvironment $environment): ExchangePrivateObservabilityStatus
    {
        $status = $metadata['private_observability_status'] ?? null;
        if ($status instanceof ExchangePrivateObservabilityStatus) {
            return $status;
        }

        return ExchangePrivateObservabilityStatus::absent(Exchange::OKX, $environment->value);
    }

    /**
     * @param array<string,mixed> $metadata
     * @return list<string>
     */
    private function dryRunGuardErrors(OrderPlan $plan, array $metadata): array
    {
        $errors = [];
        $allowedSymbols = $this->stringListMetadata($metadata, 'allowed_symbols');
        if ($allowedSymbols !== [] && !\in_array($plan->symbol, $allowedSymbols, true)) {
            $errors[] = 'requested_symbol_or_market_not_allowed';
        }

        $maxNotional = $this->positiveFloatMetadata($metadata, 'max_notional');
        if ($maxNotional !== null && $this->notional($plan) > $maxNotional) {
            $errors[] = 'max_notional_exceeded';
        }

        return $errors;
    }

    /**
     * @return list<array{operation:string, method:string, path:string, body:array<string,mixed>}>
     */
    private function serializedRequests(OrderPlan $plan): array
    {
        $instId = $this->instId($plan);
        $requests = [];

        foreach ($this->actionFactory->setLeverageRequests($instId, $plan->leverage, $plan->marginMode) as $body) {
            $requests[] = [
                'operation' => 'set_leverage',
                'method' => 'POST',
                'path' => '/api/v5/account/set-leverage',
                'body' => $body,
            ];
        }

        $requests[] = [
            'operation' => 'submit_order',
            'method' => 'POST',
            'path' => '/api/v5/trade/order',
            'body' => $this->actionFactory->order($instId, $this->entryRequest($plan)),
        ];

        if ($plan->protectionPlan?->stopLoss !== null) {
            $requests[] = [
                'operation' => 'stop_loss',
                'method' => 'POST',
                'path' => '/api/v5/trade/order-algo',
                'body' => $this->actionFactory->algoOrder($instId, $this->stopLossRequest($plan)),
            ];
        }

        if ($plan->protectionPlan?->takeProfit?->tp1Price !== null) {
            $requests[] = [
                'operation' => 'take_profit',
                'method' => 'POST',
                'path' => '/api/v5/trade/order-algo',
                'body' => $this->actionFactory->algoOrder($instId, $this->takeProfitRequest($plan)),
            ];
        }

        return $requests;
    }

    private function entryRequest(OrderPlan $plan): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $plan->symbol,
            side: $this->entrySide($plan),
            positionSide: $this->positionSide($plan),
            orderType: $this->orderType($plan),
            timeInForce: $this->timeInForce($plan),
            quantity: $plan->quantity,
            price: strtolower($plan->orderType) === 'market' ? null : $plan->entryPrice,
            stopPrice: null,
            reduceOnly: false,
            postOnly: strtolower($plan->timeInForce) === 'post_only',
            leverage: $plan->leverage,
            marginMode: $plan->marginMode,
            clientOrderId: (string) $plan->clientOrderId,
        );
    }

    private function stopLossRequest(OrderPlan $plan): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $plan->symbol,
            side: $this->closingSide($plan),
            positionSide: $this->positionSide($plan),
            orderType: ExchangeOrderType::STOP_LOSS,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $plan->quantity,
            price: null,
            stopPrice: $plan->protectionPlan?->stopLoss?->stopPrice,
            reduceOnly: true,
            postOnly: false,
            leverage: $plan->leverage,
            marginMode: $plan->marginMode,
            clientOrderId: $this->childClientOrderId((string) $plan->clientOrderId, 'SL'),
        );
    }

    private function takeProfitRequest(OrderPlan $plan): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::OKX,
            marketType: MarketType::PERPETUAL,
            symbol: $plan->symbol,
            side: $this->closingSide($plan),
            positionSide: $this->positionSide($plan),
            orderType: ExchangeOrderType::TAKE_PROFIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $plan->quantity,
            price: null,
            stopPrice: $plan->protectionPlan?->takeProfit?->tp1Price,
            reduceOnly: true,
            postOnly: false,
            leverage: $plan->leverage,
            marginMode: $plan->marginMode,
            clientOrderId: $this->childClientOrderId((string) $plan->clientOrderId, 'TP'),
            metadata: ['okx_trigger_kind' => 'tp'],
        );
    }

    private function instId(OrderPlan $plan): string
    {
        $instrument = trim($plan->instrument);

        return $instrument !== '' ? $instrument : $plan->symbol;
    }

    private function orderType(OrderPlan $plan): ExchangeOrderType
    {
        return strtolower(trim($plan->orderType)) === 'market' ? ExchangeOrderType::MARKET : ExchangeOrderType::LIMIT;
    }

    private function timeInForce(OrderPlan $plan): ExchangeTimeInForce
    {
        return match (strtolower(trim($plan->timeInForce))) {
            'ioc' => ExchangeTimeInForce::IOC,
            'fok' => ExchangeTimeInForce::FOK,
            default => ExchangeTimeInForce::GTC,
        };
    }

    private function entrySide(OrderPlan $plan): ExchangeOrderSide
    {
        return strtolower(trim($plan->side)) === 'short' ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function closingSide(OrderPlan $plan): ExchangeOrderSide
    {
        return $this->entrySide($plan) === ExchangeOrderSide::BUY ? ExchangeOrderSide::SELL : ExchangeOrderSide::BUY;
    }

    private function positionSide(OrderPlan $plan): ExchangePositionSide
    {
        return strtolower(trim($plan->side)) === 'short' ? ExchangePositionSide::SHORT : ExchangePositionSide::LONG;
    }

    private function childClientOrderId(string $clientOrderId, string $suffix): string
    {
        $candidate = preg_replace('/[^A-Za-z0-9]/', '', $clientOrderId) ?? '';
        $candidate = $candidate === '' ? 'OKXDRYRUN' : $candidate;

        return substr($candidate . $suffix, 0, 32);
    }

    private function notional(OrderPlan $plan): float
    {
        $contractSize = $plan->contractSize ?? 1.0;

        return $plan->entryPrice * $plan->quantity * $contractSize;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return list<string>
     */
    private function stringListMetadata(array $metadata, string $key): array
    {
        $value = $metadata[$key] ?? [];
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (\is_string($item) && trim($item) !== '') {
                $strings[] = trim($item);
            }
        }

        return array_values($strings);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function positiveFloatMetadata(array $metadata, string $key): ?float
    {
        if (!isset($metadata[$key]) || !\is_numeric($metadata[$key])) {
            return null;
        }

        $value = (float) $metadata[$key];

        return \is_finite($value) && $value > 0.0 ? $value : null;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function positiveIntMetadata(array $metadata, string $key): ?int
    {
        if (!isset($metadata[$key]) || !\is_numeric($metadata[$key])) {
            return null;
        }

        $value = (int) $metadata[$key];

        return $value > 0 ? $value : null;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
        $compacted = str_replace('_', '', $normalized);

        foreach (['secret', 'token', 'api_key', 'private_key', 'passphrase', 'password', 'signature', 'authorization', 'cookie', 'memo', 'credential'] as $needle) {
            if (str_contains($normalized, $needle) || str_contains($compacted, str_replace('_', '', $needle))) {
                return true;
            }
        }

        return $normalized === 'key'
            || str_ends_with($normalized, '_key')
            || str_ends_with($normalized, '_sign')
            || str_ends_with($compacted, 'key');
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[redacted]';
        }

        if ($value instanceof ExchangePrivateObservabilityStatus) {
            return $value->toArray();
        }

        if (!\is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = self::redact(
                $childValue,
                \is_string($childKey) ? $childKey : null,
            );
        }

        return $redacted;
    }
}
