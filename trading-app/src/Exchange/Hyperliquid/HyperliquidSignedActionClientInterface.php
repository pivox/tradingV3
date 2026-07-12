<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

interface HyperliquidSignedActionClientInterface
{
    /** @param array<string, mixed> $action */
    public function submit(
        array $action,
        int $nonce,
        string $correlationId,
        ?int $expiresAfter = null,
    ): HyperliquidSignedActionResult;

    public function health(): bool;
}
