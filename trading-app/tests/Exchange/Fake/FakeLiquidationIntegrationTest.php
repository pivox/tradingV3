<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeLiquidationPolicy;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversNothing]
final class FakeLiquidationIntegrationTest extends TestCase
{
    public function testExplicitMarkIsVersionedPersistedAndNeverDerivedFromBook(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake-liquidation-mark-');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $book = new FakeExchangeOrderBook($state);

            self::assertTrue($state->hasMarkPrice('BTCUSDT'));
            self::assertTrue($state->hasMarkPrice('ETHUSDT'));
            self::assertSame('25000', $state->getMarkPrice('BTCUSDT'));
            self::assertSame('1800', $state->getMarkPrice('ETHUSDT'));
            self::assertSame(FakeLiquidationPolicy::MARK_PRICE_SOURCE, $state->markPriceSource());

            $book->movePrice('BTCUSDT', 25123.45);
            $restored = new FakeExchangeStateStore($stateFile);

            self::assertSame('25123.45', $restored->getMarkPrice('BTCUSDT'));

            $envelope = unserialize((string) file_get_contents($stateFile), ['allowed_classes' => true]);
            self::assertIsArray($envelope['payload'] ?? null);
            unset($envelope['payload']['markPrices']);
            $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
            file_put_contents($stateFile, serialize($envelope));

            $legacyWithoutMark = new FakeExchangeStateStore($stateFile);
            self::assertFalse($legacyWithoutMark->hasMarkPrice('BTCUSDT'));
            self::assertNull($legacyWithoutMark->getMarkPrice('BTCUSDT'));
            self::assertSame(['bid' => 25120.937655, 'ask' => 25125.962345], $legacyWithoutMark->getOrderBookTop('BTCUSDT'));

            $legacyWithoutMark->setOrderBookTop('BTCUSDT', 29999.0, 30001.0);
            $legacyWithoutMark->clearMarkPrice('BTCUSDT');
            $withoutMark = new FakeExchangeStateStore($stateFile);

            self::assertNull($withoutMark->getMarkPrice('BTCUSDT'));
            self::assertSame(['bid' => 29999.0, 'ask' => 30001.0], $withoutMark->getOrderBookTop('BTCUSDT'));
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testIsolatedEntryPersistsCertifiedLiquidationPreflight(): void
    {
        [$adapter, , $state] = $this->runtime();

        $result = $adapter->placeOrder($this->entryRequest());

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertSame(FakeLiquidationPolicy::MODEL_VERSION, $result->order?->metadata['liquidation_model_version'] ?? null);
        self::assertSame('isolated', $result->order?->metadata['liquidation_margin_mode'] ?? null);
        self::assertSame('25000.000000000000', $result->order?->metadata['liquidation_mark_price_decimal'] ?? null);
        self::assertSame('22613.065326633166', $result->order?->metadata['liquidation_price_decimal'] ?? null);
        self::assertSame('22863.065326633166', $result->order?->metadata['liquidation_guard_price_decimal'] ?? null);

        $position = $state->getPosition('BTCUSDT', ExchangePositionSide::LONG);
        self::assertNotNull($position);
        self::assertSame(FakeLiquidationPolicy::MODEL_VERSION, $position->metadata['liquidation_model_version'] ?? null);
        self::assertSame('1.000000000000', $position->metadata['liquidation_quantity_decimal'] ?? null);
        self::assertSame('2500.000000000000', $position->metadata['liquidation_isolated_margin_decimal'] ?? null);
    }

    public function testCrossMarginIsUnsupportedAtSettingAndEntryBoundaries(): void
    {
        [$adapter, , $state] = $this->runtime();

        self::assertFalse($adapter->setLeverage('BTCUSDT', 10, 'cross'));

        $result = $adapter->placeOrder($this->entryRequest(marginMode: 'cross'));

        self::assertFalse($result->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $result->status);
        self::assertSame('liquidation_cross_margin_unsupported', $result->metadata['reason'] ?? null);
        self::assertSame(FakeLiquidationPolicy::MODEL_VERSION, $result->metadata['liquidation_model_version'] ?? null);
        self::assertSame([], $state->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $state->events('order.rejected'));
    }

    public function testMissingMarkRejectsEntryWithoutBookFallback(): void
    {
        [$adapter, , $state] = $this->runtime();
        $state->clearMarkPrice('BTCUSDT');
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);

        $result = $adapter->placeOrder($this->entryRequest());

        self::assertFalse($result->accepted);
        self::assertSame('liquidation_mark_price_unknown', $result->metadata['reason'] ?? null);
        self::assertArrayNotHasKey('liquidation_mark_price_decimal', $result->metadata);
        self::assertSame([], $state->getOpenPositions('BTCUSDT'));
    }

