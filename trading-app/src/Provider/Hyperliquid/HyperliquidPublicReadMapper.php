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
        $priceMaxDecimals = $this->priceMaxDecimals($asset);
        $quantityStep = $this->quantityStep($asset);

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
            maxLeverage: BigDecimal::of($this->maxLeverage($asset) ?? '0'),
            pricePrecision: BigDecimal::of((string) $priceMaxDecimals),
            volPrecision: BigDecimal::of((string) $this->decimalPlaces($quantityStep)),
            maxVolume: BigDecimal::of('0'),
            minVolume: BigDecimal::of($quantityStep === '0' ? '0' : $quantityStep),
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
        $fundingRate = $this->nullableNumericString($funding['fundingRate'] ?? $context['funding'] ?? null);
        $priceMaxDecimals = $this->priceMaxDecimals($asset);
        $quantityStep = $this->quantityStep($asset);
        $maxLeverage = $this->maxLeverage($asset);
        $status = $this->status($asset);

        if ($fundingRate === null) {
            $qualityFlags[] = 'funding_rate_unknown';
        }
        if (!$this->hasValidSizeDecimals($asset)) {
            $qualityFlags[] = array_key_exists('szDecimals', $asset) ? 'invalid_size_decimals' : 'missing_size_decimals';
        }
        if ($maxLeverage === null) {
            $qualityFlags[] = array_key_exists('maxLeverage', $asset) ? 'invalid_max_leverage' : 'missing_max_leverage';
            $maxLeverage = '0';
        }
        if ($status !== 'live') {
            $qualityFlags[] = 'market_suspended';
        }

        return new HyperliquidInstrumentMetadataDto(
            symbol: $this->symbol($coin),
            coin: $coin,
            assetId: $assetId,
            priceTick: $this->stepFromDecimalPlaces($priceMaxDecimals),
            priceMaxDecimals: $priceMaxDecimals,
            quantityStep: $quantityStep,
            minSize: $quantityStep,
            maxSize: '0',
            maxLeverage: $maxLeverage,
            fundingRate: $fundingRate,
            fundingTime: $this->time($funding['time'] ?? null),
            qualityFlags: array_values(array_unique($qualityFlags)),
            status: $status,
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
        if (!$this->hasValidSizeDecimals($asset)) {
            return '0';
        }

        $decimals = (int) $asset['szDecimals'];

        return $decimals === 0 ? '1' : '0.' . str_repeat('0', $decimals - 1) . '1';
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function priceMaxDecimals(array $asset): int
    {
        if (!$this->hasValidSizeDecimals($asset)) {
            return 0;
        }

        $sizeDecimals = (int) $asset['szDecimals'];

        return max(0, 6 - $sizeDecimals);
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function hasValidSizeDecimals(array $asset): bool
    {
        if (!array_key_exists('szDecimals', $asset) || !\is_scalar($asset['szDecimals'])) {
            return false;
        }

        $value = trim((string) $asset['szDecimals']);
        if ($value === '' || !ctype_digit($value)) {
            return false;
        }

        $decimals = (int) $value;

        return $decimals >= 0 && $decimals <= 6;
    }

    /**
     * @param array<string,mixed> $asset
     */
    private function maxLeverage(array $asset): ?string
    {
        $value = $this->nullableNumericString($asset['maxLeverage'] ?? null);
        if ($value === null || (float) $value <= 0.0) {
            return null;
        }

        return $value;
    }

    private function stepFromDecimalPlaces(int $places): string
    {
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
