<?php

namespace App\Service\Exchange\Bitmart\Dto;

class KlineDto
{
    public function __construct(
        public readonly int $timestamp,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly float $volume
    ) {}

    public static function fromApi(array $raw): self
    {
        return new self(
            timestamp: (int) $raw['timestamp'],
            open: (float) $raw['open_price'],
            high: (float) $raw['high_price'],
            low: (float) $raw['low_price'],
            close: (float) $raw['close_price'],
            volume: (float) $raw['volume']
        );
    }

    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'open'      => $this->open,
            'high'      => $this->high,
            'low'       => $this->low,
            'close'     => $this->close,
            'volume'    => $this->volume,
        ];
    }
}
