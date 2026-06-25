<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

use App\Entity\FillCostLedgerEntry;

final readonly class FillCostLedgerIngestionResult
{
    public function __construct(
        public FillCostLedgerEntry $entry,
        public bool $inserted,
        public bool $replayed,
        public ?string $conflictReason = null,
    ) {
    }
}
