<?php

declare(strict_types=1);

namespace App\Trading\Reporting;

final readonly class PositionTradeAnalysisReportingResult
{
    /**
     * @param list<PositionTradeAnalysisReportRow> $rows
     * @param array<string,mixed> $summary
     */
    public function __construct(
        public string $activeSource,
        public string $primarySource,
        public bool $dualRead,
        public array $rows,
        public array $summary,
    ) {
    }
}
