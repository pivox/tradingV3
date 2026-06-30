<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

interface HyperliquidSignatureBackendInterface
{
    /**
     * @return array{r: string, s: string, v: int}
     */
    public function sign(string $canonicalPayload, string $privateKey): array;
}
