<?php

declare(strict_types=1);

namespace App\Trading\Pnl;

final readonly class NetPnlCertificationResult
{
    /**
     * @param list<string> $qualityFlags
     */
    public function __construct(
        public bool $certified,
        public string $costCompleteness,
        public ?float $grossRealizedPnlUsdt,
        public ?float $entryFeeUsdt,
        public ?float $exitFeeUsdt,
        public ?float $totalKnownCostUsdt,
        public ?float $netPnlUsdt,
        public ?float $realizedGrossPnlR,
        public ?float $realizedNetPnlR,
        public array $qualityFlags,
    ) {
    }
}
