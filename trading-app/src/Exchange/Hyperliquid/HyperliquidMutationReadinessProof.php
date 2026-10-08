<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

final readonly class HyperliquidMutationReadinessProof
{
    public function __construct(
        public string $profile,
        public string $configHash,
        public int $issuedAt,
        public string $mac,
    ) {
    }
}
