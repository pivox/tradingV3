<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Fake;

use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Fake\FakeInstrument;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeInstrumentProviderInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FakeInstrument::class)]
#[CoversClass(FakeInstrumentCatalog::class)]
#[CoversClass(FakeInstrumentProviderInterface::class)]
final class FakeInstrumentCatalogTest extends TestCase
{
    public function testExposesVersionedDeterministicPerpetualFixtures(): void
    {
        $catalog = new FakeInstrumentCatalog();

        self::assertSame('fake-instrument-catalog-v1', $catalog->metadataFixtureVersion());
        self::assertSame('brick-math-exact-multiple-v1', $catalog->precisionModelVersion());
        self::assertInstanceOf(FakeInstrumentProviderInterface::class, $catalog);
        self::assertSame(['BTCUSDT', 'ETHUSDT'], array_map(
            static fn (FakeInstrument $instrument): string => $instrument->symbol,
            $catalog->all(),
        ));

        self::assertSame([
            'symbol' => 'BTCUSDT',
            'market_type' => MarketType::PERPETUAL,
            'base_asset' => 'BTC',
            'quote_asset' => 'USDT',
            'settle_asset' => 'USDT',
            'price_tick' => '0.10',
            'quantity_step' => '0.001',
            'min_quantity' => '0.001',
            'min_notional' => '5',
            'contract_size' => '1',
            'max_leverage' => 100,
            'maintenance_margin_rate' => '0.005',
            'allowed_order_types' => [
                ExchangeOrderType::LIMIT,
                ExchangeOrderType::MARKET,
                ExchangeOrderType::STOP_LOSS,
                ExchangeOrderType::TAKE_PROFIT,
            ],
        ], self::instrumentShape($catalog->find('BTCUSDT')));

        self::assertSame([
            'symbol' => 'ETHUSDT',
            'market_type' => MarketType::PERPETUAL,
            'base_asset' => 'ETH',
            'quote_asset' => 'USDT',
            'settle_asset' => 'USDT',
            'price_tick' => '0.01',
            'quantity_step' => '0.01',
            'min_quantity' => '0.01',
            'min_notional' => '5',
            'contract_size' => '1',
            'max_leverage' => 75,
            'maintenance_margin_rate' => '0.005',
            'allowed_order_types' => [
                ExchangeOrderType::LIMIT,
                ExchangeOrderType::MARKET,
                ExchangeOrderType::STOP_LOSS,
                ExchangeOrderType::TAKE_PROFIT,
            ],
        ], self::instrumentShape($catalog->find('ETHUSDT')));
    }

    public function testUnknownOrNonCanonicalSymbolsAreNotInferred(): void
    {
        $catalog = new FakeInstrumentCatalog();

        self::assertNull($catalog->find('SOLUSDT'));
        self::assertNull($catalog->find('btcusdt'));
    }

    public function testInstrumentUsesExactDecimalMultipleChecks(): void
    {
        $instrument = (new FakeInstrumentCatalog())->find('BTCUSDT');
        self::assertInstanceOf(FakeInstrument::class, $instrument);

        self::assertTrue($instrument->isPriceQuantized('25000.10'));
        self::assertFalse($instrument->isPriceQuantized('25000.11'));
        self::assertTrue($instrument->isQuantityQuantized('0.001'));
        self::assertFalse($instrument->isQuantityQuantized('0.0010000000000001'));
        self::assertTrue((new \ReflectionClass($instrument))->isReadOnly());
    }

