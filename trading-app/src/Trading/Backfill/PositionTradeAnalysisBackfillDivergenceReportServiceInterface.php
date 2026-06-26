<?php

declare(strict_types=1);

namespace App\Trading\Backfill;

interface PositionTradeAnalysisBackfillDivergenceReportServiceInterface
{
    /**
     * @return array<string,mixed>
     */
    public function buildReport(BackfillDivergenceCriteria $criteria): array;
}
