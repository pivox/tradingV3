<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeFault;
use App\Exchange\Fake\FakeExchangeFaultKind;
use App\Exchange\Fake\FakeExchangeFaultOutcome;
use App\Exchange\Fake\FakeExchangeInjectedException;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOperation;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeExchangeWsClient;
use App\Exchange\Fake\FakeFallbackTakerPolicy;
use App\Exchange\Fake\FakePrivateWsException;
use App\Exchange\Fake\FakePrivateWsScenario;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeTp1TrailingPolicy;
use App\Exchange\Event\ExchangeEventBus;
use App\Exchange\Event\ExchangeEventInterface;
use App\Exchange\Event\ExchangeEventNormalizerRegistry;
use App\Exchange\Event\AbstractExchangeOrderEvent;
use App\Exchange\Event\AbstractExchangePositionEvent;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Event\ExchangeLocalProjectionStoreInterface;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Reconciliation\ExchangeReconciliationService;
use App\Exchange\Ws\ExchangeWsIngestionService;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;

final class FakePaperGoldenScenarioRunner
{
    private const KEYS = [
        'limit_maker_full_fill',
        'limit_unfilled_then_expired',
        'partial_fill_then_cancel',
        'fallback_taker',
        'market_with_slippage',
        'insufficient_balance',
        'precision_reject',
        'leverage_cap_reject',
        'duplicate_client_order_id',
        'timeout_after_acceptance',
        'stop_loss_attach_success',
        'stop_loss_attach_failure',
        'tp1_then_trailing',
        'gap_at_stop_loss',
        'websocket_disconnect_resync',
        'duplicate_out_of_order_event',
        'restart_with_open_position',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return self::KEYS;
    }

    /**
     * @return array{scenario:string,outcome:string,clock:string,facts:array<string,mixed>}
     */
    public function run(string $key): array
    {
        if (!\in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown Fake/Paper golden scenario "%s".', $key));
        }

        $facts = match ($key) {
            'limit_maker_full_fill' => $this->limitMakerFullFill(),
            'limit_unfilled_then_expired' => $this->limitUnfilledThenExpired(),
            'partial_fill_then_cancel' => $this->partialFillThenCancel(),
            'fallback_taker' => $this->fallbackTaker(),
            'market_with_slippage' => $this->marketWithSlippage(),
            'insufficient_balance' => $this->insufficientBalance(),
            'precision_reject' => $this->precisionReject(),
            'leverage_cap_reject' => $this->leverageCapReject(),
            'duplicate_client_order_id' => $this->duplicateClientOrderId(),
            'timeout_after_acceptance' => $this->timeoutAfterAcceptance(),
            'stop_loss_attach_success' => $this->stopLossAttachSuccess(),
            'stop_loss_attach_failure' => $this->stopLossAttachFailure(),
            'tp1_then_trailing' => $this->tp1ThenTrailing(),
            'gap_at_stop_loss' => $this->gapAtStopLoss(),
            'websocket_disconnect_resync' => $this->websocketDisconnectResync(),
            'duplicate_out_of_order_event' => $this->duplicateOutOfOrderEvent(),
            'restart_with_open_position' => $this->restartWithOpenPosition(),
        };

        return [
            'scenario' => $key,
            'outcome' => 'pass',
            'clock' => $this->clock()->now()->format(\DateTimeInterface::ATOM),
            'facts' => $facts,
        ];
    }

    /** @return array<string,mixed> */
    private function limitMakerFullFill(): array
    {
        [, $adapter, $scenario] = $this->exchange();
        $placed = $adapter->placeOrder($this->request(
            price: 25000.0,
            attachedStopLossPrice: 24800.0,
        ));

        $move = $scenario->movePrice('BTCUSDT', 24990.0, 0.0);
        $filled = $adapter->getOrder('BTCUSDT', (string) $placed->exchangeOrderId);
        $protectionOrders = $adapter->getOpenOrders('BTCUSDT');
        $position = $adapter->getOpenPositions('BTCUSDT')[0] ?? null;

        return [
            'filled_quantity' => $filled?->filledQuantity,
            'initial_order_status' => $placed->status->value,
            'matched_order_count' => \count($move['matched_orders']),
            'open_protection_count' => \count($protectionOrders),
            'order_status' => $filled?->status->value,
            'position_size' => $position?->size,
        ];
    }

