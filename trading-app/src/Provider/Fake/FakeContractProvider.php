<?php

declare(strict_types=1);

namespace App\Provider\Fake;

use App\Contract\Provider\ContractProviderInterface;
use App\Contract\Provider\Dto\ContractDto;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeInstrument;
use App\Exchange\Fake\FakeInstrumentCatalog;
use Brick\Math\BigDecimal;

/**
 * Read-only legacy view over the deterministic Fake instrument catalog.
 */
final readonly class FakeContractProvider implements ContractProviderInterface
{
    public function __construct(
        private FakeInstrumentCatalog $catalog,
        private FakeExchangeStateStore $stateStore,
    ) {
    }

    /** @return list<ContractDto> */
    public function getContracts(): array
    {
        return array_map($this->contract(...), $this->catalog->all());
    }

    public function getContractDetails(string $symbol): ?ContractDto
    {
        $instrument = $this->catalog->find($symbol);

        return $instrument !== null ? $this->contract($instrument) : null;
    }

    public function getLastPrice(string $symbol): ?float
    {
        if ($this->catalog->find($symbol) === null) {
            return null;
        }

        $top = $this->stateStore->getOrderBookTop($symbol);

        return ($top['bid'] + $top['ask']) / 2.0;
    }

    /** @return array{symbol:string,bids:list<array{price:float,quantity:float}>,asks:list<array{price:float,quantity:float}>}|array{} */
    public function getOrderBook(string $symbol, int $limit = 50): array
    {
        if ($limit <= 0 || $this->catalog->find($symbol) === null) {
            return [];
        }

        $top = $this->stateStore->getOrderBookTop($symbol);

        return [
            'symbol' => $symbol,
            'bids' => [['price' => $top['bid'], 'quantity' => 0.0]],
            'asks' => [['price' => $top['ask'], 'quantity' => 0.0]],
        ];
    }

    /**
     * Trade history is outside the read-only Task4 projection.
     *
     * @return array<int, mixed>
     */
    public function getRecentTrades(string $symbol, int $limit = 100): array
    {
        return [];
    }

    /**
     * Mark-price klines are outside the read-only Task4 projection.
     *
     * @return array<int, mixed>
     */
    public function getMarkPriceKline(string $symbol, int $step = 1, int $limit = 1, ?int $startTime = null, ?int $endTime = null): array
    {
        return [];
    }

    /** @return list<array{symbol:string,bracket:int,min_leverage:int,max_leverage:int,maintenance_margin_rate:float}> */
    public function getLeverageBrackets(string $symbol): array
    {
        $instrument = $this->catalog->find($symbol);
        if ($instrument === null) {
            return [];
        }

        return [[
            'symbol' => $instrument->symbol,
            'bracket' => 1,
            'min_leverage' => 1,
            'max_leverage' => $instrument->maxLeverage,
            'maintenance_margin_rate' => (float) $instrument->maintenanceMarginRate,
        ]];
    }

    /**
     * Validates the requested local catalog filters. No network or persistence write occurs.
     *
     * @param array<string>|null $symbols
     * @return array{upserted: int, total_fetched: int, errors: array<string>}
     */
    public function syncContracts(?array $symbols = null): array
    {
        $requested = $symbols ?? array_map(
            static fn (FakeInstrument $instrument): string => $instrument->symbol,
            $this->catalog->all(),
        );
        $known = 0;
        $errors = [];

        foreach ($requested as $symbol) {
            if ($this->catalog->find($symbol) !== null) {
                ++$known;
                continue;
            }

            $errors[] = sprintf('Unknown fake instrument: %s', $symbol);
        }

        return ['upserted' => $known, 'total_fetched' => $known, 'errors' => $errors];
    }

    public function healthCheck(): bool
    {
        return $this->catalog->all() !== [];
    }

    public function getProviderName(): string
    {
        return 'Fake';
    }

    private function contract(FakeInstrument $instrument): ContractDto
    {
        $lastPrice = (string) ($this->getLastPrice($instrument->symbol) ?? 0.0);
        $epoch = new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));

        return new ContractDto(
            symbol: $instrument->symbol,
            productType: 1,
            openTimestamp: $epoch,
            expireTimestamp: $epoch,
            settleTimestamp: $epoch,
            baseCurrency: $instrument->baseAsset,
            quoteCurrency: $instrument->quoteAsset,
            lastPrice: BigDecimal::of($lastPrice),
            volume24h: BigDecimal::zero(),
            turnover24h: BigDecimal::zero(),
            indexPrice: BigDecimal::of($lastPrice),
            indexName: $instrument->symbol,
            contractSize: BigDecimal::of($instrument->contractSize),
            minLeverage: BigDecimal::one(),
            maxLeverage: BigDecimal::of($instrument->maxLeverage),
            pricePrecision: BigDecimal::of($instrument->priceTick),
            volPrecision: BigDecimal::of($instrument->quantityStep),
            maxVolume: BigDecimal::zero(),
            minVolume: BigDecimal::of($instrument->minQuantity),
            fundingRate: BigDecimal::zero(),
            expectedFundingRate: BigDecimal::zero(),
            openInterest: BigDecimal::zero(),
            openInterestValue: BigDecimal::zero(),
            high24h: BigDecimal::zero(),
            low24h: BigDecimal::zero(),
            change24h: BigDecimal::zero(),
            fundingTime: $epoch,
            marketMaxVolume: BigDecimal::zero(),
            fundingIntervalHours: 0,
            status: 'active',
            delistTime: $epoch,
        );
    }
}
