<?php

declare(strict_types=1);

namespace App\Trading\Lineage\ReadModel;

final readonly class LineageReadCriteria
{
    public const MAX_LIMIT = 100;

    private const VENUE_IDENTIFIER_KINDS = [
        'client_order_id' => true,
        'exchange_order_id' => true,
        'position_id' => true,
    ];

    private function __construct(
        public string $kind,
        public string $value,
        public ?string $exchange,
        public ?string $marketType,
        public int $limit,
        public int $offset,
    ) {
    }

    public static function forIdentifier(string $kind, string $value, int $limit, int $offset): self
    {
        return new self(
            kind: strtolower(trim($kind)),
            value: trim($value),
            exchange: null,
            marketType: null,
            limit: self::normalizeLimit($limit),
            offset: max(0, $offset),
        );
    }

    public static function forVenueIdentifier(
        string $kind,
        string $value,
        string $exchange,
        string $marketType,
        int $limit,
        int $offset,
    ): self {
        return new self(
            kind: strtolower(trim($kind)),
            value: trim($value),
            exchange: strtolower(trim($exchange)),
            marketType: strtolower(trim($marketType)),
            limit: self::normalizeLimit($limit),
            offset: max(0, $offset),
        );
    }

    public function requiresVenue(): bool
    {
        return isset(self::VENUE_IDENTIFIER_KINDS[$this->kind]);
    }

    public function isConflictSensitive(): bool
    {
        return isset(self::VENUE_IDENTIFIER_KINDS[$this->kind]);
    }

    private static function normalizeLimit(int $limit): int
    {
        return min(self::MAX_LIMIT, max(1, $limit));
    }
}