    /** @return array<string,mixed> */
    private function limitUnfilledThenExpired(): array
    {
        [, $adapter] = $this->exchange();
        $result = $adapter->placeOrder($this->request(
            price: 24950.0,
            timeInForce: ExchangeTimeInForce::IOC,
        ));

        return [
            'open_order_count' => \count($adapter->getOpenOrders('BTCUSDT')),
            'order_status' => $result->status->value,
            'reason' => $result->order?->metadata['reason'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function partialFillThenCancel(): array
    {
        [, $adapter, $scenario] = $this->exchange();
        $placed = $adapter->placeOrder($this->request(
            price: 24950.0,
            postOnly: true,
            attachedStopLossPrice: 24800.0,
        ));
        $scenario->fillOrder((string) $placed->exchangeOrderId, 0.4, 24950.0);
        $adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $placed->exchangeOrderId,
            clientOrderId: $placed->clientOrderId,
        ));

        $cancelled = $adapter->getOrder('BTCUSDT', (string) $placed->exchangeOrderId);
        $position = $adapter->getOpenPositions('BTCUSDT')[0] ?? null;
        if ($cancelled !== null && $cancelled->filledQuantity > 0.0) {
            $adapter->placeOrder($this->protectionRequest(
                quantity: $cancelled->filledQuantity,
                stopPrice: 24800.0,
            ));
        }
        $protectionOrders = $adapter->getOpenOrders('BTCUSDT');
        $protection = $protectionOrders[0] ?? null;

        return [
            'filled_quantity' => $cancelled?->filledQuantity,
            'open_protection_count' => \count($protectionOrders),
            'order_status' => $cancelled?->status->value,
            'position_size' => $position?->size,
            'protection_quantity' => $protection?->quantity,
            'remaining_quantity' => $cancelled?->remainingQuantity,
        ];
    }

    /** @return array<string,mixed> */
    private function fallbackTaker(): array
    {
        [, $adapter, $scenario] = $this->exchange();
        $policy = new FakeFallbackTakerPolicy(
            enabled: true,
            zoneMin: 24900.0,
            zoneMax: 25100.0,
            maxSlippageBps: 30.0,
        );
        $placed = $adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'golden-fallback-taker',
            postOnly: true,
            attachedStopLossPrice: 24800.0,
            metadata: $policy->toMetadata() + [
                'internal_trade_id' => 'golden-fallback-trade',
                'api_key' => 'TOP-SECRET',
                'raw_payload' => ['authorization' => 'Bearer SECRET'],
            ],
        ));
        $scenario->fillOrder((string) $placed->exchangeOrderId, 0.4, 24950.0);
        $result = $scenario->fallbackTaker((string) $placed->exchangeOrderId);
        $fills = $adapter->getFillsSnapshot('BTCUSDT');
        $makerFill = $fills[0] ?? null;
        $takerFill = $fills[1] ?? null;
        $position = $adapter->getOpenPositions('BTCUSDT')[0] ?? null;
        $protection = $adapter->getOpenOrders('BTCUSDT')[0] ?? null;
        $serialized = json_encode([
            $result->parentOrder?->metadata,
            $result->fallbackOrder?->metadata,
            array_map(static fn (FakeExchangeEvent $event): array => $event->toArray(), $scenario->events()),
        ], JSON_THROW_ON_ERROR);
        $takerNotional = ($takerFill?->quantity ?? 0.0) * ($takerFill?->price ?? 0.0);
        $takerSlippageCost = $takerFill?->metadata['slippage_cost_usdt'] ?? null;

