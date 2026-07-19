<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\PlaceOrderRequest;
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

    /**
     * @return array{FakeExchangeAdapter,FakeExchangeScenarioService,FakeExchangeStateStore}
     */
    private function runtime(): array
    {
        $state = new FakeExchangeStateStore();
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25000.0);
        $book = new FakeExchangeOrderBook($state);
        $clock = $this->clock();
        $engine = new FakeExchangeMatchingEngine($state, $book, $clock);

        return [
            new FakeExchangeAdapter($state, $book, $engine, $clock),
            new FakeExchangeScenarioService($state, $book, $engine),
            $state,
        ];
    }

    private function entryRequest(string $marginMode = 'isolated'): PlaceOrderRequest
    {
        return new PlaceOrderRequest(
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
            leverage: 10,
            marginMode: $marginMode,
            clientOrderId: 'liquidation-entry-' . $marginMode,
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
