<?php

declare(strict_types=1);

namespace App\TradingCore\OrderPlan\Canonical;

final readonly class CanonicalProtectionDecision
{
    /**
     * @param non-empty-list<CanonicalProtectionTarget> $targets
     * @param non-empty-list<string>                    $inputHashes
     */
    public function __construct(
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $exchange,
        public string $environment,
        public string $side,
        public string $symbol,
        public float $entryPrice,
        public float $stopPrice,
        public float $riskDistance,
        public array $targets,
        public \DateTimeImmutable $computedAt,
        public string $configHash,
        public array $inputHashes,
    ) {
    }
}
