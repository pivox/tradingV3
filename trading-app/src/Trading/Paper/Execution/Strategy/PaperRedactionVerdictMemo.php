<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\PaperIdentifierAwareRedaction;

/**
 * Process-wide memo of strategy observation payloads that PaperIdentifierAwareRedaction::assertSafe()
 * (site strategy_observation, #132 decision e) accepted.
 * assertSafe() is a pure function of its input: an identical payload is accepted again
 * without rescanning it. Rejections are never memoized and always rethrown by the scan.
 *
 * @internal
 */
final class PaperRedactionVerdictMemo
{
    private const MAXIMUM_ENTRIES = 4096;

    /** @var array<string, true> */
    private static array $accepted = [];

    /** @param array<array-key, mixed> $payload */
    public static function assertSafe(#[\SensitiveParameter] array $payload): void
    {
        $key = hash('sha256', serialize($payload));
        if (isset(self::$accepted[$key])) {
            return;
        }
        PaperIdentifierAwareRedaction::assertSafe($payload, PaperIdentifierAwareRedaction::SITE_STRATEGY_OBSERVATION);
        if (\count(self::$accepted) >= self::MAXIMUM_ENTRIES) {
            self::$accepted = [];
        }
        self::$accepted[$key] = true;
    }
}
