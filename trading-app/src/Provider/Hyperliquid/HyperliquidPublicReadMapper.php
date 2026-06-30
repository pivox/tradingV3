<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\ContractDto;
use App\Contract\Provider\Dto\KlineDto;
use App\Provider\Hyperliquid\Dto\HyperliquidInstrumentMetadataDto;
use Brick\Math\BigDecimal;

final readonly class HyperliquidPublicReadMapper
{
    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed>|null $context
     */
    public function contract(array $asset, int $assetId, ?array $context = null): ContractDto
    {
        $coin = $this->coin($asset);
        $last = $this->string($context['markPx'] ?? $context['midPx'] ?? '0') ?: '0';
        $prevDay = $this->string($context['prevDayPx'] ?? '0') ?: '0';
        $openInterest = $this->string($context['openInterest'] ?? '0') ?: '0';

        return new ContractDto(
            symbol: $this->symbol($coin),
            productType: 1,
            openTimestamp: $this->time(null),
            expireTimestamp: $this->time(null),
            settleTimestamp: $this->time(null),
            baseCurrency: $coin,
            quoteCurrency: 'USDC',
            lastPrice: BigDecimal::of($last),
            volume24h: BigDecimal::of('0'),
            turnover24h: BigDecimal::of($this->string($context['dayNtlVlm'] ?? '0') ?: '0'),
            indexPrice: BigDecimal::of($last),
            indexName: $coin,
            contractSize: BigDecimal::of('1'),
            minLeverage: BigDecimal::of('1'),
            maxLeverage: BigDecimal::of($this->string($asset['maxLeverage'] ?? '1') ?: '1'),
            pricePrecision: BigDecimal::of((string) $this->decimalPlaces($this->priceTick($last))),
            volPrecision: BigDecimal::of((string) $this->decimalPlaces($this->quantityStep($asset))),
            maxVolume: BigDecimal::of('0'),
            minVolume: BigDecimal::of($this->quantityStep($asset)),
            fundingRate: BigDecimal::of($this->string($context['funding'] ?? '0') ?: '0'),
            expectedFundingRate: BigDecimal::of($this->string($context['funding'] ?? '0') ?: '0'),
            openInterest: BigDecimal::of($openInterest),
            openInterestValue: BigDecimal::of((string) ((float) $openInterest * (float) $last)),
            high24h: BigDecimal::of('0'),
            low24h: BigDecimal::of('0'),
            change24h: BigDecimal::of((string) ((float) $last - (float) $prevDay)),
            fundingTime: $this->time(null),
            marketMaxVolume: BigDecimal::of('0'),
            fundingIntervalHours: 1,
            status: $this->status($asset),
            delistTime: $this->time(null),
        );
    }

    /**
     * @param array<string,mixed> $asset
     * @param array<string,mixed>|null $context
     * @param array<string,mixed>|null $funding
     * @param list<string> $qualityFlags
     */
    public function metadata(
        array $asset,
        int $assetId,
        ?array $context,
        ?array $funding,
        array $qualityFlags,
    ): HyperliquidInstrumentMetadataDto {
        $coin = $this->coin($asset);
        $last = $this->string($context['markPx'] ?? $context['midPx'] ?? '0') ?: '0';
        $fundingRate = $this->nullableNumericString($funding['fundingRate'] ?? $context['funding'] ?? null);

        if ($fundingRate === null) {
            $qualityFlags[] = 'funding_rate_unknown';
        }

        return new HyperliquidInstrumentMetadataDto(
            symbol: $this->symbol($coin),
            coin: $coin,
            assetId: $assetId,
            priceTick: $this->priceTick($last),
            quantityStep: $this->quantityStep($asset),
            minSize: $this->quantityStep($asset),
            maxSize: '0',
            maxLeverage: $this->string($asset['maxLeverage'] ?? '1') ?: '1',
            fundingRate: $fundingRate,
            fundingTime: $this->time($funding['time'] ?? null),
            qualityFlags: array_values(array_unique($qualityFlags)),
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    public function kline(array $row, string $symbol, Timeframe $timeframe): KlineDto
    {
        return new KlineDto(
            symbol: strtoupper($symbol),
            timeframe: $timeframe,
            openTime: $this->time($row['t'] ?? null),
            open: BigDecimal::of($this->string($row['o'] ?? '0') ?: '0'),
            high: BigDecimal::of($this->string($row['h'] ?? '0') ?: '0'),
            low: BigDecimal::of($this->string($row['l'] ?? '0') ?: '0'),
            close: BigDecimal::of($this->string($row['c'] ?? '0') ?: '0'),
            volume: BigDecimal::of($this->string($row['v'] ?? '0') ?: '0'),
            source: 'HYPERLIQUID_REST_PUBLIC',
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
     * @param list<mixed> $levels
     * @return list<array{price: float, quantity: float}>
     */
    public function orderBookLevels(array $levels): array
    {
        $normalized = [];
        foreach ($levels as $level) {
            if (!\is_array($level)) {
                continue;
            }

            $price = $level['px'] ?? $level[0] ?? null;
            $quantity = $level['sz'] ?? $level[1] ?? null;
            if (!\is_numeric($price) || !\is_numeric($quantity)) {
                continue;
            }

            $normalized[] = [
                'price' => (float) $price,
                'quantity' => (float) $quantity,
            ];
        }

        return $normalized;
    }

    public function interval(Timeframe $timeframe): string
    {
        return match ($timeframe) {
            Timeframe::TF_1M => '1m',
            Timeframe::TF_5M => '5m',
            Timeframe::TF_15M => '15m',
            Timeframe::TF_30M => '30m',
            Timeframe::TF_1H => '1h',
            Timeframe::TF_4H => '4h',
            Timeframe::TF_1D => '1d',
        };
    }

    /**
     * @param array<string,mixed> $asset
     */
    public function coin(array $asset): string
    {
        return strtoupper($this->string($asset['name'] ?? ''));
    }

    public function symbol(string $coin): string
    {
        return strtoupper($coin) . 'USDT';
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function quantityStep(array $asset): string
    {
        $decimals = max(0, (int) ($asset['szDecimals'] ?? 0));

        return $decimals === 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1';
    }

    private function priceTick(string $price): string
    {
        $places = $this->decimalPlaces($price);

        return $places === 0 ? '1' : '0.' . str_repeat('0', $places - 1) . '1';
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function status(array $asset): string
    {
        if (($asset['isDelisted'] ?? false) === true) {
            return 'suspend';
        }

        return 'live';
    }

    private function nullableNumericString(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return is_numeric($string) ? $string : null;
    }

    private function decimalPlaces(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !str_contains($value, '.')) {
            return 0;
        }

        return strlen(rtrim(substr($value, strpos($value, '.') + 1), '0'));
    }

    private function string(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
