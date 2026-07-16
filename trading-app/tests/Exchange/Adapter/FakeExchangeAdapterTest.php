<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Adapter;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\ExchangeBalanceDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateCorruptedException;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeInstrument;
use App\Exchange\Fake\FakeInstrumentProviderInterface;
use App\Exchange\Fake\FakeOrderValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(FakeExchangeAdapter::class)]
#[CoversClass(FakeExchangeMatchingEngine::class)]
#[CoversClass(FakeExchangeOrderBook::class)]
#[CoversClass(FakeExchangeScenarioService::class)]
#[CoversClass(FakeExchangeStateStore::class)]
final class FakeExchangeAdapterTest extends TestCase
{
    private FakeExchangeAdapter $adapter;
    private FakeExchangeScenarioService $scenario;
    private FakeExchangeStateStore $state;

    protected function setUp(): void
    {
        $this->state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($this->state);
        $engine = new FakeExchangeMatchingEngine($this->state, $book, $this->fixedClock());

        $this->adapter = new FakeExchangeAdapter($this->state, $book, $engine, $this->fixedClock());
        $this->scenario = new FakeExchangeScenarioService($this->state, $book, $engine);
    }

    /**
     * @return iterable<string,array{string,float,float,int,string}>
     */
    public static function validationRejectionCases(): iterable
    {
        yield 'price is not quantized' => ['BTCUSDT', 24950.01, 1.0, 3, 'price_not_quantized'];
        yield 'quantity is not quantized' => ['BTCUSDT', 24950.0, 1.0001, 3, 'quantity_not_quantized'];
        yield 'instrument is unknown' => ['SOLUSDT', 24950.0, 1.0, 3, 'instrument_unknown'];
        yield 'notional is below minimum' => ['BTCUSDT', 4000.0, 0.001, 3, 'notional_below_minimum'];
        yield 'leverage exceeds instrument cap' => ['BTCUSDT', 24950.0, 1.0, 101, 'leverage_above_maximum'];
    }

    #[DataProvider('validationRejectionCases')]
    public function testValidationRejectsAreStructuredPersistedAndNeverRounded(
        string $symbol,
        float $price,
        float $quantity,
        int $leverage,
        string $reason,
    ): void {
        $result = $this->adapter->placeOrder($this->request(
            symbol: $symbol,
            price: $price,
            quantity: $quantity,
            leverage: $leverage,
        ));

        self::assertFalse($result->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $result->status);
        self::assertSame($reason, $result->metadata['reason'] ?? null);
        self::assertSame($reason, $result->order->metadata['reason'] ?? null);
        self::assertSame($price, $result->order->price);
        self::assertSame($quantity, $result->order->quantity);
        self::assertSame($result->exchangeOrderId, $this->adapter->getOrder($symbol, (string) $result->exchangeOrderId)?->exchangeOrderId);

        $events = $this->scenario->events('order.rejected');
        self::assertCount(1, $events);
        self::assertSame($result->exchangeOrderId, $events[0]->payload['order_id'] ?? null);
        self::assertSame($reason, $events[0]->payload['reason'] ?? null);
    }

