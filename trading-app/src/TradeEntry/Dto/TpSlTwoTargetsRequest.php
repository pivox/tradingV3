<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

use App\TradeEntry\Types\Side;

final class TpSlTwoTargetsRequest
{
    public function __construct(
        public readonly string $symbol,
        public readonly Side $side,
        public readonly ?float $entryPrice = null,
        public readonly ?int $size = null,
        public readonly ?float $rMultiple = 2.0,
        public readonly ?float $splitPct = null,
        public readonly ?bool $cancelExistingStopLossIfDifferent = true,
        public readonly ?bool $cancelExistingTakeProfits = true,
        public readonly ?bool $slFullSize = null,
        // Hints to drive TpSplitResolver (optional)
        public readonly ?string $momentum = null,        // 'faible'|'moyen'|'fort'
        public readonly ?int $mtfValidCount = null,      // 0..3
        public readonly ?bool $pullbackClear = null,
        public readonly ?bool $lateEntry = null,
    ) {}
}
