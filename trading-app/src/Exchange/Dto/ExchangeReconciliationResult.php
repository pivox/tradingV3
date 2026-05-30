<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final readonly class ExchangeReconciliationResult
{
    /**
     * @param string[] $errors
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public ?string $symbol,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $completedAt,
        public int $ordersChecked = 0,
        public int $positionsChecked = 0,
        public int $fillsImported = 0,
        public int $correctionsApplied = 0,
        public int $staleOrdersClosed = 0,
        public int $unknownOrdersDetected = 0,
        public array $errors = [],
        public array $metadata = [],
    ) {
    }
}
