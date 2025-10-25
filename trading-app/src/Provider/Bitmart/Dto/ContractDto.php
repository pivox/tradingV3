<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\Dto;

use Brick\Math\BigDecimal;

final readonly class ContractDto
{
    public string $symbol;
    public int $productType;
    public \DateTimeImmutable $openTimestamp;
    public \DateTimeImmutable $expireTimestamp;
    public \DateTimeImmutable $settleTimestamp;
    public string $baseCurrency;
    public string $quoteCurrency;
    public BigDecimal $lastPrice;
    public BigDecimal $volume24h;
    public BigDecimal $turnover24h;
    public BigDecimal $indexPrice;
    public string $indexName;
    public BigDecimal $contractSize;
    public BigDecimal $minLeverage;
    public BigDecimal $maxLeverage;
    public BigDecimal $pricePrecision;
    public BigDecimal $volPrecision;
    public BigDecimal $maxVolume;
    public BigDecimal $minVolume;
    public BigDecimal $fundingRate;
    public BigDecimal $expectedFundingRate;
    public BigDecimal $openInterest;
    public BigDecimal $openInterestValue;
    public BigDecimal $high24h;
    public BigDecimal $low24h;
    public BigDecimal $change24h;
    public \DateTimeImmutable $fundingTime;
    public BigDecimal $marketMaxVolume;
    public int $fundingIntervalHours;
    public string $status;
    public \DateTimeImmutable $delistTime;

    public function __construct(array $items)
    {
        $this->symbol = $items['symbol'];
        $this->productType = (int) $items['product_type'];
        $this->openTimestamp = self::toUtcDateTime($items['open_timestamp']);
        $this->expireTimestamp = self::toUtcDateTime($items['expire_timestamp']);
        $this->settleTimestamp = self::toUtcDateTime($items['settle_timestamp']);
        $this->baseCurrency = $items['base_currency'];
        $this->quoteCurrency = $items['quote_currency'];
        $this->lastPrice = BigDecimal::of($items['last_price']);
        $this->volume24h = BigDecimal::of($items['volume_24h']);
        $this->turnover24h = BigDecimal::of($items['turnover_24h']);
        $this->indexPrice = BigDecimal::of($items['index_price']);
        $this->indexName = $items['index_name'];
        $this->contractSize = BigDecimal::of($items['contract_size']);
        $this->minLeverage = BigDecimal::of($items['min_leverage']);
        $this->maxLeverage = BigDecimal::of($items['max_leverage']);
        $this->pricePrecision = BigDecimal::of($items['price_precision']);
        $this->volPrecision = BigDecimal::of($items['vol_precision']);
        $this->maxVolume = BigDecimal::of($items['max_volume']);
        $this->minVolume = BigDecimal::of($items['min_volume']);
        $this->fundingRate = BigDecimal::of($items['funding_rate']);
        $this->expectedFundingRate = BigDecimal::of($items['expected_funding_rate']);
        $this->openInterest = BigDecimal::of($items['open_interest']);
        $this->openInterestValue = BigDecimal::of($items['open_interest_value']);
        $this->high24h = BigDecimal::of($items['high_24h']);
        $this->low24h = BigDecimal::of($items['low_24h']);
        $this->change24h = BigDecimal::of($items['change_24h']);
        $this->fundingTime = self::toUtcDateTime($items['funding_time']);
        $this->marketMaxVolume = BigDecimal::of($items['market_max_volume']);
        $this->fundingIntervalHours = (int) $items['funding_interval_hours'];
        $this->status = $items['status'];
        $this->delistTime = self::toUtcDateTime($items['delist_time']);
    }

    private static function toUtcDateTime(string|int $tsOrStr): \DateTimeImmutable
    {
        if (is_numeric($tsOrStr) && (int)$tsOrStr > 0) {
            $ts = (int) $tsOrStr;
            if ($ts > 2_000_000_000_000) { // ms
                $ts = intdiv($ts, 1000);
            }
            return (new \DateTimeImmutable("@$ts"))->setTimezone(new \DateTimeZone('UTC'));
        }
        return new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));
    }
}
