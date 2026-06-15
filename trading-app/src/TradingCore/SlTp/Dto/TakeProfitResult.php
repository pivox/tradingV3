<?php
declare(strict_types=1);

namespace App\TradingCore\SlTp\Dto;

final readonly class TakeProfitResult
{
    /**
     * @param list<string> $warnings
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public float $tp1Price,
        public ?float $tp2Price,
        public float $expectedR,
        public ?float $expectedNetR,
        public string $tpPolicyApplied,
        public array $warnings = [],
        public array $metadata = [],
    ) {}
}
