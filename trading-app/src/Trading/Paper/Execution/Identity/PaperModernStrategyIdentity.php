<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Identity;

use App\Trading\Lineage\CanonicalEffectiveConfigSnapshot;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;

final readonly class PaperModernStrategyIdentity
{
    private function __construct(
        public PaperMarketDataNetwork $network,
        public PaperMarketDataVenue $marketDataVenue,
        public string $modeId,
        public string $modeVersion,
        public string $setupId,
        public string $setupVersion,
        public string $side,
        public string $configHash,
        public string $conditionCatalogHash,
    ) {
    }

    public static function fromResolvedSnapshot(
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $marketDataVenue,
        EffectiveTradingConfigSnapshot $snapshot,
    ): self {
        $request = $snapshot->request;
        if ($request->capability !== ShadowExecutionCapability::Paper) {
            throw new \InvalidArgumentException('paper_modern_identity_capability_invalid');
        }
        if ($network === PaperMarketDataNetwork::LEGACY_UNKNOWN
            || $request->exchange !== $marketDataVenue->value
            || $request->environment !== $network->value
        ) {
            throw new \InvalidArgumentException('paper_modern_identity_market_scope_mismatch');
        }
        if (!$snapshot->executable || $snapshot->blockers !== []) {
            throw new \InvalidArgumentException('paper_modern_identity_snapshot_not_executable');
        }
        $catalogHash = $snapshot->conditionCatalogHash;
        if (!is_string($catalogHash)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $snapshot->configHash) !== 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $catalogHash) !== 1
        ) {
            throw new \InvalidArgumentException('paper_modern_identity_snapshot_invalid');
        }

        try {
            CanonicalEffectiveConfigSnapshot::fromArray($snapshot->toArray(), [
                ...$request->toArray(),
                'config_hash' => $snapshot->configHash,
                'condition_catalog_hash' => $catalogHash,
            ]);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('paper_modern_identity_snapshot_invalid', 0, $exception);
        }

        return new self(
            $network,
            $marketDataVenue,
            $request->modeId,
            $request->modeVersion,
            $request->setupId,
            $request->setupVersion,
            $request->side,
            $snapshot->configHash,
            $catalogHash,
        );
    }
}
