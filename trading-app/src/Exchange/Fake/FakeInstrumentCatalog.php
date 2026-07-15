<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangeOrderType;

final class FakeInstrumentCatalog implements FakeInstrumentProviderInterface
{
    private const METADATA_FIXTURE_VERSION = 'fake-instrument-catalog-v1';
    private const PRECISION_MODEL_VERSION = 'brick-math-exact-multiple-v1';

    /** @var array<string,FakeInstrument> */
    private array $instruments;

    public function __construct()
    {
        $allowedOrderTypes = [
            ExchangeOrderType::LIMIT,
            ExchangeOrderType::MARKET,
            ExchangeOrderType::STOP_LOSS,
            ExchangeOrderType::TAKE_PROFIT,
        ];

        $this->instruments = [
            'BTCUSDT' => new FakeInstrument(
                symbol: 'BTCUSDT',
                marketType: MarketType::PERPETUAL,
                baseAsset: 'BTC',
                quoteAsset: 'USDT',
                settleAsset: 'USDT',
                priceTick: '0.10',
                quantityStep: '0.001',
                minQuantity: '0.001',
                minNotional: '5',
                contractSize: '1',
                maxLeverage: 100,
                maintenanceMarginRate: '0.005',
                allowedOrderTypes: $allowedOrderTypes,
            ),
            'ETHUSDT' => new FakeInstrument(
                symbol: 'ETHUSDT',
                marketType: MarketType::PERPETUAL,
                baseAsset: 'ETH',
                quoteAsset: 'USDT',
                settleAsset: 'USDT',
                priceTick: '0.01',
                quantityStep: '0.01',
                minQuantity: '0.01',
                minNotional: '5',
                contractSize: '1',
                maxLeverage: 75,
                maintenanceMarginRate: '0.005',
                allowedOrderTypes: $allowedOrderTypes,
            ),
        ];
    }

    public function metadataFixtureVersion(): string
    {
        return self::METADATA_FIXTURE_VERSION;
    }

    public function precisionModelVersion(): string
    {
        return self::PRECISION_MODEL_VERSION;
    }

    /** @return list<FakeInstrument> */
    public function all(): array
    {
        return array_values($this->instruments);
    }

    public function find(string $symbol): ?FakeInstrument
    {
        return $this->instruments[$symbol] ?? null;
    }
}
