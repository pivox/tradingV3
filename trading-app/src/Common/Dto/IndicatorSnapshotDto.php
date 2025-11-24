<?php

declare(strict_types=1);

namespace App\Common\Dto;

use App\Common\Enum\Timeframe;
use Brick\Math\BigDecimal;

final readonly class IndicatorSnapshotDto
{
    public function __construct(
        public string $symbol,
        public Timeframe $timeframe,
        public \DateTimeImmutable $klineTime,
        public ?BigDecimal $ema20 = null,
        public ?BigDecimal $ema50 = null,
        public ?BigDecimal $ema200 = null,
        public ?BigDecimal $macd = null,
        public ?BigDecimal $macdSignal = null,
        public ?BigDecimal $macdHistogram = null,
        public ?BigDecimal $atr = null,
        public ?float $rsi = null,
        public ?BigDecimal $vwap = null,
        public ?BigDecimal $bbUpper = null,
        public ?BigDecimal $bbMiddle = null,
        public ?BigDecimal $bbLower = null,
        public ?BigDecimal $ma9 = null,
        public ?BigDecimal $ma21 = null,
        public array $meta = [],
        public string $source = 'PHP'
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe->value,
            'kline_time' => $this->klineTime->format('Y-m-d H:i:s'),
            'ema20' => $this->ema20?->toFixed(12),
            'ema50' => $this->ema50?->toFixed(12),
            'ema200' => $this->ema200?->toFixed(12),
            'macd' => $this->macd?->toFixed(12),
            'macd_signal' => $this->macdSignal?->toFixed(12),
            'macd_histogram' => $this->macdHistogram?->toFixed(12),
            'atr' => $this->atr?->toFixed(12),
            'rsi' => $this->rsi,
            'vwap' => $this->vwap?->toFixed(12),
            'bb_upper' => $this->bbUpper?->toFixed(12),
            'bb_middle' => $this->bbMiddle?->toFixed(12),
            'bb_lower' => $this->bbLower?->toFixed(12),
            'ma9' => $this->ma9?->toFixed(12),
            'ma21' => $this->ma21?->toFixed(12),
            'meta' => $this->meta,
            'source' => $this->source,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            timeframe: Timeframe::from($data['timeframe']),
            klineTime: new \DateTimeImmutable($data['kline_time'], new \DateTimeZone('UTC')),
            ema20: isset($data['ema20']) ? BigDecimal::of($data['ema20']) : null,
            ema50: isset($data['ema50']) ? BigDecimal::of($data['ema50']) : null,
            ema200: isset($data['ema200']) ? BigDecimal::of($data['ema200']) : null,
            macd: isset($data['macd']) ? BigDecimal::of($data['macd']) : null,
            macdSignal: isset($data['macd_signal']) ? BigDecimal::of($data['macd_signal']) : null,
            macdHistogram: isset($data['macd_histogram']) ? BigDecimal::of($data['macd_histogram']) : null,
            atr: isset($data['atr']) ? BigDecimal::of($data['atr']) : null,
            rsi: $data['rsi'] ?? null,
            vwap: isset($data['vwap']) ? BigDecimal::of($data['vwap']) : null,
            bbUpper: isset($data['bb_upper']) ? BigDecimal::of($data['bb_upper']) : null,
            bbMiddle: isset($data['bb_middle']) ? BigDecimal::of($data['bb_middle']) : null,
            bbLower: isset($data['bb_lower']) ? BigDecimal::of($data['bb_lower']) : null,
            ma9: isset($data['ma9']) ? BigDecimal::of($data['ma9']) : null,
            ma21: isset($data['ma21']) ? BigDecimal::of($data['ma21']) : null,
            meta: $data['meta'] ?? [],
            source: $data['source'] ?? 'PHP'
        );
    }

    public function isMacdBullish(): bool
    {
        return $this->macd !== null && $this->macdSignal !== null &&
               $this->macd->isGreaterThan($this->macdSignal);
    }

    public function isMacdBearish(): bool
    {
        return $this->macd !== null && $this->macdSignal !== null &&
               $this->macd->isLessThan($this->macdSignal);
    }

    public function isRsiOverbought(): bool
    {
        return $this->rsi !== null && $this->rsi > 70;
    }

    public function isRsiOversold(): bool
    {
        return $this->rsi !== null && $this->rsi < 30;
    }

    public function isRsiNeutral(): bool
    {
        return $this->rsi !== null && $this->rsi >= 30 && $this->rsi <= 70;
    }

    public function getPriceDistanceFromMa21(BigDecimal $price): ?BigDecimal
    {
        if ($this->ma21 === null) {
            return null;
        }

        return $price->minus($this->ma21)->abs();
    }

    public function isPriceNearMa21(BigDecimal $price, BigDecimal $atrMultiplier = null): bool
    {
        if ($this->ma21 === null || $this->atr === null) {
            return false;
        }

        $multiplier = $atrMultiplier ?? BigDecimal::of('2');
        $maxDistance = $this->atr->multipliedBy($multiplier);
        $distance = $this->getPriceDistanceFromMa21($price);

        return $distance !== null && $distance->isLessThanOrEqualTo($maxDistance);
    }
}



