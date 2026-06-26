<?php

declare(strict_types=1);

namespace App\Trading\Reporting;

use App\Trading\Entity\PositionTradeAnalysisV2;

interface PositionTradeAnalysisCertifiedReaderInterface
{
    /**
     * @param array{symbol?: string|null, from?: string|null, to?: string|null, timeframe?: string|null} $filters
     * @param array{sort?: string, direction?: string} $options
     * @return PositionTradeAnalysisV2[]
     */
    public function search(array $filters, array $options = [], int $limit = 200): array;
}
