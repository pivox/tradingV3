<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Exchange\Hyperliquid\Lifecycle\HyperliquidNormalizedOrderLifecycleDto;

interface HyperliquidIdentifierLifecycleLookupInterface
{
    public function lookup(
        string $accountAddress,
        string $identifier,
        ?string $expectedExchangeOrderId,
        string $expectedWireCloid,
    ): ?HyperliquidNormalizedOrderLifecycleDto;
}
