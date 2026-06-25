<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

final readonly class FillQuantityAggregationResult
{
    /**
     * @param list<string> $quantityQualityFlags
     */
    public function __construct(
        public string $internalTradeId,
        public string $exchange,
        public string $marketType,
        public ?\DateTimeImmutable $entryFirstFillAt,
        public ?\DateTimeImmutable $entryLastFillAt,
        public ?float $entryQty,
        public ?float $entryVwap,
        public ?\DateTimeImmutable $exitFirstFillAt,
        public ?\DateTimeImmutable $exitLastFillAt,
        public ?float $exitQty,
        public ?float $exitVwap,
        public ?float $remainingQty,
        public bool $positionFullyClosed,
        public string $quantityStatus,
        public array $quantityQualityFlags,
        public ?float $feeUsdt,
        public ?float $fundingUsdt,
        public ?float $spreadCostUsdt,
        public ?float $slippageCostUsdt,
        public ?float $borrowCostUsdt,
        public ?float $liquidationFeeUsdt,
    ) {
    }

    public function netPnlCertificationAllowed(): bool
    {
        return $this->quantityStatus === 'complete'
            && $this->positionFullyClosed
            && $this->remainingQty !== null
            && abs($this->remainingQty) <= FillQuantityAggregationService::DEFAULT_QUANTITY_TOLERANCE
            && !\in_array('fill_conflict', $this->quantityQualityFlags, true);
    }
}
