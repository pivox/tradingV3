<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Hyperliquid\HyperliquidActionFactory;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use App\Provider\Hyperliquid\FakeHyperliquidSigner;
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
 * Hyperliquid local dry-run / preview implementation of {@see ExecutionPortInterface}.
 *
 * It builds the exact redacted `/exchange` actions expected by the Hyperliquid
 * adapter, signs them with the deterministic fake signer, and never broadcasts
 * them. Live mode and mainnet environments remain forbidden.
 */
final class HyperliquidDryRunExecutionPort implements ExecutionPortInterface
{
    private const EXCHANGE = 'hyperliquid';
    private const MARKET_TYPE = 'perpetual';

    public function __construct(
        private readonly OrderPlanValidator $validator = new OrderPlanValidator(),
        private readonly HyperliquidActionFactory $actionFactory = new HyperliquidActionFactory(),
        private readonly FakeHyperliquidSigner $signer = new FakeHyperliquidSigner(),
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

        // Preserve incoming audit metadata while keeping gateway descriptors authoritative.
        $metadata = array_merge(
            $incomingMetadata,
            [
                'gateway' => self::EXCHANGE,
                'mode' => $request->mode->value,
                'environment' => $environment->value,
                'simulated' => true,
                'no_http' => true,
                'no_exchange_call' => true,
                'no_broadcast' => true,
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

        if ($request->mode === ExecutionMode::Live) {
            return $this->reject($plan->clientOrderId, $metadata, 'live_not_supported_by_hyperliquid_dry_run');
        }

        if (strtolower(trim($plan->exchange)) !== self::EXCHANGE) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['plan_exchange' => $plan->exchange]),
                'wrong_exchange_for_hyperliquid_dry_run',
            );
        }

