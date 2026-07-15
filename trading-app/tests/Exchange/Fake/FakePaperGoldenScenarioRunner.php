<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
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
use App\Exchange\Fake\FakePrivateWsException;
use Psr\Clock\ClockInterface;

final class FakePaperGoldenScenarioRunner
{
    private const KEYS = [
        'limit_maker_full_fill',
        'limit_unfilled_then_expired',
        'partial_fill_then_cancel',
        'duplicate_client_order_id',
        'timeout_after_acceptance',
        'stop_loss_attach_success',
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
            'duplicate_client_order_id' => $this->duplicateClientOrderId(),
            'timeout_after_acceptance' => $this->timeoutAfterAcceptance(),
            'stop_loss_attach_success' => $this->stopLossAttachSuccess(),
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

    private function request(
        string $symbol = 'BTCUSDT',
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ?float $price = 24950.0,
        string $clientOrderId = 'golden-client-order',
        bool $postOnly = false,
        ExchangeTimeInForce $timeInForce = ExchangeTimeInForce::GTC,
        ?float $attachedStopLossPrice = null,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: $orderType,
            timeInForce: $timeInForce,
            quantity: 1.0,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachedStopLossPrice,
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
