<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\ExchangeOrderDto;
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
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeTp1TrailingPolicy;
use Psr\Clock\ClockInterface;

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
