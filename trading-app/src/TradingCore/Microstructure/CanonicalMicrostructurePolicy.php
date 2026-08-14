<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

final readonly class CanonicalMicrostructurePolicy
{
    public const SCHEMA_VERSION = 'canonical-microstructure-policy.v1';

    public function __construct(
        public int $windowSeconds,
        public int $maximumBookAgeSeconds,
        public int $maximumTradeAgeSeconds,
        public int $maximumTradeGapSeconds,
        public int $minimumTradeCount,
    ) {
        if (
            $windowSeconds < 1 || $windowSeconds > 3600
            || $maximumBookAgeSeconds < 1 || $maximumBookAgeSeconds > $windowSeconds
            || $maximumTradeAgeSeconds < 1 || $maximumTradeAgeSeconds > $windowSeconds
            || $maximumTradeGapSeconds < 1 || $maximumTradeGapSeconds > $windowSeconds
            || $minimumTradeCount < 1 || $minimumTradeCount > 100_000
        ) {
            throw new CanonicalMicrostructureException('canonical_microstructure_policy_invalid');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'window_seconds' => $this->windowSeconds,
            'maximum_book_age_seconds' => $this->maximumBookAgeSeconds,
            'maximum_trade_age_seconds' => $this->maximumTradeAgeSeconds,
            'maximum_trade_gap_seconds' => $this->maximumTradeGapSeconds,
            'minimum_trade_count' => $this->minimumTradeCount,
        ];
    }
}
