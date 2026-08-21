<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Identity;

use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;

final readonly class PaperExecutionCell
{
    private const LEGACY_SCHEMA_VERSION = 1;
    private const MODERN_SCHEMA_VERSION = 2;

    private function __construct(
        public string $id,
        public string $accountNamespace,
        public PaperMarketDataNetwork $network,
        public PaperMarketDataVenue $marketDataVenue,
        public string $configurationSnapshotId,
        public string $strategyProfile,
        public string $runId,
        public ?PaperModernStrategyIdentity $modernIdentity,
    ) {
    }

    public static function create(
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        string $configurationSnapshotId,
        string $strategyProfile,
        string $runId,
    ): self {
        self::assertScope($network, $venue, $configurationSnapshotId, $runId);

        (new PaperProfileRegistry())->require($strategyProfile);

        $digest = hash('sha256', CanonicalJson::encode([
            'schema_version' => self::LEGACY_SCHEMA_VERSION,
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
            modernIdentity: null,
        );
    }

    public static function createModern(
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        string $configurationSnapshotId,
        PaperModernStrategyIdentity $identity,
        string $runId,
    ): self {
        self::assertScope($network, $venue, $configurationSnapshotId, $runId);
        if ($identity->network !== $network || $identity->marketDataVenue !== $venue) {
            throw new \InvalidArgumentException('paper_modern_identity_market_scope_mismatch');
        }

        $digest = hash('sha256', CanonicalJson::encode([
            'schema_version' => self::MODERN_SCHEMA_VERSION,
            'network' => $network->value,
            'market_data_venue' => $venue->value,
            'configuration_snapshot_id' => $configurationSnapshotId,
            'mode_id' => $identity->modeId,
            'mode_version' => $identity->modeVersion,
            'setup_id' => $identity->setupId,
            'setup_version' => $identity->setupVersion,
            'side' => $identity->side,
            'config_hash' => $identity->configHash,
            'condition_catalog_hash' => $identity->conditionCatalogHash,
            'run_id' => $runId,
        ]));

        return new self(
            id: 'sha256:' . $digest,
            accountNamespace: 'paper:cell:v2:' . $digest,
            network: $network,
            marketDataVenue: $venue,
            configurationSnapshotId: $configurationSnapshotId,
            strategyProfile: $identity->modeId,
            runId: $runId,
            modernIdentity: $identity,
        );
    }

    public function isModern(): bool
    {
        return $this->modernIdentity !== null;
    }

    /** @return array<string, string> */
    public function provenance(PaperProfileEligibility $eligibility): array
    {
        if (!$this->isModern() && (new PaperProfileRegistry())->require($this->strategyProfile) !== $eligibility) {
            throw new \InvalidArgumentException('paper_execution_cell_eligibility_conflict');
        }

        $provenance = [
            'paper_network' => $this->network->value,
            'market_data_venue' => $this->marketDataVenue->value,
            'paper_execution_cell_id' => $this->id,
            'configuration_snapshot_id' => $this->configurationSnapshotId,
            'paper_eligibility' => $eligibility->value,
            'strategy_profile' => $this->strategyProfile,
            'run_id' => $this->runId,
            'exchange' => 'fake',
        ];
        if ($this->modernIdentity === null) {
            return $provenance;
        }

        return $provenance + [
            'mode_id' => $this->modernIdentity->modeId,
            'mode_version' => $this->modernIdentity->modeVersion,
            'setup_id' => $this->modernIdentity->setupId,
            'setup_version' => $this->modernIdentity->setupVersion,
            'side' => $this->modernIdentity->side,
            'config_hash' => $this->modernIdentity->configHash,
            'condition_catalog_hash' => $this->modernIdentity->conditionCatalogHash,
        ];
    }

    private static function assertScope(
        PaperMarketDataNetwork $network,
        PaperMarketDataVenue $venue,
        string $configurationSnapshotId,
        string $runId,
    ): void {
        if ($network === PaperMarketDataNetwork::LEGACY_UNKNOWN) {
            throw new \InvalidArgumentException('paper_execution_network_unsupported');
        }
        if ($venue === PaperMarketDataVenue::OKX && $network !== PaperMarketDataNetwork::MAINNET) {
            throw new \InvalidArgumentException('paper_execution_network_venue_unsupported');
        }
        if (!preg_match('/\Asha256:[a-f0-9]{64}\z/D', $configurationSnapshotId)) {
            throw new \InvalidArgumentException('paper_configuration_snapshot_id_invalid');
        }
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,95}\z/D', $runId)) {
            throw new \InvalidArgumentException('paper_execution_run_id_invalid');
        }
    }
}
