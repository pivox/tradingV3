<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Contract\Provider\SystemProviderInterface;

final class HyperliquidSystemProvider implements SystemProviderInterface
{
    public function getSystemTimeMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
