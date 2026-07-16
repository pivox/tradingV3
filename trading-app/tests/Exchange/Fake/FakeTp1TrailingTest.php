<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\PlaceOrderResult;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeEvent;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeTp1TrailingPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeExchangeMatchingEngine::class)]
#[CoversClass(FakeTp1TrailingPolicy::class)]
final class FakeTp1TrailingTest extends TestCase
{
    public function testTp1UsesConfiguredQuantityAndAtomicallyArmsTrailingForRemainder(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        $initialStop = $this->orderByType($adapter, ExchangeOrderType::STOP_LOSS);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);

        self::assertSame(1.0, $initialStop->quantity);
        self::assertSame(0.4, $tp1->quantity);

        $scenario->fillOrder($tp1->exchangeOrderId, null, (float) $fixture['tp1_price']);

        $position = $adapter->getOpenPositions('BTCUSDT')[0] ?? null;
        $trailing = $this->orderByType($adapter, ExchangeOrderType::TRIGGER);
        $persistedStop = $adapter->getOrder('BTCUSDT', $initialStop->exchangeOrderId);
        self::assertNotNull($position);
        self::assertSame(0.6, $position->size);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $persistedStop?->status);
        self::assertSame('tp1_replaced_by_trailing', $persistedStop?->metadata['reason'] ?? null);
        self::assertTrue($trailing->reduceOnly);
        self::assertSame(0.6, $trailing->quantity);
        self::assertSame(25100.0, $trailing->stopPrice);
        self::assertSame(FakeTp1TrailingPolicy::VERSION, $trailing->metadata['trailing_state_version'] ?? null);
        self::assertSame('active', $trailing->metadata['trailing_state_status'] ?? null);
        self::assertSame(25200.0, $trailing->metadata['trailing_watermark'] ?? null);
        self::assertSame('100.0', $trailing->metadata[FakeTp1TrailingPolicy::TRAILING_OFFSET_KEY] ?? null);
        self::assertSame($tp1->exchangeOrderId, $trailing->metadata['trailing_activation_order_id'] ?? null);
        self::assertCount(1, $state->events('trailing_stop.armed'));
    }

    public function testPartialTp1FillKeepsInitialStopUntilConfiguredQuantityCompletes(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);

        $partial = $scenario->fillOrder($tp1->exchangeOrderId, 0.2, 25200.0);

        self::assertSame(ExchangeOrderStatus::PARTIALLY_FILLED, $partial?->status);
        self::assertSame(0.8, $adapter->getOpenPositions('BTCUSDT')[0]->size);
        self::assertCount(1, array_filter(
            $adapter->getOpenOrders('BTCUSDT'),
            static fn (ExchangeOrderDto $order): bool => $order->orderType === ExchangeOrderType::STOP_LOSS,
        ));
        self::assertCount(0, $state->events('trailing_stop.armed'));

        $scenario->fillOrder($tp1->exchangeOrderId, 0.2, 25200.0);

        self::assertSame(0.6, $adapter->getOpenPositions('BTCUSDT')[0]->size);
        self::assertSame(0.6, $this->orderByType($adapter, ExchangeOrderType::TRIGGER)->quantity);
        self::assertCount(1, $state->events('trailing_stop.armed'));
    }

    public function testIncompleteRequestedCapabilityFailsBeforeMutation(): void
    {
        [$state, $adapter] = $this->exchange();
        $fixture = $this->fixture('long');

        try {
            $this->placeFixtureEntry($adapter, $fixture, [
                FakeTp1TrailingPolicy::VERSION_KEY => FakeTp1TrailingPolicy::VERSION,
            ], false);
            self::fail('Expected the incomplete trailing capability to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('fake_tp1_trailing_policy_invalid', $exception->getMessage());
        }

        self::assertCount(0, $state->getOrders());
        self::assertCount(0, $state->getOpenPositions());
        self::assertCount(0, $state->events());
    }

    public function testTp1ReplayDoesNotDuplicateTrailingOrderOrLifecycle(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $scenario->fillOrder($tp1->exchangeOrderId, null, 25200.0);
        $orderCount = \count($state->getOrders('BTCUSDT'));
        $eventCount = \count($state->events());
        $trailingId = $this->orderByType($adapter, ExchangeOrderType::TRIGGER)->exchangeOrderId;

        $replay = $scenario->fillOrder($tp1->exchangeOrderId, null, 25200.0);

        self::assertSame(ExchangeOrderStatus::FILLED, $replay?->status);
        self::assertCount($orderCount, $state->getOrders('BTCUSDT'));
        self::assertCount($eventCount, $state->events());
        self::assertSame($trailingId, $this->orderByType($adapter, ExchangeOrderType::TRIGGER)->exchangeOrderId);
        self::assertCount(1, $state->events('trailing_stop.armed'));
    }

    public function testRestartRestoresActiveTrailingStateAndRedactsUntrustedMetadata(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_tp1_trailing_restart_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            [$state, $adapter, $scenario] = $this->exchange($stateFile);
            $fixture = $this->fixture('long');
            $this->placeFixtureEntry($adapter, $fixture, [
                'internal_trade_id' => 'tp1-restart-trade',
                'api_key' => 'TOP-SECRET',
                'raw_payload' => ['authorization' => 'Bearer SECRET'],
            ]);
            $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
            $scenario->fillOrder($tp1->exchangeOrderId, null, 25200.0);
            $sequencesBefore = $this->eventSequences($state->events());

            $restoredState = new FakeExchangeStateStore($stateFile);
            [, $restoredAdapter] = $this->exchangeForState($restoredState);
            $trailing = $this->orderByType($restoredAdapter, ExchangeOrderType::TRIGGER);
            $serialized = (string) file_get_contents($stateFile);

            self::assertTrue($restoredState->recoveryMetadata()['restored']);
            self::assertSame(25200.0, $trailing->metadata['trailing_watermark'] ?? null);
            self::assertSame('active', $trailing->metadata['trailing_state_status'] ?? null);
            self::assertSame('tp1-restart-trade', $trailing->metadata['internal_trade_id'] ?? null);
            self::assertSame($sequencesBefore, $this->eventSequences($restoredState->events()));
            self::assertStringNotContainsString('TOP-SECRET', $serialized);
            self::assertStringNotContainsString('Bearer SECRET', $serialized);
            self::assertStringNotContainsString('api_key', $serialized);
            self::assertStringNotContainsString('raw_payload', $serialized);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
            foreach (glob($stateFile . '.tmp.*') ?: [] as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }

    public function testStopLossWinningRacePreventsTp1Activation(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        $stop = $this->orderByType($adapter, ExchangeOrderType::STOP_LOSS);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);

        $scenario->fillOrder($stop->exchangeOrderId, null, 24800.0);
        $tp1AfterRace = $scenario->fillOrder($tp1->exchangeOrderId, null, 25200.0);

        self::assertSame(ExchangeOrderStatus::CANCELLED, $tp1AfterRace?->status);
        self::assertCount(0, $adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(0, $adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(0, $state->events('trailing_stop.armed'));
    }

    public function testTp1WinningRaceMakesStaleInitialStopANoOp(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        $stop = $this->orderByType($adapter, ExchangeOrderType::STOP_LOSS);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);

        $scenario->fillOrder($tp1->exchangeOrderId, null, 25200.0);
        $staleStop = $scenario->fillOrder($stop->exchangeOrderId, null, 24800.0);

        self::assertSame(ExchangeOrderStatus::CANCELLED, $staleStop?->status);
        self::assertSame(0.6, $adapter->getOpenPositions('BTCUSDT')[0]->size);
        self::assertSame(ExchangeOrderType::TRIGGER, $adapter->getOpenOrders('BTCUSDT')[0]->orderType);
        self::assertCount(1, $state->events('trailing_stop.armed'));
    }

    public function testTrailingCreationFailureRollsBackTp1PositionProtectionAndEvents(): void
    {
        $state = new class extends FakeExchangeStateStore {
            public bool $rejectTrailingSave = false;

            public function saveOrder(ExchangeOrderDto $order): void
            {
                if (
                    $this->rejectTrailingSave
                    && $order->orderType === ExchangeOrderType::TRIGGER
                    && $order->status === ExchangeOrderStatus::OPEN
                ) {
                    throw new \RuntimeException('forced_trailing_save_failure');
                }

                parent::saveOrder($order);
            }
        };
        [$state, $adapter, $scenario] = $this->exchangeForState($state);
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        $stop = $this->orderByType($adapter, ExchangeOrderType::STOP_LOSS);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $eventsBefore = \count($state->events());
        $state->rejectTrailingSave = true;

        try {
            $scenario->fillOrder($tp1->exchangeOrderId, null, 25200.0);
            self::fail('Expected forced trailing save failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_trailing_save_failure', $exception->getMessage());
        }

        self::assertSame(1.0, $adapter->getOpenPositions('BTCUSDT')[0]->size);
        self::assertSame(ExchangeOrderStatus::OPEN, $adapter->getOrder('BTCUSDT', $stop->exchangeOrderId)?->status);
        self::assertSame(ExchangeOrderStatus::OPEN, $adapter->getOrder('BTCUSDT', $tp1->exchangeOrderId)?->status);
        self::assertCount(2, $adapter->getOpenOrders('BTCUSDT'));
        self::assertCount($eventsBefore, $state->events());
        self::assertCount(0, $state->events('trailing_stop.armed'));
    }

    /**
     * @param array<string,mixed> $fixture
     * @param array<string,mixed> $extraMetadata
     */
    private function placeFixtureEntry(
        FakeExchangeAdapter $adapter,
        array $fixture,
        array $extraMetadata = [],
        bool $includePolicy = true,
    ): PlaceOrderResult {
        $policy = new FakeTp1TrailingPolicy(
            (string) $fixture['tp1_quantity'],
            (string) $fixture['trailing_offset'],
        );
        $metadata = $includePolicy ? $policy->toMetadata() + $extraMetadata : $extraMetadata;

        return $adapter->placeOrder(new PlaceOrderRequest(
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
            clientOrderId: 'tp1-trailing-' . $fixture['name'],
            attachedStopLossPrice: (float) $fixture['initial_stop'],
            attachedTakeProfitPrice: (float) $fixture['tp1_price'],
            metadata: $metadata,
            quantityDecimal: (string) $fixture['quantity'],
            attachedStopLossPriceDecimal: (string) $fixture['initial_stop'],
            attachedTakeProfitPriceDecimal: (string) $fixture['tp1_price'],
        ));
    }

    private function orderByType(
        FakeExchangeAdapter $adapter,
        ExchangeOrderType $type,
    ): ExchangeOrderDto {
        $order = array_values(array_filter(
            $adapter->getOrdersSnapshot('BTCUSDT'),
            static fn (ExchangeOrderDto $candidate): bool => $candidate->orderType === $type,
        ))[0] ?? null;
        self::assertInstanceOf(ExchangeOrderDto::class, $order);

        return $order;
    }

    /** @return array<string,mixed> */
    private function fixture(string $name): array
    {
        $path = dirname(__DIR__, 2) . '/fixtures/fake-paper/tp1-trailing-v1.json';
        $raw = file_get_contents($path);
        self::assertIsString($raw);
        /** @var array{schema_version:string,cases:list<array<string,mixed>>} $catalog */
        $catalog = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('fake-tp1-trailing-fixtures-v1', $catalog['schema_version']);

        foreach ($catalog['cases'] as $fixture) {
            if (($fixture['name'] ?? null) === $name) {
                return $fixture;
            }
        }

        throw new \LogicException('Unknown TP1 trailing fixture ' . $name);
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

    /** @param FakeExchangeEvent[] $events
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
