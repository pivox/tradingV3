<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Live;

final class OkxPaperAcknowledgedIdentityEntry
{
    private const SHA256_PATTERN = '/\A[a-f0-9]{64}\z/D';

    /**
     * @param array{string, string, string, string} $entry
     */
    public static function compact(array $entry): string
    {
        [$identity, $overlap, $rest, $webSocket] = self::validated($entry);
        $missing = OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST;
        if ($rest === $missing) {
            $origin = 'w';
        } elseif ($webSocket === $missing) {
            $origin = 'r';
        } else {
            $origin = 'b';
        }
        $digests = [$identity, $overlap, $rest, $webSocket];
        $binary = implode('', array_map(
            static fn (string $digest): string => $digest === $missing
                ? str_repeat("\0", 32)
                : (hex2bin($digest) ?: throw self::invalid()),
            $digests,
        ));

        return 'v1:' . $origin . ':' . base64_encode($binary);
    }

    /**
     * @param array<array-key, mixed>|string $entry
     * @return array{string, string, string, string}
     */
    public static function expand(array|string $entry): array
    {
        if (\is_array($entry)) {
            return self::validated($entry);
        }
        if (preg_match('/\Av1:([rwb]):([A-Za-z0-9+\/]+={0,2})\z/D', $entry, $matches) !== 1) {
            throw self::invalid();
        }
        $binary = base64_decode($matches[2], true);
        if (!\is_string($binary)
            || \strlen($binary) !== 128
            || !hash_equals($matches[2], base64_encode($binary))
        ) {
            throw self::invalid();
        }
        $digests = array_map(
            static fn (string $chunk): string => bin2hex($chunk),
            str_split($binary, 32),
        );
        $missing = OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST;
        $expanded = match ($matches[1]) {
            'r' => [$digests[0], $digests[1], $digests[2], $missing],
            'w' => [$digests[0], $digests[1], $missing, $digests[3]],
            'b' => [$digests[0], $digests[1], $digests[2], $digests[3]],
        };
        $missingChunk = str_repeat('0', 64);
        if (($matches[1] === 'r' && $digests[3] !== $missingChunk)
            || ($matches[1] === 'w' && $digests[2] !== $missingChunk)
            || ($matches[1] === 'b'
                && ($digests[2] === $missingChunk || $digests[3] === $missingChunk))
        ) {
            throw self::invalid();
        }

        return self::validated($expanded);
    }

    /**
     * @param array<array-key, mixed> $entry
     * @return array{string, string, string, string}
     */
    private static function validated(array $entry): array
    {
        if (!array_is_list($entry) || \count($entry) !== 4) {
            throw self::invalid();
        }
        [$identity, $overlap, $rest, $webSocket] = $entry;
        $missing = OkxPaperLiveCheckpoint::MISSING_CANONICAL_DIGEST;
        if (!\is_string($identity)
            || !\is_string($overlap)
            || !\is_string($rest)
            || !\is_string($webSocket)
            || preg_match(self::SHA256_PATTERN, $identity) !== 1
            || preg_match(self::SHA256_PATTERN, $overlap) !== 1
            || (preg_match(self::SHA256_PATTERN, $rest) !== 1 && $rest !== $missing)
            || (preg_match(self::SHA256_PATTERN, $webSocket) !== 1 && $webSocket !== $missing)
            || ($rest === $missing && $webSocket === $missing)
        ) {
            throw self::invalid();
        }

        return [$identity, $overlap, $rest, $webSocket];
    }

    private static function invalid(): \InvalidArgumentException
    {
        return new \InvalidArgumentException(
            'okx_paper_acknowledged_identity_entry_invalid',
        );
    }
}
