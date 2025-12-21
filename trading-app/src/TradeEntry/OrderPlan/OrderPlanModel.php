<?php
declare(strict_types=1);

namespace App\TradeEntry\OrderPlan;

use App\TradeEntry\Types\Side;

final class OrderPlanModel
{
    public function __construct(
        public readonly string $symbol,
        public readonly Side $side,
        public readonly string $orderType,   // 'limit' | 'market'
        public readonly string $openType,    // 'isolated' | 'cross'
        public readonly int    $orderMode,   // 1=GTC,2=FOK,3=IOC,4=MakerOnly
        public readonly float  $entry,
        public readonly float  $stop,
        public readonly float  $takeProfit,
        public readonly int    $size,
        public readonly int    $leverage,
        public readonly int    $pricePrecision,
        public readonly float  $contractSize,
        public readonly ?float $entryZoneLow = null,
        public readonly ?float $entryZoneHigh = null,
        public readonly ?\DateTimeImmutable $zoneExpiresAt = null,
        /** @var array<string,mixed>|null */
        public readonly ?array $entryZoneMeta = null,
        public readonly ?float $stopAtr = null,
        public readonly ?float $stopRisk = null,
        public readonly ?float $stopPivot = null,
        public readonly ?string $stopFinalSource = null,
    ) {}

    public function copyWith(
        ?string $orderType = null,
        ?int    $orderMode = null,
        ?float  $entry     = null,
        ?float  $takeProfit = null,
        ?int    $size = null,
        ?int    $leverage = null
    ): self {
        return new self(
            $this->symbol,
            $this->side,
            $orderType   ?? $this->orderType,
            $this->openType,
            $orderMode   ?? $this->orderMode,
            $entry       ?? $this->entry,
            $this->stop,
            $takeProfit  ?? $this->takeProfit,
            $size ?? $this->size,
            $leverage ?? $this->leverage,
            $this->pricePrecision,
            $this->contractSize,
            $this->entryZoneLow,
            $this->entryZoneHigh,
            $this->zoneExpiresAt,
            $this->entryZoneMeta,
            $this->stopAtr,
            $this->stopRisk,
            $this->stopPivot,
            $this->stopFinalSource,
        );
    }
}
