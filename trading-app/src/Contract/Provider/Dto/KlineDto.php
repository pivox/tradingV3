<?php

declare(strict_types=1);

namespace App\Contract\Provider\Dto;

use App\Common\Enum\Timeframe;
use Brick\Math\BigDecimal;

/**
 * DTO pour les klines
 */
final class KlineDto extends BaseDto
{
    public function __construct(
        public readonly string $symbol,
        public readonly Timeframe $timeframe,
        public readonly \DateTimeImmutable $openTime,
        public readonly BigDecimal $open,
        public readonly BigDecimal $high,
        public readonly BigDecimal $low,
        public readonly BigDecimal $close,
        public readonly BigDecimal $volume,
        public readonly string $source = 'REST'
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            timeframe: Timeframe::from($data['timeframe']),
            openTime: new \DateTimeImmutable($data['open_time']),
            open: BigDecimal::of($data['open_price']),
            high: BigDecimal::of($data['high_price']),
            low: BigDecimal::of($data['low_price']),
            close: BigDecimal::of($data['close_price']),
            volume: BigDecimal::of($data['volume']),
            source: $data['source'] ?? 'REST'
        );
    }
}


