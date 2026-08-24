<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;

final class OkxPaperRetainedCandleRow
{
    /** @param array<array-key, mixed> $row */
    public static function compact(array $row): string
    {
        return CanonicalJson::encode(self::expand($row));
    }

    /**
     * @param array<array-key, mixed>|string $row
     * @return list<string>
     */
    public static function expand(array|string $row): array
    {
        $encoded = null;
        if (\is_string($row)) {
            $encoded = $row;
            try {
                $row = json_decode($row, true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw self::invalid();
            }
            if (!\is_array($row)) {
                throw self::invalid();
            }
        }
        if (!array_is_list($row) || \count($row) !== 9) {
            throw self::invalid();
        }
        foreach ($row as $value) {
            if (!\is_string($value)) {
                throw self::invalid();
            }
        }
        if ($encoded !== null && !hash_equals($encoded, CanonicalJson::encode($row))) {
            throw self::invalid();
        }

        return $row;
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException('okx_paper_retained_candle_row_invalid');
    }
}
