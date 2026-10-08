<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Event\ExchangeFillReceived;
use App\Exchange\Fake\FakeExchangeEventNormalizer;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeFillQuantityUnit;
use App\Exchange\Fake\FakeInstrument;
use App\Exchange\Fake\FakeInstrumentProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * #132 p: a Fake fill whose contract size is not 1 declares that its quantity is in contracts of
 * the contract size the engine priced it with (fee, slippage, margin), whether it reaches the
 * ledger through the private stream or through the REST reconciliation, so both copies of a fill
 * stay identical. A contract size of 1 declares nothing (fills recorded as before); an unknown
 * one declares contracts without a value (the ledger flags the row).
 */
#[CoversClass(FakeFillQuantityUnit::class)]
#[CoversClass(FakeExchangeEventNormalizer::class)]
#[CoversClass(FakeExchangeAdapter::class)]
final class FakeFillQuantityUnitTest extends TestCase
{
    public function testTheDeclarationCarriesTheOrderContractSizeOrNothing(): void
    {
        $cases = [
            'okx ctVal' => ['0.01', ['quantity_unit' => 'contracts', 'contract_value' => '0.01']],
            'trailing zeros' => ['0.0100', ['quantity_unit' => 'contracts', 'contract_value' => '0.01']],
            'legacy catalogue and hyperliquid' => ['1', []],
            'one with trailing zeros' => ['1.000', []],
            'integer one' => [1, []],
            'integer' => [10, ['quantity_unit' => 'contracts', 'contract_value' => '10']],
            'float' => [0.01, ['quantity_unit' => 'contracts', 'contract_value' => '0.01']],
            'missing' => [null, ['quantity_unit' => 'contracts']],
            'zero' => ['0', ['quantity_unit' => 'contracts']],
            'negative' => ['-0.01', ['quantity_unit' => 'contracts']],
            'not a number' => ['ctVal', ['quantity_unit' => 'contracts']],
            'not finite' => [INF, ['quantity_unit' => 'contracts']],
            'array' => [['0.01'], ['quantity_unit' => 'contracts']],
        ];
        foreach ($cases as $label => [$contractSize, $expected]) {
            $metadata = $contractSize === null ? [] : ['margin_contract_size' => $contractSize];

            self::assertSame($expected, FakeFillQuantityUnit::metadata($this->order($metadata)), $label);
        }
    }

    public function testBothFakeFillSourcesDeclareTheContractSizeTheFeeWasComputedWith(): void
    {
        $state = new FakeExchangeStateStore();
        $book = new FakeExchangeOrderBook($state);
        $engine = new FakeExchangeMatchingEngine($state, $book, $this->clock(), instruments: new class implements FakeInstrumentProviderInterface {
            public function find(string $symbol): ?FakeInstrument
            {
                return $symbol !== 'BTCUSDT' ? null : new FakeInstrument(
                    symbol: 'BTCUSDT',
                    marketType: MarketType::PERPETUAL,
                    baseAsset: 'BTC',
                    quoteAsset: 'USDT',
                    settleAsset: 'USDT',
                    priceTick: '0.1',
                    quantityStep: '0.01',
                    minQuantity: '0.01',
                    minNotional: '5',
                    contractSize: '0.01',
                    maxLeverage: 100,
                    maintenanceMarginRate: '0.005',
                    allowedOrderTypes: [ExchangeOrderType::LIMIT, ExchangeOrderType::MARKET, ExchangeOrderType::STOP_LOSS, ExchangeOrderType::TAKE_PROFIT],
                );
            }
        });
        $scenario = new FakeExchangeScenarioService($state, $book, $engine);
        $adapter = new FakeExchangeAdapter($state, $book, $engine, $this->clock());

        $placed = $adapter->placeOrder(new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            timeInForce: ExchangeTimeInForce::GTC,
            quantity: 0.8,
            price: 24950.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            leverage: 3,
            marginMode: 'isolated',
            clientOrderId: 'cid-fake-contracts',
        ));
        self::assertTrue($placed->accepted, (string) ($placed->metadata['reason'] ?? ''));
        $scenario->fillOrder((string) $placed->exchangeOrderId, 0.8, 24950.0);

        $streamed = null;
        foreach ($scenario->events('order.filled') as $event) {
            foreach ((new FakeExchangeEventNormalizer())->normalize($event) as $normalized) {
                if ($normalized instanceof ExchangeFillReceived) {
                    $streamed = $normalized->fill();
                }
            }
        }
        self::assertInstanceOf(ExchangeFillDto::class, $streamed);
        $reconciled = $adapter->getFillsSnapshot('BTCUSDT');
        self::assertCount(1, $reconciled);

        foreach (['stream' => $streamed, 'reconciliation' => $reconciled[0]] as $label => $fill) {
            self::assertSame('contracts', $fill->metadata['quantity_unit'] ?? null, $label);
            self::assertSame('0.01', $fill->metadata['contract_value'] ?? null, $label);
            self::assertEqualsWithDelta(0.8, $fill->quantity, 1e-12, $label);
            // 0.8 contracts x 0.01 BTC x 24950 x 5 bps: the venue fee is already on the base notional.
            self::assertEqualsWithDelta(0.0998, $fill->fee, 1e-12, $label);
        }
        self::assertSame($streamed->fillId, $reconciled[0]->fillId);
        self::assertSame(
            array_intersect_key($streamed->metadata, array_flip(['quantity_unit', 'contract_value'])),
            array_intersect_key($reconciled[0]->metadata, array_flip(['quantity_unit', 'contract_value'])),
        );
    }

    /** @param array<string, mixed> $metadata */
    private function order(array $metadata): ExchangeOrderDto
    {
        return new ExchangeOrderDto(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: 'BTCUSDT',
            exchangeOrderId: 'fake-order-unit',
            clientOrderId: 'cid-unit',
            side: ExchangeOrderSide::BUY,
            positionSide: ExchangePositionSide::LONG,
            orderType: ExchangeOrderType::LIMIT,
            status: ExchangeOrderStatus::FILLED,
            quantity: 0.8,
            filledQuantity: 0.8,
            remainingQuantity: 0.0,
            price: 30310.0,
            averagePrice: 30310.0,
            stopPrice: null,
            reduceOnly: false,
            postOnly: true,
            timeInForce: ExchangeTimeInForce::GTC,
            createdAt: new \DateTimeImmutable('2026-01-01 00:00:00 UTC'),
            updatedAt: null,
            metadata: $metadata,
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
