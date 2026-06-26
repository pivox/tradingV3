<?php

declare(strict_types=1);

namespace App\Trading\Reporting;

final class PositionTradeAnalysisReportingSourceException extends \RuntimeException
{
    public function __construct(public readonly string $source, \Throwable $previous)
    {
        parent::__construct(
            sprintf('position_trade_analysis reporting source "%s" is unavailable.', $source),
            0,
            $previous,
        );
    }
}