        return [
            'fallback_client_linked' => $result->fallbackOrder?->clientOrderId !== null
                && str_starts_with($result->fallbackOrder->clientOrderId, 'fake-fallback-')
                && ($result->fallbackOrder->metadata['fallback_parent_order_id'] ?? null)
                    === $result->parentOrder?->exchangeOrderId,
            'fallback_quantity' => $result->fallbackOrder?->quantity,
            'fallback_status' => $result->fallbackOrder?->status->value,
            'fallback_trigger' => $result->fallbackOrder?->metadata['fallback_trigger'] ?? null,
            'fallback_type' => $result->fallbackOrder?->orderType->value,
            'maker_liquidity_role' => $makerFill?->metadata['liquidity_role'] ?? null,
            'maker_slippage_cost_usdt' => $makerFill?->metadata['slippage_cost_usdt'] ?? null,
            'metadata_redacted' => !str_contains($serialized, 'TOP-SECRET')
                && !str_contains($serialized, 'Bearer SECRET')
                && !str_contains($serialized, 'api_key')
                && !str_contains($serialized, 'raw_payload'),
            'parent_filled_quantity' => $result->parentOrder?->filledQuantity,
            'parent_remaining_quantity' => $result->parentOrder?->remainingQuantity,
            'parent_status' => $result->parentOrder?->status->value,
            'position_entry_order_count' => $position?->metadata['entry_order_count'] ?? null,
            'position_size' => $position?->size,
            'protection_quantity' => $protection?->quantity,
            'slippage_guard_bps' => $result->slippageBps !== null
                ? round($result->slippageBps, 6)
                : null,
            'taker_liquidity_role' => $takerFill?->metadata['liquidity_role'] ?? null,
            'taker_slippage_bps' => is_numeric($takerSlippageCost) && $takerNotional > 0.0
                ? round(((float) $takerSlippageCost / $takerNotional) * 10_000.0, 6)
                : null,
            'taker_slippage_cost_usdt' => is_numeric($takerSlippageCost)
                ? round((float) $takerSlippageCost, 6)
                : null,
        ];
    }

    /** @return array<string,mixed> */
    private function marketWithSlippage(): array
    {
        [, $adapter] = $this->exchange();
        $adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'golden-market-with-slippage',
        ));
        $fill = $adapter->getFillsSnapshot('BTCUSDT')[0] ?? null;
        if ($fill === null) {
            throw new \LogicException('The market-with-slippage fixture did not produce a fill.');
        }

        $notional = $fill->quantity * $fill->price;
        $slippageCost = $fill->metadata['slippage_cost_usdt'] ?? null;
        if (!is_numeric($slippageCost) || $notional <= 0.0) {
            throw new \LogicException('The market-with-slippage fixture did not expose a valid cost.');
        }

        return [
            'cost_model_version' => $fill->metadata['cost_model_version'] ?? null,
            'execution_price' => round($fill->price, 6),
            'liquidity_role' => $fill->metadata['liquidity_role'] ?? null,
            'notional_usdt' => round($notional, 6),
            'slippage_bps' => round(((float) $slippageCost / $notional) * 10_000.0, 6),
            'slippage_cost_usdt' => round((float) $slippageCost, 6),
            'spread_cost_usdt' => $fill->metadata['spread_cost_usdt'] ?? null,
            'spread_model_version' => $fill->metadata['spread_model_version'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function insufficientBalance(): array
    {
        [$state, $adapter] = $this->exchange();
        $availableMarginBefore = $adapter->getBalances()[0]->available;
        $request = $this->request(
            price: 24950.0,
            clientOrderId: 'golden-insufficient-balance',
            postOnly: true,
            quantity: 13.0,
        );
        $result = $adapter->placeOrder($request);

        return $this->rejectionAuditFacts($state, $adapter, $result, $availableMarginBefore) + [
            'available_margin_before' => $availableMarginBefore,
            'persisted_quantity' => $result->order?->quantity,
            'requested_initial_margin' => round(
                $request->quantity * (float) $request->price / (float) ($request->leverage ?? 1),
                6,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function precisionReject(): array
    {
        [$state, $adapter] = $this->exchange();
        $availableMarginBefore = $adapter->getBalances()[0]->available;
        $request = $this->request(
            price: 24950.01,
            clientOrderId: 'golden-precision-reject',
            postOnly: true,
        );
        $result = $adapter->placeOrder($request);
        $instrument = (new FakeInstrumentCatalog())->find('BTCUSDT');

        return $this->rejectionAuditFacts($state, $adapter, $result, $availableMarginBefore) + [
            'persisted_price' => $result->order?->price,
            'price_tick' => $instrument?->priceTick,
            'submitted_price' => $request->price,
        ];
    }

    /** @return array<string,mixed> */
    private function leverageCapReject(): array
    {
        [$state, $adapter] = $this->exchange();
        $availableMarginBefore = $adapter->getBalances()[0]->available;
        $instrument = (new FakeInstrumentCatalog())->find('BTCUSDT');
        $request = $this->request(
            price: 24950.0,
            clientOrderId: 'golden-leverage-cap-reject',
            postOnly: true,
            leverage: 101,
        );
        $result = $adapter->placeOrder($request);

        return $this->rejectionAuditFacts($state, $adapter, $result, $availableMarginBefore) + [
            'leverage_setting_count' => \count($state->leverageSettings()),
            'max_leverage' => $instrument?->maxLeverage,
            'persisted_leverage' => $result->order?->metadata['leverage'] ?? null,
            'requested_leverage' => $request->leverage,
        ];
    }

    /** @return array<string,mixed> */
    private function rejectionAuditFacts(
        FakeExchangeStateStore $state,
        FakeExchangeAdapter $adapter,
        PlaceOrderResult $result,
        float $availableMarginBefore,
    ): array {
        $events = $state->events();
        $rejectionEvents = $state->events('order.rejected');
        $persisted = $result->exchangeOrderId !== null
            ? $adapter->getOrder($result->symbol, $result->exchangeOrderId)
            : null;
        $availableMarginAfter = $adapter->getBalances()[0]->available;

        return [
            'accepted' => $result->accepted,
            'available_margin_unchanged' => $availableMarginBefore === $availableMarginAfter,
            'event_count' => \count($events),
            'event_types' => array_map(
                static fn (FakeExchangeEvent $event): string => $event->type,
                $events,
            ),
            'exchange_order_id' => $result->exchangeOrderId,
            'open_order_count' => \count($adapter->getOpenOrders($result->symbol)),
            'open_position_count' => \count($adapter->getOpenPositions($result->symbol)),
            'order_count' => \count($adapter->getOrdersSnapshot($result->symbol)),
            'order_status' => $result->status->value,
            'persisted_filled_quantity' => $persisted?->filledQuantity,
            'persisted_identity_matches_result' => $result->exchangeOrderId !== null
                && $persisted?->exchangeOrderId === $result->exchangeOrderId,
            'persisted_order_status' => $persisted?->status->value,
            'reason' => $result->metadata['reason'] ?? null,
            'rejection_event_count' => \count($rejectionEvents),
            'rejection_event_order_id' => $rejectionEvents[0]->payload['order_id'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function duplicateClientOrderId(): array
    {
        [, $adapter] = $this->exchange();
        $request = $this->request(price: 24950.0, postOnly: true);
        $first = $adapter->placeOrder($request);
        $replay = $adapter->placeOrder($request);

        return [
            'active_order_count' => \count($adapter->getOpenOrders('BTCUSDT')),
            'idempotent_replay' => $replay->metadata['idempotent_replay'] ?? false,
            'same_exchange_order_id' => $first->exchangeOrderId === $replay->exchangeOrderId,
        ];
    }

    /** @return array<string,mixed> */
    private function timeoutAfterAcceptance(): array
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $request = $this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'timeout-after-acceptance',
            attachedStopLossPrice: 24000.0,
        );
        $scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::PlaceOrder,
            FakeExchangeFaultKind::NetworkTimeout,
            FakeExchangeFaultOutcome::AppliedResponseLost,
        ));

        try {
            $adapter->placeOrder($request);
            throw new \LogicException('The timeout-after-acceptance fixture did not lose its response.');
        } catch (FakeExchangeInjectedException $exception) {
            $injected = $exception;
        }

        $accepted = $state->getOrderByClientOrderId('BTCUSDT', 'timeout-after-acceptance');
        $orderCountBeforeReplay = \count($state->getOrders());
        $eventCountBeforeReplay = \count($state->events());
        $replay = $adapter->placeOrder($request);

        return [
            'error_code' => $injected->fault->kind->value,
            'event_count_unchanged' => $eventCountBeforeReplay === \count($state->events()),
            'idempotent_replay' => $replay->metadata['idempotent_replay'] ?? false,
            'order_count_unchanged' => $orderCountBeforeReplay === \count($state->getOrders()),
            'outcome_unknown' => $injected->outcomeUnknown(),
            'open_protection_count' => \count($adapter->getOpenOrders('BTCUSDT')),
            'protection_status' => $replay->order?->metadata['protection_status'] ?? null,
            'same_exchange_order_id' => $accepted?->exchangeOrderId === $replay->exchangeOrderId,
        ];
    }

    /** @return array<string,mixed> */
    private function stopLossAttachSuccess(): array
    {
        [, $adapter] = $this->exchange();
        $entry = $adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            attachedStopLossPrice: 24800.0,
        ));
        $protectionOrders = $adapter->getOpenOrders('BTCUSDT');
        $protection = $protectionOrders[0] ?? null;

        return [
            'entry_status' => $entry->status->value,
            'open_protection_count' => \count($protectionOrders),
            'protection_reduce_only' => $protection?->reduceOnly,
            'protection_status' => $entry->order?->metadata['protection_status'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function stopLossAttachFailure(): array
    {
        [, $adapter, $scenario] = $this->exchange();
        $scenario->rejectNextProtectionOrder();
        $entry = $adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'golden-stop-attach-failure',
            attachedStopLossPrice: 24800.0,
        ));
        $compensationOrderId = $entry->order?->metadata['compensation_order_id'] ?? null;
        $compensation = \is_string($compensationOrderId)
            ? $adapter->getOrder('BTCUSDT', $compensationOrderId)
            : null;

        return [
            'compensation_order_status' => $compensation?->status->value,
            'compensation_order_type' => $compensation?->orderType->value,
            'compensation_outcome' => $entry->order?->metadata['compensation_outcome'] ?? null,
            'compensation_reduce_only' => $compensation?->reduceOnly,
            'compensation_status' => $entry->order?->metadata['compensation_status'] ?? null,
            'entry_status' => $entry->status->value,
            'fail_safe_action' => $entry->order?->metadata['fail_safe_action'] ?? null,
            'open_order_count' => \count($adapter->getOpenOrders('BTCUSDT')),
            'open_position_count' => \count($adapter->getOpenPositions('BTCUSDT')),
            'position_closed_count' => \count($scenario->events('position.closed')),
            'protection_status' => $entry->order?->metadata['protection_status'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function tp1ThenTrailing(): array
    {
        $fixtures = $this->tp1TrailingFixtures();
        $facts = ['fixture_version' => $fixtures['schema_version']];
        foreach ($fixtures['cases'] as $fixture) {
            $name = $fixture['name'] ?? null;
            if (!\is_string($name) || !\in_array($name, ['long', 'short'], true)) {
                throw new \LogicException('The TP1 trailing fixture direction is invalid.');
            }

            $facts[$name] = $this->tp1TrailingDirectionFacts($fixture);
        }

        return $facts;
    }

    /**
     * @param array<string,mixed> $fixture
     * @return array<string,mixed>
     */
    private function tp1TrailingDirectionFacts(array $fixture): array
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_paper_golden_tp1_trailing_');
        if ($stateFile === false) {
            throw new \RuntimeException('Unable to allocate the TP1 trailing golden state file.');
        }
        @unlink($stateFile);

        try {
            [$state, $adapter, $scenario] = $this->exchange($stateFile);
            $adapter->placeOrder($this->tp1TrailingRequest($fixture));
            $tp1 = $this->goldenOrderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
            $scenario->fillOrder(
                $tp1->exchangeOrderId,
                null,
                (float) $fixture['tp1_price'],
            );
            $trailing = $this->goldenOrderByType($adapter, ExchangeOrderType::TRIGGER);
            $activationStop = $trailing->stopPrice;
            $firstFavorable = (float) $fixture['favorable_prices'][0];
            $scenario->movePrice((string) $fixture['symbol'], $firstFavorable, 0.0);
            $firstRatcheted = $adapter->getOrder((string) $fixture['symbol'], $trailing->exchangeOrderId);
            $eventsBeforeRestart = \count($state->events());

            $restoredState = new FakeExchangeStateStore($stateFile);
            [, $restoredAdapter, $restoredScenario] = $this->exchangeForState($restoredState);
            $restoredTrailing = $restoredAdapter->getOrder(
                (string) $fixture['symbol'],
                $trailing->exchangeOrderId,
            );
            $watermarkAfterRestart = $restoredTrailing?->metadata['trailing_watermark'] ?? null;
            $restartRestored = $restoredState->recoveryMetadata()['restored']
                && $watermarkAfterRestart === $firstFavorable
                && \count($restoredState->events()) === $eventsBeforeRestart;

            $eventsBeforeDuplicate = \count($restoredState->events());
            $restoredScenario->movePrice((string) $fixture['symbol'], $firstFavorable, 0.0);
            $duplicatePriceIdempotent = \count($restoredState->events()) === $eventsBeforeDuplicate;

            $secondFavorable = (float) $fixture['favorable_prices'][1];
            $restoredScenario->movePrice((string) $fixture['symbol'], $secondFavorable, 0.0);
            $finalTrailing = $restoredAdapter->getOrder(
                (string) $fixture['symbol'],
                $trailing->exchangeOrderId,
            );
            $gap = $restoredScenario->movePrice(
                (string) $fixture['symbol'],
                (float) $fixture['gap_price'],
                0.0,
            );
            $gapFill = $gap['matched_orders'][0] ?? null;
            if (!$gapFill instanceof ExchangeOrderDto) {
                throw new \LogicException('The TP1 trailing golden gap did not fill.');
            }

            $ordersBeforeReplay = \count($restoredState->getOrders((string) $fixture['symbol']));
            $eventsBeforeReplay = \count($restoredState->events());
            $restoredScenario->movePrice(
                (string) $fixture['symbol'],
                (float) $fixture['gap_price'],
                0.0,
            );
            $restoredScenario->fillOrder($trailing->exchangeOrderId);
            $terminalReplayIdempotent = $ordersBeforeReplay
                    === \count($restoredState->getOrders((string) $fixture['symbol']))
                && $eventsBeforeReplay === \count($restoredState->events());

            $closed = $restoredState->events('position.closed')[0] ?? null;
            if (!$closed instanceof FakeExchangeEvent) {
                throw new \LogicException('The TP1 trailing golden fixture did not close its position.');
            }
            $serialized = (string) file_get_contents($stateFile);
            $positionSide = ExchangePositionSide::from((string) $fixture['position_side']);
            $firstStop = $firstRatcheted?->stopPrice;
            $finalStop = $finalTrailing?->stopPrice;
            $stopMonotone = $activationStop !== null && $firstStop !== null && $finalStop !== null
                && ($positionSide === ExchangePositionSide::LONG
                    ? $activationStop <= $firstStop && $firstStop <= $finalStop
                    : $activationStop >= $firstStop && $firstStop >= $finalStop);

            return [
                'activation_stop' => $activationStop,
                'armed_event_count' => \count($restoredState->events('trailing_stop.armed')),
                'cost_completeness' => $closed->payload['cost_completeness'] ?? null,
                'duplicate_price_idempotent' => $duplicatePriceIdempotent,
                'entry_quantity' => (float) $fixture['quantity'],
                'fill_count' => \count($restoredAdapter->getFillsSnapshot((string) $fixture['symbol'])),
                'gap_fill_price' => round((float) $gapFill->averagePrice, 6),
                'metadata_redacted' => !str_contains($serialized, 'TOP-SECRET')
                    && !str_contains($serialized, 'Bearer SECRET')
                    && !str_contains($serialized, 'api_key')
                    && !str_contains($serialized, 'raw_payload'),
                'open_order_count' => \count($restoredAdapter->getOpenOrders((string) $fixture['symbol'])),
                'open_position_count' => \count($restoredAdapter->getOpenPositions((string) $fixture['symbol'])),
                'quantity_coherent' => $closed->payload['quantity_coherent'] ?? null,
                'restart_restored' => $restartRestored,
                'stop_monotone' => $stopMonotone,
                'terminal_replay_idempotent' => $terminalReplayIdempotent,
                'tp1_quantity' => $tp1->quantity,
                'trailing_offset' => (float) $fixture['trailing_offset'],
                'trailing_quantity' => $trailing->quantity,
                'triggered_event_count' => \count($restoredState->events('trailing_stop.triggered')),
                'updated_event_count' => \count($restoredState->events('trailing_stop.updated')),
                'watermark_after_restart' => $watermarkAfterRestart,
            ];
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
            @unlink($stateFile . '.private-ws-consumer.lock');
            foreach (glob($stateFile . '.tmp.*') ?: [] as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }

    /**
     * @return array{schema_version:string,cases:list<array<string,mixed>>}
     */
    private function tp1TrailingFixtures(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fake-paper/tp1-trailing-v1.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \LogicException('The TP1 trailing golden fixture is unavailable.');
        }

        /** @var array{schema_version:string,cases:list<array<string,mixed>>} $fixtures */
        $fixtures = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if ($fixtures['schema_version'] !== 'fake-tp1-trailing-fixtures-v1') {
            throw new \LogicException('The TP1 trailing golden fixture version is unsupported.');
        }

        return $fixtures;
    }

    /** @param array<string,mixed> $fixture */
    private function tp1TrailingRequest(array $fixture): PlaceOrderRequest
    {
        $policy = new FakeTp1TrailingPolicy(
            (string) $fixture['tp1_quantity'],
            (string) $fixture['trailing_offset'],
        );

        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: (string) $fixture['symbol'],
            side: ExchangeOrderSide::from((string) $fixture['entry_side']),
            positionSide: ExchangePositionSide::from((string) $fixture['position_side']),
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: (float) $fixture['quantity'],
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'golden-tp1-trailing-' . $fixture['name'],
            attachedStopLossPrice: (float) $fixture['initial_stop'],
            attachedTakeProfitPrice: (float) $fixture['tp1_price'],
            metadata: $policy->toMetadata() + [
                'internal_trade_id' => 'golden-tp1-trailing-' . $fixture['name'],
                'api_key' => 'TOP-SECRET',
                'raw_payload' => ['authorization' => 'Bearer SECRET'],
            ],
            quantityDecimal: (string) $fixture['quantity'],
            attachedStopLossPriceDecimal: (string) $fixture['initial_stop'],
            attachedTakeProfitPriceDecimal: (string) $fixture['tp1_price'],
        );
    }

    private function goldenOrderByType(
        FakeExchangeAdapter $adapter,
        ExchangeOrderType $type,
    ): ExchangeOrderDto {
        foreach ($adapter->getOrdersSnapshot('BTCUSDT') as $order) {
            if ($order->orderType === $type) {
                return $order;
            }
        }

        throw new \LogicException(sprintf('Missing golden %s order.', $type->value));
    }

    /** @return array<string,mixed> */
    private function gapAtStopLoss(): array
    {
        [, $adapter, $scenario] = $this->exchange();
        $adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            attachedStopLossPrice: 24800.0,
        ));
        $move = $scenario->movePrice('BTCUSDT', 24790.0, 0.0);
        $stop = $move['matched_orders'][0] ?? null;

        return [
            'fill_price' => $stop?->averagePrice !== null ? round($stop->averagePrice, 6) : null,
            'open_position_count' => \count($adapter->getOpenPositions('BTCUSDT')),
            'order_status' => $stop?->status->value,
            'stop_price' => $stop?->stopPrice,
        ];
    }

    /** @return array<string,mixed> */
    private function websocketDisconnectResync(): array
    {
        [$state, $adapter] = $this->exchange();
        $adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'golden-ws-btc',
            attachedStopLossPrice: 24000.0,
        ));
        $adapter->placeOrder($this->request(
            symbol: 'ETHUSDT',
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'golden-ws-eth',
            attachedStopLossPrice: 24000.0,
        ));

        $expectedSignatures = [];
        $this->drainSignatures(new FakeExchangeWsClient($state), $expectedSignatures);

        $client = new FakeExchangeWsClient($state, disconnectAfterAcknowledgedEvents: 2);
        $actualSignatures = [];
        try {
            $this->drainSignatures($client, $actualSignatures);
            throw new \LogicException('The Fake private WS fixture did not disconnect.');
        } catch (FakePrivateWsException $exception) {
            $disconnectCode = $exception->errorCode;
        }

        $eventsBeforeDisconnect = \count($actualSignatures);
        $resyncRequired = $client->requiresResync();
        $client->reconnect();
        $this->drainSignatures($client, $actualSignatures);
        $afterCompleteDrain = [];
        $this->drainSignatures($client, $afterCompleteDrain);

        return [
            'disconnect_code' => $disconnectCode,
            'events_before_disconnect' => $eventsBeforeDisconnect,
            'open_protection_count' => \count($adapter->getOpenOrders()),
            'resumed_without_duplicate_or_loss' => $actualSignatures === $expectedSignatures
                && \count($actualSignatures) === \count(array_unique($actualSignatures))
                && $afterCompleteDrain === [],
            'resync_required_before_reconnect' => $resyncRequired,
        ];
    }

    /** @return array<string,mixed> */
    private function duplicateOutOfOrderEvent(): array
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_paper_golden_private_ws_');
        $conflictStateFile = tempnam(sys_get_temp_dir(), 'fake_paper_golden_private_ws_conflict_');
        if ($stateFile === false || $conflictStateFile === false) {
            throw new \RuntimeException('Unable to allocate the private WS golden state files.');
        }
        @unlink($stateFile);
        @unlink($conflictStateFile);

        try {
            $fixture = $this->privateWsFixture();
            $scenarioPayload = $fixture['scenario'] ?? null;
            $conflictPayload = $fixture['conflict_scenario'] ?? null;
            $resumePayload = $fixture['resume_event'] ?? null;
            if (!\is_array($scenarioPayload) || !\is_array($conflictPayload) || !\is_array($resumePayload)) {
                throw new \LogicException('The private WS golden fixture shape is invalid.');
            }

            [$state, $adapter] = $this->exchange($stateFile);
            $adapter->placeOrder($this->request(
                orderType: ExchangeOrderType::MARKET,
                price: null,
                clientOrderId: 'golden-private-ws',
            ));
            $state->configurePrivateWsScenario(FakePrivateWsScenario::fromArray($scenarioPayload));

            $projectionStore = new GoldenPrivateWsProjectionStore();
            $bus = new ExchangeEventBus($projectionStore, new NullLogger());
            $ingestion = new ExchangeWsIngestionService(
                new ExchangeEventNormalizerRegistry([new FakeExchangeEventNormalizer()]),
                $bus,
                new NullLogger(),
            );
            $client = new FakeExchangeWsClient($state);
            try {
                $ingestion->drain($client);
                throw new \LogicException('The private WS golden fixture did not create a gap.');
            } catch (FakePrivateWsException $exception) {
                $gapCode = $exception->errorCode;
            }

            $projectedAtGap = $projectionStore->projectedCount;
            try {
                $ingestion->drain($client);
                throw new \LogicException('The private WS golden fixture projected while resync was required.');
            } catch (FakePrivateWsException $exception) {
                if ($exception->errorCode !== 'fake_private_ws_snapshot_resync_required') {
                    throw $exception;
                }
            }
            $noProjectionAfterGap = $projectionStore->projectedCount === $projectedAtGap;
            $gapAudit = $client->audit();

            $restoredState = new FakeExchangeStateStore($stateFile);
            $restoredClient = new FakeExchangeWsClient($restoredState);
            $restartPreservedResync = $restoredClient->requiresResync()
                && $restoredClient->audit()['resync_reason'] === 'fake_private_ws_sequence_gap';

            [, $restoredAdapter] = $this->exchangeForState($restoredState);
            $reconciliationResult = (new ExchangeReconciliationService(
                $bus,
                $projectionStore,
                $this->clock(),
                new NullLogger(),
            ))->reconcile($restoredAdapter);
            $restoredClient->completeSnapshotResync($reconciliationResult);

            $restoredState->appendEvent($this->fakeEventFromArray($resumePayload));
            $resumed = $ingestion->drain($restoredClient);
            $resyncAudit = $restoredClient->audit();
            $resumedContiguously = $resumed->rawEventsRead === 1
                && $resyncAudit['last_acknowledged_sequence'] === '4';
            $normalizedSignatures = $projectionStore->normalizedSignatures();

            $conflictState = new FakeExchangeStateStore($conflictStateFile);
            $conflictState->configurePrivateWsScenario(FakePrivateWsScenario::fromArray($conflictPayload));
            try {
                $ingestion->drain(new FakeExchangeWsClient($conflictState));
                throw new \LogicException('The private WS conflict fixture did not fail closed.');
            } catch (FakePrivateWsException $exception) {
                $conflictCode = $exception->errorCode;
            }
            $conflictAudit = $conflictState->privateWsAudit();

            return [
                'conflict_code' => $conflictCode,
                'conflict_total' => $conflictAudit['conflict_total'],
                'duplicate_total' => $gapAudit['duplicate_total'],
                'gap_code' => $gapCode,
                'gap_total' => $gapAudit['gap_total'],
                'no_projection_after_gap' => $noProjectionAfterGap,
                'normalized_projection_count' => \count($normalizedSignatures),
                'normalized_projection_signatures' => $normalizedSignatures,
                'normalized_projections_unique' => \count($normalizedSignatures) === \count(array_unique($normalizedSignatures)),
                'resync_total' => $resyncAudit['resync_total'],
                'restart_preserved_resync' => $restartPreservedResync,
                'resumed_contiguously' => $resumedContiguously,
            ];
        } finally {
            foreach ([$stateFile, $conflictStateFile] as $file) {
                @unlink($file);
                @unlink($file . '.lock');
                @unlink($file . '.private-ws-consumer.lock');
                foreach (glob($file . '.tmp.*') ?: [] as $temporaryFile) {
                    @unlink($temporaryFile);
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function privateWsFixture(): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fake-paper/private-ws-out-of-order-v1.json';
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \LogicException('The private WS golden fixture is unavailable.');
        }

        /** @var array<string,mixed> $fixture */
        $fixture = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (($fixture['schema_version'] ?? null) !== 'fake-private-ws-out-of-order-v1') {
            throw new \LogicException('The private WS golden fixture version is unsupported.');
        }

        return $fixture;
    }

    /** @param array<string,mixed> $payload */
    private function fakeEventFromArray(array $payload): FakeExchangeEvent
    {
        $type = $payload['type'] ?? null;
        $symbol = $payload['symbol'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;
        $eventPayload = $payload['payload'] ?? null;
        if (
            !\is_string($type)
            || !\is_string($symbol)
            || !\is_string($occurredAt)
            || !\is_array($eventPayload)
        ) {
            throw new \LogicException('The private WS resume event is invalid.');
        }

        return new FakeExchangeEvent(
            $type,
            $symbol,
            new \DateTimeImmutable($occurredAt),
            $eventPayload,
        );
    }

    /** @return array<string,mixed> */
    private function restartWithOpenPosition(): array
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_paper_golden_restart_');
        if ($stateFile === false) {
            throw new \RuntimeException('Unable to allocate the Fake/Paper restart state file.');
        }
        @unlink($stateFile);

        try {
            [$state, $adapter] = $this->exchange($stateFile);
            $adapter->placeOrder($this->request(
                orderType: ExchangeOrderType::MARKET,
                price: null,
                attachedStopLossPrice: 24800.0,
            ));
            $sequenceBeforeRestart = $this->eventSequences($state->events());

            $restoredState = new FakeExchangeStateStore($stateFile);
            [, $restoredAdapter, $restoredScenario] = $this->exchangeForState($restoredState);
            $recovery = $restoredState->recoveryMetadata();
            $position = $restoredAdapter->getOpenPositions('BTCUSDT')[0] ?? null;
            $protectionOrderCount = \count($restoredAdapter->getOpenOrders('BTCUSDT'));

            $restoredScenario->movePrice('BTCUSDT', 24790.0, 0.0);
            $sequenceAfterRestart = $this->eventSequences($restoredState->events());
            $sortedSequences = $sequenceAfterRestart;
            sort($sortedSequences);
            $lastBeforeRestart = max($sequenceBeforeRestart);

            return [
                'event_sequence_continued' => $recovery['next_event_sequence'] === $lastBeforeRestart + 1
                    && max($sequenceAfterRestart) > $lastBeforeRestart
                    && $sequenceAfterRestart === array_values(array_unique($sequenceAfterRestart))
                    && $sequenceAfterRestart === $sortedSequences,
                'historical_events_preserved' => $sequenceBeforeRestart === array_slice(
                    $sequenceAfterRestart,
                    0,
                    \count($sequenceBeforeRestart),
                ),
                'position_size' => $position?->size,
                'protection_order_count' => $protectionOrderCount,
            ];
        } finally {
            @unlink($stateFile);
            foreach (glob($stateFile . '.tmp.*') ?: [] as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }

    /**
     * @return array{FakeExchangeStateStore,FakeExchangeAdapter,FakeExchangeScenarioService}
     */
    private function exchange(?string $stateFile = null): array
    {
        return $this->exchangeForState(new FakeExchangeStateStore($stateFile));
    }

    /**
     * @return array{FakeExchangeStateStore,FakeExchangeAdapter,FakeExchangeScenarioService}
     */
    private function exchangeForState(FakeExchangeStateStore $state): array
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->clock());

        return [
            $state,
            new FakeExchangeAdapter($state, $book, $engine, $this->clock()),
            new FakeExchangeScenarioService($state, $book, $engine),
        ];
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function request(
        string $symbol = 'BTCUSDT',
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ?float $price = 24950.0,
        string $clientOrderId = 'golden-client-order',
        bool $postOnly = false,
        ExchangeTimeInForce $timeInForce = ExchangeTimeInForce::GTC,
        ?float $attachedStopLossPrice = null,
        float $quantity = 1.0,
        ?int $leverage = 3,
        array $metadata = [],
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: $orderType,
            timeInForce: $timeInForce,
            quantity: $quantity,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: $postOnly,
            leverage: $leverage,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachedStopLossPrice,
            metadata: $metadata,
        );
    }

    private function protectionRequest(float $quantity, float $stopPrice): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::STOP_LOSS,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: $quantity,
            price: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'golden-partial-protection',
        );
    }

    /** @param list<string> $signatures */
    private function drainSignatures(FakeExchangeWsClient $client, array &$signatures): void
    {
        foreach ($client->drainPrivateEvents() as $event) {
            $signatures[] = $this->eventSignature($event);
        }
    }

    private function eventSignature(FakeExchangeEvent $event): string
    {
        return hash('sha256', serialize($event->toArray()));
    }

    /**
     * @param FakeExchangeEvent[] $events
     * @return list<int>
     */
    private function eventSequences(array $events): array
    {
        return array_map(
            static fn (FakeExchangeEvent $event): int => (int) ($event->payload['event_sequence'] ?? 0),
            $events,
        );
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}

