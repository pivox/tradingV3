<?php

namespace App\Contract\Provider\Dto;

class SymbolBidAskDto
{

    public function __construct(
        public readonly string $symbol,
        public readonly float $bid,
        public readonly float $ask,
        public readonly \DateTimeImmutable $timestamp,
    ){}

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'bid' => $this->bid,
            'ask' => $this->ask,
            'timestamp' => $this->timestamp->format('c'),
        ];
    }
}
