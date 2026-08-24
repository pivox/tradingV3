<?php

declare(strict_types=1);

namespace App\Trading\Paper\MarketData;

final class PaperMarketHexNibbles
{
    /** @return list<int> */
    public static function fromHex(string $hex, int $maximumNibbles): array
    {
        if ($maximumNibbles < 1
            || \strlen($hex) > $maximumNibbles
            || preg_match('/\A[0-9a-fA-F]+\z/D', $hex) !== 1
        ) {
            throw new \InvalidArgumentException('paper_market_hex_invalid');
        }

        return array_map(
            static fn (string $nibble): int => hexdec($nibble),
            str_split(strtolower($hex)),
        );
    }

    public static function toHex(mixed $value, int $maximumNibbles): ?string
    {
        if (!self::isCanonical($value, $maximumNibbles)) {
            return null;
        }

        return implode('', array_map(
            static fn (int $nibble): string => dechex($nibble),
            $value,
        ));
    }

    public static function toFixedHex(mixed $value, int $nibbles): ?string
    {
        if (!\is_array($value) || \count($value) !== $nibbles) {
            return null;
        }

        return self::toHex($value, $nibbles);
    }

    public static function isCanonical(mixed $value, int $maximumNibbles): bool
    {
        if ($maximumNibbles < 1
            || !\is_array($value)
            || !array_is_list($value)
            || $value === []
            || \count($value) > $maximumNibbles
        ) {
            return false;
        }
        foreach ($value as $nibble) {
            if (!\is_int($nibble) || $nibble < 0 || $nibble > 15) {
                return false;
            }
        }

        return true;
    }
}
