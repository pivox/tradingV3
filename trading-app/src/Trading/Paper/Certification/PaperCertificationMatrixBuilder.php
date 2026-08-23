<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification;

use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Mode\ModeContractValidator;
use App\TradingCore\Setup\SetupContractLoader;

final readonly class PaperCertificationMatrixBuilder
{
    private const INPUT_SCHEMA = 'paper-certification-matrix-input-v1';
    private const OUTPUT_SCHEMA = 'paper-certification-matrix-v1';
    private const MINIMUM_TRADES = 50;

    public function __construct(
        private ModeContractLoader $modes,
        private SetupContractLoader $setups,
    ) {
    }

    /**
     * @param array<string, mixed> $specification
     * @return array{
     *   schema_version:string,
     *   minimum_certified_trades_per_cell:int,
     *   cells_sha256:string,
     *   expected_cell_count_by_mode:array<string,int>,
     *   cells:list<array{paper_network:string,market_data_venue:string,mode_id:string,mode_version:string,setup_id:string,setup_version:string,canonical_side:string}>
     * }
     */
    public function build(array $specification): array
    {
        $this->assertExactKeys($specification, [
            'schema_version',
            'minimum_certified_trades_per_cell',
            'scopes',
            'mode_versions',
            'setup_versions',
        ], 'paper_certification_spec_shape_invalid');
        if ($specification['schema_version'] !== self::INPUT_SCHEMA) {
            throw new \InvalidArgumentException('paper_certification_spec_schema_unsupported');
        }
        if ($specification['minimum_certified_trades_per_cell'] !== self::MINIMUM_TRADES) {
            throw new \InvalidArgumentException('paper_certification_minimum_invalid');
        }

        $scopes = $this->scopes($specification['scopes']);
        $modeVersions = $this->stringMap($specification['mode_versions'], 'paper_certification_mode_versions_invalid');
        $expectedModeIds = ModeContractValidator::MODE_IDS;
        sort($expectedModeIds, SORT_STRING);
        $actualModeIds = array_keys($modeVersions);
        sort($actualModeIds, SORT_STRING);
        if ($actualModeIds !== $expectedModeIds) {
            throw new \InvalidArgumentException('paper_certification_mode_versions_incomplete');
        }

        $setupVersions = $this->stringMap($specification['setup_versions'], 'paper_certification_setup_versions_invalid');
        $requiredSetupIds = [];
        $modeContracts = [];
        foreach (ModeContractValidator::MODE_IDS as $modeId) {
            $mode = $this->modes->load($modeId, $modeVersions[$modeId]);
            if (!$mode->isExecutable()) {
                throw new \InvalidArgumentException('paper_certification_mode_not_executable');
            }
            $modeContracts[$modeId] = $mode;
            array_push($requiredSetupIds, ...$mode->compatibleSetupIds());
        }
        sort($requiredSetupIds, SORT_STRING);
        $specifiedSetupIds = array_keys($setupVersions);
        sort($specifiedSetupIds, SORT_STRING);
        if ($specifiedSetupIds !== $requiredSetupIds) {
            throw new \InvalidArgumentException('paper_certification_setup_versions_incomplete');
        }

        $cells = [];
        foreach ($modeContracts as $modeId => $mode) {
            foreach ($mode->compatibleSetupIds() as $setupId) {
                $setup = $this->setups->load($setupId, $setupVersions[$setupId]);
                if (!$setup->isExecutable()) {
                    continue;
                }
                $compatible = false;
                foreach ($setup->toArray()['compatible_modes'] as $identity) {
                    if (($identity['mode_id'] ?? null) === $modeId
                        && ($identity['mode_version'] ?? null) === $mode->modeVersion
                    ) {
                        $compatible = true;
                        break;
                    }
                }
                if (!$compatible) {
                    throw new \InvalidArgumentException('paper_certification_setup_mode_incompatible');
                }
                foreach ($scopes as $scope) {
                    $cells[] = $scope + [
                        'mode_id' => $modeId,
                        'mode_version' => $mode->modeVersion,
                        'setup_id' => $setupId,
                        'setup_version' => $setup->setupVersion,
                        'canonical_side' => $setup->side,
                    ];
                }
            }
        }

        usort($cells, static fn (array $left, array $right): int => strcmp(
            implode('|', array_values($left)),
            implode('|', array_values($right)),
        ));
        $byMode = [];
        foreach ($cells as $cell) {
            $byMode[$cell['mode_id']] = ($byMode[$cell['mode_id']] ?? 0) + 1;
        }
        ksort($byMode, SORT_STRING);

        $encodedCells = json_encode($cells, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return [
            'schema_version' => self::OUTPUT_SCHEMA,
            'minimum_certified_trades_per_cell' => self::MINIMUM_TRADES,
            'cells_sha256' => 'sha256:' . hash('sha256', $encodedCells),
            'expected_cell_count_by_mode' => $byMode,
            'cells' => $cells,
        ];
    }

    /** @return list<array{paper_network:string,market_data_venue:string}> */
    private function scopes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException('paper_certification_scopes_invalid');
        }
        $scopes = [];
        $seen = [];
        foreach ($value as $scope) {
            if (!is_array($scope) || array_is_list($scope)) {
                throw new \InvalidArgumentException('paper_certification_scope_invalid');
            }
            $this->assertExactKeys($scope, ['paper_network', 'market_data_venue'], 'paper_certification_scope_invalid');
            try {
                $network = PaperMarketDataNetwork::from($scope['paper_network']);
                $venue = PaperMarketDataVenue::from($scope['market_data_venue']);
            } catch (\TypeError|\ValueError) {
                throw new \InvalidArgumentException('paper_certification_scope_invalid');
            }
            if (!$network->isCertifiable() || ($venue === PaperMarketDataVenue::OKX && $network !== PaperMarketDataNetwork::MAINNET)) {
                throw new \InvalidArgumentException('paper_certification_scope_unsupported');
            }
            $key = $network->value . '|' . $venue->value;
            if (isset($seen[$key])) {
                throw new \InvalidArgumentException('paper_certification_scope_duplicate');
            }
            $seen[$key] = true;
            $scopes[] = [
                'paper_network' => $network->value,
                'market_data_venue' => $venue->value,
            ];
        }

        return $scopes;
    }

    /** @return array<string, string> */
    private function stringMap(mixed $value, string $error): array
    {
        if (!is_array($value) || array_is_list($value) || $value === []) {
            throw new \InvalidArgumentException($error);
        }
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '' || !is_string($item) || $item === '') {
                throw new \InvalidArgumentException($error);
            }
        }

        /** @var array<string, string> $value */
        return $value;
    }

    /**
     * @param array<mixed> $value
     * @param list<string> $expected
     */
    private function assertExactKeys(array $value, array $expected, string $error): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \InvalidArgumentException($error);
        }
    }
}
