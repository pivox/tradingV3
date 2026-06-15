<?php
declare(strict_types=1);

namespace App\TradingCore\Entry\Service;

use App\TradingCore\Entry\Dto\EntryZone;
use App\TradingCore\Entry\Dto\EntryZoneDecision;
use App\TradingCore\Entry\Enum\EntryZoneStatus;

final class EntryZoneGuard
{
    /**
     * @param array<string,mixed> $metadata
     */
    public function decide(
        EntryZone $entryZone,
        float $candidatePrice,
        ?float $referencePrice,
        ?float $zoneMaxDevPct,
        ?string $reasonIfRejected = null,
        array $metadata = [],
    ): EntryZoneDecision {
        $normalizedZoneMax = $zoneMaxDevPct !== null ? $this->normalizePercent($zoneMaxDevPct) : null;
        $zoneDevPct = $this->computeZoneDeviation($referencePrice, $entryZone);
        $accepted = $entryZone->contains($candidatePrice)
            && ($normalizedZoneMax === null || $zoneDevPct === null || $zoneDevPct <= $normalizedZoneMax);

        return new EntryZoneDecision(
            status: $accepted ? EntryZoneStatus::Accepted : EntryZoneStatus::Rejected,
            entryZone: $entryZone,
            candidatePrice: $candidatePrice,
            zoneDevPct: $zoneDevPct,
            zoneMaxDevPct: $normalizedZoneMax,
            reasonIfRejected: $accepted ? null : ($reasonIfRejected ?? 'entry_not_within_zone'),
            metadata: $metadata,
        );
    }

    private function computeZoneDeviation(?float $referencePrice, EntryZone $entryZone): ?float
    {
        if ($referencePrice === null || $referencePrice <= 0.0) {
            return null;
        }

        return max(
            abs($entryZone->low - $referencePrice),
            abs($entryZone->high - $referencePrice),
        ) / $referencePrice;
    }

    private function normalizePercent(float $value): float
    {
        $value = max(0.0, $value);
        if ($value > 1.0) {
            $value *= 0.01;
        }

        return min($value, 1.0);
    }
}
