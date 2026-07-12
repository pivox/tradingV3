<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use App\Provider\Hyperliquid\HyperliquidSignerInterface;

final readonly class HyperliquidAgentSigner implements HyperliquidSignerInterface
{
    public function signAction(array $action, int $nonce): array
    {
        throw new \RuntimeException('hyperliquid_php_key_custody_forbidden');
    }
}
