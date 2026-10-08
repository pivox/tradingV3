<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid;

use App\TradingCore\Execution\Hyperliquid\HyperliquidMutationReadinessGate;

final readonly class HyperliquidMutationReadinessProof
{
    private function __construct(
        public string $profile,
        public string $configHash,
    ) {
    }

    public static function issuedBy(HyperliquidMutationReadinessGate $gate, string $profile, string $configHash): self
    {
        if (trim($profile) === '' || preg_match('/^[a-f0-9]{64}$/D', $configHash) !== 1) {
            throw new \InvalidArgumentException('hyperliquid_mutation_proof_invalid');
        }

        return new self($profile, $configHash);
    }
}
