<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

use App\Trading\Paper\MarketData\CanonicalJson;

final class OkxPaperRetainedTradeRow
{
    /** @var list<string> */
    private const LEGACY_KEYS = [
        'instId',
        'tradeId',
        'px',
        'sz',
        'side',
        'source',
        'ts',
    ];

    /** @var list<string> */
    private const MODERN_KEYS = [
        'instId',
        'tradeId',
        'px',
        'sz',
        'side',
        'count',
        'source',
        'ts',
        'seqId',
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
     * @return array{instId: string, tradeId: string, px: string, sz: string, side: string, source: string, ts: string}|array{instId: string, tradeId: string, px: string, sz: string, side: string, count: string, source: string, ts: string, seqId: int|string}
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
            $keys = match (\count($row)) {
                7 => self::LEGACY_KEYS,
                9 => self::MODERN_KEYS,
                default => throw self::invalid(),
            };
            if (\count($row) !== \count($keys)) {
                throw self::invalid();
            }
            $values = $row;
        } else {
            $actual = array_keys($row);
            sort($actual, \SORT_STRING);
            $legacy = self::LEGACY_KEYS;
            sort($legacy, \SORT_STRING);
            $modern = self::MODERN_KEYS;
            sort($modern, \SORT_STRING);
            $keys = match ($actual) {
                $legacy => self::LEGACY_KEYS,
                $modern => self::MODERN_KEYS,
                default => throw self::invalid(),
            };
            $values = array_map(
                static fn (string $key): mixed => $row[$key],
                $keys,
            );
        }
        foreach ($values as $index => $value) {
            if ($keys[$index] === 'seqId') {
                if (!\is_int($value)
                    && (!\is_string($value)
                        || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value) !== 1)
                ) {
                    throw self::invalid();
                }

                continue;
            }
            if (!\is_string($value)) {
                throw self::invalid();
            }
        }
        if ($encoded !== null
            && !hash_equals($encoded, CanonicalJson::encode($values))
        ) {
            throw self::invalid();
        }

        return array_combine($keys, $values);
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'okx_paper_retained_trade_row_invalid',
        );
    }
}
