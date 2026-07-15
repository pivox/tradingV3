<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeFault;
use App\Exchange\Fake\FakeExchangeFaultKind;
use App\Exchange\Fake\FakeExchangeFaultOutcome;
use App\Exchange\Fake\FakeExchangeInjectedException;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOperation;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeExchangeAdapter::class)]
#[CoversClass(FakeExchangeFault::class)]
#[CoversClass(FakeExchangeInjectedException::class)]
#[CoversClass(FakeExchangeScenarioService::class)]
#[CoversClass(FakeExchangeStateStore::class)]
final class FakeExchangeFaultInjectionTest extends TestCase
{
    private FakeExchangeStateStore $state;
    private FakeExchangeAdapter $adapter;
    private FakeExchangeScenarioService $scenario;

    protected function setUp(): void
    {
        $this->state = new FakeExchangeStateStore();
        [$this->adapter, $this->scenario] = $this->services($this->state);
    }

    public function testRateLimitFaultRequiresPositiveRetryAfter(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_exchange_fault_retry_after_invalid');

        new FakeExchangeFault(
            operation: FakeExchangeOperation::PlaceOrder,
            kind: FakeExchangeFaultKind::Http429,
            outcome: FakeExchangeFaultOutcome::NotApplied,
            retryAfterSeconds: 0,
        );
    }

