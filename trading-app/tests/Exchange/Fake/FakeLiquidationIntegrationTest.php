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
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
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

    public function testBankruptcyGapPersistsTerminalLiquidationAndAuditedBalanceClampAcrossRestart(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake-liquidation-bankruptcy-');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $state->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);
            [$adapter, $scenario] = $this->runtimeForState($state);
            $entry = $adapter->placeOrder($this->entryRequest(
                clientOrderId: 'liquidation-bankruptcy-gap',
                side: ExchangePositionSide::SHORT,
                leverage: 50,
                quantity: 80.0,
            ));
            self::assertSame(ExchangeOrderStatus::FILLED, $entry->status);

            $failure = null;
            try {
                $scenario->movePrice('BTCUSDT', 100000.0);
            } catch (\LogicException $exception) {
                $failure = $exception->getMessage();
            }

            self::assertNull($failure, 'A bankruptcy gap must not roll back terminal liquidation.');
            self::assertNull($state->getPosition('BTCUSDT', ExchangePositionSide::SHORT));
            self::assertCount(1, $state->events('liquidation.triggered'));
            self::assertCount(1, $state->events('liquidation.filled'));
            self::assertCount(1, $state->events('position.closed'));

            $closed = $state->events('position.closed')[0];
            $certifiedDelta = $closed->payload['recorded_pnl_usdt_decimal'] ?? null;
            self::assertIsString($certifiedDelta);
            self::assertTrue(BigDecimal::of($certifiedDelta)->isLessThan('-100000'));
            $expectedShortfall = (string) BigDecimal::of($certifiedDelta)
                ->plus('100000')
                ->abs()
                ->toScale(12, RoundingMode::HALF_EVEN);

            $balance = $adapter->getBalances()[0];
            self::assertSame(0.0, $balance->available);
            self::assertSame(0.0, $balance->total);
            self::assertSame(0.0, $balance->equity);
            self::assertSame($certifiedDelta, $balance->metadata['last_certified_balance_delta_usdt'] ?? null);
            self::assertSame(
                '-100000.000000000000',
                $balance->metadata['last_certified_balance_applied_delta_usdt'] ?? null,
            );
            self::assertSame(
                $expectedShortfall,
                $balance->metadata['last_certified_balance_shortfall_usdt'] ?? null,
            );
            self::assertTrue($balance->metadata['last_certified_balance_clamped'] ?? false);
            self::assertSame(
                'fake-liquidation-balance-floor-v1',
                $balance->metadata['last_certified_balance_clamp_model_version'] ?? null,
            );

            $restored = new FakeExchangeStateStore($stateFile);
            [$restoredAdapter, $restoredScenario] = $this->runtimeForState($restored);
            $restoredScenario->movePrice('BTCUSDT', 100000.0);
            $restoredScenario->movePrice('BTCUSDT', 100000.0);

            self::assertNull($restored->getPosition('BTCUSDT', ExchangePositionSide::SHORT));
            self::assertCount(1, $restored->events('liquidation.triggered'));
            self::assertCount(1, $restored->events('liquidation.filled'));
            self::assertCount(1, $restored->events('position.closed'));
            self::assertCount(2, $restoredAdapter->getFillsSnapshot('BTCUSDT'));
            self::assertSame(0.0, $restoredAdapter->getBalances()[0]->total);

            $rejected = $restoredAdapter->placeOrder($this->entryRequest(
                clientOrderId: 'liquidation-bankruptcy-no-reentry',
                leverage: 100,
                quantity: 0.001,
            ));
            self::assertFalse($rejected->accepted);
            self::assertSame('insufficient_balance', $rejected->metadata['reason'] ?? null);
            self::assertSame([], $restored->getOpenPositions('BTCUSDT'));
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
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

    public function testRestartReplayKeepsIdentityAndDistinctReopenCanLiquidateAtSameClockAndInputs(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake-liquidation-position-identity-');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $state->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);
            [$adapter, $scenario] = $this->runtimeForState($state);
            $firstEntry = $adapter->placeOrder($this->entryRequest(clientOrderId: 'liquidation-identity-first'));
            self::assertSame(ExchangeOrderStatus::FILLED, $firstEntry->status);
            $firstPosition = $state->getPosition('BTCUSDT', ExchangePositionSide::LONG);
            self::assertNotNull($firstPosition);
            $firstPositionIdentity = $firstPosition->metadata['liquidation_position_identity'] ?? null;
            $firstOpenedAt = $firstPosition->openedAt;

            $scenario->movePrice('BTCUSDT', 22000.0);
            $firstLiquidation = $state->events('liquidation.filled')[0] ?? null;
            self::assertNotNull($firstLiquidation);
            $firstLiquidationIdentity = $firstLiquidation->payload['liquidation_identity'] ?? null;
            $firstLiquidationOrders = array_values(array_filter(
                $state->getOrders('BTCUSDT'),
                static fn ($order): bool => str_starts_with($order->clientOrderId, 'fake-liq-'),
            ));
            self::assertCount(1, $firstLiquidationOrders);
            $firstLiquidationClientOrderId = $firstLiquidationOrders[0]->clientOrderId;
            $balanceAfterFirst = $state->totalBalanceUsdt();

            $restored = new FakeExchangeStateStore($stateFile);
            [$restoredAdapter, $restoredScenario] = $this->runtimeForState($restored);
            $restoredScenario->movePrice('BTCUSDT', 22000.0);

            self::assertCount(1, $restored->events('liquidation.filled'));
            self::assertSame($balanceAfterFirst, $restored->totalBalanceUsdt());
            $replayedLiquidationOrders = array_values(array_filter(
                $restored->getOrders('BTCUSDT'),
                static fn ($order): bool => str_starts_with($order->clientOrderId, 'fake-liq-'),
            ));
            self::assertCount(1, $replayedLiquidationOrders);
            self::assertSame($firstLiquidationClientOrderId, $replayedLiquidationOrders[0]->clientOrderId);
            self::assertSame(
                $firstLiquidationIdentity,
                $restored->events('liquidation.filled')[0]->payload['liquidation_identity'] ?? null,
            );

            $restored->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);
            $restored->setMarkPrice('BTCUSDT', '25000');
            $secondEntry = $restoredAdapter->placeOrder($this->entryRequest(
                clientOrderId: 'liquidation-identity-second',
            ));
            self::assertSame(ExchangeOrderStatus::FILLED, $secondEntry->status);
            $secondPosition = $restored->getPosition('BTCUSDT', ExchangePositionSide::LONG);
            self::assertNotNull($secondPosition);
            $secondPositionIdentity = $secondPosition->metadata['liquidation_position_identity'] ?? null;

            $collision = null;
            try {
                $restoredScenario->movePrice('BTCUSDT', 22000.0);
            } catch (\LogicException $exception) {
                $collision = $exception->getMessage();
            }
            self::assertNull($collision, 'A distinct reopened position must not reuse a liquidation identity.');

            self::assertIsString($firstPositionIdentity);
            self::assertIsString($secondPositionIdentity);
            self::assertNotSame($firstPositionIdentity, $secondPositionIdentity);
            self::assertEquals($firstOpenedAt, $secondPosition->openedAt);
            self::assertSame(
                $firstPosition->metadata['liquidation_price_decimal'] ?? null,
                $secondPosition->metadata['liquidation_price_decimal'] ?? null,
            );
            self::assertCount(2, $restored->events('liquidation.filled'));
            $liquidationIdentities = array_map(
                static fn ($event): mixed => $event->payload['liquidation_identity'] ?? null,
                $restored->events('liquidation.filled'),
            );
            self::assertCount(2, array_unique($liquidationIdentities));
            $liquidationClientOrderIds = array_map(
                static fn ($order): string => $order->clientOrderId,
                array_values(array_filter(
                    $restored->getOrders('BTCUSDT'),
                    static fn ($order): bool => str_starts_with($order->clientOrderId, 'fake-liq-'),
                )),
            );
            self::assertCount(2, array_unique($liquidationClientOrderIds));
            self::assertNull($restored->getPosition('BTCUSDT', ExchangePositionSide::LONG));
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

    public function testMissingPersistedPositionIdentityFailsClosedAndRollsBackMarkMove(): void
    {
        [$adapter, $scenario, $state] = $this->runtime();
        $adapter->placeOrder($this->entryRequest(clientOrderId: 'liquidation-missing-position-identity'));
        $position = $state->getPosition('BTCUSDT', ExchangePositionSide::LONG);
        self::assertNotNull($position);
        $metadata = $position->metadata;
        unset($metadata['liquidation_position_identity']);
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
        $ordersBefore = $state->getOrders('BTCUSDT');
        $eventsBefore = $state->events();
        $balanceBefore = $state->totalBalanceUsdt();

        $reason = null;
        try {
            $scenario->movePrice('BTCUSDT', 22000.0);
        } catch (\LogicException $exception) {
            $reason = $exception->getMessage();
        }

        self::assertSame('fake_liquidation_position_identity_unknown', $reason);
        self::assertSame('25000', $state->getMarkPrice('BTCUSDT'));
        self::assertNotNull($state->getPosition('BTCUSDT', ExchangePositionSide::LONG));
        self::assertEquals($ordersBefore, $state->getOrders('BTCUSDT'));
        self::assertEquals($eventsBefore, $state->events());
        self::assertSame($balanceBefore, $state->totalBalanceUsdt());
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

    public function testCrossingLimitPreflightUsesExecutableBookPriceForLongAndShort(): void
    {
        foreach ([
            'long' => [ExchangeOrderSide::BUY, ExchangePositionSide::LONG, 30000.0, 25000.0],
            'short' => [ExchangeOrderSide::SELL, ExchangePositionSide::SHORT, 20000.0, 24999.0],
        ] as $case => [$orderSide, $positionSide, $limitPrice, $executablePrice]) {
            [$adapter, , $state] = $this->runtime();

            $result = $adapter->placeOrder(new PlaceOrderRequest(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                symbol: 'BTCUSDT',
                side: $orderSide,
                positionSide: $positionSide,
                orderType: ExchangeOrderType::LIMIT,
                timeInForce: ExchangeTimeInForce::GTC,
                quantity: 1.0,
                price: $limitPrice,
                stopPrice: null,
                reduceOnly: false,
                postOnly: false,
                leverage: 50,
                marginMode: 'isolated',
                clientOrderId: 'liquidation-crossing-limit-' . $case,
                quantityDecimal: '1',
                priceDecimal: (string) $limitPrice,
            ));

            self::assertTrue($result->accepted, $case);
            self::assertSame(ExchangeOrderStatus::FILLED, $result->status, $case);
            self::assertSame($executablePrice, $result->order?->averagePrice, $case);
            self::assertSame(
                number_format($executablePrice, 12, '.', ''),
                $result->order?->metadata['liquidation_entry_price_decimal'] ?? null,
                $case,
            );
            self::assertSame(
                $executablePrice,
                $state->events('order.filled')[0]->payload['fill_price'] ?? null,
                $case,
            );
        }
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

    public function testLiquidationFeeKeepsScaleTwelvePrecisionInCertifiedNetPnl(): void
    {
        [$adapter, , $state] = $this->runtime();
        $entry = $adapter->placeOrder($this->entryRequest(
            clientOrderId: 'liquidation-decimal-fee',
            side: ExchangePositionSide::SHORT,
            leverage: 50,
            quantity: 80.0,
        ));
        self::assertSame(ExchangeOrderStatus::FILLED, $entry->status);
        $positionBeforeLiquidation = $state->getPosition('BTCUSDT', ExchangePositionSide::SHORT);
        self::assertNotNull($positionBeforeLiquidation);

        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->clock());
        $state->runAtomically(function () use ($engine, $state): void {
            $state->setMarkPrice('BTCUSDT', '25399.000000000002');
            $engine->matchOpenOrders('BTCUSDT');
        });

        $fill = $state->events('liquidation.filled')[0] ?? null;
        $closed = $state->events('position.closed')[0] ?? null;
        self::assertNotNull($fill);
        self::assertNotNull($closed);
        self::assertSame('10159.600000000001', $fill->payload['liquidation_fee_decimal'] ?? null);
        self::assertSame('10159.600000000001', $closed->payload['liquidation_fee_usdt_decimal'] ?? null);

        $canonicalFloat = static function (float $value): string {
            $encoded = json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            self::assertIsString($encoded);

            return $encoded;
        };
        $entryMetadata = $positionBeforeLiquidation->metadata;
        $spreadCost = (float) ($entryMetadata['entry_spread_cost_usdt'] ?? 0.0)
            + (float) ($fill->payload['spread_cost_usdt'] ?? 0.0);
        $slippageCost = (float) ($entryMetadata['entry_slippage_cost_usdt'] ?? 0.0)
            + (float) ($fill->payload['slippage_cost_usdt'] ?? 0.0);

        $expectedNet = BigDecimal::of((string) $closed->payload['gross_realized_pnl_usdt_decimal'])
            ->minus($canonicalFloat((float) ($entryMetadata['entry_fee_usdt'] ?? 0.0)))
            ->minus($canonicalFloat((float) ($fill->payload['fill_fee'] ?? 0.0)))
            ->minus($canonicalFloat($spreadCost))
            ->minus($canonicalFloat($slippageCost))
            ->minus((string) $fill->payload['liquidation_fee_decimal'])
            ->toScale(12, RoundingMode::HALF_EVEN);

        self::assertSame((string) $expectedNet, $closed->payload['recorded_pnl_usdt_decimal'] ?? null);
        self::assertSame((string) $expectedNet, $state->getBalances()[0]->metadata['last_certified_balance_delta_usdt'] ?? null);
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
        int $leverage = 10,
        float $quantity = 1.0,
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
            quantity: $quantity,
            price: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: false,
            leverage: $leverage,
            marginMode: $marginMode,
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachedStopLossPrice,
            attachedTakeProfitPrice: $attachedTakeProfitPrice,
            quantityDecimal: (string) BigDecimal::of($quantity)->stripTrailingZeros(),
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