final class GoldenPrivateWsProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    public int $projectedCount = 0;

    /** @var list<string> */
    private array $signatures = [];

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        return false;
    }

    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        return [];
    }

    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array
    {
        return [];
    }

    public function project(ExchangeEventInterface $event): void
    {
        ++$this->projectedCount;
        $this->signatures[] = $this->normalizedSignature($event);
    }

    public function projectAtomically(array $events): void
    {
        $before = $this->projectedCount;
        $signaturesBefore = $this->signatures;
        try {
            foreach ($events as $event) {
                $this->project($event);
            }
        } catch (\Throwable $exception) {
            $this->projectedCount = $before;
            $this->signatures = $signaturesBefore;

            throw $exception;
        }
    }

    /** @return list<string> */
    public function normalizedSignatures(): array
    {
        return $this->signatures;
    }

    private function normalizedSignature(ExchangeEventInterface $event): string
    {
        $semantics = [
            'event_type' => $event->eventType(),
            'exchange' => $event->exchange()->value,
            'market_type' => $event->marketType()->value,
            'symbol' => $event->symbol(),
            'occurred_at' => $event->occurredAt(),
            'payload' => $event->payload(),
        ];
        if ($event instanceof AbstractExchangeOrderEvent) {
            $semantics['order'] = $this->orderSemantics($event->order());
        }
        if ($event instanceof ExchangeFillReceived) {
            $semantics['fill'] = $this->fillSemantics($event->fill());
        }
        if ($event instanceof AbstractExchangePositionEvent) {
            $semantics['position_event'] = [
                'side' => $event->side()->value,
                'size' => $event->size(),
                'position' => $event->position() instanceof ExchangePositionDto
                    ? $this->positionSemantics($event->position())
                    : null,
            ];
        }

        return hash('sha256', json_encode(
            $this->canonicalValue($semantics),
            JSON_THROW_ON_ERROR
                | JSON_PRESERVE_ZERO_FRACTION
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE,
        ));
    }

    /** @return array<string,mixed> */
    private function orderSemantics(ExchangeOrderDto $order): array
    {
        return [
            'exchange' => $order->exchange->value,
            'market_type' => $order->marketType->value,
            'symbol' => $order->symbol,
            'exchange_order_id' => $order->exchangeOrderId,
            'client_order_id' => $order->clientOrderId,
            'side' => $order->side->value,
            'position_side' => $order->positionSide?->value,
            'order_type' => $order->orderType->value,
            'status' => $order->status->value,
            'quantity' => $order->quantity,
            'filled_quantity' => $order->filledQuantity,
            'remaining_quantity' => $order->remainingQuantity,
            'price' => $order->price,
            'average_price' => $order->averagePrice,
            'stop_price' => $order->stopPrice,
            'reduce_only' => $order->reduceOnly,
            'post_only' => $order->postOnly,
            'time_in_force' => $order->timeInForce?->value,
            'created_at' => $order->createdAt,
            'updated_at' => $order->updatedAt,
            'metadata' => $order->metadata,
        ];
    }

    /** @return array<string,mixed> */
    private function fillSemantics(ExchangeFillDto $fill): array
    {
        return [
            'exchange' => $fill->exchange->value,
            'market_type' => $fill->marketType->value,
            'symbol' => $fill->symbol,
            'exchange_order_id' => $fill->exchangeOrderId,
            'client_order_id' => $fill->clientOrderId,
            'fill_id' => $fill->fillId,
            'side' => $fill->side->value,
            'position_side' => $fill->positionSide?->value,
            'quantity' => $fill->quantity,
            'price' => $fill->price,
            'fee' => $fill->fee,
            'fee_currency' => $fill->feeCurrency,
            'filled_at' => $fill->filledAt,
            'metadata' => $fill->metadata,
        ];
    }

    /** @return array<string,mixed> */
    private function positionSemantics(ExchangePositionDto $position): array
    {
        return [
            'exchange' => $position->exchange->value,
            'market_type' => $position->marketType->value,
            'symbol' => $position->symbol,
            'side' => $position->side->value,
            'size' => $position->size,
            'entry_price' => $position->entryPrice,
            'mark_price' => $position->markPrice,
            'unrealized_pnl' => $position->unrealizedPnl,
            'realized_pnl' => $position->realizedPnl,
            'margin' => $position->margin,
            'leverage' => $position->leverage,
            'opened_at' => $position->openedAt,
            'updated_at' => $position->updatedAt,
            'metadata' => $position->metadata,
        ];
    }

    private function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\\TH:i:s.u\\Z');
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if (\is_float($value) && !\is_finite($value)) {
            throw new \LogicException('Golden normalized semantics contain a non-finite number.');
        }
        if (!\is_array($value)) {
            if ($value === null || \is_scalar($value)) {
                return $value;
            }

            throw new \LogicException(sprintf(
                'Golden normalized semantics contain unsupported value type %s.',
                get_debug_type($value),
            ));
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalValue($item);
        }
        if (!array_is_list($canonical)) {
            ksort($canonical, SORT_STRING);
        }

        return $canonical;
    }
}
