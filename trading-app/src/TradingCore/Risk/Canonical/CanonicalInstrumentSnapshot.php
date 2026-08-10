<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical;

final readonly class CanonicalInstrumentSnapshot
{
    public function __construct(
        public string $exchange,
        public string $environment,
        public string $symbol,
        public string $marketType,
        public float $contractSize,
        public float $quantityStep,
        public float $minQuantity,
        public float $maxQuantity,
        public ?float $marketMaxQuantity,
        public float $exchangeLeverageCap,
        public ?float $symbolLeverageCap,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
        if (
            trim($exchange) === ''
            || trim($environment) === ''
            || preg_match('/\A[A-Z0-9][A-Z0-9_.-]*\z/D', $symbol) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/D', $marketType) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1
        ) {
            throw new CanonicalRiskException('canonical_instrument_identity_invalid');
        }
    }
}
