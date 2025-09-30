<?php
declare(strict_types=1);

namespace App\Dto;

/**
 * DTO pour /contract/public/details (Futures V2).
 * Beaucoup de champs numériques reviennent en string côté API.
 */
final class ContractDetailsDto
{
    public function __construct(
    public readonly string $symbol,
    public readonly int $productType,
    public readonly int $openTimestampMs,
    public readonly int $expireTimestampMs,
    public readonly int $settleTimestampMs,
    public readonly string $baseCurrency,
    public readonly string $quoteCurrency,
    public readonly ?string $lastPrice,           // string venant de l'API
    public readonly ?string $volume24h,           // string
    public readonly ?string $turnover24h,         // string
    public readonly ?string $indexPrice,          // string
    public readonly string $indexName,
    public readonly string $contractSize,         // string
    public readonly string $minLeverage,          // string
    public readonly string $maxLeverage,          // string
    public readonly string $pricePrecision,       // string
    public readonly string $volPrecision,         // string
    public readonly string $maxVolume,            // string
    public readonly string $marketMaxVolume,      // string
    public readonly string $minVolume,            // string
    public readonly ?string $fundingRate,         // string
    public readonly ?string $expectedFundingRate, // string
    public readonly ?string $openInterest,        // string
    public readonly ?string $openInterestValue,   // string
    public readonly ?string $high24h,             // string
    public readonly ?string $low24h,              // string
    public readonly ?string $change24h,           // string
    public readonly ?int $fundingIntervalHours,
    public readonly string $status,
    public readonly int $delistTimeSec            // ATTENTION: converti en SECONDES (voir fromApi)
) {}

    public static function fromApi(array $d): self
    {
        $ms = fn($k) => isset($d[$k]) ? (int)$d[$k] : 0;
        return new self(
            symbol: $d['symbol'],
            productType: (int)$d['product_type'],
            openTimestampMs: $ms('open_timestamp'),
            expireTimestampMs: $ms('expire_timestamp'),
            settleTimestampMs: $ms('settle_timestamp'),
            baseCurrency: $d['base_currency'],
            quoteCurrency: $d['quote_currency'],
            lastPrice: $d['last_price'] ?? null,
            volume24h: $d['volume_24h'] ?? null,
            turnover24h: $d['turnover_24h'] ?? null,
            indexPrice: $d['index_price'] ?? null,
            indexName: $d['index_name'],
            contractSize: $d['contract_size'],
            minLeverage: $d['min_leverage'],
            maxLeverage: $d['max_leverage'],
            pricePrecision: $d['price_precision'],
            volPrecision: $d['vol_precision'],
            maxVolume: $d['max_volume'],
            marketMaxVolume: $d['market_max_volume'],
            minVolume: $d['min_volume'],
            fundingRate: $d['funding_rate'] ?? null,
            expectedFundingRate: $d['expected_funding_rate'] ?? null,
            openInterest: $d['open_interest'] ?? null,
            openInterestValue: $d['open_interest_value'] ?? null,
            high24h: $d['high_24h'] ?? null,
            low24h: $d['low_24h'] ?? null,
            change24h: $d['change_24h'] ?? null,
            fundingIntervalHours: isset($d['funding_interval_hours']) ? (int)$d['funding_interval_hours'] : null,
            status: $d['status'],
            delistTimeSec: isset($d['delist_time']) ? (int)$d['delist_time'] : 0 // ici l’API renvoie déjà en secondes pour delist_time
        );
    }
}
