<?php

declare(strict_types=1);

namespace App\Trading\Backfill;

interface PositionTradeAnalysisBackfillDivergenceReaderInterface
{
    /**
     * @return list<array<string,mixed>>
     */
    public function fetchRows(BackfillDivergenceCriteria $criteria): array;
}
