<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

interface HyperliquidMutationReadinessConfigSourceInterface
{
    public function current(): HyperliquidMutationReadinessConfig;
}
