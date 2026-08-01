<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Identity;

use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;

final readonly class PaperExecutionCell
{
    private const SCHEMA_VERSION = 1;

    private function __construct(
        public string $id,
        public string $accountNamespace,
        public PaperMarketDataNetwork $network,
        public PaperMarketDataVenue $marketDataVenue,
        public string $configurationSnapshotId,
        public string $strategyProfile,
        public string $runId,
    ) {
    }

    public static function create(
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        string $configurationSnapshotId,
        string $strategyProfile,
        string $runId,
    ): self {
        if ($network === PaperMarketDataNetwork::LEGACY_UNKNOWN) {
            throw new \InvalidArgumentException('paper_execution_network_unsupported');
        }

        if ($venue === PaperMarketDataVenue::OKX && $network !== PaperMarketDataNetwork::MAINNET) {
            throw new \InvalidArgumentException('paper_execution_network_venue_unsupported');
        }

        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $configurationSnapshotId)) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_id_invalid');
        }

        (new PaperProfileRegistry())->require($strategyProfile);

        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $runId)) {
            throw new \InvalidArgumentException('paper_execution_run_id_invalid');
        }

        $digest = hash('sha256', CanonicalJson::encode([
            'schema_version' => self::SCHEMA_VERSION,
            'network' => $network->value,
            'market_data_venue' => $venue->value,
            'configuration_snapshot_id' => $configurationSnapshotId,
            'strategy_profile' => $strategyProfile,
            'run_id' => $runId,
        ]));

        return new self(
            id: 'sha256:' . $digest,
            accountNamespace: 'paper:cell:v1:' . $digest,
            network: $network,
            marketDataVenue: $venue,
            configurationSnapshotId: $configurationSnapshotId,
            strategyProfile: $strategyProfile,
            runId: $runId,
        );
    }
}
