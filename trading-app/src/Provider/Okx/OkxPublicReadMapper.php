<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\ContractDto;
use App\Contract\Provider\Dto\KlineDto;
use App\Exchange\Okx\OkxInstrumentResolver;
use Brick\Math\BigDecimal;

final readonly class OkxPublicReadMapper
{
    public function __construct(
        private OkxInstrumentResolver $instruments = new OkxInstrumentResolver(),
    ) {
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed>|null $ticker
     */
    public function contract(array $row, ?array $ticker = null): ContractDto
    {
        $symbol = $this->instruments->symbol($this->string($row['instId'] ?? ''));
        $last = $this->string($ticker['last'] ?? '0');
        $volume24h = $this->string($ticker['vol24h'] ?? '0');

        return new ContractDto(
            symbol: $symbol,
            productType: 1,
            openTimestamp: $this->time($row['listTime'] ?? null),
            expireTimestamp: $this->time($row['expTime'] ?? null),
            settleTimestamp: $this->time($row['settleTime'] ?? $row['expTime'] ?? null),
            baseCurrency: strtoupper($this->string($row['baseCcy'] ?? $row['ctValCcy'] ?? '')),
            quoteCurrency: strtoupper($this->string($row['quoteCcy'] ?? $row['settleCcy'] ?? '')),
            lastPrice: BigDecimal::of($last === '' ? '0' : $last),
            volume24h: BigDecimal::of($volume24h === '' ? '0' : $volume24h),
            turnover24h: BigDecimal::of($this->turnover24h($ticker)),
            indexPrice: BigDecimal::of($this->string($ticker['idxPx'] ?? '0') ?: '0'),
            indexName: $this->string($row['instFamily'] ?? $row['instId'] ?? ''),
            contractSize: BigDecimal::of($this->string($row['ctVal'] ?? '0') ?: '0'),
            minLeverage: BigDecimal::of('1'),
            maxLeverage: BigDecimal::of($this->string($row['lever'] ?? '1') ?: '1'),
            pricePrecision: BigDecimal::of((string) $this->decimalPlaces($this->string($row['tickSz'] ?? '0'))),
            volPrecision: BigDecimal::of((string) $this->decimalPlaces($this->string($row['lotSz'] ?? $row['minSz'] ?? '0'))),
            maxVolume: BigDecimal::of($this->string($row['maxMktSz'] ?? $row['maxLmtSz'] ?? '0') ?: '0'),
            minVolume: BigDecimal::of($this->string($row['minSz'] ?? $row['lotSz'] ?? '0') ?: '0'),
            fundingRate: BigDecimal::of('0'),
            expectedFundingRate: BigDecimal::of('0'),
            openInterest: BigDecimal::of('0'),
            openInterestValue: BigDecimal::of('0'),
            high24h: BigDecimal::of($this->string($ticker['high24h'] ?? '0') ?: '0'),
            low24h: BigDecimal::of($this->string($ticker['low24h'] ?? '0') ?: '0'),
            change24h: BigDecimal::of('0'),
            fundingTime: $this->time(null),
            marketMaxVolume: BigDecimal::of($this->string($row['maxMktSz'] ?? '0') ?: '0'),
            fundingIntervalHours: 0,
            status: strtolower($this->string($row['state'] ?? 'unknown')),
            delistTime: $this->time($row['expTime'] ?? null),
        );
    }

    /**
     * @param array<int,mixed> $row
     */
    public function kline(array $row, string $symbol, Timeframe $timeframe): KlineDto
    {
        return new KlineDto(
            symbol: strtoupper($symbol),
            timeframe: $timeframe,
            openTime: $this->time($row[0] ?? null),
            open: BigDecimal::of($this->string($row[1] ?? '0') ?: '0'),
            high: BigDecimal::of($this->string($row[2] ?? '0') ?: '0'),
            low: BigDecimal::of($this->string($row[3] ?? '0') ?: '0'),
            close: BigDecimal::of($this->string($row[4] ?? '0') ?: '0'),
            volume: BigDecimal::of($this->string($row[5] ?? '0') ?: '0'),
            source: 'OKX_REST_PUBLIC',
        );
    }

    /**
     * @param mixed $value
     */
    public function time(mixed $value): \DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return new \DateTimeImmutable('@0', new \DateTimeZone('UTC'));
        }

        $raw = (string) $value;
        if (is_numeric($raw)) {
            $milliseconds = (int) $raw;
            $seconds = intdiv($milliseconds, 1000);
            $millis = $milliseconds % 1000;

            return \DateTimeImmutable::createFromFormat(
                'U.u',
                sprintf('%d.%03d000', $seconds, $millis),
                new \DateTimeZone('UTC'),
            ) ?: new \DateTimeImmutable('@' . $seconds, new \DateTimeZone('UTC'));
        }

        return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param array<mixed> $levels
     * @return list<array{price: float, quantity: float}>
     */
    public function orderBookLevels(array $levels): array
    {
        $normalized = [];
        foreach ($levels as $level) {
            if (!\is_array($level) || !isset($level[0], $level[1])) {
                continue;
            }

            $normalized[] = [
                'price' => (float) $level[0],
                'quantity' => (float) $level[1],
            ];
        }

        return $normalized;
    }

    public function bar(Timeframe $timeframe): string
    {
        return match ($timeframe) {
            Timeframe::TF_1M => '1m',
            Timeframe::TF_5M => '5m',
            Timeframe::TF_15M => '15m',
            Timeframe::TF_30M => '30m',
            Timeframe::TF_1H => '1H',
            Timeframe::TF_4H => '4H',
            Timeframe::TF_1D => '1Dutc',
        };
    }

    private function decimalPlaces(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr($value, strpos($value, '.') + 1), '0'));
    }

    /**
     * @param array<string,mixed>|null $ticker
     */
    private function turnover24h(?array $ticker): string
    {
        if ($ticker === null) {
            return '0';
        }

        $quoteVolume = $this->string($ticker['volCcyQuote24h'] ?? '');
        if ($quoteVolume !== '') {
            return $quoteVolume;
        }

        $last = (float) ($ticker['last'] ?? 0.0);
        $volume = (float) ($ticker['vol24h'] ?? 0.0);

        return (string) ($last * $volume);
    }

    private function string(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