    public function testEntryAlreadyInsideGuardIsRejectedWithoutExposure(): void
    {
        [$adapter, , $state] = $this->runtime();
        $state->setMarkPrice('BTCUSDT', '22800');

        $result = $adapter->placeOrder($this->entryRequest());

        self::assertFalse($result->accepted);
        self::assertSame('liquidation_entry_inside_guard', $result->metadata['reason'] ?? null);
        self::assertSame('guard', $result->metadata['liquidation_mark_state'] ?? null);
        self::assertSame('22800.000000000000', $result->metadata['liquidation_mark_price_decimal'] ?? null);
        self::assertSame([], $state->getOpenPositions('BTCUSDT'));
    }

    public function testGuardEntryIsAuditedOnceWithoutLiquidation(): void
    {
        [, $scenario, $state] = $this->runtime();
        [$adapter] = $this->runtimeForState($state);
        $adapter->placeOrder($this->entryRequest());

        $first = $scenario->movePrice('BTCUSDT', 22800.0);
        $second = $scenario->movePrice('BTCUSDT', 22800.0);

        self::assertSame([], $first['matched_orders']);
        self::assertSame([], $second['matched_orders']);
        self::assertNotNull($state->getPosition('BTCUSDT', ExchangePositionSide::LONG));
        self::assertCount(1, $state->events('liquidation.guard_entered'));
        self::assertSame('22800.000000000000', $state->events('liquidation.guard_entered')[0]->payload['liquidation_mark_price_decimal'] ?? null);
        self::assertSame([], $state->events('liquidation.filled'));
        self::assertSame([], $state->events('position.closed'));
    }

    public function testLongGapLiquidatesAtObservedMarkWithSeparateFeeAndProtectionCleanup(): void
    {
        [$adapter, $scenario, $state] = $this->runtime();
        $entry = $adapter->placeOrder($this->entryRequest(
            clientOrderId: 'liquidation-long-gap',
            attachedStopLossPrice: 22500.0,
            attachedTakeProfitPrice: 27000.0,
        ));
        self::assertSame(ExchangeOrderStatus::FILLED, $entry->status);
        self::assertCount(2, $state->getOpenOrders('BTCUSDT'));

        $move = $scenario->movePrice('BTCUSDT', 22000.0);

        self::assertSame([], $move['matched_orders']);
        self::assertNull($state->getPosition('BTCUSDT', ExchangePositionSide::LONG));
        self::assertCount(1, $state->events('liquidation.triggered'));
        self::assertCount(1, $state->events('liquidation.filled'));
        self::assertCount(1, $state->events('position.closed'));
        self::assertCount(1, $state->events('order.filled'));

        $fill = $state->events('liquidation.filled')[0];
        self::assertSame(1.0, $fill->payload['fill_quantity'] ?? null);
        self::assertSame(22000.0, $fill->payload['fill_price'] ?? null);
        self::assertSame(11.0, $fill->payload['fill_fee'] ?? null);
        self::assertSame(110.0, $fill->payload['liquidation_fee_usdt'] ?? null);
        self::assertSame(FakeLiquidationPolicy::FEE_MODEL_VERSION, $fill->payload['liquidation_fee_model_version'] ?? null);
        self::assertSame('USDT', $fill->payload['liquidation_fee_currency'] ?? null);
        self::assertSame('-3000.000000000000', $fill->payload['realized_gross_pnl_usdt'] ?? null);

        $closed = $state->events('position.closed')[0];
        self::assertSame('liquidation', $closed->payload['close_reason'] ?? null);
        self::assertSame(110.0, $closed->payload['liquidation_fee_usdt'] ?? null);
        self::assertSame(-3157.0, $closed->payload['recorded_pnl_usdt'] ?? null);
        self::assertSame('-3157.000000000000', $closed->payload['recorded_pnl_usdt_decimal'] ?? null);
        self::assertSame(96843.0, $state->totalBalanceUsdt());
        self::assertSame(
            '-3157.000000000000',
            $state->getBalances()[0]->metadata['last_certified_balance_delta_usdt'] ?? null,
        );

        $cancelledProtections = array_values(array_filter(
            $state->getOrders('BTCUSDT'),
            static fn ($order): bool => $order->reduceOnly && $order->status === ExchangeOrderStatus::CANCELLED,
        ));
        self::assertCount(2, $cancelledProtections);
        foreach ($cancelledProtections as $protection) {
            self::assertSame('position_liquidated', $protection->metadata['reason'] ?? null);
        }

        $fills = $adapter->getFillsSnapshot('BTCUSDT');
        self::assertCount(2, $fills);
        self::assertSame(110.0, $fills[1]->metadata['liquidation_fee_usdt'] ?? null);
    }