    public function testCatalogConstructorHasNoFixtureInjectionSurface(): void
    {
        $constructor = (new \ReflectionClass(FakeInstrumentCatalog::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(0, $constructor->getNumberOfParameters());
    }

    /**
     * @return iterable<string,array{\Closure(): FakeInstrument}>
     */
    public static function invalidInstrumentCases(): iterable
    {
        yield 'blank symbol' => [static fn (): FakeInstrument => self::instrument(symbol: '')];
        yield 'lowercase symbol' => [static fn (): FakeInstrument => self::instrument(symbol: 'btcusdt')];
        yield 'non-canonical symbol whitespace' => [static fn (): FakeInstrument => self::instrument(symbol: ' BTCUSDT')];
        yield 'blank base asset' => [static fn (): FakeInstrument => self::instrument(baseAsset: '')];
        yield 'lowercase quote asset' => [static fn (): FakeInstrument => self::instrument(quoteAsset: 'usdt')];
        yield 'non-canonical settle asset whitespace' => [static fn (): FakeInstrument => self::instrument(settleAsset: ' USDT')];
        yield 'unparseable decimal' => [static fn (): FakeInstrument => self::instrument(priceTick: 'tick')];
        yield 'non-positive price tick' => [static fn (): FakeInstrument => self::instrument(priceTick: '0')];
        yield 'non-positive quantity step' => [static fn (): FakeInstrument => self::instrument(quantityStep: '0')];
        yield 'non-positive minimum quantity' => [static fn (): FakeInstrument => self::instrument(minQuantity: '0')];
        yield 'non-positive minimum notional' => [static fn (): FakeInstrument => self::instrument(minNotional: '0')];
        yield 'non-positive contract size' => [static fn (): FakeInstrument => self::instrument(contractSize: '0')];
        yield 'non-positive maintenance margin' => [static fn (): FakeInstrument => self::instrument(maintenanceMarginRate: '0')];
        yield 'non-positive maximum leverage' => [static fn (): FakeInstrument => self::instrument(maxLeverage: 0)];
        yield 'empty allowed order types' => [static fn (): FakeInstrument => self::instrument(allowedOrderTypes: [])];
        yield 'duplicate allowed order types' => [static fn (): FakeInstrument => self::instrument(allowedOrderTypes: [
            ExchangeOrderType::LIMIT,
            ExchangeOrderType::LIMIT,
        ])];
    }

    #[DataProvider('invalidInstrumentCases')]
    public function testRejectsInvalidInstrumentDefinition(\Closure $factory): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $factory();
    }

    /**
     * @return array<string,mixed>
     */
    private static function instrumentShape(?FakeInstrument $instrument): array
    {
        self::assertInstanceOf(FakeInstrument::class, $instrument);

        return [
            'symbol' => $instrument->symbol,
            'market_type' => $instrument->marketType,
            'base_asset' => $instrument->baseAsset,
            'quote_asset' => $instrument->quoteAsset,
            'settle_asset' => $instrument->settleAsset,
            'price_tick' => $instrument->priceTick,
            'quantity_step' => $instrument->quantityStep,
            'min_quantity' => $instrument->minQuantity,
            'min_notional' => $instrument->minNotional,
            'contract_size' => $instrument->contractSize,
            'max_leverage' => $instrument->maxLeverage,
            'maintenance_margin_rate' => $instrument->maintenanceMarginRate,
            'allowed_order_types' => $instrument->allowedOrderTypes,
        ];
    }

    /**
     * @param list<ExchangeOrderType>|null $allowedOrderTypes
     */
    private static function instrument(
        string $symbol = 'BTCUSDT',
        string $baseAsset = 'BTC',
        string $quoteAsset = 'USDT',
        string $settleAsset = 'USDT',
        string $priceTick = '0.1',
        string $quantityStep = '0.001',
        string $minQuantity = '0.001',
        string $minNotional = '5',
        string $contractSize = '1',
        int $maxLeverage = 100,
        string $maintenanceMarginRate = '0.005',
        ?array $allowedOrderTypes = null,
    ): FakeInstrument {
        return new FakeInstrument(
            symbol: $symbol,
            marketType: MarketType::PERPETUAL,
            baseAsset: $baseAsset,
            quoteAsset: $quoteAsset,
            settleAsset: $settleAsset,
            priceTick: $priceTick,
            quantityStep: $quantityStep,
            minQuantity: $minQuantity,
            minNotional: $minNotional,
            contractSize: $contractSize,
            maxLeverage: $maxLeverage,
            maintenanceMarginRate: $maintenanceMarginRate,
            allowedOrderTypes: $allowedOrderTypes ?? [ExchangeOrderType::LIMIT],
        );
    }
}
