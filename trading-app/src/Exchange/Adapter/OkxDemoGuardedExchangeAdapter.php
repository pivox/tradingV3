<?php

declare(strict_types=1);

namespace App\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Contract\Provider\ContractProviderInterface;
use App\Exchange\Okx\Demo\OkxDemoWriteDecision;
use App\Exchange\Okx\Demo\OkxDemoWriteGate;
use App\Exchange\Okx\Demo\OkxDemoWriteKind;
use App\Exchange\Okx\Demo\OkxDemoWriteRefusedException;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use App\TradingCore\Execution\Safety\DemoTradingAuditSinkInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final readonly class OkxDemoGuardedExchangeAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    public function __construct(
        private OkxExchangeAdapter $inner,
        private OkxDemoWriteGate $gate,
        private DemoTradingAuditSinkInterface $audit,
        private ?ContractProviderInterface $contracts = null,
        #[Autowire(service: 'monolog.logger.positions')] private ?LoggerInterface $logger = null,
    ) {
    }

    public function exchange(): Exchange
    {
        return $this->inner->exchange();
    }

    public function marketType(): MarketType
    {
        return $this->inner->marketType();
    }

    public function capabilities(): ExchangeCapabilities
    {
        return $this->inner->capabilities();
    }

    public function getBalances(): array
    {
        return $this->inner->getBalances();
    }

    public function getOpenPositions(?string $symbol = null): array
    {
        return $this->inner->getOpenPositions($symbol);
    }

    public function getOpenOrders(?string $symbol = null): array
    {
        return $this->inner->getOpenOrders($symbol);
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        return $this->inner->getOrder($symbol, $exchangeOrderId);
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        return $this->inner->getOrderBookTop($symbol);
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        return $this->inner->reconcile($symbol);
    }

    public function getOrdersSnapshot(?string $symbol = null): array
    {
        return $this->inner->getOrdersSnapshot($symbol);
    }

    public function getFillsSnapshot(?string $symbol = null): array
    {
        return $this->inner->getFillsSnapshot($symbol);
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return $this->inner->hasAuthoritativePositionSnapshot($symbol);
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $context = [
            'action' => 'place_order',
            'symbol' => strtoupper($request->symbol),
            'side' => $request->side->value,
            'position_side' => $request->positionSide->value,
            'order_type' => $request->orderType->value,
            'quantity' => $request->quantity,
            'price' => $request->price,
            'stop_price' => $request->stopPrice,
            'reduce_only' => $request->reduceOnly,
            'client_order_id' => $request->clientOrderId,
            'decision_id' => $this->stringOrNull($request->metadata['decision_key'] ?? null),
            'order_intent_id' => $this->stringOrNull($request->metadata['order_intent_id'] ?? null),
        ];
        $kind = $this->placeKind($request);
        $context['write_kind'] = $kind->value;
        $stopLossPresent = $kind === OkxDemoWriteKind::ENTRY ? $this->stopLossPresent($request) : null;
        $context['stop_loss_present'] = $stopLossPresent;
        [$notional, $notionalFailure] = $this->notional($request);
        if ($notional === null && $kind === OkxDemoWriteKind::PROTECTIVE) {
            $notional = OkxDemoWriteGate::NON_SIZING_NOTIONAL;
            $notionalFailure = null;
        }
        $decision = $notional === null
            ? OkxDemoWriteDecision::refuse([$notionalFailure ?? 'notional_unavailable'], $kind)
            : $this->gate->evaluate(
                $kind,
                'place_order',
                $context['symbol'],
                $notional,
                $request->clientOrderId,
                $this->correlationIds($context),
                $request->reduceOnly,
                $stopLossPresent,
            );
        $this->assertAllowed('place_order', $this->before($context + ['notional' => $notional], $decision));

        try {
            $result = $this->inner->placeOrder($request);
        } catch (\Throwable $e) {
            $this->after($context, 'error', ['error_class' => $e::class]);
            throw $e;
        }
        $this->after($context, $result->accepted ? 'accepted' : 'rejected', [
            'exchange_order_id' => $result->exchangeOrderId,
            'status' => $result->status->value,
        ]);

        return $result;
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        $identifier = $request->clientOrderId ?? $request->exchangeOrderId ?? '';
        $context = [
            'action' => 'cancel_order',
            'symbol' => strtoupper($request->symbol),
            'client_order_id' => $request->clientOrderId,
            'exchange_order_id' => $request->exchangeOrderId,
            'decision_id' => $this->stringOrNull($request->metadata['decision_key'] ?? null),
            'order_intent_id' => $this->stringOrNull($request->metadata['order_intent_id'] ?? null),
        ];
        $kind = OkxDemoWriteKind::fromMetadata($request->metadata['write_kind'] ?? null);
        $context['write_kind'] = $kind->value;
        $decision = $this->gate->evaluate(
            $kind,
            'cancel_order',
            $context['symbol'],
            OkxDemoWriteGate::NON_SIZING_NOTIONAL,
            $identifier,
            $this->correlationIds($context),
        );
        $this->assertAllowed('cancel_order', $this->before($context, $decision));

        try {
            $result = $this->inner->cancelOrder($request);
        } catch (\Throwable $e) {
            $this->after($context, 'error', ['error_class' => $e::class]);
            throw $e;
        }
        $this->after($context, $result->cancelled ? 'cancelled' : 'rejected', [
            'exchange_order_id' => $result->exchangeOrderId,
            'status' => $result->status->value,
        ]);

        return $result;
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        $context = [
            'action' => 'set_leverage',
            'symbol' => strtoupper($symbol),
            'leverage' => $leverage,
            'margin_mode' => $marginMode,
            'write_kind' => OkxDemoWriteKind::ENTRY->value,
            'client_order_id' => sprintf('set_leverage:%s', strtoupper($symbol)),
        ];
        $decision = $this->gate->evaluate(
            OkxDemoWriteKind::ENTRY,
            'set_leverage',
            $context['symbol'],
            OkxDemoWriteGate::NON_SIZING_NOTIONAL,
            $context['client_order_id'],
        );
        if (!$this->before($context, $decision)->allowed) {
            return false;
        }

        try {
            $applied = $this->inner->setLeverage($symbol, $leverage, $marginMode);
        } catch (\Throwable $e) {
            $this->after($context, 'error', ['error_class' => $e::class]);
            throw $e;
        }
        $this->after($context, $applied ? 'accepted' : 'rejected');

        return $applied;
    }

    private function placeKind(PlaceOrderRequest $request): OkxDemoWriteKind
    {
        $explicit = $request->metadata['write_kind'] ?? null;
        if (\is_string($explicit) && OkxDemoWriteKind::tryFrom($explicit) !== null) {
            return OkxDemoWriteKind::from($explicit);
        }
        if (!$request->reduceOnly) {
            return OkxDemoWriteKind::ENTRY;
        }

        return match ($request->orderType) {
            ExchangeOrderType::TAKE_PROFIT, ExchangeOrderType::LIMIT => OkxDemoWriteKind::TAKE_PROFIT,
            default => OkxDemoWriteKind::PROTECTIVE,
        };
    }

    private function stopLossPresent(PlaceOrderRequest $request): bool
    {
        $planned = $request->metadata['stop_loss_price'] ?? null;

        return ($request->attachedStopLossPrice !== null && $request->attachedStopLossPrice > 0.0)
            || (is_numeric($planned) && (float) $planned > 0.0);
    }

    /**
     * @return array{0: ?float, 1: ?string}
     */
    private function notional(PlaceOrderRequest $request): array
    {
        $contractSize = $this->contractSize($request);
        if ($contractSize === null) {
            return [null, 'contract_size_unavailable'];
        }

        $reference = $request->price ?? $request->stopPrice;
        if ($reference === null) {
            try {
                $book = $this->inner->getOrderBookTop($request->symbol);
            } catch (\Throwable) {
                return [null, 'notional_unavailable'];
            }
            $reference = $request->side === ExchangeOrderSide::BUY ? $book->ask : $book->bid;
        }

        return $reference > 0.0 ? [$request->quantity * $contractSize * $reference, null] : [null, 'notional_unavailable'];
    }

    private function contractSize(PlaceOrderRequest $request): ?float
    {
        $carried = $request->metadata['contract_size'] ?? null;
        if (is_numeric($carried) && (float) $carried > 0.0) {
            return (float) $carried;
        }

        try {
            $resolved = $this->contracts?->getContractDetails(strtoupper($request->symbol))?->contractSize->toFloat();
        } catch (\Throwable) {
            return null;
        }

        return $resolved !== null && $resolved > 0.0 ? $resolved : null;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    private function correlationIds(array $context): array
    {
        $ids = [];
        foreach (['decision_id', 'order_intent_id'] as $key) {
            if (\is_string($context[$key] ?? null)) {
                $ids[$key] = $context[$key];
            }
        }

        return $ids;
    }

    /**
     * @param array<string,mixed> $context
     */
    private function before(array $context, OkxDemoWriteDecision $decision): OkxDemoWriteDecision
    {
        $reasons = $decision->reasons;
        $allowed = $decision->allowed;
        try {
            $this->record('before', $context, [
                'allowed' => $allowed,
                'outcome' => $allowed ? 'attempted' : 'refused',
                'reasons' => $reasons,
                'write_kind' => $decision->kind?->value,
                'exemption_applied' => $decision->exemptionApplied(),
                'exempted_reasons' => $decision->exemptedReasons,
            ]);
        } catch (\Throwable) {
            $allowed = false;
            $reasons = array_values(array_unique([...$reasons, 'audit_failed']));
        }

        return $allowed ? $decision : OkxDemoWriteDecision::refuse($reasons, $decision->kind);
    }

    private function assertAllowed(string $action, OkxDemoWriteDecision $decision): void
    {
        if (!$decision->allowed) {
            throw new OkxDemoWriteRefusedException($action, $decision->reasons);
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extra
     */
    private function after(array $context, string $outcome, array $extra = []): void
    {
        try {
            $this->record('after', $context, ['outcome' => $outcome] + $extra);
        } catch (\Throwable $e) {
            $this->logger?->critical('okx_demo.audit_after_failed', [
                'action' => $context['action'] ?? null,
                'symbol' => $context['symbol'] ?? null,
                'client_order_id' => $context['client_order_id'] ?? null,
                'outcome' => $outcome,
                'error_class' => $e::class,
            ]);
            try {
                $this->gate->trip('audit_after_failed');
            } catch (\Throwable $tripFailure) {
                $this->logger?->critical('okx_demo.trip_persistence_failed', ['error_class' => $tripFailure::class]);
            }
        }
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extra
     */
    private function record(string $phase, array $context, array $extra): void
    {
        $this->audit->recordDemoTradingAttempt([
            'exchange' => Exchange::OKX->value,
            'environment' => 'demo',
            'phase' => $phase,
        ] + $context + $extra);
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
