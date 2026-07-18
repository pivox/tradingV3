<?php

declare(strict_types=1);

namespace App\Exchange\Reconciliation;

use App\Exchange\Dto\ExchangeReconciliationResult;

interface ExchangeReconciliationSnapshotProofOrchestratorInterface
{
    public function reconcileWithSnapshotProof(
        ExchangeReconciliationService $reconciliation,
        ?string $symbol = null,
    ): ExchangeReconciliationResult;
}
