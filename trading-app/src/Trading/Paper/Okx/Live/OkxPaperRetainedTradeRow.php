<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;

final class OkxPaperRetainedTradeRow
{
    /** @var list<string> */
    private const KEYS = [
        'instId',
        'tradeId',
        'px',
        'sz',
        'side',
        'source',
        'ts',
    ];

    /**
     * @param array<array-key, mixed> $row
     */
    public static function compact(array $row): string
    {
        if (array_is_list($row)) {
            throw self::invalid();
        }
        $expanded = self::expand($row);

        return CanonicalJson::encode(array_values($expanded));
    }

    /**
     * @param array<array-key, mixed>|string $row
     * @return array{instId: string, tradeId: string, px: string, sz: string, side: string, source: string, ts: string}
     */
    public static function expand(array|string $row): array
    {
        $encoded = null;
        if (\is_string($row)) {
            $encoded = $row;
            try {
                $row = json_decode(
                    $row,
                    true,
                    8,
                    \JSON_THROW_ON_ERROR,
                );
            } catch (\JsonException) {
                throw self::invalid();
            }
            if (!\is_array($row)) {
                throw self::invalid();
            }
        }
        if (array_is_list($row)) {
            if (\count($row) !== \count(self::KEYS)) {
                throw self::invalid();
            }
            $values = $row;
        } else {
            $keys = array_keys($row);
            sort($keys, \SORT_STRING);
            $expected = self::KEYS;
            sort($expected, \SORT_STRING);
            if ($keys !== $expected) {
                throw self::invalid();
            }
            $values = array_map(
                static fn (string $key): mixed => $row[$key],
                self::KEYS,
            );
        }
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw self::invalid();
            }
        }
        if ($encoded !== null
            && !hash_equals($encoded, CanonicalJson::encode($values))
        ) {
            throw self::invalid();
        }

        return array_combine(self::KEYS, $values);
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'okx_paper_retained_trade_row_invalid',
        );
    }
}
