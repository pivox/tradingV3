<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Provider\Hyperliquid\Dto\HyperliquidMarginSafetyEvidence;

interface HyperliquidMarginSafetyEvidenceProviderInterface
{
    public function current(string $symbol, string $notional, int $requestedLeverage): HyperliquidMarginSafetyEvidence;
}
