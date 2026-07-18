<?php

declare(strict_types=1);

namespace App\Exchange\Dto;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Enum\ExchangePositionSide;

final readonly class ExchangeFundingDto
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public string $symbol,
        public ExchangePositionSide $positionSide,
        public string $positionId,
        public ?string $internalTradeId,
        public ?string $internalPositionId,
        public string $notional,
        public string $fundingRate,
        public int $rateIntervalSeconds,
        public int $appliedIntervalSeconds,
        public string $amount,
        public string $currency,
        public ?string $amountUsdt,
        public \DateTimeImmutable $dueAt,
        public string $source,
        public string $modelVersion,
        public array $metadata = [],
    ) {
    }
}
