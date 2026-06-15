<?php
declare(strict_types=1);

namespace App\TradingCore\Entry\Mapper;

use App\TradeEntry\Dto\EntryZone as LegacyEntryZone;
use App\TradingCore\Entry\Dto\EntryZone;

final class LegacyEntryZoneMapper
{
    public function fromLegacy(LegacyEntryZone $legacy): EntryZone
    {
        $center = ($legacy->min + $legacy->max) / 2;
        $metadata = $legacy->getMetadata();
        $widthPct = isset($metadata['width_pct']) && \is_numeric($metadata['width_pct'])
            ? (float)$metadata['width_pct']
            : ($center > 0.0 ? ($legacy->max - $legacy->min) / $center : 0.0);
        $source = isset($metadata['pivot_source']) && \is_string($metadata['pivot_source'])
            ? (string)$metadata['pivot_source']
            : 'legacy';
        $atrUsed = isset($metadata['atr']) && \is_numeric($metadata['atr']) ? (float)$metadata['atr'] : null;
        $expiresAt = null;
        if ($legacy->createdAt !== null && $legacy->ttlSec !== null) {
            $expiresAt = $legacy->createdAt->modify(sprintf('+%d seconds', $legacy->ttlSec));
        }

        return new EntryZone(
            low: $legacy->min,
            high: $legacy->max,
            center: $center,
            widthPct: $widthPct,
            ttlSec: $legacy->ttlSec,
            expiresAt: $expiresAt,
            source: $source,
            atrUsed: $atrUsed,
            quantized: (bool)($metadata['quantized'] ?? false),
            metadata: $metadata + [
                'legacy_rationale' => $legacy->rationale,
            ],
        );
    }
}