    public function testShortGapLiquidatesAtObservedMark(): void
    {
        [$adapter, $scenario, $state] = $this->runtime();
        $state->setOrderBookTop('BTCUSDT', 25000.0, 25001.0);
        $entry = $adapter->placeOrder($this->entryRequest(
            clientOrderId: 'liquidation-short-gap',
            side: ExchangePositionSide::SHORT,
        ));
        self::assertSame(ExchangeOrderStatus::FILLED, $entry->status);

        $scenario->movePrice('BTCUSDT', 28000.0);

        self::assertNull($state->getPosition('BTCUSDT', ExchangePositionSide::SHORT));
        $fill = $state->events('liquidation.filled')[0] ?? null;
        self::assertNotNull($fill);
        self::assertSame(ExchangePositionSide::SHORT->value, $fill->payload['liquidation_position_side'] ?? null);
        self::assertSame(28000.0, $fill->payload['fill_price'] ?? null);
        self::assertSame(140.0, $fill->payload['liquidation_fee_usdt'] ?? null);
        self::assertSame('-3000.000000000000', $fill->payload['realized_gross_pnl_usdt'] ?? null);
        self::assertSame(96807.0, $state->totalBalanceUsdt());
    }

    public function testRestartAndRepeatedMarkAreExactOnce(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake-liquidation-restart-');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $state->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);
            [$adapter, $scenario] = $this->runtimeForState($state);
            $adapter->placeOrder($this->entryRequest(clientOrderId: 'liquidation-restart'));
            $scenario->movePrice('BTCUSDT', 22000.0);

            $balanceAfterFirst = $state->totalBalanceUsdt();
            self::assertCount(1, $state->events('liquidation.filled'));

            $restored = new FakeExchangeStateStore($stateFile);
            [, $restoredScenario] = $this->runtimeForState($restored);
            $restoredScenario->movePrice('BTCUSDT', 21000.0);
            $restoredScenario->movePrice('BTCUSDT', 21000.0);

            self::assertCount(1, $restored->events('liquidation.triggered'));
            self::assertCount(1, $restored->events('liquidation.filled'));
            self::assertCount(1, $restored->events('position.closed'));
            self::assertSame($balanceAfterFirst, $restored->totalBalanceUsdt());
            self::assertCount(2, (new FakeExchangeAdapter(
                $restored,
                new FakeExchangeOrderBook($restored),
                new FakeExchangeMatchingEngine($restored, new FakeExchangeOrderBook($restored), $this->clock()),
                $this->clock(),
            ))->getFillsSnapshot('BTCUSDT'));
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testUnknownPersistedLiquidationMetadataFailsClosedAndRollsBackMarkMove(): void
    {
        [$adapter, $scenario, $state] = $this->runtime();
        $adapter->placeOrder($this->entryRequest(clientOrderId: 'liquidation-invalid-position'));
        $position = $state->getPosition('BTCUSDT', ExchangePositionSide::LONG);
        self::assertNotNull($position);
        $metadata = $position->metadata;
        unset($metadata['liquidation_contract_size_decimal']);
        $state->savePosition(new ExchangePositionDto(
            exchange: $position->exchange,
            marketType: $position->marketType,
            symbol: $position->symbol,
            side: $position->side,
            size: $position->size,
            entryPrice: $position->entryPrice,
            markPrice: $position->markPrice,
            unrealizedPnl: $position->unrealizedPnl,
            realizedPnl: $position->realizedPnl,
            margin: $position->margin,
            leverage: $position->leverage,
            openedAt: $position->openedAt,
            updatedAt: $position->updatedAt,
            metadata: $metadata,
        ));

        try {
            $scenario->movePrice('BTCUSDT', 22000.0);
            self::fail('Malformed persisted liquidation metadata must fail closed.');
        } catch (\LogicException $exception) {
            self::assertSame('liquidation_contract_size_unknown', $exception->getMessage());
        }

        self::assertSame('25000', $state->getMarkPrice('BTCUSDT'));
        self::assertNotNull($state->getPosition('BTCUSDT', ExchangePositionSide::LONG));
        self::assertSame([], $state->events('liquidation.triggered'));
        self::assertSame([], $state->events('liquidation.filled'));
        self::assertSame(100000.0, $state->totalBalanceUsdt());
    }

    public function testRestingEntryIsRevalidatedAtFillBoundary(): void
    {
        [$adapter, $scenario, $state] = $this->runtime();
        $resting = $adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: 24900.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            leverage: 10,
            marginMode: 'isolated',
            clientOrderId: 'liquidation-resting-boundary',
            quantityDecimal: '1',
            priceDecimal: '24900',
        ));
        self::assertSame(ExchangeOrderStatus::OPEN, $resting->status);
        $state->setMarkPrice('BTCUSDT', '22500');

