<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Dto;

final readonly class LiquidationCheckResult
{
    /**
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public bool $isSafe,
        public ?float $liquidationPrice,
        public ?float $liquidationDistancePct,
        public ?float $stopToLiquidationRatio,
        public array $warnings = [],
        public ?string $reasonIfUnsafe = null,
        public array $metadata = [],
    ) {}
}
