<?php

declare(strict_types=1);

namespace App\Exchange\Reconciliation;

interface ExchangeReconciliationSnapshotProofProviderInterface
{
    /** @return array<string,mixed>|null */
    public function captureReconciliationSnapshotProof(?string $symbol = null): ?array;

    /**
     * @param array<string,mixed> $pendingProof
     * @return array<string,mixed>
     */
    public function attestReconciliationSnapshotProof(array $pendingProof): array;
}
