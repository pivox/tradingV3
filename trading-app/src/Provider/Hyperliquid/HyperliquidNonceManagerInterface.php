<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

interface HyperliquidNonceManagerInterface
{
    public function nextNonce(string $signerAddress): int;
}
