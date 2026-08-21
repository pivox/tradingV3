<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

use App\Entity\PaperExecutionProvenanceAwareInterface;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\Identity\PaperModernStrategyIdentity;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;

final class PaperExecutionProvenance
{
    /** @var list<string> */
    public const KEYS = [
        'paper_network',
        'market_data_venue',
        'paper_execution_cell_id',
        'configuration_snapshot_id',
        'paper_eligibility',
        'strategy_profile',
        'run_id',
        'exchange',
    ];

    /** @var list<string> */
    public const MODERN_KEYS = [
        ...self::KEYS,
        'mode_id',
        'mode_version',
        'setup_id',
        'setup_version',
        'side',
        'config_hash',
        'condition_catalog_hash',
    ];

    /** @var list<string> */
    private const MODERN_MARKER_KEYS = [
        'mode_id',
        'mode_version',
        'setup_id',
        'setup_version',
        'condition_catalog_hash',
    ];

    /** @var list<string> */
    private const MARKER_KEYS = [
        'paper_network',
        'paper_execution_cell_id',
        'configuration_snapshot_id',
        'paper_eligibility',
    ];

    /**
     * @param array<string, mixed> $source
     * @return array<string, string>|null
     */
    public static function extract(array $source): ?array
    {
        if (array_intersect(self::MARKER_KEYS, array_keys($source)) === []) {
            return null;
        }

        $candidate = [];
        $keys = array_intersect(self::MODERN_MARKER_KEYS, array_keys($source)) === []
            ? self::KEYS
            : self::MODERN_KEYS;
        foreach ($keys as $key) {
            $candidate[$key] = $source[$key] ?? null;
        }

        return self::validate($candidate);
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, string>
     */
    public static function validate(array $candidate): array
    {
        $modern = array_keys($candidate) === self::MODERN_KEYS;
        if (!$modern && array_keys($candidate) !== self::KEYS) {
            throw new \InvalidArgumentException('paper_execution_provenance_invalid');
        }
        foreach ($candidate as $value) {
            if (!is_string($value) || $value === '') {
                throw new \InvalidArgumentException('paper_execution_provenance_invalid');
            }
        }

        try {
            $network = PaperMarketDataNetwork::from($candidate['paper_network']);
            $venue = PaperMarketDataVenue::from($candidate['market_data_venue']);
            $eligibility = PaperProfileEligibility::from($candidate['paper_eligibility']);
            if ($modern) {
                $identity = PaperModernStrategyIdentity::fromDurableIdentity(
                    $network,
                    $venue,
                    $candidate['mode_id'],
                    $candidate['mode_version'],
                    $candidate['setup_id'],
                    $candidate['setup_version'],
                    $candidate['side'],
                    $candidate['config_hash'],
                    $candidate['condition_catalog_hash'],
                );
                $cell = PaperExecutionCell::createModern(
                    $network,
                    $venue,
                    $candidate['configuration_snapshot_id'],
                    $identity,
                    $candidate['run_id'],
                );
            } else {
                $cell = PaperExecutionCell::create(
                    $network,
                    $venue,
                    $candidate['configuration_snapshot_id'],
                    $candidate['strategy_profile'],
                    $candidate['run_id'],
                );
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException('paper_execution_provenance_invalid');
        }

        if ($candidate['exchange'] !== 'fake'
            || $network === PaperMarketDataNetwork::LEGACY_UNKNOWN
            || $cell->id !== $candidate['paper_execution_cell_id']
            || ($modern
                ? $candidate['strategy_profile'] !== $cell->modernIdentity?->modeId
                : (new PaperProfileRegistry())->require($cell->strategyProfile) !== $eligibility)
        ) {
            throw new \InvalidArgumentException('paper_execution_provenance_invalid');
        }

        /** @var array<string, string> $candidate */
        return $candidate;
    }

    /** @param array<string, mixed> $candidate */
    public static function assertMatches(PaperExecutionProvenanceAwareInterface $fact, array $candidate): void
    {
        $provenance = self::validate($candidate);
        if ($fact->getExchange() !== $provenance['exchange']
            || $fact->getMarketDataVenue() !== $provenance['market_data_venue']
            || $fact->getPaperNetwork() !== $provenance['paper_network']
            || $fact->getPaperExecutionCellId() !== $provenance['paper_execution_cell_id']
            || $fact->getConfigurationSnapshotId() !== $provenance['configuration_snapshot_id']
            || $fact->getPaperEligibility() !== $provenance['paper_eligibility']
        ) {
            throw new \LogicException('paper_execution_provenance_conflict');
        }
    }
}
