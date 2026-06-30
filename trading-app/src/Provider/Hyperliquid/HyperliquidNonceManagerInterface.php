<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

interface HyperliquidNonceManagerInterface
{
    public function nextNonce(HyperliquidNonceScope $scope): int;

    public function recordObservedNonce(HyperliquidNonceScope $scope, int $nonce): void;
}