        if (strtolower(trim($plan->marketType)) !== self::MARKET_TYPE) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['plan_market_type' => $plan->marketType]),
                'market_type_not_supported_by_hyperliquid_dry_run',
            );
        }

        if ($environment === ExchangeRuntimeEnvironment::MAINNET) {
            return $this->reject($plan->clientOrderId, $metadata, 'mainnet_environment_forbidden_for_hyperliquid_dry_run');
        }

        $maxLeverage = $this->positiveIntMetadata($request->metadata, 'max_leverage');
        if ($maxLeverage !== null && $plan->leverage > $maxLeverage) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['max_leverage' => $maxLeverage]),
                'leverage_cap_exceeded',
            );
        }

        $validation = $this->validator->validate($plan);
        if (!$validation->isExecutable) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['invalid_reasons' => $validation->invalidReasons]),
                'order_plan_not_executable',
            );
        }

        $safetyDecision = $this->safetyPolicyEvaluator->evaluate($this->safetyPolicy($plan, $environment, $incomingMetadata));
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

        $assetId = $this->assetId($plan, $request->metadata);
        if ($assetId === null) {
            return $this->reject($plan->clientOrderId, $metadata, 'hyperliquid_asset_id_required_for_symbol');
        }
        $metadata['hyperliquid_asset_id'] = $assetId;

        $observabilityDecision = $this->privateObservabilityPolicy->evaluate(
            $this->privateObservabilityStatus($request->metadata, $environment),
            dryRun: true,
            expectedExchange: Exchange::HYPERLIQUID,
            expectedEnvironment: $environment->value,
        );
        $metadata['private_observability_decision'] = $observabilityDecision->toArray();

        try {
            $requests = $this->serializedRequests($plan, $request, $assetId);
        } catch (\InvalidArgumentException $exception) {
            return $this->reject(
                $plan->clientOrderId,
                array_merge($metadata, ['payload_error' => $exception->getMessage()]),
                'hyperliquid_dry_run_payload_unencodable',
            );
        }

        $raw = [
            'hyperliquid_dry_run' => [
                'no_http' => true,
                'no_exchange_call' => true,
                'no_broadcast' => true,
                'redacted' => true,
                'signer' => 'fake_hyperliquid_signer',
                'nonce_policy' => 'deterministic_preview',
                'requests' => $requests,
            ],
        ];
        $metadata['local_dry_run_ready'] = true;
        $metadata['readiness_level'] = 'local_dry_run_ready';

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
            metadata: array_merge($metadata, ['reject_reason' => $reason]),
        );
    }

    private function dryRunOrderId(?string $clientOrderId): string
    {
        $seed = $clientOrderId !== null && trim($clientOrderId) !== '' ? $clientOrderId : 'UNKNOWN';

        return 'HYPERLIQUID-DRYRUN-' . $seed;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function environment(array $metadata): ExchangeRuntimeEnvironment
    {
        $candidate = strtolower(trim((string)($metadata['environment'] ?? $metadata['runtime_environment'] ?? 'local_dry_run')));

        return match ($candidate) {
            'demo' => ExchangeRuntimeEnvironment::DEMO,
            'testnet' => ExchangeRuntimeEnvironment::TESTNET,
            'mainnet', 'live', 'production', 'prod' => ExchangeRuntimeEnvironment::MAINNET,
            default => ExchangeRuntimeEnvironment::LOCAL_DRY_RUN,
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

        return ExchangePrivateObservabilityStatus::absent(Exchange::HYPERLIQUID, $environment->value);
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
    private function serializedRequests(OrderPlan $plan, ExecutionRequest $request, int $assetId): array
    {
        $actions = [
            ['set_leverage', $this->actionFactory->updateLeverage($assetId, $plan->leverage, $plan->marginMode)],
            ['submit_order', $this->actionFactory->order($assetId, $this->entryRequest($plan))],
        ];

        if ($plan->protectionPlan?->stopLoss !== null) {
            $actions[] = ['stop_loss', $this->actionFactory->order($assetId, $this->stopLossRequest($plan))];
        }

        if ($plan->protectionPlan?->takeProfit?->tp1Price !== null) {
            $actions[] = ['take_profit', $this->actionFactory->order($assetId, $this->takeProfitRequest($plan))];
        }

        $requests = [];
        foreach ($actions as $index => [$operation, $action]) {
            $requests[] = [
                'operation' => $operation,
                'method' => 'POST',
                'path' => '/exchange',
                'body' => $this->signer->signAction($action, $this->previewNonce($request, $index)),
            ];
        }

        return $requests;
    }

    private function entryRequest(OrderPlan $plan): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            symbol: $plan->symbol,
            side: $this->entrySide($plan),
            positionSide: $this->positionSide($plan),
            orderType: $this->orderType($plan),
            timeInForce: $this->timeInForce($plan),
            quantity: $plan->quantity,
            price: $plan->entryPrice,
            stopPrice: null,
            reduceOnly: false,
            postOnly: strtolower(trim($plan->timeInForce)) === 'post_only',
            leverage: $plan->leverage,
            marginMode: $plan->marginMode,
            clientOrderId: (string) $plan->clientOrderId,
        );
    }

    private function stopLossRequest(OrderPlan $plan): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::HYPERLIQUID,
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
            exchange: Exchange::HYPERLIQUID,
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
        );
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
        $suffix = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($suffix)) ?? '';
        $suffix = $suffix === '' ? 'CHILD' : substr($suffix, 0, 16);

        return trim($clientOrderId) . '-' . $suffix;
    }

    private function notional(OrderPlan $plan): float
    {
        $contractSize = $plan->contractSize ?? 1.0;

        return $plan->entryPrice * $plan->quantity * $contractSize;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function assetId(OrderPlan $plan, array $metadata): ?int
    {
        foreach (['hyperliquid_asset_id', 'asset_id'] as $key) {
            if (isset($metadata[$key]) && \is_numeric($metadata[$key])) {
                $value = (int) $metadata[$key];
                if ($value >= 0) {
                    return $value;
                }
            }
        }

        return $this->normalizedCoin($plan->symbol) === 'BTC' ? 0 : null;
    }

    private function normalizedCoin(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        foreach (['-PERP', 'PERP', '/USDC', '-USDC', 'USDC', '/USDT', '-USDT', 'USDT'] as $suffix) {
            if (str_ends_with($symbol, $suffix)) {
                return substr($symbol, 0, -strlen($suffix));
            }
        }

        return $symbol;
    }

    private function previewNonce(ExecutionRequest $request, int $index): int
    {
        $seed = $this->positiveIntMetadata($request->metadata, 'hyperliquid_dry_run_nonce');
        if ($seed === null) {
            $seed = ((int) $request->requestedAt->format('U') * 1000)
                + ((int) floor(((int) $request->requestedAt->format('u')) / 1000));
        }

        return $seed + $index;
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
        if (\in_array($normalized, ['decision_key', 'idempotency_key', 'owner_decision_key', 'blocking_decision_key'], true)) {
            return false;
        }

        foreach (['secret', 'token', 'api_key', 'private_key', 'passphrase', 'password', 'signature', 'authorization', 'cookie', 'memo', 'credential', 'sign'] as $needle) {
            if (str_contains($normalized, $needle) || str_contains($compacted, str_replace('_', '', $needle))) {
                return true;
            }
        }

        return $normalized === 'key'
            || $normalized === 'sign'
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
