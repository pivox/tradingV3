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
use App\Exchange\Okx\Demo\OkxDemoWriteDecision;
use App\Exchange\Okx\Demo\OkxDemoWriteGate;
use App\Exchange\Okx\Demo\OkxDemoWriteRefusedException;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use App\TradingCore\Execution\Safety\DemoTradingAuditSinkInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final readonly class OkxDemoGuardedExchangeAdapter implements ExchangeAdapterInterface, ExchangeRestSnapshotProviderInterface
{
    public function __construct(
        private OkxExchangeAdapter $inner,
        private OkxDemoWriteGate $gate,
        private DemoTradingAuditSinkInterface $audit,
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
        $notional = $this->notional($request);
        $decision = $notional === null
            ? OkxDemoWriteDecision::refuse(['notional_unavailable'])
            : $this->gate->evaluate(
                'place_order',
                $context['symbol'],
                $notional,
                $request->clientOrderId,
                $this->correlationIds($context),
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
        $decision = $this->gate->evaluate(
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
            'client_order_id' => sprintf('set_leverage:%s', strtoupper($symbol)),
        ];
        $decision = $this->gate->evaluate(
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

    private function notional(PlaceOrderRequest $request): ?float
    {
        $reference = $request->price ?? $request->stopPrice;
        if ($reference === null) {
            try {
                $book = $this->inner->getOrderBookTop($request->symbol);
            } catch (\Throwable) {
                return null;
            }
            $reference = $request->side === ExchangeOrderSide::BUY ? $book->ask : $book->bid;
        }

        return $reference > 0.0 ? $request->quantity * $reference : null;
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
            ]);
        } catch (\Throwable) {
            $allowed = false;
            $reasons = array_values(array_unique([...$reasons, 'audit_failed']));
        }

        return $allowed ? $decision : OkxDemoWriteDecision::refuse($reasons);
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
        } catch (\Throwable) {
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