    public function testTimeoutBeforeSubmitIsOneShotAndDoesNotMutateState(): void
    {
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::PlaceOrder,
            FakeExchangeFaultKind::NetworkTimeout,
        ));

        try {
            $this->adapter->placeOrder($this->request());
            self::fail('The injected timeout must fail the first submit.');
        } catch (FakeExchangeInjectedException $exception) {
            self::assertSame('network_timeout', $exception->getMessage());
            self::assertSame(FakeExchangeOperation::PlaceOrder, $exception->fault->operation);
            self::assertFalse($exception->outcomeUnknown());
        }

        self::assertCount(0, $this->state->getOrders());
        self::assertCount(0, $this->state->events());

        $retry = $this->adapter->placeOrder($this->request());

        self::assertTrue($retry->accepted);
        self::assertCount(1, $this->state->getOrders());
    }

    public function testTimeoutAfterAcceptedOrderIsAmbiguousAndReplayIsIdempotent(): void
    {
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::PlaceOrder,
            FakeExchangeFaultKind::NetworkTimeout,
            FakeExchangeFaultOutcome::AppliedResponseLost,
        ));

        try {
            $this->adapter->placeOrder($this->request(
                orderType: ExchangeOrderType::MARKET,
                price: null,
                postOnly: false,
                attachedStopLossPrice: 24000.0,
            ));
            self::fail('The accepted response must be lost.');
        } catch (FakeExchangeInjectedException $exception) {
            self::assertTrue($exception->outcomeUnknown());
            self::assertSame('applied_response_lost', $exception->context()['outcome']);
        }

        $ordersBeforeReplay = $this->state->getOrders();
        $eventsBeforeReplay = $this->state->events();
        $entryOrder = array_values(array_filter(
            $ordersBeforeReplay,
            static fn ($order): bool => $order->clientOrderId === 'fault-cid',
        ))[0];

        $retry = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24000.0,
        ));

        self::assertSame($entryOrder->exchangeOrderId, $retry->exchangeOrderId);
        self::assertTrue($retry->metadata['idempotent_replay'] ?? false);
        self::assertCount(\count($ordersBeforeReplay), $this->state->getOrders());
        self::assertCount(\count($eventsBeforeReplay), $this->state->events());
    }

    public function testRateLimitExposesNormalizedRetryAfterWithoutRawPayload(): void
    {
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::GetOpenOrders,
            FakeExchangeFaultKind::Http429,
            retryAfterSeconds: 7,
        ));

        try {
            $this->adapter->getOpenOrders();
            self::fail('The read must be rate limited once.');
        } catch (FakeExchangeInjectedException $exception) {
            self::assertSame([
                'injected_error' => 'http_429',
                'operation' => 'get_open_orders',
                'outcome' => 'not_applied',
                'outcome_unknown' => false,
                'http_status' => 429,
                'retry_after_seconds' => 7,
            ], $exception->context());
        }

        self::assertSame([], $this->adapter->getOpenOrders());
    }

    public function testLostCancelResponseCanBeRetriedWithoutDuplicateEvent(): void
    {
        $placed = $this->adapter->placeOrder($this->request());
        $request = new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $placed->exchangeOrderId,
            clientOrderId: $placed->clientOrderId,
        );
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::CancelOrder,
            FakeExchangeFaultKind::TransportError,
            FakeExchangeFaultOutcome::AppliedResponseLost,
        ));

        try {
            $this->adapter->cancelOrder($request);
            self::fail('The cancel response must be lost after application.');
        } catch (FakeExchangeInjectedException $exception) {
            self::assertTrue($exception->outcomeUnknown());
        }
        self::assertCount(1, $this->state->events('order.cancelled'));

        $retry = $this->adapter->cancelOrder($request);

        self::assertTrue($retry->cancelled);
        self::assertTrue($retry->metadata['idempotent_replay'] ?? false);
        self::assertCount(1, $this->state->events('order.cancelled'));
    }

    public function testFaultQueueIsIsolatedByOperationAndPreservesFifo(): void
    {
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::GetBalances,
            FakeExchangeFaultKind::Http500,
        ));
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::PlaceOrder,
            FakeExchangeFaultKind::TransportError,
        ));

        try {
            $this->adapter->placeOrder($this->request());
            self::fail('The operation-specific transport fault must be consumed.');
        } catch (FakeExchangeInjectedException $exception) {
            self::assertSame(FakeExchangeFaultKind::TransportError, $exception->fault->kind);
        }

        self::assertCount(1, $this->scenario->pendingFaults());
        self::assertSame(FakeExchangeOperation::GetBalances, $this->scenario->pendingFaults()[0]->operation);

        $this->expectException(FakeExchangeInjectedException::class);
        $this->expectExceptionMessage('http_500');
        $this->adapter->getBalances();
    }

    public function testFaultQueuePreservesFifoAcrossOutcomesForSameOperation(): void
    {
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::PlaceOrder,
            FakeExchangeFaultKind::NetworkTimeout,
            FakeExchangeFaultOutcome::AppliedResponseLost,
        ));
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::PlaceOrder,
            FakeExchangeFaultKind::Http500,
        ));

        try {
            $this->adapter->placeOrder($this->request());
            self::fail('The oldest fault for the operation must be applied first.');
        } catch (FakeExchangeInjectedException $exception) {
            self::assertSame(FakeExchangeFaultOutcome::AppliedResponseLost, $exception->fault->outcome);
        }
        self::assertCount(1, $this->state->getOrders());

        try {
            $this->adapter->placeOrder($this->request());
            self::fail('The second fault must remain queued until the next call.');
        } catch (FakeExchangeInjectedException $exception) {
            self::assertSame(FakeExchangeFaultKind::Http500, $exception->fault->kind);
        }
    }

    public function testAppliedResponseLostRemainsQueuedWhenCancelDoesNotMutate(): void
    {
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::CancelOrder,
            FakeExchangeFaultKind::NetworkTimeout,
            FakeExchangeFaultOutcome::AppliedResponseLost,
        ));

        $result = $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'unknown-order',
        ));

        self::assertFalse($result->cancelled);
        self::assertCount(1, $this->scenario->pendingFaults());
        self::assertSame(FakeExchangeFaultOutcome::AppliedResponseLost, $this->scenario->pendingFaults()[0]->outcome);
    }

    public function testAppliedResponseLostRollsBackAndRemainsQueuedWhenOperationFails(): void
    {
        $this->scenario->failNext(new FakeExchangeFault(
            FakeExchangeOperation::PlaceOrder,
            FakeExchangeFaultKind::NetworkTimeout,
            FakeExchangeFaultOutcome::AppliedResponseLost,
        ));

        try {
            $this->adapter->placeOrder($this->request(symbol: ''));
            self::fail('The invalid operation must fail before any mutation.');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $this->state->getOrders());
        }

        self::assertCount(1, $this->scenario->pendingFaults());
    }

    public function testPendingFaultSurvivesRestartAndConsumptionIsPersisted(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_fault_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            [, $scenario] = $this->services($state);
            $scenario->failNext(new FakeExchangeFault(
                FakeExchangeOperation::GetBalances,
                FakeExchangeFaultKind::NetworkTimeout,
            ));

            $restored = new FakeExchangeStateStore($stateFile);
            self::assertSame(1, $restored->recoveryMetadata()['pending_fault_count']);
            [$adapter] = $this->services($restored);
            try {
                $adapter->getBalances();
                self::fail('The persisted fault must be restored.');
            } catch (FakeExchangeInjectedException) {
                self::assertCount(0, $restored->pendingFaults());
            }

            $afterConsumption = new FakeExchangeStateStore($stateFile);
            [$adapterAfterConsumption] = $this->services($afterConsumption);
            self::assertCount(1, $adapterAfterConsumption->getBalances());
        } finally {
            @unlink($stateFile);
        }
    }

    public function testPreviousVersionedEnvelopeWithoutFaultQueueRemainsReadable(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_fault_legacy_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            new FakeExchangeStateStore($stateFile);
            $envelope = unserialize((string) file_get_contents($stateFile), ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            self::assertIsArray($envelope['payload'] ?? null);
            unset($envelope['payload']['pendingFaults']);
            $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
            file_put_contents($stateFile, serialize($envelope));

            $restored = new FakeExchangeStateStore($stateFile);

            self::assertSame([], $restored->pendingFaults());
        } finally {
            @unlink($stateFile);
        }
    }

    public function testAppliedResponseLostIsRejectedForReadOperations(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_exchange_fault_applied_outcome_requires_mutation');

        new FakeExchangeFault(
            FakeExchangeOperation::GetOrder,
            FakeExchangeFaultKind::NetworkTimeout,
            FakeExchangeFaultOutcome::AppliedResponseLost,
        );
    }

    /**
     * @return array{FakeExchangeAdapter,FakeExchangeScenarioService}
     */
    private function services(FakeExchangeStateStore $state): array
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->clock());

        return [
            new FakeExchangeAdapter($state, $book, $engine, $this->clock()),
            new FakeExchangeScenarioService($state, $book, $engine),
        ];
    }

    private function request(
        string $symbol = 'BTCUSDT',
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ?float $price = 24950.0,
        bool $postOnly = true,
        ?float $attachedStopLossPrice = null,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: $symbol,
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: $price,
            stopPrice: null,
            reduceOnly: false,
            postOnly: $postOnly,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'fault-cid',
            attachedStopLossPrice: $attachedStopLossPrice,
        );
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        };
    }
}
