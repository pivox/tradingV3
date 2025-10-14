<?php

namespace App\Indicator;

final class EvaluationContext
{
    public function __construct(
        public string $symbol,
        public string $timeframe,
        public array $indicators,  // ex: ['ema_20'=>..., 'ema_50'=>..., 'macd'=>...]
        public array $klines       // optional: open/high/low/close/volume
    ) {}
}

