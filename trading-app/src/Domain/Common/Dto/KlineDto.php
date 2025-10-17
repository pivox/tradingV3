<?php

declare(strict_types=1);

namespace App\Domain\Common\Dto;

use App\Domain\Common\Enum\Timeframe;
use Brick\Math\BigDecimal;

final readonly class KlineDto
{
    public function __construct(
        public string $symbol,
        public Timeframe $timeframe,
        public \DateTimeImmutable $openTime,
        public BigDecimal $open,
        public BigDecimal $high,
        public BigDecimal $low,
        public BigDecimal $close,
        public BigDecimal $volume,
        public string $source = 'REST'
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe->value,
            'open_time' => $this->openTime->format('Y-m-d H:i:s'),
            'open' => $this->open->toFixed(12),
            'high' => $this->high->toFixed(12),
            'low' => $this->low->toFixed(12),
            'close' => $this->close->toFixed(12),
            'volume' => $this->volume->toFixed(12),
            'source' => $this->source,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            timeframe: Timeframe::from($data['timeframe']),
            openTime: new \DateTimeImmutable($data['open_time'], new \DateTimeZone('UTC')),
            open: BigDecimal::of($data['open']),
            high: BigDecimal::of($data['high']),
            low: BigDecimal::of($data['low']),
            close: BigDecimal::of($data['close']),
            volume: BigDecimal::of($data['volume']),
            source: $data['source'] ?? 'REST'
        );
    }

    public function isBullish(): bool
    {
        return $this->close->isGreaterThan($this->open);
    }

    public function isBearish(): bool
    {
        return $this->close->isLessThan($this->open);
    }

    public function getBodySize(): BigDecimal
    {
        return $this->close->minus($this->open)->abs();
    }

    public function getWickSize(): BigDecimal
    {
        return $this->high->minus($this->low)->minus($this->getBodySize());
    }

    public function getBodyPercentage(): float
    {
        $totalRange = $this->high->minus($this->low);
        if ($totalRange->isZero()) {
            return 0.0;
        }
        
        return $this->getBodySize()->dividedBy($totalRange)->toFloat();
    }
}