    public function testRejectedOrderReplayPrecedesValidationAndDoesNotReuseOrderId(): void
    {
        $rejected = $this->adapter->placeOrder($this->request(
            price: 24950.01,
            clientOrderId: 'cid-invalid',
        ));
        $replayed = $this->adapter->placeOrder($this->request(
            price: 24950.01,
            clientOrderId: 'cid-invalid',
        ));
        $accepted = $this->adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'cid-valid',
            postOnly: true,
        ));

        self::assertSame($rejected->exchangeOrderId, $replayed->exchangeOrderId);
        self::assertTrue($replayed->metadata['idempotent_replay'] ?? false);
        self::assertNotSame($rejected->exchangeOrderId, $accepted->exchangeOrderId);
        self::assertCount(2, $this->adapter->getOrdersSnapshot('BTCUSDT'));
        self::assertCount(1, $this->scenario->events('order.rejected'));
    }

    public function testRejectsQuantizedEntryWhenDerivedAvailableBalanceIsInsufficient(): void
    {
        $this->adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'cid-reservation',
            postOnly: true,
            quantity: 12.0,
        ));

        $result = $this->adapter->placeOrder($this->request(
            price: 24940.0,
            clientOrderId: 'cid-insufficient',
            postOnly: true,
            quantity: 0.1,
        ));

        self::assertFalse($result->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $result->status);
        self::assertSame('insufficient_balance', $result->metadata['reason'] ?? null);
        self::assertSame(24940.0, $result->order->price);
        self::assertSame(0.1, $result->order->quantity);
    }

    public function testUnavailablePositionMarginProducesAuditedFailClosedRejection(): void
    {
        $this->state->savePosition(new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangePositionSide::LONG,
            size: 1.0,
            entryPrice: 25000.0,
            markPrice: 25000.0,
            unrealizedPnl: 0.0,
            realizedPnl: 0.0,
            margin: null,
            leverage: 3.0,
        ));

        try {
            $result = $this->adapter->placeOrder($this->request(clientOrderId: 'cid-margin-unavailable'));
        } catch (\LogicException $exception) {
            self::fail(sprintf('Expected a structured rejection, got LogicException: %s', $exception->getMessage()));
        }

        self::assertFalse($result->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $result->status);
        self::assertSame('insufficient_balance', $result->metadata['reason'] ?? null);
        self::assertSame(['margin_state_unavailable'], $result->metadata['quality_flags'] ?? null);
        self::assertArrayNotHasKey('exception', $result->metadata);
        self::assertArrayNotHasKey('exception_message', $result->metadata);
        self::assertSame(
            $result->exchangeOrderId,
            $this->adapter->getOrder('BTCUSDT', (string) $result->exchangeOrderId)?->exchangeOrderId,
        );

        $events = $this->scenario->events('order.rejected');
        self::assertCount(1, $events);
        self::assertSame('insufficient_balance', $events[0]->payload['reason'] ?? null);
        self::assertSame(['margin_state_unavailable'], $events[0]->payload['quality_flags'] ?? null);
        self::assertArrayNotHasKey('exception', $events[0]->payload);
        self::assertArrayNotHasKey('exception_message', $events[0]->payload);
    }

    public function testSpotMarketTypeProducesPersistedAuditedRejectionWithCoherentReplay(): void
    {
        try {
            $rejected = $this->adapter->placeOrder($this->request(
                clientOrderId: 'cid-spot',
                marketType: MarketType::SPOT,
            ));
        } catch (\InvalidArgumentException $exception) {
            self::fail(sprintf('Expected a structured rejection, got InvalidArgumentException: %s', $exception->getMessage()));
        }

        $replayed = $this->adapter->placeOrder($this->request(
            clientOrderId: 'cid-spot',
            marketType: MarketType::SPOT,
        ));
        $changedMarket = $this->adapter->placeOrder($this->request(
            clientOrderId: 'cid-spot',
            marketType: MarketType::PERPETUAL,
        ));

        self::assertFalse($rejected->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $rejected->status);
        self::assertSame(MarketType::SPOT, $rejected->order->marketType);
        self::assertSame('market_type_not_supported', $rejected->metadata['reason'] ?? null);
        self::assertSame($rejected->exchangeOrderId, $replayed->exchangeOrderId);
        self::assertTrue($replayed->metadata['idempotent_replay'] ?? false);
        self::assertSame('duplicate_client_order_id_intent_mismatch', $changedMarket->metadata['reason'] ?? null);
        self::assertSame(
            $rejected->exchangeOrderId,
            $this->adapter->getOrder('BTCUSDT', (string) $rejected->exchangeOrderId)?->exchangeOrderId,
        );

        $events = $this->scenario->events('order.rejected');
        self::assertCount(1, $events);
        self::assertSame('market_type_not_supported', $events[0]->payload['reason'] ?? null);
    }

    public function testBalancesExposeDerivedAvailableAndMarginMetadata(): void
    {
        $balance = $this->adapter->getBalances()[0];

        self::assertSame('USDT', $balance->currency);
        self::assertSame(100000.0, $balance->total);
        self::assertSame(100000.0, $balance->equity);
        self::assertSame(100000.0, $balance->available);
        self::assertSame('fake_exchange', $balance->metadata['source'] ?? null);
        self::assertSame(0.0, $balance->metadata['used_margin_usdt'] ?? null);
        self::assertSame('fake-derived-initial-margin-v1', $balance->metadata['margin_model_version'] ?? null);
    }

    /**
     * @return iterable<string,array{string,int,string}>
     */
    public static function invalidLeverageSettings(): iterable
    {
        yield 'unknown symbol' => ['SOLUSDT', 25, 'isolated'];
        yield 'lowercase symbol' => ['btcusdt', 25, 'isolated'];
        yield 'zero leverage' => ['BTCUSDT', 0, 'isolated'];
        yield 'negative leverage' => ['BTCUSDT', -1, 'isolated'];
        yield 'above instrument maximum' => ['BTCUSDT', 101, 'isolated'];
        yield 'unsupported margin mode' => ['BTCUSDT', 25, 'portfolio'];
    }

    #[DataProvider('invalidLeverageSettings')]
    public function testSetLeverageRejectsInvalidSettingWithoutMutatingState(
        string $symbol,
        int $leverage,
        string $marginMode,
    ): void
    {
        self::assertFalse($this->adapter->setLeverage($symbol, $leverage, $marginMode));
        self::assertSame([], $this->state->leverageSettings());
        self::assertSame([], $this->state->events('leverage.updated'));
    }

    public function testSetLeveragePersistsStableSettingAndRedactedEvent(): void
    {
        self::assertTrue($this->adapter->setLeverage('BTCUSDT', 25, 'isolated'));
        self::assertSame(
            ['leverage' => 25, 'margin_mode' => 'isolated'],
            $this->state->getLeverageSetting('BTCUSDT'),
        );
        self::assertSame([
            'BTCUSDT' => ['leverage' => 25, 'margin_mode' => 'isolated'],
        ], $this->state->leverageSettings());

        $events = $this->state->events('leverage.updated');
        self::assertCount(1, $events);
        self::assertSame('BTCUSDT', $events[0]->symbol);
        self::assertEquals($this->fixedClock()->now(), $events[0]->occurredAt);
        self::assertSame([
            'event_sequence' => 1,
            'leverage' => 25,
            'margin_mode' => 'isolated',
        ], $events[0]->payload);
    }

    public function testSetLeverageReplacesExistingSymbolSettingDeterministically(): void
    {
        self::assertTrue($this->adapter->setLeverage('BTCUSDT', 10, 'cross'));
        self::assertTrue($this->adapter->setLeverage('BTCUSDT', 25, 'isolated'));

        self::assertSame([
            'BTCUSDT' => ['leverage' => 25, 'margin_mode' => 'isolated'],
        ], $this->state->leverageSettings());
        self::assertCount(2, $this->state->events('leverage.updated'));
    }

    public function testStateStoreRejectsInvalidLeverageSettingWithoutMutation(): void
    {
        try {
            $this->state->setLeverageSetting('btcusdt', 25, 'isolated');
            self::fail('The state store must reject a non-canonical symbol.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('fake_leverage_setting_invalid', $exception->getMessage());
        }

        self::assertSame([], $this->state->leverageSettings());
    }

    public function testLeverageSettingSurvivesRestart(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_leverage_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            self::assertTrue($this->adapterForState($state)->setLeverage('BTCUSDT', 25, 'isolated'));

            $restored = new FakeExchangeStateStore($stateFile);

            self::assertSame(
                ['leverage' => 25, 'margin_mode' => 'isolated'],
                $restored->getLeverageSetting('BTCUSDT'),
            );
            self::assertSame([
                'BTCUSDT' => ['leverage' => 25, 'margin_mode' => 'isolated'],
            ], $restored->leverageSettings());
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testPreviousVersionedPayloadWithoutLeverageSettingsUpgradesOnNextWrite(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_leverage_v1_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            new FakeExchangeStateStore($stateFile);
            $envelope = unserialize((string) file_get_contents($stateFile), ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            self::assertIsArray($envelope['payload'] ?? null);
            unset($envelope['payload']['leverageSettings']);
            $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
            file_put_contents($stateFile, serialize($envelope));

            $restored = new FakeExchangeStateStore($stateFile);
            self::assertSame([], $restored->leverageSettings());

            self::assertTrue($this->adapterForState($restored)->setLeverage('BTCUSDT', 25, 'isolated'));
            $upgraded = unserialize((string) file_get_contents($stateFile), ['allowed_classes' => true]);
            self::assertIsArray($upgraded);
            self::assertIsArray($upgraded['payload'] ?? null);
            self::assertSame([
                'BTCUSDT' => ['leverage' => 25, 'margin_mode' => 'isolated'],
            ], $upgraded['payload']['leverageSettings'] ?? null);
            self::assertSame(
                hash('sha256', serialize($upgraded['payload'])),
                $upgraded['payload_checksum'] ?? null,
            );
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testStateStoreRejectsMalformedPersistedLeverageSettings(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_leverage_shape_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            new FakeExchangeStateStore($stateFile);
            $envelope = unserialize((string) file_get_contents($stateFile), ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            self::assertIsArray($envelope['payload'] ?? null);
            $envelope['payload']['leverageSettings'] = [
                'BTCUSDT' => ['leverage' => '25', 'margin_mode' => 'isolated'],
            ];
            $envelope['payload_checksum'] = hash('sha256', serialize($envelope['payload']));
            file_put_contents($stateFile, serialize($envelope));

            $this->expectException(FakeExchangeStateCorruptedException::class);
            $this->expectExceptionMessage('fake_exchange_state_shape_invalid');
            new FakeExchangeStateStore($stateFile);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testSetLeverageRollsBackSettingWhenEventAppendFails(): void
    {
        $state = new class extends FakeExchangeStateStore {
            public function appendEvent(\App\Exchange\Fake\FakeExchangeEvent $event): void
            {
                if ($event->type === 'leverage.updated') {
                    throw new \RuntimeException('forced_leverage_event_failure');
                }

                parent::appendEvent($event);
            }
        };
        $adapter = $this->adapterForState($state);

        try {
            $adapter->setLeverage('BTCUSDT', 25, 'isolated');
            self::fail('The event append failure must abort the leverage update.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced_leverage_event_failure', $exception->getMessage());
        }

        self::assertSame([], $state->leverageSettings());
        self::assertSame([], $state->events('leverage.updated'));
    }

    public function testAvailableMarginUsesLowerFiniteEquityAsCollateral(): void
    {
        $this->replaceUsdtBalance(total: 100000.0, equity: 75000.0);

        $balance = $this->adapter->getBalances()[0];

        self::assertSame(100000.0, $balance->total);
        self::assertSame(75000.0, $balance->equity);
        self::assertSame(75000.0, $balance->available);
        self::assertSame(0.0, $balance->metadata['used_margin_usdt'] ?? null);
    }

    /**
     * @return iterable<string,array{float,?float}>
     */
    public static function invalidCollateralCases(): iterable
    {
        yield 'negative total' => [-1.0, 100000.0];
        yield 'non-finite total' => [INF, 100000.0];
        yield 'negative equity' => [100000.0, -1.0];
        yield 'non-finite equity' => [100000.0, INF];
    }

    #[DataProvider('invalidCollateralCases')]
    public function testInvalidCollateralValuesFailClosed(float $total, ?float $equity): void
    {
        $this->replaceUsdtBalance($total, $equity);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('fake_usdt_margin_collateral_invalid');

        $this->adapter->getBalances();
    }

    public function testOpenLimitOrderReservesRemainingInitialMarginAndCancelReleasesIt(): void
    {
        $placed = $this->adapter->placeOrder($this->request(
            price: 24950.0,
            postOnly: true,
            leverage: 5,
        ));

        $reserved = $this->adapter->getBalances()[0];
        self::assertEqualsWithDelta(4990.0, $reserved->metadata['used_margin_usdt'] ?? null, 0.000001);
        self::assertEqualsWithDelta(95010.0, $reserved->available, 0.000001);

        $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $placed->exchangeOrderId,
        ));

        $released = $this->adapter->getBalances()[0];
        self::assertSame(0.0, $released->metadata['used_margin_usdt'] ?? null);
        self::assertSame(100000.0, $released->available);
    }

    public function testPartialFillCountsPositionAndRemainderMarginExactlyOnce(): void
    {
        $placed = $this->adapter->placeOrder($this->request(
            price: 24950.0,
            postOnly: true,
            leverage: 5,
        ));
        self::assertNotNull($placed->exchangeOrderId);

        $this->scenario->fillOrder($placed->exchangeOrderId, 0.4, 24950.0);

        $position = $this->adapter->getOpenPositions('BTCUSDT')[0];
        $balance = $this->adapter->getBalances()[0];
        self::assertEqualsWithDelta(1996.0, $position->margin, 0.000001);
        self::assertEqualsWithDelta(4990.0, $balance->metadata['used_margin_usdt'] ?? null, 0.000001);
        self::assertEqualsWithDelta(95010.0, $balance->available, 0.000001);
    }

    public function testContractSizeMetadataDrivesOpenAndPartiallyFilledMargin(): void
    {
        $provider = $this->instrumentProvider(contractSize: '2');
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine(
            $state,
            $book,
            $this->fixedClock(),
            new FakeOrderValidator($provider),
            $provider,
        );
        $adapter = new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
        $scenario = new FakeExchangeScenarioService($state, $book, $engine);

        $placed = $adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'cid-contract-size',
            postOnly: true,
            leverage: 5,
        ));

        self::assertSame('2', $placed->order->metadata['margin_contract_size'] ?? null);
        self::assertEqualsWithDelta(9980.0, $adapter->getBalances()[0]->metadata['used_margin_usdt'] ?? null, 0.000001);

        self::assertNotNull($placed->exchangeOrderId);
        $scenario->fillOrder($placed->exchangeOrderId, 0.4, 24950.0);

        $position = $adapter->getOpenPositions('BTCUSDT')[0];
        self::assertEqualsWithDelta(3992.0, $position->margin, 0.000001);
        self::assertEqualsWithDelta(9980.0, $adapter->getBalances()[0]->metadata['used_margin_usdt'] ?? null, 0.000001);
    }

    public function testLegacyOpenOrderWithoutContractSizeMetadataFallsBackToOne(): void
    {
        $this->state->saveOrder(new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'legacy-order',
            clientOrderId: 'legacy-cid',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            status: ExchangeOrderStatus::OPEN,
            quantity: 1.0,
            filledQuantity: 0.0,
            remainingQuantity: 1.0,
            price: 24950.0,
            averagePrice: null,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            timeInForce: ExchangeTimeInForce::GTC,
            createdAt: $this->fixedClock()->now(),
            metadata: ['leverage' => 5],
        ));

        self::assertEqualsWithDelta(4990.0, $this->state->usedMarginUsdt(), 0.000001);
    }

    public function testMixedLeverageEntriesAccumulateMarginAndReductionKeepsItProportional(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'entry-leverage-5',
            postOnly: false,
            leverage: 5,
        ));
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'entry-leverage-10',
            postOnly: false,
            leverage: 10,
        ));

        $position = $this->adapter->getOpenPositions('BTCUSDT')[0];
        $expectedMargin = (25000.5 / 5.0) + (25000.5 / 10.0);
        $expectedLeverage = (2.0 * 25000.5) / $expectedMargin;
        self::assertEqualsWithDelta(2.0, $position->size, 0.000001);
        self::assertEqualsWithDelta($expectedMargin, $position->margin, 0.000001);
        self::assertEqualsWithDelta($expectedLeverage, $position->leverage, 0.000001);

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'reduce-mixed-leverage',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            quantity: 0.5,
        ));

        $reduced = $this->adapter->getOpenPositions('BTCUSDT')[0];
        self::assertEqualsWithDelta(1.5, $reduced->size, 0.000001);
        self::assertEqualsWithDelta($expectedMargin * 0.75, $reduced->margin, 0.000001);
        self::assertEqualsWithDelta($expectedLeverage, $reduced->leverage, 0.000001);
    }

    public function testDerivedAvailableMarginNeverBecomesNegative(): void
    {
        $this->state->savePosition(new ExchangePositionDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangePositionSide::LONG,
            size: 1.0,
            entryPrice: 25000.0,
            markPrice: 25000.0,
            unrealizedPnl: 0.0,
            realizedPnl: 0.0,
            margin: 120000.0,
            leverage: 1.0,
        ));

        $balance = $this->adapter->getBalances()[0];
        self::assertSame(0.0, $balance->available);
        self::assertSame(120000.0, $balance->metadata['used_margin_usdt'] ?? null);
    }

    public function testReduceOnlyProtectionOrderDoesNotReserveInitialMargin(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            side: ExchangeOrderSide::SELL,
            reduceOnly: false,
            postOnly: false,
            stopPrice: 24800.0,
            leverage: 5,
        ));

        self::assertTrue($result->order->reduceOnly);
        $balance = $this->adapter->getBalances()[0];
        self::assertSame(0.0, $balance->metadata['used_margin_usdt'] ?? null);
        self::assertSame(100000.0, $balance->available);
    }

    public function testPlaceLimitOrderLeavesOpenMakerOrder(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::LIMIT,
            price: 24950.0,
            postOnly: true,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::OPEN, $result->status);
        self::assertSame('cid-1', $result->order->clientOrderId);
        self::assertCount(1, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testCancelOrderClosesOpenOrder(): void
    {
        $placed = $this->adapter->placeOrder($this->request(price: 24950.0, postOnly: true));

        $cancelled = $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $placed->exchangeOrderId,
        ));

        self::assertTrue($cancelled->cancelled);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $cancelled->status);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testMovePriceFillsLimitOrderAndCreatesPosition(): void
    {
        $placed = $this->adapter->placeOrder($this->request(price: 25000.0));

        $result = $this->scenario->movePrice('BTCUSDT', 24990.0, 0.0);
        $filled = $this->adapter->getOrder('BTCUSDT', (string) $placed->exchangeOrderId);
        $positions = $this->adapter->getOpenPositions('BTCUSDT');

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderStatus::FILLED, $filled?->status);
        self::assertCount(1, $positions);
        self::assertSame(ExchangePositionSide::LONG, $positions[0]->side);
        self::assertSame(1.0, $positions[0]->size);
    }

    public function testMarketOrderFillsImmediately(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertSame(1.0, $result->order->filledQuantity);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testFakeFillsExposeDeterministicUsdtFeesForCertificationFixtures(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        $fills = $this->adapter->getFillsSnapshot('BTCUSDT');

        self::assertCount(1, $fills);
        self::assertSame('USDT', $fills[0]->feeCurrency);
        self::assertNotNull($fills[0]->fee);
        self::assertGreaterThan(0.0, $fills[0]->fee);
        self::assertSame('fake_paper_fill_ledger_v1', $fills[0]->metadata['pnl_source'] ?? null);
        self::assertSame('complete', $fills[0]->metadata['cost_completeness'] ?? null);
    }

    public function testNonCrossingIocLimitExpiresWithoutResting(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::LIMIT,
            price: 24950.0,
            postOnly: false,
            timeInForce: ExchangeTimeInForce::IOC,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::EXPIRED, $result->status);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertSame('immediate_execution_not_available', $result->order->metadata['reason'] ?? null);
    }

    public function testCanPartiallyFillThenCompleteOrder(): void
    {
        $placed = $this->adapter->placeOrder($this->request(price: 24950.0, postOnly: true));

        $partial = $this->scenario->fillOrder((string) $placed->exchangeOrderId, 0.4, 24950.0);
        self::assertSame(ExchangeOrderStatus::PARTIALLY_FILLED, $partial->status);
        self::assertEqualsWithDelta(0.4, $partial->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $partial->remainingQuantity, 0.000001);

        $complete = $this->scenario->fillOrder((string) $placed->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $complete->status);
        self::assertEqualsWithDelta(1.0, $complete->filledQuantity, 0.000001);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testAcceptedAttachedProtectionCreatesReduceOnlyStopOrder(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));

        $openOrders = $this->adapter->getOpenOrders('BTCUSDT');

        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertSame('accepted', $result->order->metadata['protection_status'] ?? null);
        self::assertCount(1, $openOrders);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $openOrders[0]->orderType);
        self::assertTrue($openOrders[0]->reduceOnly);
        self::assertCount(1, $this->scenario->events('protection_order.created'));
    }

    public function testRejectedAttachedProtectionKeepsEntryFillAndEmitsEvent(): void
    {
        $this->scenario->rejectNextProtectionOrder();

        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertSame('rejected', $result->order->metadata['protection_status'] ?? null);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $this->scenario->events('protection_order.rejected'));
    }

    public function testMovePriceTriggersAttachedStopLossAndClosesPosition(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
            metadata: [
                'internal_trade_id' => 'itd-fake-sl',
                'position_id' => 'fake-pos-sl',
                'order_intent_id' => '123',
            ],
        ));

        $result = $this->scenario->movePrice('BTCUSDT', 24790.0, 0.0);

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $result['matched_orders'][0]->orderType);
        self::assertSame(ExchangeOrderStatus::FILLED, $result['matched_orders'][0]->status);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
        $closedEvents = $this->scenario->events('position.closed');
        self::assertCount(1, $closedEvents);
        self::assertSame('fake_paper_fill_ledger_v1', $closedEvents[0]->payload['pnl_source'] ?? null);
        self::assertSame('complete', $closedEvents[0]->payload['cost_completeness'] ?? null);
        self::assertSame(true, $closedEvents[0]->payload['position_fully_closed'] ?? null);
        self::assertSame(true, $closedEvents[0]->payload['fills_complete'] ?? null);
        self::assertSame('itd-fake-sl', $closedEvents[0]->payload['internal_trade_id'] ?? null);
        self::assertSame('fake-pos-sl', $closedEvents[0]->payload['position_id'] ?? null);
        self::assertSame('123', $closedEvents[0]->payload['order_intent_id'] ?? null);
        self::assertArrayHasKey('gross_realized_pnl_usdt', $closedEvents[0]->payload);
        self::assertArrayHasKey('entry_fee_usdt', $closedEvents[0]->payload);
        self::assertArrayHasKey('exit_fee_usdt', $closedEvents[0]->payload);
        self::assertEqualsWithDelta(1.0, (float) $closedEvents[0]->payload['entry_qty'], 0.000001);
        self::assertEqualsWithDelta(1.0, (float) $closedEvents[0]->payload['exit_qty'], 0.000001);
        self::assertEqualsWithDelta(0.0, (float) $closedEvents[0]->payload['remaining_qty'], 0.000001);
    }

    public function testMovePriceTriggersAttachedTakeProfitAndClosesPosition(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedTakeProfitPrice: 25200.0,
        ));

        $result = $this->scenario->movePrice('BTCUSDT', 25210.0, 0.0);

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderType::TAKE_PROFIT, $result['matched_orders'][0]->orderType);
        self::assertSame(ExchangeOrderStatus::FILLED, $result['matched_orders'][0]->status);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
    }

    public function testScaledInPositionCloseIsNotCertifiedAsSingleLogicalTrade(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            metadata: ['internal_trade_id' => 'itd-first-entry'],
        ));
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'cid-scale-in',
            postOnly: false,
            metadata: ['internal_trade_id' => 'itd-second-entry'],
        ));

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'reduce-scaled',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            quantity: 2.0,
        ));

        $closedEvents = $this->scenario->events('position.closed');
        self::assertCount(1, $closedEvents);
        self::assertSame(false, $closedEvents[0]->payload['lineage_sufficient'] ?? null);
        self::assertSame('partial', $closedEvents[0]->payload['cost_completeness'] ?? null);
        self::assertEqualsWithDelta(2.0, (float) $closedEvents[0]->payload['entry_qty'], 0.000001);
        self::assertEqualsWithDelta(2.0, (float) $closedEvents[0]->payload['exit_qty'], 0.000001);
    }

    public function testClosePayloadKeepsEntryClientOrderIdWhenEntryMetadataHasOnlyOrderIntent(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'entry-cid-from-execution',
            postOnly: false,
            metadata: ['order_intent_id' => '456'],
        ));

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'reduce-cid-from-execution',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
        ));

        $closedEvents = $this->scenario->events('position.closed');
        self::assertCount(1, $closedEvents);
        self::assertSame('entry-cid-from-execution', $closedEvents[0]->payload['client_order_id'] ?? null);
        self::assertSame('456', $closedEvents[0]->payload['order_intent_id'] ?? null);
        self::assertSame('complete', $closedEvents[0]->payload['cost_completeness'] ?? null);
    }

    public function testClosePayloadKeepsLegacyTradeIdWhenEntryMetadataOnlyHasTradeId(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'entry-cid-legacy-trade',
            postOnly: false,
            metadata: ['trade_id' => 'legacy-trade-123'],
        ));

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'reduce-cid-legacy-trade',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
        ));

        $closedEvents = $this->scenario->events('position.closed');
        self::assertCount(1, $closedEvents);
        self::assertSame('legacy-trade-123', $closedEvents[0]->payload['trade_id'] ?? null);
        self::assertSame('complete', $closedEvents[0]->payload['cost_completeness'] ?? null);
    }

    public function testReduceOnlyProtectionFillIsCappedToRemainingPositionSize(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'manual-reduce',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            quantity: 0.6,
        ));

        $result = $this->scenario->movePrice('BTCUSDT', 24790.0, 0.0);

        self::assertCount(1, $result['matched_orders']);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $result['matched_orders'][0]->status);
        self::assertEqualsWithDelta(0.4, $result['matched_orders'][0]->filledQuantity, 0.000001);
        self::assertEqualsWithDelta(0.6, $result['matched_orders'][0]->remainingQuantity, 0.000001);
        self::assertCount(0, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testTriggeringOneAttachedProtectionCancelsSiblingOrder(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
            attachedTakeProfitPrice: 25200.0,
        ));
        self::assertCount(2, $this->adapter->getOpenOrders('BTCUSDT'));

        $this->scenario->movePrice('BTCUSDT', 24790.0, 0.0);

        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(1, array_filter(
            $this->scenario->events('order.cancelled'),
            static fn ($event): bool => ($event->payload['reason'] ?? null) === 'sibling_protection_filled',
        ));
    }

    public function testPartialProtectionFillCancelsSiblingOrder(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
            attachedTakeProfitPrice: 25200.0,
        ));
        $stopLoss = array_values(array_filter(
            $this->adapter->getOpenOrders('BTCUSDT'),
            static fn ($order): bool => $order->orderType === ExchangeOrderType::STOP_LOSS,
        ))[0];

        $this->scenario->fillOrder($stopLoss->exchangeOrderId, 0.4, 24800.0);
        $openOrders = $this->adapter->getOpenOrders('BTCUSDT');

        self::assertCount(1, $openOrders);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $openOrders[0]->orderType);
        self::assertSame(ExchangeOrderStatus::PARTIALLY_FILLED, $openOrders[0]->status);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertEqualsWithDelta(0.6, $this->adapter->getOpenPositions('BTCUSDT')[0]->size, 0.000001);
    }

    public function testStandaloneProtectionOrderIsReduceOnlyEvenWhenPayloadOmitsFlag(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            side: ExchangeOrderSide::SELL,
            reduceOnly: false,
            postOnly: false,
            stopPrice: 24800.0,
        ));

        self::assertTrue($result->accepted);
        self::assertSame(ExchangeOrderStatus::OPEN, $result->status);
        self::assertTrue($result->order->reduceOnly);
        self::assertSame(ExchangeOrderType::STOP_LOSS, $result->order->orderType);
        self::assertCount(1, $this->scenario->events('protection_order.created'));
    }

    public function testStandaloneProtectionOrderRequiresStopPrice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trigger orders require a stop price');

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::STOP_LOSS,
            price: null,
            side: ExchangeOrderSide::SELL,
            reduceOnly: false,
            postOnly: false,
        ));
    }

    public function testReduceOnlyOrderRejectsAttachedProtection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('attached SL/TP is only supported for entry orders');

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));
    }

    public function testClientOrderIdReplayDoesNotCreateSecondActiveOrder(): void
    {
        $first = $this->adapter->placeOrder($this->request(price: 24950.0, postOnly: true));
        $second = $this->adapter->placeOrder($this->request(price: 24900.0, postOnly: true));

        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertTrue($second->metadata['idempotent_replay'] ?? false);
        self::assertCount(1, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testFilledClientOrderIdReplayDoesNotCreateSecondEntry(): void
    {
        $first = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'cid-filled',
            postOnly: false,
        ));
        $second = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'cid-filled',
            postOnly: false,
        ));

        self::assertTrue($first->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $first->status);

        $entryOrders = array_filter(
            $this->adapter->getOrdersSnapshot('BTCUSDT'),
            static fn (ExchangeOrderDto $order): bool => $order->clientOrderId === 'cid-filled' && !$order->reduceOnly,
        );

        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::FILLED, $second->status);
        self::assertTrue($second->metadata['idempotent_replay'] ?? false);
        self::assertCount(1, $entryOrders);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertEqualsWithDelta(1.0, $this->adapter->getOpenPositions('BTCUSDT')[0]->size, 0.000001);
    }

    public function testRejectedClientOrderIdReplayPreservesTerminalFailure(): void
    {
        $first = $this->adapter->placeOrder($this->request(
            price: 26000.0,
            clientOrderId: 'cid-rejected',
            postOnly: true,
        ));
        $second = $this->adapter->placeOrder($this->request(
            price: 26000.0,
            clientOrderId: 'cid-rejected',
            postOnly: true,
        ));

        self::assertFalse($first->accepted);
        self::assertFalse($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::REJECTED, $second->status);
        self::assertSame('post_only_would_cross', $second->metadata['reason'] ?? null);
        self::assertTrue($second->metadata['idempotent_replay'] ?? false);
        self::assertCount(0, $this->adapter->getOpenOrders('BTCUSDT'));
    }

    public function testFilledClientOrderIdReplayRejectsChangedIntent(): void
    {
        $first = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'cid-filled-changed',
            postOnly: false,
            attachedStopLossPrice: 24800.0,
        ));
        $changed = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'cid-filled-changed',
            postOnly: false,
            attachedStopLossPrice: 24700.0,
        ));

        self::assertTrue($first->accepted);
        self::assertSame(ExchangeOrderStatus::FILLED, $first->status);
        self::assertFalse($changed->accepted);
        self::assertSame(ExchangeOrderStatus::REJECTED, $changed->status);
        self::assertSame('duplicate_client_order_id_intent_mismatch', $changed->metadata['reason'] ?? null);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertCount(1, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertEqualsWithDelta(24800.0, $this->adapter->getOpenOrders('BTCUSDT')[0]->stopPrice, 0.000001);
    }

    public function testExpiredClientOrderIdReplayPreservesAcceptedTerminalStatus(): void
    {
        $first = $this->adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'cid-expired',
            postOnly: false,
            timeInForce: ExchangeTimeInForce::IOC,
        ));
        $second = $this->adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'cid-expired',
            postOnly: false,
            timeInForce: ExchangeTimeInForce::IOC,
        ));

        self::assertTrue($first->accepted);
        self::assertSame(ExchangeOrderStatus::EXPIRED, $first->status);
        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::EXPIRED, $second->status);
        self::assertTrue($second->metadata['idempotent_replay'] ?? false);
    }

    public function testCancelledPartialClientOrderIdReplayPreservesFilledSemantics(): void
    {
        $first = $this->adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'cid-partial-cancelled',
            postOnly: true,
        ));
        self::assertNotNull($first->exchangeOrderId);

        $this->scenario->fillOrder($first->exchangeOrderId, 0.4, 24950.0);
        $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $first->exchangeOrderId,
            clientOrderId: 'cid-partial-cancelled',
        ));

        $second = $this->adapter->placeOrder($this->request(
            price: 24950.0,
            clientOrderId: 'cid-partial-cancelled',
            postOnly: true,
        ));

        self::assertTrue($second->accepted);
        self::assertSame($first->exchangeOrderId, $second->exchangeOrderId);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $second->status);
        self::assertSame(ExchangeOrderStatus::CANCELLED, $second->order->status);
        self::assertEqualsWithDelta(0.4, $second->order->filledQuantity, 0.000001);
        self::assertCount(1, $this->adapter->getOpenPositions('BTCUSDT'));
        self::assertEqualsWithDelta(0.4, $this->adapter->getOpenPositions('BTCUSDT')[0]->size, 0.000001);
    }

    public function testCrossingLimitFillsAtBookPriceInsteadOfLimitPrice(): void
    {
        $result = $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::LIMIT,
            price: 26000.0,
            postOnly: false,
        ));
        $position = $this->adapter->getOpenPositions('BTCUSDT')[0] ?? null;

        self::assertSame(ExchangeOrderStatus::FILLED, $result->status);
        self::assertEqualsWithDelta(25000.5, $result->order->averagePrice, 0.000001);
        self::assertEqualsWithDelta(25000.5, $position?->entryPrice, 0.000001);
    }

    public function testCancelWithWrongSymbolDoesNotCancelExchangeOrderId(): void
    {
        $placed = $this->adapter->placeOrder($this->request(
            symbol: 'ETHUSDT',
            price: 1800.0,
            postOnly: true,
        ));

        $cancelled = $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $placed->exchangeOrderId,
        ));

        self::assertFalse($cancelled->cancelled);
        self::assertSame(ExchangeOrderStatus::UNKNOWN, $cancelled->status);
        self::assertCount(1, $this->adapter->getOpenOrders('ETHUSDT'));
    }

    public function testCancelWithMismatchedExchangeIdDoesNotFallBackToClientId(): void
    {
        $eth = $this->adapter->placeOrder($this->request(
            symbol: 'ETHUSDT',
            price: 1800.0,
            clientOrderId: 'shared-client-id',
            postOnly: true,
        ));
        $this->adapter->placeOrder($this->request(
            symbol: 'BTCUSDT',
            price: 24950.0,
            clientOrderId: 'shared-client-id',
            postOnly: true,
        ));

        $cancelled = $this->adapter->cancelOrder(new CancelOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: $eth->exchangeOrderId,
            clientOrderId: 'shared-client-id',
        ));

        self::assertFalse($cancelled->cancelled);
        self::assertCount(1, $this->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(1, $this->adapter->getOpenOrders('ETHUSDT'));
    }

    public function testPartialReduceRecomputesRemainingPositionMargin(): void
    {
        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            postOnly: false,
        ));

        $this->adapter->placeOrder($this->request(
            orderType: ExchangeOrderType::MARKET,
            price: null,
            clientOrderId: 'reduce-1',
            side: ExchangeOrderSide::SELL,
            reduceOnly: true,
            postOnly: false,
            quantity: 0.4,
        ));
        $position = $this->adapter->getOpenPositions('BTCUSDT')[0] ?? null;

        self::assertNotNull($position);
        self::assertEqualsWithDelta(0.6, $position->size, 0.000001);
        self::assertEqualsWithDelta(($position->entryPrice * 0.6) / 3.0, $position->margin, 0.000001);
    }

    public function testStateStoreCanPersistAcrossServiceInstances(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_state_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $adapter = $this->adapterForState($state);
            $adapter->placeOrder($this->request(price: 24950.0, postOnly: true));

            $restoredAdapter = $this->adapterForState(new FakeExchangeStateStore($stateFile));

            self::assertCount(1, $restoredAdapter->getOpenOrders('BTCUSDT'));
            self::assertSame('cid-1', $restoredAdapter->getOpenOrders('BTCUSDT')[0]->clientOrderId);
        } finally {
            @unlink($stateFile);
        }
    }

    public function testConcurrentStateStoresReloadUnderLockAndPreserveBothOrders(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_concurrent_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $firstAdapter = $this->adapterForState(new FakeExchangeStateStore($stateFile));
            $secondAdapter = $this->adapterForState(new FakeExchangeStateStore($stateFile));

            $first = $firstAdapter->placeOrder($this->request(
                clientOrderId: 'concurrent-first',
                postOnly: true,
            ));
            $second = $secondAdapter->placeOrder($this->request(
                price: 24900.0,
                clientOrderId: 'concurrent-second',
                postOnly: true,
            ));

            self::assertNotSame($first->exchangeOrderId, $second->exchangeOrderId);
            $restoredOrders = $this->adapterForState(new FakeExchangeStateStore($stateFile))->getOpenOrders('BTCUSDT');
            self::assertCount(2, $restoredOrders);
            self::assertSame(
                ['concurrent-first', 'concurrent-second'],
                array_map(static fn (ExchangeOrderDto $order): ?string => $order->clientOrderId, $restoredOrders),
            );
            self::assertFileExists($stateFile . '.lock');
            self::assertStringNotContainsString($stateFile . '.lock', (string) file_get_contents($stateFile));
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testStaleStateStoreReloadsBeforeCancelTransaction(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_cancel_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $firstAdapter = $this->adapterForState(new FakeExchangeStateStore($stateFile));
            $staleAdapter = $this->adapterForState(new FakeExchangeStateStore($stateFile));
            $placed = $firstAdapter->placeOrder($this->request(postOnly: true));

            $cancelled = $staleAdapter->cancelOrder(new CancelOrderRequest(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                symbol: 'BTCUSDT',
                exchangeOrderId: $placed->exchangeOrderId,
            ));

            self::assertTrue($cancelled->cancelled);
            $restored = $this->adapterForState(new FakeExchangeStateStore($stateFile));
            self::assertCount(0, $restored->getOpenOrders('BTCUSDT'));
            self::assertSame(ExchangeOrderStatus::CANCELLED, $restored->getOrdersSnapshot('BTCUSDT')[0]->status);
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testMarketFailureRollsBackOpenOrderBeforeRestart(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_market_rollback_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new class($stateFile) extends FakeExchangeStateStore {
                private bool $failNextMarketOrderRead = false;

                public function saveOrder(ExchangeOrderDto $order): void
                {
                    parent::saveOrder($order);
                    if ($order->orderType === ExchangeOrderType::MARKET && $order->status === ExchangeOrderStatus::OPEN) {
                        $this->failNextMarketOrderRead = true;
                    }
                }

                public function getOrder(string $exchangeOrderId): ?ExchangeOrderDto
                {
                    if ($this->failNextMarketOrderRead) {
                        $this->failNextMarketOrderRead = false;

                        throw new \RuntimeException('forced_market_fill_failure');
                    }

                    return parent::getOrder($exchangeOrderId);
                }
            };
            $adapter = $this->adapterForState($state);

            try {
                $adapter->placeOrder($this->request(
                    orderType: ExchangeOrderType::MARKET,
                    price: null,
                    clientOrderId: 'market-rollback',
                    postOnly: false,
                ));
                self::fail('Expected forced market fill failure.');
            } catch (\RuntimeException $exception) {
                self::assertSame('forced_market_fill_failure', $exception->getMessage());
            }

            $restored = $this->adapterForState(new FakeExchangeStateStore($stateFile));
            self::assertCount(0, $restored->getOpenOrders('BTCUSDT'));
            self::assertCount(0, $restored->getOrdersSnapshot('BTCUSDT'));
        } finally {
            @unlink($stateFile);
            @unlink($stateFile . '.lock');
        }
    }

    public function testStateStoreRestoresProtectedPositionAndContinuesEventSequence(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_state_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $adapter = $this->adapterForState($state);
            $adapter->placeOrder($this->request(
                orderType: ExchangeOrderType::MARKET,
                price: null,
                postOnly: false,
                attachedStopLossPrice: 24800.0,
            ));

            $restoredState = new FakeExchangeStateStore($stateFile);
            $restoredAdapter = $this->adapterForState($restoredState);
            $book = new FakeExchangeOrderBook($restoredState);
            $engine = new FakeExchangeMatchingEngine($restoredState, $book, $this->fixedClock());
            $scenario = new FakeExchangeScenarioService($restoredState, $book, $engine);

            self::assertTrue($restoredState->recoveryMetadata()['restored']);
            self::assertFalse($restoredState->recoveryMetadata()['legacy']);
            self::assertCount(1, $restoredAdapter->getOpenPositions('BTCUSDT'));
            self::assertCount(1, $restoredAdapter->getOpenOrders('BTCUSDT'));

            $replayed = $restoredAdapter->placeOrder($this->request(
                orderType: ExchangeOrderType::MARKET,
                price: null,
                postOnly: false,
                attachedStopLossPrice: 24800.0,
            ));
            self::assertTrue($replayed->metadata['idempotent_replay'] ?? false);
            self::assertCount(1, $restoredAdapter->getOpenPositions('BTCUSDT'));

            $scenario->movePrice('BTCUSDT', 24790.0, 0.0);
            self::assertCount(0, $restoredAdapter->getOpenPositions('BTCUSDT'));

            $sequences = array_map(
                static fn ($event): int => (int) ($event->payload['event_sequence'] ?? 0),
                $scenario->events(),
            );
            self::assertSame($sequences, array_values(array_unique($sequences)));
            $sortedSequences = $sequences;
            sort($sortedSequences);
            self::assertSame($sortedSequences, $sequences);
        } finally {
            @unlink($stateFile);
        }
    }

    public function testStateStoreRejectsChecksumMismatchWithoutSilentReset(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_state_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $this->adapterForState($state)->placeOrder($this->request(price: 24950.0, postOnly: true));

            $raw = file_get_contents($stateFile);
            self::assertIsString($raw);
            $envelope = unserialize($raw, ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            $envelope['payload_checksum'] = str_repeat('0', 64);
            file_put_contents($stateFile, serialize($envelope));

            $this->expectException(FakeExchangeStateCorruptedException::class);
            $this->expectExceptionMessage('fake_exchange_state_checksum_mismatch');
            new FakeExchangeStateStore($stateFile);
        } finally {
            @unlink($stateFile);
        }
    }

    public function testStateStoreWrapsTypedPropertyDeserializationFailure(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_state_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            new FakeExchangeStateStore($stateFile);
            $raw = file_get_contents($stateFile);
            self::assertIsString($raw);
            $corrupted = str_replace('d:100000;', 's:6:"broken";', $raw, $replacements);
            self::assertGreaterThan(0, $replacements);
            file_put_contents($stateFile, $corrupted);

            $this->expectException(FakeExchangeStateCorruptedException::class);
            $this->expectExceptionMessage('fake_exchange_state_deserialization_failed');
            new FakeExchangeStateStore($stateFile);
        } finally {
            @unlink($stateFile);
        }
    }

    public function testStateStoreRestoresLegacyPayloadAndUpgradesOnNextWrite(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'fake_exchange_state_');
        self::assertIsString($stateFile);
        @unlink($stateFile);

        try {
            $state = new FakeExchangeStateStore($stateFile);
            $this->adapterForState($state)->placeOrder($this->request(price: 24950.0, postOnly: true));

            $raw = file_get_contents($stateFile);
            self::assertIsString($raw);
            $envelope = unserialize($raw, ['allowed_classes' => true]);
            self::assertIsArray($envelope);
            self::assertIsArray($envelope['payload'] ?? null);
            file_put_contents($stateFile, serialize($envelope['payload']));

            $legacyState = new FakeExchangeStateStore($stateFile);
            self::assertTrue($legacyState->recoveryMetadata()['restored']);
            self::assertTrue($legacyState->recoveryMetadata()['legacy']);
            self::assertCount(1, $legacyState->getOpenOrders('BTCUSDT'));

            $legacyState->setOrderBookTop('BTCUSDT', 24998.0, 25002.0);
            $upgraded = unserialize((string) file_get_contents($stateFile), ['allowed_classes' => true]);
            self::assertIsArray($upgraded);
            self::assertSame(1, $upgraded['format_version'] ?? null);
            self::assertIsString($upgraded['payload_checksum'] ?? null);
        } finally {
            @unlink($stateFile);
        }
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function request(
        string $symbol = 'BTCUSDT',
        ExchangeOrderType $orderType = ExchangeOrderType::LIMIT,
        ?float $price = 24950.0,
        string $clientOrderId = 'cid-1',
        ExchangePositionSide $positionSide = ExchangePositionSide::LONG,
        ExchangeOrderSide $side = ExchangeOrderSide::BUY,
        bool $reduceOnly = false,
        bool $postOnly = false,
        ExchangeTimeInForce $timeInForce = ExchangeTimeInForce::GTC,
        float $quantity = 1.0,
        ?float $stopPrice = null,
        ?float $attachedStopLossPrice = null,
        ?float $attachedTakeProfitPrice = null,
        array $metadata = [],
        ?int $leverage = 3,
        MarketType $marketType = MarketType::PERPETUAL,
    ): PlaceOrderRequest {
        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: $marketType,
            symbol: $symbol,
            side: $side,
            positionSide: $positionSide,
            orderType: $orderType,
            timeInForce: $timeInForce,
            quantity: $quantity,
            price: $price,
            stopPrice: $stopPrice,
            reduceOnly: $reduceOnly,
            postOnly: $postOnly,
            leverage: $leverage,
            marginMode: 'isolated',
            clientOrderId: $clientOrderId,
            attachedStopLossPrice: $attachedStopLossPrice,
            attachedTakeProfitPrice: $attachedTakeProfitPrice,
            metadata: $metadata,
        );
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
            }
        };
    }

    private function adapterForState(FakeExchangeStateStore $state): FakeExchangeAdapter
    {
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->fixedClock());

        return new FakeExchangeAdapter($state, $book, $engine, $this->fixedClock());
    }

    private function instrumentProvider(string $contractSize): FakeInstrumentProviderInterface
    {
        $instrument = new FakeInstrument(
            symbol: 'BTCUSDT',
            marketType: MarketType::PERPETUAL,
            baseAsset: 'BTC',
            quoteAsset: 'USDT',
            settleAsset: 'USDT',
            priceTick: '0.10',
            quantityStep: '0.001',
            minQuantity: '0.001',
            minNotional: '5',
            contractSize: $contractSize,
            maxLeverage: 100,
            maintenanceMarginRate: '0.005',
            allowedOrderTypes: [
                ExchangeOrderType::LIMIT,
                ExchangeOrderType::MARKET,
                ExchangeOrderType::STOP_LOSS,
                ExchangeOrderType::TAKE_PROFIT,
            ],
        );

        return new class($instrument) implements FakeInstrumentProviderInterface {
            public function __construct(private readonly FakeInstrument $instrument)
            {
            }

            public function find(string $symbol): ?FakeInstrument
            {
                return $symbol === $this->instrument->symbol ? $this->instrument : null;
            }
        };
    }

    private function replaceUsdtBalance(float $total, ?float $equity): void
    {
        $property = new \ReflectionProperty(FakeExchangeStateStore::class, 'balances');
        $property->setValue($this->state, [
            'USDT' => new ExchangeBalanceDto(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                currency: 'USDT',
                available: $total,
                total: $total,
                equity: $equity,
                unrealizedPnl: 0.0,
                metadata: ['source' => 'fake_exchange'],
            ),
        ]);
    }
}
