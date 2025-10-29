<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

use App\TradeEntry\Types\Side;

final class TradeEntryRequest
{
    public function __construct(
        public readonly string $symbol,
        public readonly Side $side,
        public readonly string $orderType = 'limit',
        public readonly string $openType = 'isolated',
        public readonly int $orderMode = 4,
        public readonly float $initialMarginUsdt = 100.0,
        public readonly float $riskPct = 0.02,
        public readonly float $rMultiple = 2.0,
        public readonly ?float $entryLimitHint = null,
        public readonly string $stopFrom = 'risk',
        public readonly ?float $atrValue = null,
        public readonly float $atrK = 1.5,
        public readonly ?float $marketMaxSpreadPct = 0.001
    ) {}
}
