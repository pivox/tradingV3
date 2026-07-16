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
use App\Exchange\Fake\FakeFallbackTakerPolicy;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeTp1TrailingPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeExchangeMatchingEngine::class)]
#[CoversClass(FakeTp1TrailingPolicy::class)]
final class FakeTp1TrailingTest extends TestCase
{
    #[DataProvider('directionProvider')]
    public function testGapThroughTrailingClosesOnlyRemainderAndCleansProtections(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture($direction);
        $trailing = $this->armFixture($adapter, $scenario, $fixture);
        foreach ($fixture['favorable_prices'] as $favorablePrice) {
            $scenario->movePrice((string) $fixture['symbol'], (float) $favorablePrice, 0.0);
        }

        $move = $scenario->movePrice(
            (string) $fixture['symbol'],
            (float) $fixture['gap_price'],
            0.0,
        );
        $filled = $move['matched_orders'][0] ?? null;
        $persisted = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);

        self::assertInstanceOf(ExchangeOrderDto::class, $filled);
        self::assertSame($trailing->exchangeOrderId, $filled->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $filled->status);
        self::assertTrue($filled->reduceOnly);
        self::assertSame(0.6, $filled->filledQuantity);
        self::assertSame((float) $fixture['gap_price'], round((float) $filled->averagePrice, 6));
        self::assertSame('triggered', $persisted?->metadata['trailing_state_status'] ?? null);
        self::assertSame($filled->averagePrice, $persisted?->metadata['trailing_trigger_price'] ?? null);
        self::assertCount(0, $adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(0, $adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(1, $state->events('trailing_stop.triggered'));

        $orderCount = \count($state->getOrders('BTCUSDT'));
        $eventCount = \count($state->events());
        $scenario->movePrice((string) $fixture['symbol'], (float) $fixture['gap_price'], 0.0);
        $scenario->fillOrder($trailing->exchangeOrderId);
        self::assertCount($orderCount, $state->getOrders('BTCUSDT'));
        self::assertCount($eventCount, $state->events());
        self::assertCount(1, $state->events('trailing_stop.triggered'));
    }

    #[DataProvider('directionProvider')]
    public function testTp1AndTrailingCostsAndPnlAreBookedExactlyOnce(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture($direction);
        $trailing = $this->armFixture($adapter, $scenario, $fixture);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        foreach ($fixture['favorable_prices'] as $favorablePrice) {
            $scenario->movePrice((string) $fixture['symbol'], (float) $favorablePrice, 0.0);
        }
        $scenario->movePrice((string) $fixture['symbol'], (float) $fixture['gap_price'], 0.0);

        $fills = $adapter->getFillsSnapshot('BTCUSDT');
        $closed = $state->events('position.closed')[0] ?? null;
        self::assertInstanceOf(FakeExchangeEvent::class, $closed);
        self::assertCount(3, $fills);
        self::assertSame([1.0, 0.4, 0.6], array_map(
            static fn ($fill): float => $fill->quantity,
            $fills,
        ));
        self::assertSame(1.0, $closed->payload['entry_qty'] ?? null);
        self::assertSame(1.0, $closed->payload['exit_qty'] ?? null);
        self::assertSame(0.0, $closed->payload['remaining_qty'] ?? null);
        self::assertTrue($closed->payload['quantity_coherent'] ?? false);
        self::assertTrue($closed->payload['position_fully_closed'] ?? false);
        self::assertTrue($closed->payload['fills_complete'] ?? false);
        self::assertSame('complete', $closed->payload['cost_completeness'] ?? null);
        self::assertSame('fake_paper_fill_ledger_v1', $closed->payload['pnl_source'] ?? null);
        self::assertSame('fixed_adverse_slippage_bps_v1', $closed->payload['cost_model_version'] ?? null);
        self::assertSame('top_of_book_embedded_spread_v1', $closed->payload['spread_model_version'] ?? null);
        self::assertGreaterThan(0.0, $closed->payload['entry_fee_usdt'] ?? 0.0);
        self::assertGreaterThan(0.0, $closed->payload['exit_fee_usdt'] ?? 0.0);

        $reduceFillEvents = array_values(array_filter(
            $state->events('order.filled'),
            static fn (FakeExchangeEvent $event): bool => \in_array(
                $event->payload['order_id'] ?? null,
                [$tp1->exchangeOrderId, $trailing->exchangeOrderId],
                true,
            ),
        ));
        $exitFeeFromFills = array_sum(array_map(
            static fn (FakeExchangeEvent $event): float => (float) ($event->payload['fill_fee'] ?? 0.0),
            $reduceFillEvents,
        ));
        self::assertCount(2, $reduceFillEvents);
        self::assertSame(round($exitFeeFromFills, 12), $closed->payload['exit_fee_usdt'] ?? null);

        $eventsBeforeReplay = \count($state->events());
        $recordedPnl = $closed->payload['recorded_pnl_usdt'] ?? null;
        $scenario->fillOrder($trailing->exchangeOrderId);
        self::assertCount($eventsBeforeReplay, $state->events());
        self::assertSame($recordedPnl, $state->events('position.closed')[0]->payload['recorded_pnl_usdt'] ?? null);
    }

    public function testRestartPreservesRatchetWatermarkAndContinuesWithoutDuplicateUpdate(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_tp1_trailing_ratchet_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            [$state, $adapter, $scenario] = $this->exchange($stateFile);
            $fixture = $this->fixture('long');
            $trailing = $this->armFixture($adapter, $scenario, $fixture);
            $scenario->movePrice('BTCUSDT', 25300.0, 0.0);
            self::assertCount(1, $state->events('trailing_stop.updated'));

            $restoredState = new FakeExchangeStateStore($stateFile);
            [, $restoredAdapter, $restoredScenario] = $this->exchangeForState($restoredState);
            $restored = $restoredAdapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);
            self::assertSame(25300.0, $restored?->metadata['trailing_watermark'] ?? null);
            self::assertSame(25200.0, $restored?->stopPrice);

            $eventCount = \count($restoredState->events());
            $restoredScenario->movePrice('BTCUSDT', 25300.0, 0.0);
            self::assertCount($eventCount, $restoredState->events());
            self::assertCount(1, $restoredState->events('trailing_stop.updated'));

            $restoredScenario->movePrice('BTCUSDT', 25400.0, 0.0);
            $advanced = $restoredAdapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);
            self::assertSame(25400.0, $advanced?->metadata['trailing_watermark'] ?? null);
            self::assertSame(25300.0, $advanced?->stopPrice);
            self::assertCount(2, $restoredState->events('trailing_stop.updated'));
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
            foreach (glob($stateFile . '.tmp.*') ?: [] as $temporaryFile) {
                @unlink($temporaryFile);
            }
        }
    }

    #[DataProvider('directionProvider')]
    public function testFavorableMovementRatchetsWatermarkAndStopForBothSides(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture($direction);
        $trailing = $this->armFixture($adapter, $scenario, $fixture);

        foreach ($fixture['favorable_prices'] as $index => $favorablePrice) {
            $scenario->movePrice((string) $fixture['symbol'], (float) $favorablePrice, 0.0);
            $persisted = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);

            self::assertSame((float) $favorablePrice, $persisted?->metadata['trailing_watermark'] ?? null);
            self::assertSame(
                (float) $fixture['expected_favorable_stops'][$index],
                $persisted?->stopPrice,
            );
        }

        self::assertCount(2, $state->events('trailing_stop.updated'));
    }

    #[DataProvider('directionProvider')]
    public function testAdverseMovementNeverLoosensTrailingStop(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture($direction);
        $trailing = $this->armFixture($adapter, $scenario, $fixture);
        foreach ($fixture['favorable_prices'] as $favorablePrice) {
            $scenario->movePrice((string) $fixture['symbol'], (float) $favorablePrice, 0.0);
        }
        $beforeAdverse = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);
        $updateCount = \count($state->events('trailing_stop.updated'));

        $scenario->movePrice((string) $fixture['symbol'], (float) $fixture['adverse_price'], 0.0);
        $afterAdverse = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);
        $lastFavorableIndex = array_key_last($fixture['favorable_prices']);
        $lastStopIndex = array_key_last($fixture['expected_favorable_stops']);

        self::assertInstanceOf(ExchangeOrderDto::class, $beforeAdverse);
        self::assertInstanceOf(ExchangeOrderDto::class, $afterAdverse);
        self::assertIsInt($lastFavorableIndex);
        self::assertIsInt($lastStopIndex);
        self::assertSame((float) $fixture['favorable_prices'][$lastFavorableIndex], $beforeAdverse->metadata['trailing_watermark'] ?? null);
        self::assertSame((float) $fixture['expected_favorable_stops'][$lastStopIndex], $beforeAdverse->stopPrice);
        self::assertSame($beforeAdverse->metadata['trailing_watermark'], $afterAdverse->metadata['trailing_watermark'] ?? null);
        self::assertSame($beforeAdverse->stopPrice, $afterAdverse->stopPrice);
        self::assertCount($updateCount, $state->events('trailing_stop.updated'));
    }

    #[DataProvider('directionProvider')]
    public function testDuplicatePriceEventDoesNotRewriteStateOrDuplicateLifecycle(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture($direction);
        $trailing = $this->armFixture($adapter, $scenario, $fixture);
        $favorablePrice = (float) $fixture['favorable_prices'][0];
        $scenario->movePrice((string) $fixture['symbol'], $favorablePrice, 0.0);
        $afterFirst = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);
        $eventCount = \count($state->events());
        $updateCount = \count($state->events('trailing_stop.updated'));

        $scenario->movePrice((string) $fixture['symbol'], $favorablePrice, 0.0);
        $afterDuplicate = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);

        self::assertSame((float) $fixture['expected_favorable_stops'][0], $afterFirst?->stopPrice);
        self::assertEquals($afterFirst, $afterDuplicate);
        self::assertCount($eventCount, $state->events());
        self::assertCount($updateCount, $state->events('trailing_stop.updated'));
    }

    public function testRatchetDerivedStopQuantizesToInstrumentTick(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $trailing = $this->armFixture($adapter, $scenario, $fixture);

        $scenario->movePrice('BTCUSDT', 25300.03, 0.0);

        $updated = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);
        self::assertSame(25200.0, $updated?->stopPrice);
        $stopPriceDecimal = $updated?->metadata['stop_price_decimal'] ?? null;
        self::assertIsString($stopPriceDecimal);
        self::assertTrue((new FakeInstrumentCatalog())->find('BTCUSDT')?->isPriceQuantized($stopPriceDecimal));
        self::assertCount(1, $state->events('trailing_stop.updated'));
    }

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

    public function testTrailingProtectsOnlyNewEntryRemainderWhenSameSideExposureAlreadyExists(): void
    {
        [, $adapter, $scenario] = $this->exchange();
        $adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'pre-existing-long',
            quantityDecimal: '1.0',
        ));
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        self::assertSame(2.0, $adapter->getOpenPositions('BTCUSDT')[0]->size);

        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $scenario->fillOrder($tp1->exchangeOrderId, null, (float) $fixture['tp1_price']);

        $trailing = $this->orderByType($adapter, ExchangeOrderType::TRIGGER);
        self::assertSame(1.6, $adapter->getOpenPositions('BTCUSDT')[0]->size);
        self::assertSame(0.6, $trailing->quantity);
        self::assertSame('0.6', $trailing->metadata['quantity_decimal'] ?? null);

        $scenario->fillOrder($trailing->exchangeOrderId);

        self::assertSame(1.0, $adapter->getOpenPositions('BTCUSDT')[0]->size);
    }

    #[DataProvider('directionProvider')]
    public function testDefaultSpreadActivationAndNonTickRatchetQuantizeRuntimeStop(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture($direction);
        $this->placeFixtureEntry($adapter, $fixture);

        $scenario->movePrice('BTCUSDT', (float) $fixture['tp1_price']);

        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $trailing = $this->orderByType($adapter, ExchangeOrderType::TRIGGER);
        $instrument = (new FakeInstrumentCatalog())->find('BTCUSDT');
        self::assertNotNull($instrument);
        self::assertSame(ExchangeOrderStatus::FILLED, $tp1->status);
        self::assertSame(ExchangeOrderStatus::OPEN, $trailing->status);
        self::assertIsString($trailing->metadata['stop_price_decimal'] ?? null);
        self::assertTrue($instrument->isPriceQuantized($trailing->metadata['stop_price_decimal']));
        self::assertCount(1, $state->events('trailing_stop.armed'));

        $favorableMid = $direction === 'long' ? 25300.03 : 24699.97;
        $scenario->movePrice('BTCUSDT', $favorableMid);

        $ratcheted = $adapter->getOrder('BTCUSDT', $trailing->exchangeOrderId);
        self::assertNotNull($ratcheted);
        self::assertIsString($ratcheted->metadata['stop_price_decimal'] ?? null);
        self::assertTrue($instrument->isPriceQuantized($ratcheted->metadata['stop_price_decimal']));
        self::assertCount(1, $state->events('trailing_stop.updated'));
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

    public function testTp1QuantityMustObeyInstrumentQuantityRulesBeforeMutation(): void
    {
        [$state, $adapter] = $this->exchange();
        $fixture = array_replace($this->fixture('long'), ['tp1_quantity' => '0.0005']);

        try {
            $this->placeFixtureEntry($adapter, $fixture);
            self::fail('Expected the non-quantized TP1 quantity to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('fake_tp1_trailing_quantity_invalid', $exception->getMessage());
        }

        self::assertCount(0, $state->getOrders());
        self::assertCount(0, $state->getOpenPositions());
        self::assertCount(0, $state->events());
    }

    public function testDerivedInitialTrailingStopMustObeyInstrumentTickBeforeMutation(): void
    {
        [$state, $adapter] = $this->exchange();
        $fixture = array_replace($this->fixture('long'), ['trailing_offset' => '100.03']);

        try {
            $this->placeFixtureEntry($adapter, $fixture);
            self::fail('Expected the non-quantized derived trailing stop to fail.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('fake_tp1_trailing_stop_invalid', $exception->getMessage());
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

    public function testEntryReplayWithDifferentTrailingPolicyIsRejectedAsIntentMismatch(): void
    {
        [$state, $adapter] = $this->exchange();
        $fixture = $this->fixture('long');
        $first = $this->placeFixtureEntry($adapter, $fixture);
        $orderCount = \count($state->getOrders('BTCUSDT'));
        $eventCount = \count($state->events());
        $conflictingFixture = array_replace($fixture, ['trailing_offset' => '120.0']);

        $replay = $this->placeFixtureEntry($adapter, $conflictingFixture);

        self::assertTrue($first->accepted);
        self::assertFalse($replay->accepted);
        self::assertSame($first->exchangeOrderId, $replay->exchangeOrderId);
        self::assertSame('duplicate_client_order_id_intent_mismatch', $replay->metadata['reason'] ?? null);
        self::assertFalse($replay->metadata['idempotent_replay'] ?? true);
        self::assertCount($orderCount, $state->getOrders('BTCUSDT'));
        self::assertCount($eventCount, $state->events());
    }

    public function testFallbackEntryPreservesTp1TrailingPolicyForLogicalTotalExposure(): void
    {
        [, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $trailingPolicy = new FakeTp1TrailingPolicy('0.4', '100.0');
        $fallbackPolicy = new FakeFallbackTakerPolicy(
            enabled: true,
            zoneMin: 24900.0,
            zoneMax: 25100.0,
            maxSlippageBps: 30.0,
        );
        $parent = $adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: 24950.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'tp1-trailing-fallback',
            attachedStopLossPrice: (float) $fixture['initial_stop'],
            attachedTakeProfitPrice: (float) $fixture['tp1_price'],
            metadata: $trailingPolicy->toMetadata() + $fallbackPolicy->toMetadata(),
            quantityDecimal: '1.0',
            priceDecimal: '24950.0',
            attachedStopLossPriceDecimal: (string) $fixture['initial_stop'],
            attachedTakeProfitPriceDecimal: (string) $fixture['tp1_price'],
        ));
        self::assertNotNull($parent->exchangeOrderId);
        $scenario->fillOrder($parent->exchangeOrderId, 0.8, 24950.0);

        $fallback = $scenario->fallbackTaker($parent->exchangeOrderId);

        self::assertTrue($fallback->executed);
        self::assertSame(0.2, $fallback->fallbackOrder?->quantity);
        self::assertSame(
            FakeTp1TrailingPolicy::VERSION,
            $fallback->fallbackOrder?->metadata[FakeTp1TrailingPolicy::VERSION_KEY] ?? null,
        );
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        self::assertSame(0.4, $tp1->quantity);

        $scenario->fillOrder($tp1->exchangeOrderId, null, 25200.0);

        self::assertSame(0.6, $adapter->getOpenPositions('BTCUSDT')[0]->size);
        self::assertSame(0.6, $this->orderByType($adapter, ExchangeOrderType::TRIGGER)->quantity);
    }

    #[DataProvider('fallbackProtectionFailureProvider')]
    public function testFallbackProtectionCompensatesWhenTp1WouldConsumePartialExposure(
        bool $rejectFallbackOrder,
    ): void {
        $state = new class extends FakeExchangeStateStore {
            public bool $rejectEntryOrder = false;

            public function availableMarginUsdt(): float
            {
                return $this->rejectEntryOrder ? 0.0 : parent::availableMarginUsdt();
            }
        };
        [, $adapter, $scenario] = $this->exchangeForState($state);
        $fixture = $this->fixture('long');
        $trailingPolicy = new FakeTp1TrailingPolicy('0.4', '100.0');
        $fallbackPolicy = new FakeFallbackTakerPolicy(
            enabled: true,
            zoneMin: 24900.0,
            zoneMax: $rejectFallbackOrder ? 25100.0 : 24999.0,
            maxSlippageBps: 30.0,
        );
        $parent = $adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: 24950.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'tp1-trailing-partial-fallback-' . ($rejectFallbackOrder ? 'order' : 'price'),
            attachedStopLossPrice: (float) $fixture['initial_stop'],
            attachedTakeProfitPrice: (float) $fixture['tp1_price'],
            metadata: $trailingPolicy->toMetadata() + $fallbackPolicy->toMetadata(),
            quantityDecimal: '1.0',
            priceDecimal: '24950.0',
            attachedStopLossPriceDecimal: (string) $fixture['initial_stop'],
            attachedTakeProfitPriceDecimal: (string) $fixture['tp1_price'],
        ));
        self::assertNotNull($parent->exchangeOrderId);
        $scenario->fillOrder($parent->exchangeOrderId, 0.2, 24950.0);
        $state->rejectEntryOrder = $rejectFallbackOrder;
        $result = $scenario->fallbackTaker($parent->exchangeOrderId);

        self::assertFalse($result->executed);
        self::assertCount(0, $adapter->getOpenPositions('BTCUSDT'));
        self::assertSame(
            'reduce_only_market_close',
            $result->parentOrder?->metadata['fail_safe_action'] ?? null,
        );
        self::assertSame(
            'fake_tp1_trailing_quantity_invalid',
            $result->parentOrder?->metadata['protection_reject_reason'] ?? null,
        );
        self::assertTrue($result->parentOrder?->metadata['position_flat_after_compensation'] ?? false);
        self::assertCount(1, array_filter(
            $state->getOrders('BTCUSDT'),
            static fn (ExchangeOrderDto $order): bool => $order->reduceOnly
                && $order->orderType === ExchangeOrderType::MARKET
                && $order->status === ExchangeOrderStatus::FILLED,
        ));
        self::assertCount(0, array_filter(
            $adapter->getOpenOrders('BTCUSDT'),
            static fn (ExchangeOrderDto $order): bool => \in_array(
                $order->orderType,
                [ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TAKE_PROFIT],
                true,
            ),
        ));
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

    #[DataProvider('directionProvider')]
    public function testTp1ActivationRejectsLooserStopAndRollsBackForBothSides(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = array_replace($this->fixture($direction), ['trailing_offset' => '500.0']);
        $this->placeFixtureEntry($adapter, $fixture);
        $initialStop = $this->orderByType($adapter, ExchangeOrderType::STOP_LOSS);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $ordersBefore = $state->getOrders('BTCUSDT');
        $positionsBefore = $state->getOpenPositions('BTCUSDT');
        $eventsBefore = $state->events();

        try {
            $scenario->fillOrder($tp1->exchangeOrderId, null, (float) $fixture['tp1_price']);
            self::fail('Expected a looser derived trailing stop to fail activation.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_tp1_trailing_stop_looser_than_initial_stop', $exception->getMessage());
        }

        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($positionsBefore, $state->getOpenPositions('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertSame(ExchangeOrderStatus::OPEN, $adapter->getOrder('BTCUSDT', $initialStop->exchangeOrderId)?->status);
        self::assertSame(ExchangeOrderStatus::OPEN, $adapter->getOrder('BTCUSDT', $tp1->exchangeOrderId)?->status);
        self::assertCount(0, $state->events('trailing_stop.armed'));
    }

    public function testExistingTrailingChildWithActiveInitialStopFailsExplicitlyAndRollsBack(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture, ['internal_trade_id' => 'immutable-trade']);
        $entry = $this->orderByType($adapter, ExchangeOrderType::MARKET);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $state->saveOrder($this->expectedTrailingChild($entry, $tp1, $fixture));
        $ordersBefore = $state->getOrders('BTCUSDT');
        $positionsBefore = $state->getOpenPositions('BTCUSDT');
        $eventsBefore = $state->events();

        try {
            $scenario->fillOrder($tp1->exchangeOrderId, null, (float) $fixture['tp1_price']);
            self::fail('Expected the active initial stop to conflict with the existing trailing child.');
        } catch (\LogicException $exception) {
            self::assertSame(
                'fake_tp1_trailing_existing_child_with_initial_stop_active',
                $exception->getMessage(),
            );
        }

        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($positionsBefore, $state->getOpenPositions('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertCount(0, $state->events('trailing_stop.armed'));
    }

    #[DataProvider('trailingChildConflictProvider')]
    public function testExistingTrailingChildImmutableIntentConflictsRollBack(string $conflict): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture, ['internal_trade_id' => 'immutable-trade']);
        $entry = $this->orderByType($adapter, ExchangeOrderType::MARKET);
        $initialStop = $this->orderByType($adapter, ExchangeOrderType::STOP_LOSS);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $state->saveOrder($this->withStatus($initialStop, ExchangeOrderStatus::CANCELLED));
        $state->saveOrder($this->conflictingTrailingChild(
            $this->expectedTrailingChild($entry, $tp1, $fixture),
            $conflict,
        ));
        $ordersBefore = $state->getOrders('BTCUSDT');
        $positionsBefore = $state->getOpenPositions('BTCUSDT');
        $eventsBefore = $state->events();

        try {
            $scenario->fillOrder($tp1->exchangeOrderId, null, (float) $fixture['tp1_price']);
            self::fail(sprintf('Expected trailing child conflict "%s" to fail.', $conflict));
        } catch (\LogicException $exception) {
            self::assertSame('fake_tp1_trailing_client_order_id_conflict', $exception->getMessage());
        }

        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($positionsBefore, $state->getOpenPositions('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertCount(0, $state->events('trailing_stop.armed'));
    }

    public function testTrailingCreationFailureRollsBackTp1PositionProtectionAndEvents(): void
    {
        $faultState = new class extends FakeExchangeStateStore {
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
        [, $adapter, $scenario] = $this->exchangeForState($faultState);
        $fixture = $this->fixture('long');
        $this->placeFixtureEntry($adapter, $fixture);
        $stop = $this->orderByType($adapter, ExchangeOrderType::STOP_LOSS);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $eventsBefore = \count($faultState->events());
        $faultState->rejectTrailingSave = true;

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
        self::assertCount($eventsBefore, $faultState->events());
        self::assertCount(0, $faultState->events('trailing_stop.armed'));
    }

    #[DataProvider('malformedPersistedTrailingStateProvider')]
    public function testMalformedPersistedActiveTrailingStateFailsAtomicallyBeforeRatchetOrTrigger(
        string $conflict,
    ): void {
        [$state, $adapter, $scenario] = $this->exchange();
        $fixture = $this->fixture('long');
        $trailing = $this->armFixture($adapter, $scenario, $fixture);
        $state->saveOrder($this->malformedPersistedTrailingOrder($trailing, $conflict));
        $ordersBefore = $state->getOrders('BTCUSDT');
        $positionsBefore = $state->getOpenPositions('BTCUSDT');
        $eventsBefore = $state->events();
        $bookBefore = $state->getOrderBookTop('BTCUSDT');

        try {
            $scenario->movePrice('BTCUSDT', 25000.0, 0.0);
            self::fail(sprintf('Expected malformed trailing state "%s" to fail.', $conflict));
        } catch (\LogicException $exception) {
            self::assertSame('fake_tp1_trailing_persisted_state_invalid', $exception->getMessage());
        }

        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($positionsBefore, $state->getOpenPositions('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertSame($bookBefore, $state->getOrderBookTop('BTCUSDT'));
        self::assertSame(0.6, $adapter->getOpenPositions('BTCUSDT')[0]->size);
        self::assertCount(1, $adapter->getOpenPositions('BTCUSDT'));
    }

    public function testMalformedPersistedTrailingStateFailsBeforeDirectTriggerFill(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $trailing = $this->armFixture($adapter, $scenario, $this->fixture('long'));
        $state->saveOrder($this->malformedPersistedTrailingOrder($trailing, 'reduce_only'));
        $ordersBefore = $state->getOrders('BTCUSDT');
        $positionsBefore = $state->getOpenPositions('BTCUSDT');
        $eventsBefore = $state->events();

        try {
            $scenario->fillOrder($trailing->exchangeOrderId);
            self::fail('Expected malformed trailing state to fail before a direct trigger fill.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_tp1_trailing_persisted_state_invalid', $exception->getMessage());
        }

        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($positionsBefore, $state->getOpenPositions('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertSame(0.6, $adapter->getOpenPositions('BTCUSDT')[0]->size);
    }

    #[DataProvider('directionProvider')]
    public function testPartialTrailingFillIsRejectedAtomicallyAndFullFillRemainsUsable(string $direction): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $trailing = $this->armFixture($adapter, $scenario, $this->fixture($direction));
        $ordersBefore = $state->getOrders('BTCUSDT');
        $positionsBefore = $state->getOpenPositions('BTCUSDT');
        $eventsBefore = $state->events();
        $fillsBefore = $adapter->getFillsSnapshot('BTCUSDT');

        try {
            $scenario->fillOrder($trailing->exchangeOrderId, 0.2);
            self::fail('Expected a partial trailing fill to be rejected.');
        } catch (\LogicException $exception) {
            self::assertSame('fake_tp1_trailing_partial_fill_unsupported', $exception->getMessage());
        }

        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($positionsBefore, $state->getOpenPositions('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertEquals($fillsBefore, $adapter->getFillsSnapshot('BTCUSDT'));

        $filled = $scenario->fillOrder($trailing->exchangeOrderId);

        self::assertSame(ExchangeOrderStatus::FILLED, $filled->status);
        self::assertSame(0.6, $filled->filledQuantity);
        self::assertCount(0, $adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $state->events('trailing_stop.triggered'));
    }

    public function testOrdinaryPersistedTriggerWithoutTrailingStateRemainsExecutable(): void
    {
        [$state, $adapter, $scenario] = $this->exchange();
        $trailing = $this->armFixture($adapter, $scenario, $this->fixture('long'));
        $metadata = $trailing->metadata;
        foreach ([
            'protection_kind',
            'trailing_state_version',
            'trailing_state_status',
            'trailing_activation_order_id',
            'trailing_watermark',
            'trailing_watermark_decimal',
        ] as $key) {
            unset($metadata[$key]);
        }
        $ordinaryTrigger = new ExchangeOrderDto(
            exchange: $trailing->exchange,
            marketType: $trailing->marketType,
            symbol: $trailing->symbol,
            exchangeOrderId: $trailing->exchangeOrderId,
            clientOrderId: $trailing->clientOrderId,
            side: $trailing->side,
            positionSide: $trailing->positionSide,
            orderType: $trailing->orderType,
            status: $trailing->status,
            quantity: $trailing->quantity,
            filledQuantity: $trailing->filledQuantity,
            remainingQuantity: $trailing->remainingQuantity,
            price: $trailing->price,
            averagePrice: $trailing->averagePrice,
            stopPrice: $trailing->stopPrice,
            reduceOnly: $trailing->reduceOnly,
            postOnly: $trailing->postOnly,
            timeInForce: $trailing->timeInForce,
            createdAt: $trailing->createdAt,
            updatedAt: $trailing->updatedAt,
            metadata: $metadata,
        );
        $state->saveOrder($ordinaryTrigger);

        $filled = $scenario->fillOrder($ordinaryTrigger->exchangeOrderId);

        self::assertSame(ExchangeOrderStatus::FILLED, $filled?->status);
        self::assertCount(0, $adapter->getOpenPositions('BTCUSDT'));
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

    /**
     * @param array<string,mixed> $fixture
     */
    private function armFixture(
        FakeExchangeAdapter $adapter,
        FakeExchangeScenarioService $scenario,
        array $fixture,
    ): ExchangeOrderDto {
        $this->placeFixtureEntry($adapter, $fixture);
        $tp1 = $this->orderByType($adapter, ExchangeOrderType::TAKE_PROFIT);
        $scenario->fillOrder(
            $tp1->exchangeOrderId,
            null,
            (float) $fixture['tp1_price'],
        );

        return $this->orderByType($adapter, ExchangeOrderType::TRIGGER);
    }

    /** @return iterable<string,array{string}> */
    public static function directionProvider(): iterable
    {
        yield 'long' => ['long'];
        yield 'short' => ['short'];
    }

    /** @return iterable<string,array{bool}> */
    public static function fallbackProtectionFailureProvider(): iterable
    {
        yield 'fallback price rejected' => [false];
        yield 'fallback order rejected' => [true];
    }

    /** @return iterable<string,array{string}> */
    public static function malformedPersistedTrailingStateProvider(): iterable
    {
        foreach ([
            'reduce_only',
            'side',
            'position_side',
            'order_type',
            'active_status',
            'filled_quantity',
            'quantity',
            'remaining_quantity',
            'quantity_decimal',
            'quantity_decimal_missing',
            'filled_quantity_decimal',
            'remaining_quantity_decimal',
            'stop_price',
            'stop_price_decimal',
            'stop_price_decimal_missing',
            'watermark',
            'watermark_decimal',
            'watermark_decimal_missing',
            'protection_kind',
            'protection_kind_missing',
        ] as $conflict) {
            yield $conflict => [$conflict];
        }
    }

    /** @return iterable<string,array{string}> */
    public static function trailingChildConflictProvider(): iterable
    {
        foreach ([
            'exchange',
            'market_type',
            'side',
            'position_side',
            'order_type',
            'status',
            'quantity',
            'filled_quantity',
            'remaining_quantity',
            'price',
            'average_price',
            'stop_price',
            'reduce_only',
            'post_only',
            'time_in_force',
            'source',
            'protection_kind',
            'parent_order_id',
            'parent_client_order_id',
            'lineage',
            'policy_version',
            'policy_enabled',
            'policy_tp1_quantity_decimal',
            'policy_offset_decimal',
            'state_version',
            'state_status',
            'activation_order_id',
            'watermark',
            'quantity_decimal',
            'filled_quantity_decimal',
            'remaining_quantity_decimal',
            'stop_price_decimal',
            'watermark_decimal',
            'margin_contract_size',
        ] as $conflict) {
            yield $conflict => [$conflict];
        }
    }

    /**
     * @param array<string,mixed> $fixture
     */
    private function expectedTrailingChild(
        ExchangeOrderDto $entry,
        ExchangeOrderDto $tp1,
        array $fixture,
    ): ExchangeOrderDto {
        $parentOrderId = (string) ($tp1->metadata['parent_order_id'] ?? '');
        $clientOrderId = 'fake-trailing-' . substr(hash(
            'sha256',
            $parentOrderId . ':' . $tp1->exchangeOrderId,
        ), 0, 32);
        $quantity = (float) $fixture['quantity'] - (float) $fixture['tp1_quantity'];
        $watermark = (float) $fixture['tp1_price'];
        $stopPrice = $tp1->positionSide === ExchangePositionSide::LONG
            ? $watermark - (float) $fixture['trailing_offset']
            : $watermark + (float) $fixture['trailing_offset'];
        $lineageKeys = [
            'internal_trade_id',
            'trade_id',
            'internal_position_id',
            'position_id',
            'exchange_position_id',
            'order_intent_id',
            'client_order_id',
            'run_id',
            'correlation_run_id',
            'orchestration_run_id',
            'orchestration_set_id',
            'orchestration_dashboard_id',
            'mtf_profile',
            'origin',
            'attempt_number',
            'decision_key',
        ];
        $metadata = array_intersect_key($tp1->metadata, array_flip($lineageKeys))
            + (new FakeTp1TrailingPolicy(
                (string) $fixture['tp1_quantity'],
                (string) $fixture['trailing_offset'],
            ))->toMetadata()
            + [
                'source' => 'fake_exchange',
                'parent_order_id' => $parentOrderId,
                'parent_client_order_id' => $tp1->metadata['parent_client_order_id'] ?? null,
                'protection_kind' => 'trailing',
                'trailing_state_version' => FakeTp1TrailingPolicy::VERSION,
                'trailing_state_status' => 'active',
                'trailing_activation_order_id' => $tp1->exchangeOrderId,
                'trailing_watermark' => $watermark,
                'trailing_watermark_decimal' => (string) $fixture['tp1_price'],
                'quantity_decimal' => json_encode(
                    $quantity,
                    JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                ),
                'filled_quantity_decimal' => '0',
                'remaining_quantity_decimal' => json_encode(
                    $quantity,
                    JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                ),
                'stop_price_decimal' => json_encode(
                    $stopPrice,
                    JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
                ),
                'margin_contract_size' => $tp1->metadata['margin_contract_size'] ?? null,
            ];

        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: (string) $fixture['symbol'],
            exchangeOrderId: 'fake-existing-trailing',
            clientOrderId: $clientOrderId,
            side: $tp1->side,
            positionSide: $tp1->positionSide,
            orderType: ExchangeOrderType::TRIGGER,
            status: ExchangeOrderStatus::OPEN,
            quantity: $quantity,
            filledQuantity: 0.0,
            remainingQuantity: $quantity,
            price: null,
            averagePrice: null,
            stopPrice: $stopPrice,
            reduceOnly: true,
            postOnly: false,
            timeInForce: null,
            createdAt: $entry->createdAt,
            metadata: $metadata,
        );
    }

    private function conflictingTrailingChild(ExchangeOrderDto $order, string $conflict): ExchangeOrderDto
    {
        $exchange = $order->exchange;
        $marketType = $order->marketType;
        $side = $order->side;
        $positionSide = $order->positionSide;
        $orderType = $order->orderType;
        $status = $order->status;
        $quantity = $order->quantity;
        $filledQuantity = $order->filledQuantity;
        $remainingQuantity = $order->remainingQuantity;
        $price = $order->price;
        $averagePrice = $order->averagePrice;
        $stopPrice = $order->stopPrice;
        $reduceOnly = $order->reduceOnly;
        $postOnly = $order->postOnly;
        $timeInForce = $order->timeInForce;
        $metadata = $order->metadata;

        match ($conflict) {
            'exchange' => $exchange = Exchange::OKX,
            'market_type' => $marketType = MarketType::SPOT,
            'side' => $side = ExchangeOrderSide::BUY,
            'position_side' => $positionSide = ExchangePositionSide::SHORT,
            'order_type' => $orderType = ExchangeOrderType::STOP_LOSS,
            'status' => $status = ExchangeOrderStatus::FILLED,
            'quantity' => $quantity = 0.7,
            'filled_quantity' => $filledQuantity = 0.1,
            'remaining_quantity' => $remainingQuantity = 0.5,
            'price' => $price = 25100.0,
            'average_price' => $averagePrice = 25100.0,
            'stop_price' => $stopPrice = 25100.1,
            'reduce_only' => $reduceOnly = false,
            'post_only' => $postOnly = true,
            'time_in_force' => $timeInForce = ExchangeTimeInForce::GTC,
            'source' => $metadata['source'] = 'other',
            'protection_kind' => $metadata['protection_kind'] = 'sl',
            'parent_order_id' => $metadata['parent_order_id'] = 'other-parent',
            'parent_client_order_id' => $metadata['parent_client_order_id'] = 'other-client',
            'lineage' => $metadata['internal_trade_id'] = 'other-trade',
            'policy_version' => $metadata[FakeTp1TrailingPolicy::VERSION_KEY] = 'fake-tp1-trailing-v2',
            'policy_enabled' => $metadata[FakeTp1TrailingPolicy::ENABLED_KEY] = false,
            'policy_tp1_quantity_decimal' => $metadata[FakeTp1TrailingPolicy::TP1_QUANTITY_KEY] = '0.40',
            'policy_offset_decimal' => $metadata[FakeTp1TrailingPolicy::TRAILING_OFFSET_KEY] = '100.00',
            'state_version' => $metadata['trailing_state_version'] = 'fake-tp1-trailing-v2',
            'state_status' => $metadata['trailing_state_status'] = 'triggered',
            'activation_order_id' => $metadata['trailing_activation_order_id'] = 'other-activation',
            'watermark' => $metadata['trailing_watermark'] = 25200.1,
            'quantity_decimal' => $metadata['quantity_decimal'] = '0.60',
            'filled_quantity_decimal' => $metadata['filled_quantity_decimal'] = '0.0',
            'remaining_quantity_decimal' => $metadata['remaining_quantity_decimal'] = '0.60',
            'stop_price_decimal' => $metadata['stop_price_decimal'] = '25100.00',
            'watermark_decimal' => $metadata['trailing_watermark_decimal'] = '25200.00',
            'margin_contract_size' => $metadata['margin_contract_size'] = '2',
            default => throw new \LogicException('Unknown trailing child conflict ' . $conflict),
        };

        return new ExchangeOrderDto(
            exchange: $exchange,
            marketType: $marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            status: $status,
            quantity: $quantity,
            filledQuantity: $filledQuantity,
            remainingQuantity: $remainingQuantity,
            price: $price,
            averagePrice: $averagePrice,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: $postOnly,
            timeInForce: $timeInForce,
            createdAt: $order->createdAt,
            updatedAt: $order->updatedAt,
            metadata: $metadata,
        );
    }

    private function malformedPersistedTrailingOrder(
        ExchangeOrderDto $order,
        string $conflict,
    ): ExchangeOrderDto {
        $side = $order->side;
        $positionSide = $order->positionSide;
        $orderType = $order->orderType;
        $status = $order->status;
        $quantity = $order->quantity;
        $filledQuantity = $order->filledQuantity;
        $remainingQuantity = $order->remainingQuantity;
        $stopPrice = $order->stopPrice;
        $reduceOnly = $order->reduceOnly;
        $metadata = $order->metadata;

        if (\in_array($conflict, [
            'quantity_decimal_missing',
            'stop_price_decimal_missing',
            'watermark_decimal_missing',
            'protection_kind_missing',
        ], true)) {
            unset($metadata[match ($conflict) {
                'quantity_decimal_missing' => 'quantity_decimal',
                'stop_price_decimal_missing' => 'stop_price_decimal',
                'watermark_decimal_missing' => 'trailing_watermark_decimal',
                'protection_kind_missing' => 'protection_kind',
            }]);
        } else {
            match ($conflict) {
                'reduce_only' => $reduceOnly = false,
                'side' => $side = ExchangeOrderSide::BUY,
                'position_side' => $positionSide = ExchangePositionSide::SHORT,
                'order_type' => $orderType = ExchangeOrderType::STOP_LOSS,
                'active_status' => $status = ExchangeOrderStatus::PENDING,
                'filled_quantity' => $filledQuantity = 0.1,
                'quantity' => $quantity = 0.0,
                'remaining_quantity' => $remainingQuantity = 0.5,
                'quantity_decimal' => $metadata['quantity_decimal'] = '0.7',
                'filled_quantity_decimal' => $metadata['filled_quantity_decimal'] = '0.1',
                'remaining_quantity_decimal' => $metadata['remaining_quantity_decimal'] = '0.5',
                'stop_price' => $stopPrice = 25100.03,
                'stop_price_decimal' => $metadata['stop_price_decimal'] = '25100.1',
                'watermark' => $metadata['trailing_watermark'] = 25200.1,
                'watermark_decimal' => $metadata['trailing_watermark_decimal'] = '25200.1',
                'protection_kind' => $metadata['protection_kind'] = 'sl',
                default => throw new \LogicException('Unknown persisted trailing conflict ' . $conflict),
            };
        }

        return new ExchangeOrderDto(
            exchange: $order->exchange,
            marketType: $order->marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            status: $status,
            quantity: $quantity,
            filledQuantity: $filledQuantity,
            remainingQuantity: $remainingQuantity,
            price: $order->price,
            averagePrice: $order->averagePrice,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: $order->postOnly,
            timeInForce: $order->timeInForce,
            createdAt: $order->createdAt,
            updatedAt: $order->updatedAt,
            metadata: $metadata,
        );
    }

    private function withStatus(ExchangeOrderDto $order, ExchangeOrderStatus $status): ExchangeOrderDto
    {
        return new ExchangeOrderDto(
            exchange: $order->exchange,
            marketType: $order->marketType,
            symbol: $order->symbol,
            exchangeOrderId: $order->exchangeOrderId,
            clientOrderId: $order->clientOrderId,
            side: $order->side,
            positionSide: $order->positionSide,
            orderType: $order->orderType,
            status: $status,
            quantity: $order->quantity,
            filledQuantity: $order->filledQuantity,
            remainingQuantity: $order->remainingQuantity,
            price: $order->price,
            averagePrice: $order->averagePrice,
            stopPrice: $order->stopPrice,
            reduceOnly: $order->reduceOnly,
            postOnly: $order->postOnly,
            timeInForce: $order->timeInForce,
            createdAt: $order->createdAt,
            updatedAt: $order->updatedAt,
            metadata: $order->metadata,
        );
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
