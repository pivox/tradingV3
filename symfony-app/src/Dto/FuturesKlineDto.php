<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Représente une bougie (Kline) BitMart Futures V2.
 */
final class FuturesKlineDto
{
    public function __construct(
        public readonly float $low,
        public readonly float $high,
        public readonly float $open,
        public readonly float $close,
        public readonly float $volume,
        public readonly int   $timestamp, // secondes
    ) {}

    /**
     * Fabrique un DTO à partir du tableau renvoyé par l’API.
     *
     * @param array<string,string|int|float> $data
     */
    public static function fromApi(array $data): self
    {
        return new self(
            low: (float) $data['low_price'],
            high: (float) $data['high_price'],
            open: (float) $data['open_price'],
            close: (float) $data['close_price'],
            volume: (float) $data['volume'],
            timestamp: (int) $data['timestamp'],
        );
    }

    public function getDateString(): string
    {
        return date('d-m-Y H:i:s', $this->timestamp);
    }

    public function __toString(): string
    {
        return sprintf(
            '[%s] O:%.2f H:%.2f L:%.2f C:%.2f V:%.2f',
            date('Y-m-d H:i:s', $this->timestamp),
            $this->open,
            $this->high,
            $this->low,
            $this->close,
            $this->volume,
        );
    }
}
