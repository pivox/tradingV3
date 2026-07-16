<?php

declare(strict_types=1);

namespace App\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\Dto\SymbolBidAskDto;
use App\Exchange\Contract\ExchangeAdapterInterface;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\CancelOrderResult;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\ExchangeReconciliationResult;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeFaultOutcome;
use App\Exchange\Fake\FakeExchangeInjectedException;
use App\Exchange\Fake\FakeFillCostModel;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOperation;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeInstrumentProviderInterface;
use App\Exchange\Reconciliation\ExchangeRestSnapshotProviderInterface;
use App\Exchange\Reconciliation\ExchangeReconciliationSnapshotProofProviderInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.exchange_adapter')]
final readonly class FakeExchangeAdapter implements
    ExchangeAdapterInterface,
    ExchangeRestSnapshotProviderInterface,
    ExchangeReconciliationSnapshotProofProviderInterface
{
    private const FEE_RATE = 0.0005;
    private const MARGIN_MODEL_VERSION = 'fake-derived-initial-margin-v1';

    private FakeInstrumentProviderInterface $instruments;

    public function __construct(
        private FakeExchangeStateStore $stateStore,
        private FakeExchangeOrderBook $orderBook,
        private FakeExchangeMatchingEngine $matchingEngine,
        private ClockInterface $clock,
        ?FakeInstrumentProviderInterface $instruments = null,
    ) {
        $this->instruments = $instruments ?? new FakeInstrumentCatalog();
    }

    public function exchange(): Exchange
    {
        return Exchange::FAKE;
    }

    public function marketType(): MarketType
    {
        return MarketType::PERPETUAL;
    }

    public function capabilities(): ExchangeCapabilities
    {
        return new ExchangeCapabilities(
            supportsTestnet: true,
            supportsWebSocketPrivate: true,
            supportsClientOrderId: true,
            supportsCancelByClientOrderId: true,
            supportsPostOnly: true,
            supportsIoc: true,
            supportsReduceOnly: true,
            supportsAttachedStopLossOnEntry: true,
            supportsAttachedTakeProfitOnEntry: true,
            supportsTriggerOrders: true,
            supportsModifyOrder: false,
            requiresSeparateLeverageSubmit: false,
            supportsPerSymbolLeverage: true,
        );
    }

    /**
     * @return array{fee_model:string,fee_rate:float,fill_model:string,slippage_model:string,slippage_bps:float,spread_model:string,metadata_fixture_version:string,precision_model_version:string}
     */
    public function runtimeModelMetadata(): array
    {
        $catalog = new FakeInstrumentCatalog();

        return [
            'fee_model' => 'fixed_notional_fee_v1',
            'fee_rate' => self::FEE_RATE,
            'fill_model' => 'top_of_book_v1',
            'slippage_model' => FakeFillCostModel::MODEL_VERSION,
            'slippage_bps' => FakeFillCostModel::TAKER_SLIPPAGE_BPS,
            'spread_model' => FakeFillCostModel::SPREAD_MODEL_VERSION,
            'metadata_fixture_version' => $catalog->metadataFixtureVersion(),
            'precision_model_version' => $catalog->precisionModelVersion(),
        ];
    }

    /**
     * @return ExchangeBalanceDto[]
     */
    public function getBalances(): array
    {
        $this->throwInjectedFault(FakeExchangeOperation::GetBalances, FakeExchangeFaultOutcome::NotApplied);

        $usedMargin = $this->stateStore->usedMarginUsdt();
        $availableMargin = $this->stateStore->availableMarginUsdt();
        $total = $this->stateStore->totalBalanceUsdt();

        return array_map(
            static function (ExchangeBalanceDto $balance) use ($usedMargin, $availableMargin, $total): ExchangeBalanceDto {
                if ($balance->currency !== 'USDT') {
                    return $balance;
                }

                return new ExchangeBalanceDto(
                    exchange: $balance->exchange,
                    marketType: $balance->marketType,
                    currency: $balance->currency,
                    available: $availableMargin,
                    total: $total,
                    equity: $balance->equity,
                    unrealizedPnl: $balance->unrealizedPnl,
                    metadata: array_replace(
                        ['source' => 'fake_exchange'],
                        $balance->metadata,
                        [
                            'used_margin_usdt' => $usedMargin,
                            'margin_model_version' => self::MARGIN_MODEL_VERSION,
                        ],
                    ),
                );
            },
            $this->stateStore->getBalances(),
        );
    }

    /**
     * @return ExchangePositionDto[]
     */
    public function getOpenPositions(?string $symbol = null): array
    {
        $this->throwInjectedFault(FakeExchangeOperation::GetOpenPositions, FakeExchangeFaultOutcome::NotApplied);

        return $this->stateStore->getOpenPositions($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOpenOrders(?string $symbol = null): array
    {
        $this->throwInjectedFault(FakeExchangeOperation::GetOpenOrders, FakeExchangeFaultOutcome::NotApplied);

        return $this->stateStore->getOpenOrders($symbol);
    }

    /**
     * @return ExchangeOrderDto[]
     */
    public function getOrdersSnapshot(?string $symbol = null): array
    {
        $this->throwInjectedFault(FakeExchangeOperation::GetOrdersSnapshot, FakeExchangeFaultOutcome::NotApplied);

        return $this->stateStore->getOrders($symbol);
    }

    /**
     * @return ExchangeFillDto[]
     */
    public function getFillsSnapshot(?string $symbol = null): array
    {
        $this->throwInjectedFault(FakeExchangeOperation::GetFillsSnapshot, FakeExchangeFaultOutcome::NotApplied);

        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;
        $fills = [];
        foreach ($this->stateStore->events() as $index => $event) {
            if (!\in_array($event->type, ['order.filled', 'order.partially_filled'], true)) {
                continue;
            }
            if ($normalizedSymbol !== null && $event->symbol !== $normalizedSymbol) {
                continue;
            }

            $fill = $this->fillFromEvent($event, $index);
            if ($fill instanceof ExchangeFillDto) {
                $fills[] = $fill;
            }
        }

        return $fills;
    }

    public function hasAuthoritativePositionSnapshot(?string $symbol = null): bool
    {
        return true;
    }

    public function captureReconciliationSnapshotProof(?string $symbol = null): ?array
    {
        if ($symbol !== null) {
            return null;
        }

        return $this->stateStore->capturePrivateWsSnapshotProof();
    }

    public function placeOrder(PlaceOrderRequest $request): PlaceOrderResult
    {
        $this->throwInjectedFault(FakeExchangeOperation::PlaceOrder, FakeExchangeFaultOutcome::NotApplied);
        $execution = $this->stateStore->runWithAppliedResponseLoss(
            FakeExchangeOperation::PlaceOrder,
            fn (): PlaceOrderResult => $this->matchingEngine->submit($request),
        );
        if ($execution['fault'] !== null) {
            throw new FakeExchangeInjectedException($execution['fault']);
        }

        return $execution['result'];
    }

    public function cancelOrder(CancelOrderRequest $request): CancelOrderResult
    {
        $this->throwInjectedFault(FakeExchangeOperation::CancelOrder, FakeExchangeFaultOutcome::NotApplied);
        $execution = $this->stateStore->runWithAppliedResponseLoss(
            FakeExchangeOperation::CancelOrder,
            fn (): CancelOrderResult => $this->matchingEngine->cancel($request),
        );
        if ($execution['fault'] !== null) {
            throw new FakeExchangeInjectedException($execution['fault']);
        }

        return $execution['result'];
    }

    public function getOrder(string $symbol, string $exchangeOrderId): ?ExchangeOrderDto
    {
        $this->throwInjectedFault(FakeExchangeOperation::GetOrder, FakeExchangeFaultOutcome::NotApplied);

        $order = $this->stateStore->getOrder($exchangeOrderId);
        if (!$order instanceof ExchangeOrderDto || $order->symbol !== strtoupper($symbol)) {
            return null;
        }

        return $order;
    }

    public function getOrderBookTop(string $symbol): SymbolBidAskDto
    {
        $this->throwInjectedFault(FakeExchangeOperation::GetOrderBookTop, FakeExchangeFaultOutcome::NotApplied);

        return $this->orderBook->top($symbol);
    }

    public function setLeverage(string $symbol, int $leverage, string $marginMode): bool
    {
        $this->throwInjectedFault(FakeExchangeOperation::SetLeverage, FakeExchangeFaultOutcome::NotApplied);

        if (
            $symbol === ''
            || trim($symbol) !== $symbol
            || strtoupper($symbol) !== $symbol
            || $leverage <= 0
            || !\in_array($marginMode, ['isolated', 'cross'], true)
        ) {
            return false;
        }

        $instrument = $this->instruments->find($symbol);
        if ($instrument === null || $leverage > $instrument->maxLeverage) {
            return false;
        }

        $execution = $this->stateStore->runWithAppliedResponseLoss(
            FakeExchangeOperation::SetLeverage,
            function () use ($symbol, $leverage, $marginMode): bool {
                $setting = ['leverage' => $leverage, 'margin_mode' => $marginMode];
                if ($this->stateStore->getLeverageSetting($symbol) === $setting) {
                    return true;
                }

                $this->stateStore->setLeverageSetting($symbol, $leverage, $marginMode);
                $this->stateStore->appendEvent(new FakeExchangeEvent(
                    type: 'leverage.updated',
                    symbol: $symbol,
                    occurredAt: $this->clock->now(),
                    payload: [
                        'leverage' => $leverage,
                        'margin_mode' => $marginMode,
                    ],
                ));

                return true;
            },
        );
        if ($execution['fault'] !== null) {
            throw new FakeExchangeInjectedException($execution['fault']);
        }

        return $execution['result'];
    }

    public function reconcile(?string $symbol = null): ExchangeReconciliationResult
    {
        $this->throwInjectedFault(FakeExchangeOperation::Reconcile, FakeExchangeFaultOutcome::NotApplied);

        $startedAt = $this->clock->now();

        return new ExchangeReconciliationResult(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol !== null ? strtoupper($symbol) : null,
            startedAt: $startedAt,
            completedAt: $this->clock->now(),
            ordersChecked: $this->stateStore->openOrderCount($symbol),
            positionsChecked: $this->stateStore->openPositionCount($symbol),
            metadata: [
                'source' => 'fake_exchange',
                'events_seen' => \count($this->stateStore->events()),
            ],
        );
    }

    private function fillFromEvent(FakeExchangeEvent $event, int $index): ?ExchangeFillDto
    {
        $orderId = $event->payload['order_id'] ?? null;
        if (!\is_scalar($orderId) || trim((string)$orderId) === '') {
            return null;
        }

        $order = $this->stateStore->getOrder((string)$orderId);
        if (!$order instanceof ExchangeOrderDto) {
            return null;
        }

        $fillQuantity = isset($event->payload['fill_quantity']) && is_numeric($event->payload['fill_quantity'])
            ? (float)$event->payload['fill_quantity']
            : max(0.0, $order->filledQuantity);
        $fillPrice = isset($event->payload['fill_price']) && is_numeric($event->payload['fill_price'])
            ? (float)$event->payload['fill_price']
            : ($order->averagePrice ?? $order->price ?? 0.0);
        $fee = isset($event->payload['fill_fee']) && is_numeric($event->payload['fill_fee'])
            ? (float) $event->payload['fill_fee']
            : $this->fillFee($fillQuantity, $fillPrice);

        return new ExchangeFillDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            fillId: 'fake-fill-' . substr(hash('sha256', implode(':', [
                (string)($event->payload['event_sequence'] ?? $index),
                $event->type,
                $order->exchangeOrderId,
                $event->occurredAt->format('U.u'),
                (string)$fillQuantity,
                (string)$fillPrice,
            ])), 0, 32),
            side: $order->side,
            positionSide: $order->positionSide,
            quantity: $fillQuantity,
            price: $fillPrice,
            fee: $fee,
            feeCurrency: 'USDT',
            filledAt: $event->occurredAt,
            metadata: [
                'source' => 'fake_exchange_rest_reconciliation',
                'pnl_source' => 'fake_paper_fill_ledger_v1',
                'cost_completeness' => 'complete',
                ...$this->fillCostMetadata($event),
            ],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function fillCostMetadata(FakeExchangeEvent $event): array
    {
        $metadata = [];
        foreach ([
            'liquidity_role',
            'spread_cost_usdt',
            'slippage_cost_usdt',
            'cost_model_version',
            'spread_model_version',
        ] as $key) {
            if (array_key_exists($key, $event->payload)) {
                $metadata[$key] = $event->payload[$key];
            }
        }

        return $metadata;
    }

    private function fillFee(float $quantity, float $price): float
    {
        return round($quantity * $price * self::FEE_RATE, 12);
    }

    private function throwInjectedFault(
        FakeExchangeOperation $operation,
        FakeExchangeFaultOutcome $outcome,
    ): void {
        $fault = $this->stateStore->consumeFault($operation, $outcome);
        if ($fault !== null) {
            throw new FakeExchangeInjectedException($fault);
        }
    }
}
