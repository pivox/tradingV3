<?php

declare(strict_types=1);

namespace App\TradeEntry\Dto;

final class ZoneSkipEventDto
{
    public const REASON = 'skipped_out_of_zone';

    /**
     * @param array<string,mixed> $mtfContext
     */
    public function __construct(
        public readonly string $symbol,
        public readonly \DateTimeImmutable $happenedAt,
        public readonly ?string $decisionKey,
        public readonly ?string $timeframe,
        public readonly ?string $configProfile,
        public readonly float $zoneMin,
        public readonly float $zoneMax,
        public readonly float $candidatePrice,
        public readonly float $zoneDevPct,
        public readonly float $zoneMaxDevPct,
        public readonly ?float $atrPct = null,
        public readonly ?float $spreadBps = null,
        public readonly ?float $volumeRatio = null,
        public readonly ?float $vwapDistancePct = null,
        public readonly ?float $entryZoneWidthPct = null,
        public readonly array $mtfContext = [],
        public readonly ?string $mtfLevel = null,
        public readonly ?float $proposedZoneMaxPct = null,
        public readonly ?string $category = null,
        public readonly string $reason = self::REASON,
    ) {}
}