        $rejected = $scenario->fillOrder((string) $resting->exchangeOrderId, 1.0, 24900.0);

        self::assertSame(ExchangeOrderStatus::REJECTED, $rejected?->status);
        self::assertSame('liquidation_entry_inside_guard', $rejected?->metadata['reason'] ?? null);
        self::assertSame([], $state->getOpenPositions('BTCUSDT'));
        self::assertSame([], $state->events('order.filled'));
        self::assertCount(1, $state->events('order.rejected'));
    }

    public function testPartialReductionRecalculatesLiquidationQuantityMarginAndFee(): void
    {
        [$adapter, $scenario, $state] = $this->runtime();
        $adapter->placeOrder($this->entryRequest(clientOrderId: 'liquidation-partial-entry'));
        $reduction = $adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::SELL,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 0.4,
            price: null,
            stopPrice: null,
            reduceOnly: true,
            postOnly: false,
            leverage: 10,
            marginMode: 'isolated',
            clientOrderId: 'liquidation-partial-reduce',
            quantityDecimal: '0.4',
        ));
        self::assertSame(ExchangeOrderStatus::FILLED, $reduction->status);

        $remaining = $state->getPosition('BTCUSDT', ExchangePositionSide::LONG);
        self::assertNotNull($remaining);
        self::assertSame('0.600000000000', $remaining->metadata['liquidation_quantity_decimal'] ?? null);
        self::assertSame('1500.000000000000', $remaining->metadata['liquidation_isolated_margin_decimal'] ?? null);

        $scenario->movePrice('BTCUSDT', 22000.0);

        $fill = $state->events('liquidation.filled')[0] ?? null;
        self::assertNotNull($fill);
        self::assertSame(0.6, $fill->payload['fill_quantity'] ?? null);
        self::assertSame(66.0, $fill->payload['liquidation_fee_usdt'] ?? null);
        self::assertNull($state->getPosition('BTCUSDT', ExchangePositionSide::LONG));
    }

    public function testSameSideIncreasePersistsAggregatedExactLiquidationInputs(): void
    {
        [$adapter, , $state] = $this->runtime();
        $adapter->placeOrder($this->entryRequest(clientOrderId: 'liquidation-scale-first'));
        $state->setOrderBookTop('BTCUSDT', 25999.0, 26000.0);
        $second = $adapter->placeOrder($this->entryRequest(clientOrderId: 'liquidation-scale-second'));

        self::assertSame(ExchangeOrderStatus::FILLED, $second->status);
        $position = $state->getPosition('BTCUSDT', ExchangePositionSide::LONG);
        self::assertNotNull($position);
        self::assertSame('2.000000000000', $position->metadata['liquidation_quantity_decimal'] ?? null);
        self::assertSame('25500.000000000000', $position->metadata['liquidation_entry_price_decimal'] ?? null);
        self::assertSame('5100.000000000000', $position->metadata['liquidation_isolated_margin_decimal'] ?? null);
        self::assertSame('23065.326633165829', $position->metadata['liquidation_price_decimal'] ?? null);
        self::assertSame('23320.326633165829', $position->metadata['liquidation_guard_price_decimal'] ?? null);
    }

    public function testLiquidationRollsBackEveryMutationWhenBalanceBookingFails(): void
    {
        $state = new FailingLiquidationBalanceStateStore();
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);
        [$adapter, $scenario] = $this->runtimeForState($state);
        $adapter->placeOrder($this->entryRequest(
            clientOrderId: 'liquidation-atomic-rollback',
            attachedStopLossPrice: 22500.0,
            attachedTakeProfitPrice: 27000.0,
        ));
        $ordersBefore = $state->getOrders('BTCUSDT');
        $eventsBefore = $state->events();
        $state->failBalanceBooking = true;

        try {
            $scenario->movePrice('BTCUSDT', 22000.0);
            self::fail('The injected balance booking failure must abort liquidation.');
        } catch (\LogicException $exception) {
            self::assertSame('forced_liquidation_balance_booking_failure', $exception->getMessage());
        }

        self::assertSame('25000', $state->getMarkPrice('BTCUSDT'));
        self::assertNotNull($state->getPosition('BTCUSDT', ExchangePositionSide::LONG));
        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertCount(2, $state->getOpenOrders('BTCUSDT'));
        self::assertSame(100000.0, $state->totalBalanceUsdt());
    }

    /**
     * @return array{FakeExchangeAdapter,FakeExchangeScenarioService,FakeExchangeStateStore}
     */
    private function runtime(): array
    {
        $state = new FakeExchangeStateStore();
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);
        [$adapter, $scenario] = $this->runtimeForState($state);

        return [$adapter, $scenario, $state];
    }

    /** @return array{FakeExchangeAdapter,FakeExchangeScenarioService} */
    private function runtimeForState(FakeExchangeStateStore $state): array
    {
        $book = new FakeExchangeOrderBook($state);
        $clock = $this->clock();
        $engine = new FakeExchangeMatchingEngine($state, $book, $clock);

        return [
            new FakeExchangeAdapter($state, $book, $engine, $clock),
            new FakeExchangeScenarioService($state, $book, $engine),
        ];
    }

    private function entryRequest(
        string $marginMode = 'isolated',
        string $clientOrderId = 'liquidation-entry-isolated',
        ExchangePositionSide $side = ExchangePositionSide::LONG,
        ?float $attachedStopLossPrice = null,
        ?float $attachedTakeProfitPrice = null,
    ): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: $side === ExchangePositionSide::LONG ? ExchangeOrderSide::BUY : ExchangeOrderSide::SELL,
            positionSide: $side,
            orderType: ExchangeOrderType::MARKET,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 1.0,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: 10,
            marginMode: $marginMode,
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachedStopLossPrice,
            attachedTakeProfitPrice: $attachedTakeProfitPrice,
            quantityDecimal: '1',
        );
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-07-19T10:00:00+00:00');
            }
        };
    }
}

final class FailingLiquidationBalanceStateStore extends FakeExchangeStateStore
{
    public bool $failBalanceBooking = false;

    public function applyCertifiedBalanceDeltaUsdt(string $delta, string $modelVersion): void
    {
        if ($this->failBalanceBooking) {
            throw new \LogicException('forced_liquidation_balance_booking_failure');
        }

        parent::applyCertifiedBalanceDeltaUsdt($delta, $modelVersion);
    }
}
