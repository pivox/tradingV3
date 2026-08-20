<?php

declare(strict_types=1);

namespace App\TradingCore\Config\Audit;

final class EffectiveConfigSnapshotNotFound extends \RuntimeException
{
    public function __construct(public readonly string $snapshotHash)
    {
        parent::__construct('effective_config_snapshot_not_found');
    }
}
