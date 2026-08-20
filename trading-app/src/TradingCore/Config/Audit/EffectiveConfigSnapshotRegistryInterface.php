<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

interface EffectiveConfigSnapshotRegistryInterface
{
    public function register(EffectiveConfigViewerDocument $document): void;

    public function find(string $snapshotHash): ?EffectiveConfigSnapshotRecord;

    /** @return list<EffectiveConfigSnapshotRecord> */
    public function findByConfigHash(string $configHash): array;
}
