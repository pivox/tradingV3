<?php

declare(strict_types=1);

namespace App\Trading\Reporting;

final class PositionTradeAnalysisReportingSource
{
    public const V1 = 'v1';
    public const V2 = 'v2';
    public const DUAL = 'dual';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::V2, self::V1, self::DUAL];
    }

    public static function normalize(?string $requested, string $configured): string
    {
        $source = strtolower(trim($requested ?? ''));
        if ($source === '') {
            $source = strtolower(trim($configured));
        }

        return in_array($source, self::all(), true) ? $source : self::V2;
    }
}
