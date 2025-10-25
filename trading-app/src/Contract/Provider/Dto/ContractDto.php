<?php

declare(strict_types=1);

namespace App\Contract\Provider\Dto;

use Brick\Math\BigDecimal;

/**
 * DTO pour les contrats
 */
final class ContractDto extends BaseDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly int $productType,
        public readonly \DateTimeImmutable $openTimestamp,
        public readonly \DateTimeImmutable $expireTimestamp,
        public readonly \DateTimeImmutable $settleTimestamp,
        public readonly string $baseCurrency,
        public readonly string $quoteCurrency,
        public readonly BigDecimal $lastPrice,
        public readonly BigDecimal $volume24h,
        public readonly BigDecimal $turnover24h,
        public readonly BigDecimal $indexPrice,
        public readonly string $indexName,
        public readonly BigDecimal $contractSize,
        public readonly BigDecimal $minLeverage,
        public readonly BigDecimal $maxLeverage,
        public readonly BigDecimal $pricePrecision,
        public readonly BigDecimal $volPrecision,
        public readonly BigDecimal $maxVolume,
        public readonly BigDecimal $minVolume,
        public readonly BigDecimal $fundingRate,
        public readonly BigDecimal $expectedFundingRate,
        public readonly BigDecimal $openInterest,
        public readonly BigDecimal $openInterestValue,
        public readonly BigDecimal $high24h,
        public readonly BigDecimal $low24h,
        public readonly BigDecimal $change24h,
        public readonly \DateTimeImmutable $fundingTime,
        public readonly BigDecimal $marketMaxVolume,
        public readonly int $fundingIntervalHours,
        public readonly string $status,
        public readonly \DateTimeImmutable $delistTime
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            productType: (int) $data['product_type'],
            openTimestamp: self::toUtcDateTime($data['open_timestamp']),
            expireTimestamp: self::toUtcDateTime($data['expire_timestamp']),
            settleTimestamp: self::toUtcDateTime($data['settle_timestamp']),
            baseCurrency: $data['base_currency'],
            quoteCurrency: $data['quote_currency'],
            lastPrice: BigDecimal::of($data['last_price']),
            volume24h: BigDecimal::of($data['volume_24h']),
            turnover24h: BigDecimal::of($data['turnover_24h']),
            indexPrice: BigDecimal::of($data['index_price']),
            indexName: $data['index_name'],
            contractSize: BigDecimal::of($data['contract_size']),
            minLeverage: BigDecimal::of($data['min_leverage']),
            maxLeverage: BigDecimal::of($data['max_leverage']),
            pricePrecision: BigDecimal::of($data['price_precision']),
            volPrecision: BigDecimal::of($data['vol_precision']),
            maxVolume: BigDecimal::of($data['max_volume']),
            minVolume: BigDecimal::of($data['min_volume']),
            fundingRate: BigDecimal::of($data['funding_rate']),
            expectedFundingRate: BigDecimal::of($data['expected_funding_rate']),
            openInterest: BigDecimal::of($data['open_interest']),
            openInterestValue: BigDecimal::of($data['open_interest_value']),
            high24h: BigDecimal::of($data['high_24h']),
            low24h: BigDecimal::of($data['low_24h']),
            change24h: BigDecimal::of($data['change_24h']),
            fundingTime: self::toUtcDateTime($data['funding_time']),
            marketMaxVolume: BigDecimal::of($data['market_max_volume']),
            fundingIntervalHours: (int) $data['funding_interval_hours'],
            status: $data['status'],
            delistTime: self::toUtcDateTime($data['delist_time'])
        );
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


