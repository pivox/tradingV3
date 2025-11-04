<?php
declare(strict_types=1);

namespace App\TradeEntry\TpSplit\Dto;

final class TpSplitContext
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $momentum,         // 'faible'|'moyen'|'fort'
        public readonly float $atrPct,            // ATR% (0..100)
        public readonly int $mtfValidCount,       // 0..3
        public readonly bool $pullbackClear = false,
        public readonly bool $lateEntry = false,
    ) {}
}

