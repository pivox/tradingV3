<?php

declare(strict_types=1);

namespace App\Exchange\Reconciliation;

interface ExchangeReconciliationSnapshotProofProviderInterface
{
    /** @return array<string,mixed>|null */
    public function captureReconciliationSnapshotProof(?string $symbol = null): ?array;
}
