<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

interface HyperliquidSignerInterface
{
    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    public function signAction(array $action, int $nonce): array;
}
