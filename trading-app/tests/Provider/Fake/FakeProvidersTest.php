<?php

declare(strict_types=1);

namespace App\Tests\Provider\Fake;

use App\Common\Enum\OrderSide;
use App\Common\Enum\OrderStatus;
use App\Common\Enum\OrderType;
use App\Common\Enum\PositionSide;
use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\ContractDto;
use App\Contract\Provider\Dto\KlineDto;
use App\Exchange\Adapter\BitmartLegacyOrderMapper;
use App\Exchange\Enum\ExchangeOrderType;
use App\Provider\Fake\FakeAccountProvider;
use App\Provider\Fake\FakeContractProvider;
use App\Provider\Fake\FakeKlineProvider;
use App\Provider\Fake\FakeOrderProvider;
use App\Provider\Fake\FakeSystemProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeAccountProvider::class)]
#[CoversClass(FakeOrderProvider::class)]
#[CoversClass(FakeContractProvider::class)]
#[CoversClass(FakeKlineProvider::class)]
#[CoversClass(FakeSystemProvider::class)]
final class FakeProvidersTest extends TestCase
{
    private FakeProviderFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = FakeProviderFixture::create();
    }

    public function testContractProviderMapsCanonicalCatalogAndTopOfBook(): void
    {
        $contracts = $this->fixture->contract->getContracts();

        self::assertCount(2, $contracts);
        self::assertContainsOnlyInstancesOf(ContractDto::class, $contracts);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], array_map(
            static fn (ContractDto $contract): string => $contract->symbol,
            $contracts,
        ));

        $btc = $this->fixture->contract->getContractDetails('BTCUSDT');
        self::assertNotNull($btc);
        self::assertSame('BTC', $btc->baseCurrency);
        self::assertSame('USDT', $btc->quoteCurrency);
        self::assertSame('25000', (string) $btc->lastPrice);
        self::assertSame('1', (string) $btc->contractSize);
        self::assertSame('1', (string) $btc->minLeverage);
        self::assertSame('100', (string) $btc->maxLeverage);
        self::assertSame('0.10', (string) $btc->pricePrecision);
        self::assertSame('0.001', (string) $btc->volPrecision);
        self::assertSame('0.001', (string) $btc->minVolume);
        self::assertSame('active', $btc->status);
        self::assertSame(25000.0, $this->fixture->contract->getLastPrice('BTCUSDT'));
        self::assertSame([
            'symbol' => 'BTCUSDT',
            'bids' => [['price' => 24999.0, 'quantity' => 0.0]],
            'asks' => [['price' => 25001.0, 'quantity' => 0.0]],
        ], $this->fixture->contract->getOrderBook('BTCUSDT'));
        self::assertSame([[
            'symbol' => 'BTCUSDT',
            'bracket' => 1,
            'min_leverage' => 1,
            'max_leverage' => 100,
            'maintenance_margin_rate' => 0.005,
        ]], $this->fixture->contract->getLeverageBrackets('BTCUSDT'));
        self::assertNull($this->fixture->contract->getContractDetails('SOLUSDT'));
        self::assertNull($this->fixture->contract->getLastPrice('SOLUSDT'));
        self::assertSame([], $this->fixture->contract->getOrderBook('SOLUSDT'));
    }

    public function testContractSyncCountsKnownFiltersAndReportsUnknownSymbolsWithoutNetwork(): void
    {
        self::assertSame(
            ['upserted' => 2, 'total_fetched' => 2, 'errors' => []],
            $this->fixture->contract->syncContracts(),
        );
        self::assertSame(
            [
                'upserted' => 1,
                'total_fetched' => 1,
                'errors' => ['Unknown fake instrument: SOLUSDT'],
            ],
            $this->fixture->contract->syncContracts(['ETHUSDT', 'SOLUSDT']),
        );
    }

    public function testAccountProviderMapsDerivedUsdtBalanceAndFixedFees(): void
    {
        $account = $this->fixture->account->getAccountInfo();

        self::assertNotNull($account);
        self::assertSame('USDT', $account->currency);
        self::assertSame('100000', (string) $account->availableBalance);
        self::assertSame('0', (string) $account->frozenBalance);
        self::assertSame('100000', (string) $account->equity);
        self::assertSame('0', (string) $account->positionDeposit);
        self::assertSame(100000.0, $this->fixture->account->getAccountBalance());
        self::assertSame(0.0, $this->fixture->account->getAccountBalance('BTC'));
        self::assertSame([
            'exchange' => 'fake',
            'symbol' => 'BTCUSDT',
            'fee_currency' => 'USDT',
            'fee_model' => 'fixed_notional_fee_v1',
            'maker' => 0.0005,
            'taker' => 0.0005,
        ], $this->fixture->account->getTradingFees('BTCUSDT'));
    }

    public function testAccountProviderMapsPositionsAndUsedMarginStrictly(): void
    {
        $order = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::MARKET,
            1.0,
            options: ['client_order_id' => 'position-1', 'leverage' => 10],
        );

        self::assertNotNull($order);
        self::assertSame(OrderStatus::FILLED, $order->status);
        $positions = $this->fixture->account->getOpenPositions('BTCUSDT');
        self::assertCount(1, $positions);
        self::assertSame(PositionSide::LONG, $positions[0]->side);
        self::assertSame('1', (string) $positions[0]->size);
        self::assertSame('25001', (string) $positions[0]->entryPrice);
        self::assertSame('2500.1', (string) $positions[0]->margin);
        self::assertEquals($positions[0], $this->fixture->account->getPosition('BTCUSDT'));
        self::assertNull($this->fixture->account->getPosition('ETHUSDT'));

        $account = $this->fixture->account->getAccountInfo();
        self::assertNotNull($account);
        self::assertSame('97499.9', (string) $account->availableBalance);
        self::assertSame('2500.1', (string) $account->frozenBalance);
        self::assertSame('2500.1', (string) $account->positionDeposit);
    }

    public function testOrderProviderIgnoresLegacyPriceForMarketExecution(): void
    {
        $order = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::MARKET,
            1.0,
            20000.0,
            options: ['client_order_id' => 'market-price-ignored-1'],
        );

        self::assertNotNull($order);
        self::assertSame(OrderStatus::FILLED, $order->status);
        self::assertNull($order->price);
        self::assertSame('25001', (string) $order->averagePrice);

        $position = $this->fixture->account->getPosition('BTCUSDT');
        self::assertNotNull($position);
        self::assertSame('25001', (string) $position->entryPrice);
    }

    public function testOrderProviderRejectsNonFiniteLegacyNumbersBeforePersistence(): void
    {
        $cases = [
            ['quantity', OrderType::MARKET, NAN, null, null],
            ['price', OrderType::LIMIT, 1.0, INF, null],
            ['stopPrice', OrderType::STOP, 1.0, null, INF],
        ];

        foreach ($cases as [$field, $type, $quantity, $price, $stopPrice]) {
            try {
                $this->fixture->order->placeOrder(
                    'BTCUSDT',
                    OrderSide::BUY,
                    $type,
                    $quantity,
                    $price,
                    $stopPrice,
                    ['client_order_id' => 'non-finite-' . $field],
                );
                self::fail(sprintf('Expected non-finite %s to throw.', $field));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame(sprintf('%s must be finite.', $field), $exception->getMessage());
            }
        }

        self::assertSame([], $this->fixture->adapter->getOrdersSnapshot());
    }

    public function testOrderProviderAppliesPersistedSymbolLeverageWhenOrderOmitsIt(): void
    {
        self::assertTrue($this->fixture->order->submitLeverage('BTCUSDT', 10, 'isolated'));

        $order = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::MARKET,
            20.0,
            options: ['client_order_id' => 'persisted-leverage-entry-1'],
        );

        self::assertNotNull($order);
        self::assertSame(OrderStatus::FILLED, $order->status);
        self::assertSame(10, $order->metadata['leverage'] ?? null);
        self::assertSame('isolated', $order->metadata['margin_mode'] ?? null);

        $position = $this->fixture->account->getPosition('BTCUSDT');
        self::assertNotNull($position);
        self::assertSame('50002', (string) $position->margin);
        self::assertSame('10', (string) $position->leverage);

        $replay = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::MARKET,
            20.0,
            options: ['client_order_id' => 'persisted-leverage-entry-1'],
        );

        self::assertNotNull($replay);
        self::assertSame($order->orderId, $replay->orderId);
        self::assertTrue($replay->metadata['idempotent_replay'] ?? false);
    }

    public function testOrderProviderExplicitLeverageOverridesPersistedSymbolLeverage(): void
    {
        self::assertTrue($this->fixture->order->submitLeverage('BTCUSDT', 10, 'isolated'));

        $order = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::MARKET,
            1.0,
            options: [
                'client_order_id' => 'explicit-leverage-entry-1',
                'leverage' => 20,
                'margin_mode' => 'isolated',
            ],
        );

        self::assertNotNull($order);
        self::assertSame(OrderStatus::FILLED, $order->status);
        self::assertSame(20, $order->metadata['leverage'] ?? null);
        self::assertSame('isolated', $order->metadata['margin_mode'] ?? null);
    }

    public function testReadOnlyTradeAndTransactionHistoriesRemainExplicitlyEmpty(): void
    {
        self::assertSame([], $this->fixture->account->getTradeHistory('BTCUSDT'));
        self::assertSame([], $this->fixture->account->getTrades());
        self::assertSame([], $this->fixture->account->getTransactionHistory());
    }

    public function testOrderProviderDelegatesPlacementReadsLeverageAndCancellation(): void
    {
        $placed = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            1.0,
            24950.0,
            options: [
                'client_order_id' => 'legacy-1',
                'position_side' => 'long',
                'margin_mode' => 'isolated',
                'leverage' => 5,
                'post_only' => true,
                'time_in_force' => 'gtc',
                'attached_stop_loss_price' => 24500.0,
                'attached_take_profit_price' => 26000.0,
                'decision_key' => 'decision-safe-1',
            ],
        );

        self::assertNotNull($placed);
        self::assertSame(OrderStatus::PENDING, $placed->status);
        self::assertSame('legacy-1', $placed->metadata['client_order_id']);
        self::assertSame('long', $placed->metadata['position_side']);
        self::assertTrue($placed->metadata['post_only']);
        self::assertSame('decision-safe-1', $placed->metadata['decision_key'] ?? null);
        self::assertEquals($placed, $this->fixture->order->getOrder('BTCUSDT', $placed->orderId));
        self::assertEquals([$placed], $this->fixture->order->getOpenOrders('BTCUSDT'));
        self::assertEquals([$placed], $this->fixture->order->getOpenOrdersOrFail('BTCUSDT'));
        self::assertEquals([$placed], $this->fixture->order->getOrderHistory('BTCUSDT'));

        $top = $this->fixture->order->getOrderBookTop('BTCUSDT');
        self::assertSame('BTCUSDT', $top->symbol);
        self::assertSame(24999.0, $top->bid);
        self::assertSame(25001.0, $top->ask);

        self::assertTrue($this->fixture->order->submitLeverage('ETHUSDT', 25, 'isolated'));
        self::assertSame(
            ['leverage' => 25, 'margin_mode' => 'isolated'],
            $this->fixture->state->getLeverageSetting('ETHUSDT'),
        );
        self::assertTrue($this->fixture->order->cancelOrder('BTCUSDT', $placed->orderId));
        self::assertSame([], $this->fixture->order->getOpenOrders('BTCUSDT'));
        self::assertSame(OrderStatus::CANCELLED, $this->fixture->order->getOrder('BTCUSDT', $placed->orderId)?->status);
    }

    public function testOrderProviderRejectsUnsupportedCrossLeverageSetting(): void
    {
        self::assertFalse($this->fixture->order->submitLeverage('BTCUSDT', 10, 'cross'));
        self::assertNull($this->fixture->state->getLeverageSetting('BTCUSDT'));
    }

    public function testOrderProviderReturnsRejectedResultForConflictingClientOrderIdReplay(): void
    {
        $original = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            1.0,
            24950.0,
            options: ['client_order_id' => 'conflicting-replay-1'],
        );
        $rejected = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            2.0,
            24950.0,
            options: ['client_order_id' => 'conflicting-replay-1'],
        );

        self::assertNotNull($original);
        self::assertNotNull($rejected);
        self::assertSame(OrderStatus::REJECTED, $rejected->status);
        self::assertSame($original->orderId, $rejected->orderId);
        self::assertSame('duplicate_client_order_id_intent_mismatch', $rejected->metadata['reason'] ?? null);
        self::assertArrayNotHasKey('margin_reference_price', $rejected->metadata);

        $openOrders = $this->fixture->order->getOpenOrders('BTCUSDT');
        self::assertCount(1, $openOrders);
        self::assertSame($original->orderId, $openOrders[0]->orderId);
        self::assertSame(OrderStatus::PENDING, $openOrders[0]->status);
        self::assertSame('1', (string) $openOrders[0]->quantity);
    }

    public function testOrderProviderAcceptsRealBitmartLegacyMapperOptionsWithIocAndAttachedProtection(): void
    {
        $mapper = new BitmartLegacyOrderMapper();
        $options = $mapper->orderOptions([
            'side' => 1,
            'mode' => 3,
            'open_type' => 'isolated',
            'client_order_id' => 'mapped-entry-1',
            'leverage' => 5,
            'preset_stop_loss_price' => '24500.0',
            'preset_stop_loss_price_type' => 1,
            'preset_take_profit_price' => '26000.0',
            'preset_take_profit_price_type' => 1,
        ]);

        $placed = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            1.0,
            25001.0,
            options: $options,
        );

        self::assertNotNull($placed);
        self::assertSame(OrderStatus::FILLED, $placed->status);
        self::assertSame('long', $placed->metadata['position_side']);
        self::assertFalse($placed->metadata['reduce_only']);
        self::assertSame('ioc', $placed->metadata['time_in_force']);
        self::assertSame('isolated', $placed->metadata['margin_mode']);
        self::assertSame(24500.0, $placed->metadata['attached_stop_loss_price']);
        self::assertSame(26000.0, $placed->metadata['attached_take_profit_price']);
        self::assertSame('accepted', $placed->metadata['protection_status'] ?? null);
        self::assertCount(2, $placed->metadata['protection_order_ids'] ?? []);

        $protectionOrders = $this->fixture->adapter->getOpenOrders('BTCUSDT');
        self::assertCount(2, $protectionOrders);
        self::assertSame(
            array_map(static fn ($order): string => $order->exchangeOrderId, $protectionOrders),
            $placed->metadata['protection_order_ids'],
        );
        self::assertTrue((bool) array_filter(
            $protectionOrders,
            static fn ($order): bool => $order->orderType === ExchangeOrderType::STOP_LOSS
                && $order->stopPrice === 24500.0,
        ));
    }

    public function testOrderProviderExposesRejectedAttachedProtectionOutcome(): void
    {
        $this->fixture->state->rejectNextProtectionOrder();

        $placed = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::MARKET,
            1.0,
            options: [
                'client_order_id' => 'rejected-protection-entry-1',
                'attached_stop_loss_price' => 24500.0,
            ],
        );

        self::assertNotNull($placed);
        self::assertSame(OrderStatus::FILLED, $placed->status);
        self::assertSame('rejected', $placed->metadata['protection_status'] ?? null);
        self::assertArrayNotHasKey('protection_order_ids', $placed->metadata);
        self::assertSame('reduce_only_market_close', $placed->metadata['fail_safe_action'] ?? null);
        self::assertSame('completed', $placed->metadata['compensation_status'] ?? null);
        self::assertSame('position_closed', $placed->metadata['compensation_outcome'] ?? null);
        self::assertIsString($placed->metadata['compensation_order_id'] ?? null);
        self::assertIsString($placed->metadata['compensation_client_order_id'] ?? null);
        self::assertSame(1.0, $placed->metadata['compensation_quantity'] ?? null);
        self::assertSame(1.0, $placed->metadata['position_size_before_compensation'] ?? null);
        self::assertSame(0.0, $placed->metadata['position_size_after_compensation'] ?? null);
        self::assertSame(true, $placed->metadata['failed_entry_exposure_closed'] ?? null);
        self::assertSame(true, $placed->metadata['remaining_position_protected_after_compensation'] ?? null);
        self::assertSame(true, $placed->metadata['position_flat_after_compensation'] ?? null);
        self::assertSame([], $this->fixture->adapter->getOpenOrders('BTCUSDT'));
        self::assertCount(0, $this->fixture->account->getOpenPositions('BTCUSDT'));
    }

    public function testLimitOrderWithStopPriceRemainsTriggeredProtection(): void
    {
        $entry = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::MARKET,
            1.0,
            options: ['client_order_id' => 'triggered-protection-entry'],
        );
        $stop = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::SELL,
            OrderType::LIMIT,
            1.0,
            24500.0,
            24500.0,
            options: [
                'client_order_id' => 'triggered-protection-stop',
                'side' => 2,
                'reduce_only' => true,
                'reduceOnly' => true,
            ],
        );

        self::assertNotNull($entry);
        self::assertSame(OrderStatus::FILLED, $entry->status);
        self::assertNotNull($stop);
        self::assertSame(OrderStatus::PENDING, $stop->status);
        self::assertSame('24500', (string) $stop->stopPrice);
        self::assertCount(1, $this->fixture->account->getOpenPositions('BTCUSDT'));
    }

    public function testLegacyOpenLimitWithStopCompatibilityKeepsEntryOrderType(): void
    {
        $cases = [
            1 => [OrderSide::BUY, 24950.0, 24500.0, 26000.0, 'long'],
            4 => [OrderSide::SELL, 25050.0, 25500.0, 24000.0, 'short'],
        ];

        foreach ($cases as $sideCode => [$side, $price, $stopPrice, $takeProfitPrice, $positionSide]) {
            $fixture = FakeProviderFixture::create();
            $placed = $fixture->order->placeOrder(
                'BTCUSDT',
                $side,
                OrderType::LIMIT,
                1.0,
                $price,
                $stopPrice,
                options: [
                    'client_order_id' => 'legacy-open-with-stop-' . $sideCode,
                    'side' => $sideCode,
                    'open_type' => 'isolated',
                    'preset_stop_loss_price' => (string) $stopPrice,
                    'preset_stop_loss_price_type' => 1,
                    'preset_take_profit_price' => (string) $takeProfitPrice,
                    'preset_take_profit_price_type' => 1,
                ],
            );

            self::assertNotNull($placed);
            self::assertSame(OrderStatus::PENDING, $placed->status);
            self::assertSame($positionSide, $placed->metadata['position_side']);
            self::assertFalse($placed->metadata['reduce_only']);

            $canonical = $fixture->adapter->getOrder('BTCUSDT', $placed->orderId);
            self::assertNotNull($canonical);
            self::assertSame(ExchangeOrderType::LIMIT, $canonical->orderType);
            self::assertNull($canonical->stopPrice);
            self::assertSame($stopPrice, $canonical->metadata['attached_stop_loss_price'] ?? null);
            self::assertSame($takeProfitPrice, $canonical->metadata['attached_take_profit_price'] ?? null);
        }
    }

    public function testOrderProviderRejectsConflictingReduceOnlyAliases(): void
    {
        try {
            $this->fixture->order->placeOrder(
                'BTCUSDT',
                OrderSide::BUY,
                OrderType::LIMIT,
                1.0,
                24950.0,
                options: [
                    'client_order_id' => 'conflicting-reduce-only-aliases',
                    'reduce_only' => true,
                    'reduceOnly' => false,
                ],
            );
            self::fail('Expected conflicting reduce-only aliases to throw.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('reduce_only conflicts with reduceOnly.', $exception->getMessage());
        }
    }

    public function testOrderProviderMapsEveryBitmartLegacySideCode(): void
    {
        $cases = [
            1 => [OrderSide::BUY, 'long', false],
            '2' => [OrderSide::SELL, 'long', true],
            3 => [OrderSide::BUY, 'short', true],
            '4' => [OrderSide::SELL, 'short', false],
        ];

        foreach ($cases as $code => [$side, $positionSide, $reduceOnly]) {
            $price = $side === OrderSide::BUY ? 24950.0 : 25050.0;
            $placed = $this->fixture->order->placeOrder(
                'BTCUSDT',
                $side,
                OrderType::LIMIT,
                1.0,
                $price,
                options: [
                    'client_order_id' => 'legacy-side-' . $code,
                    'side' => $code,
                    'mode' => 1,
                ],
            );

            self::assertNotNull($placed);
            self::assertSame($side, $placed->side);
            self::assertSame($positionSide, $placed->metadata['position_side']);
            self::assertSame($reduceOnly, $placed->metadata['reduce_only']);
            self::assertSame('gtc', $placed->metadata['time_in_force']);
        }
    }

    public function testOrderProviderMapsLegacyFokModeAndExpiresUnfilledOrder(): void
    {
        $placed = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            1.0,
            24950.0,
            options: [
                'client_order_id' => 'legacy-mode-2',
                'side' => 1,
                'mode' => 2,
            ],
        );

        self::assertNotNull($placed);
        self::assertSame(OrderStatus::EXPIRED, $placed->status);
        self::assertSame('fok', $placed->metadata['time_in_force']);
        self::assertFalse($placed->metadata['post_only']);
        self::assertSame(OrderStatus::EXPIRED, $this->fixture->order->getOrder('BTCUSDT', $placed->orderId)?->status);
    }

    public function testOrderProviderMapsLegacyMakerOnlyModeToGtc(): void
    {
        $placed = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            1.0,
            24950.0,
            options: [
                'client_order_id' => 'legacy-mode-4',
                'side' => 1,
                'mode' => 4,
            ],
        );

        self::assertNotNull($placed);
        self::assertSame('gtc', $placed->metadata['time_in_force']);
        self::assertTrue($placed->metadata['post_only']);
    }

    public function testOrderProviderRejectsAmbiguousLegacySideAndModeOptions(): void
    {
        $cases = [
            [OrderSide::SELL, ['side' => 1]],
            [OrderSide::BUY, ['side' => 1, 'position_side' => 'short']],
            [OrderSide::BUY, ['side' => 1, 'reduce_only' => true]],
            [OrderSide::BUY, ['side' => 0]],
            [OrderSide::BUY, ['side' => 'open_long']],
            [OrderSide::BUY, ['side' => 1, 'mode' => 2, 'time_in_force' => 'gtc']],
            [OrderSide::BUY, ['side' => 1, 'mode' => 3, 'time_in_force' => 'gtc']],
            [OrderSide::BUY, ['side' => 1, 'mode' => 4, 'post_only' => false]],
            [OrderSide::BUY, ['side' => 1, 'mode' => 9]],
        ];

        foreach ($cases as $index => [$side, $options]) {
            try {
                $this->fixture->order->placeOrder(
                    'BTCUSDT',
                    $side,
                    OrderType::LIMIT,
                    1.0,
                    24950.0,
                    options: ['client_order_id' => 'ambiguous-' . $index] + $options,
                );
                self::fail('Expected ambiguous legacy side/mode options to throw.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCancelAllOrdersCancelsEveryOpenOrderForSymbolOnly(): void
    {
        foreach ([24950.0, 24900.0] as $index => $price) {
            $this->fixture->order->placeOrder(
                'BTCUSDT',
                OrderSide::BUY,
                OrderType::LIMIT,
                1.0,
                $price,
                options: ['client_order_id' => 'cancel-' . $index, 'post_only' => true],
            );
        }
        $this->fixture->order->placeOrder(
            'ETHUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            1.0,
            1750.0,
            options: ['client_order_id' => 'keep-eth', 'post_only' => true],
        );

        self::assertTrue($this->fixture->order->cancelAllOrders('BTCUSDT'));
        self::assertSame([], $this->fixture->order->getOpenOrders('BTCUSDT'));
        self::assertCount(1, $this->fixture->order->getOpenOrders('ETHUSDT'));
    }

    public function testRejectedOrderIsReturnedAndUnsupportedLegacyShapesThrow(): void
    {
        $rejected = $this->fixture->order->placeOrder(
            'BTCUSDT',
            OrderSide::BUY,
            OrderType::LIMIT,
            1.0,
            24950.01,
            options: ['client_order_id' => 'rejected-1'],
        );

        self::assertNotNull($rejected);
        self::assertSame(OrderStatus::REJECTED, $rejected->status);
        self::assertSame('price_not_quantized', $rejected->metadata['reason']);

        foreach ([
            [OrderSide::UNKNOWN, OrderType::LIMIT, ['client_order_id' => 'bad-side']],
            [OrderSide::BUY, OrderType::STOP_LIMIT, ['client_order_id' => 'bad-type']],
            [OrderSide::BUY, OrderType::LIMIT, []],
            [OrderSide::BUY, OrderType::LIMIT, ['client_order_id' => 'bad-tif', 'time_in_force' => 'day']],
            [OrderSide::BUY, OrderType::LIMIT, [
                'client_order_id' => 'bad-preset-price-type',
                'preset_stop_loss_price' => 24500.0,
                'preset_stop_loss_price_type' => 2,
            ]],
        ] as [$side, $type, $options]) {
            try {
                $this->fixture->order->placeOrder('BTCUSDT', $side, $type, 1.0, 24950.0, options: $options);
                self::fail('Expected unsupported legacy order shape to throw.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testKlineProviderReturnsReadOnlyEmptySets(): void
    {
        $provider = new FakeKlineProvider();

        self::assertSame([], $provider->getKlines('BTCUSDT', Timeframe::TF_1M));
        self::assertSame([], $provider->getKlinesInWindow(
            'BTCUSDT',
            Timeframe::TF_1M,
            new \DateTimeImmutable('-1 hour'),
            new \DateTimeImmutable('now'),
        ));
        self::assertNull($provider->getLastKline('BTCUSDT', Timeframe::TF_1M));
        self::assertFalse($provider->hasGaps('BTCUSDT', Timeframe::TF_1M));
        self::assertSame([], $provider->getGaps('BTCUSDT', Timeframe::TF_1M));

        $kline = new KlineDto(
            'BTCUSDT',
            Timeframe::TF_1M,
            new \DateTimeImmutable('now'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
            \Brick\Math\BigDecimal::of('1'),
        );
        $provider->saveKline($kline);
        $provider->saveKlines([$kline], 'BTCUSDT', Timeframe::TF_1M);
        $this->addToAssertionCount(1);
    }

    public function testSystemProviderReturnsCurrentTime(): void
    {
        $provider = new FakeSystemProvider();

        $before = (int) (microtime(true) * 1000);
        $now = $provider->getSystemTimeMs();
        $after = (int) (microtime(true) * 1000);

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
