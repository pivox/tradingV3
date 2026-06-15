<?php
declare(strict_types=1);

namespace App\TradingCore\Entry\Dto;

use App\TradingCore\Entry\Enum\EntryZoneStatus;

final readonly class EntryZoneDecision
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function __construct(
        public EntryZoneStatus $status,
        public EntryZone $entryZone,
        public float $candidatePrice,
        public ?float $zoneDevPct,
        public ?float $zoneMaxDevPct,
        public ?string $reasonIfRejected,
        public array $metadata = [],
    ) {}
}
