<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

final class EffectiveTradingHyperliquidMutationReadinessConfigSource implements HyperliquidMutationReadinessConfigSourceInterface
{
    public function current(): HyperliquidMutationReadinessConfig
    {
        // This legacy profile-only source cannot provide the exact mode/setup
        // versions and side required by the canonical boundary. It must remain
        // fail-closed until its caller is migrated with #302 lineage.
        return HyperliquidMutationReadinessConfig::failClosed();
    }
}
