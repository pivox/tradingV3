<?php

declare(strict_types=1);

namespace App\Trading\Reporting;

use App\Trading\Entity\PositionTradeAnalysis;

interface PositionTradeAnalysisLegacyReaderInterface
{
    /**
     * @param array{symbol?: string|null, from?: string|null, to?: string|null, timeframe?: string|null} $filters
     * @param array{sort?: string, direction?: string} $options
     * @return PositionTradeAnalysis[]
     */
    public function search(array $filters, array $options = [], int $limit = 200): array;
}
