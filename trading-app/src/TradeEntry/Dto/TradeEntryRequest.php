<?php
declare(strict_types=1);

namespace App\TradeEntry\Dto;

use App\TradeEntry\Types\Side;

final class TradeEntryRequest
{
    public function __construct(
        public readonly string $symbol,
        public readonly Side $side,
        public readonly ?string $executionTf = null,
        public readonly string $orderType = 'limit',
        public readonly string $openType = 'isolated',
        public readonly int $orderMode = 4,
        public readonly float $initialMarginUsdt = 100.0,
        public readonly float $riskPct = 0.02,
        public readonly float $rMultiple = 2.0,
        public readonly ?float $entryLimitHint = null,
        public readonly string $stopFrom = 'risk',
        public readonly ?string $stopFallback = 'atr',
        public readonly string $pivotSlPolicy = 'nearest',
        public readonly ?float $pivotSlBufferPct = 0.0015,
        public readonly ?float $pivotSlMinKeepRatio = 0.8,
        public readonly ?float $atrValue = null,
        public readonly float $atrK = 1.5,
        public readonly ?float $marketMaxSpreadPct = 0.001,
        public readonly ?int $insideTicks = null,
        public readonly ?float $maxDeviationPct = null,
        public readonly ?float $implausiblePct = null,
        public readonly ?float $zoneMaxDeviationPct = null,
        public readonly string $tpPolicy = 'pivot_conservative',
        public readonly ?float $tpBufferPct = null,
        public readonly ?int $tpBufferTicks = null,
        public readonly float $tpMinKeepRatio = 0.95,
        public readonly ?float $tpMaxExtraR = null,
        public readonly float $leverageMultiplier = 1.0,
        public readonly ?float $leverageExchangeCap = null,
    ) {}
}
