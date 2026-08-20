<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

use App\Trading\Lineage\LineageContext;

interface CanonicalMicrostructureSnapshotProviderInterface
{
    public function snapshotFor(
        LineageContext $identity,
        \DateTimeImmutable $evaluatedAt,
    ): ?CanonicalMicrostructureSnapshot;
}
