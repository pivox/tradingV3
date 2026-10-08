<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final readonly class HyperliquidMutationReadinessProof
{
    /** @param list<string> $allowedSymbols */
    public function __construct(
        public string $profile,
        public string $configHash,
        public string $side,
        public array $allowedSymbols,
        public float $maxNotional,
        public int $issuedAt,
        public string $mac,
    ) {
    }
}
