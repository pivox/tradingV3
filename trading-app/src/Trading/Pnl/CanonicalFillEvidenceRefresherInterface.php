<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

interface CanonicalFillEvidenceRefresherInterface
{
    public function refreshAfterFill(string $internalTradeId, string $exchange, string $marketType): void;
}
