<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

use App\Trading\Paper\MarketData\CanonicalJson;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PaperCertificationCampaignRunner
{
    public const STATE_SCHEMA = 'paper-certification-campaign-state-v1';

    /** @var list<string> */
    private const CELL_FIELDS = [
        'paper_network',
        'market_data_venue',
        'mode_id',
        'mode_version',
        'setup_id',
        'setup_version',
        'canonical_side',
    ];

    public function __construct(
        private PaperCertificationCampaignProcessExecutorInterface $processes,
        private PaperCertificationCampaignStateStore $states,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDirectory,
        private string $phpBinary = PHP_BINARY,
    ) {
    }

    /**
     * @param array<string, mixed> $matrix
     * @param array<string, string> $datasets keyed by network/venue
     * @return array<string, mixed>
     */
    public function run(
        array $matrix,
        string $campaignId,
        #[\SensitiveParameter] string $configurationPath,
        #[\SensitiveParameter] array $datasets,
        #[\SensitiveParameter] string $statePath,
        int $timeoutSeconds,
    ): array {
        $cells = $this->validatedCells($matrix);
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,47}\z/D', $campaignId) !== 1) {
            throw new \InvalidArgumentException('paper_campaign_id_invalid');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 86_400) {
            throw new \InvalidArgumentException('paper_campaign_timeout_invalid');
        }
        $scopes = [];
        foreach ($cells as $cell) {
            $scopes[$this->scopeKey($cell)] = true;
        }
        $expectedScopes = array_keys($scopes);
        $actualScopes = array_keys($datasets);
        sort($expectedScopes, SORT_STRING);
        sort($actualScopes, SORT_STRING);
        if ($actualScopes !== $expectedScopes) {
            throw new \InvalidArgumentException('paper_campaign_dataset_scopes_mismatch');
        }

        $configurationSha256 = $this->fingerprintFile(
            $configurationPath,
            'paper_campaign_configuration_invalid',
            true,
        );
        $datasetEvidence = [];
        foreach ($expectedScopes as $scope) {
            $directory = $datasets[$scope];
            $this->assertDirectory($directory);
            $datasetEvidence[$scope] = [
                'manifest_sha256' => $this->fingerprintFile(
                    $directory . '/manifest.json',
                    'paper_campaign_dataset_invalid',
                ),
                'events_sha256' => $this->fingerprintFile(
                    $directory . '/events.ndjson',
                    'paper_campaign_dataset_invalid',
                ),
            ];
        }
        ksort($datasetEvidence, SORT_STRING);
        $inputsSha256 = 'sha256:' . hash('sha256', CanonicalJson::encode([
            'campaign_id' => $campaignId,
            'matrix_cells_sha256' => $matrix['cells_sha256'],
            'configuration_sha256' => $configurationSha256,
            'datasets' => $datasetEvidence,
        ]));

        $expectedCellStates = [];
        foreach ($cells as $cell) {
            $expectedCellStates[] = [
                'identity' => $cell,
                'run_id' => $this->runId(
                    $campaignId,
                    (string) $matrix['cells_sha256'],
                    $inputsSha256,
                    $cell,
                ),
                'status' => 'pending',
                'attempts' => 0,
                'readiness' => null,
                'blocker' => null,
            ];
        }
        $state = $this->states->load($statePath);
        if ($state === null) {
            $state = [
                'schema_version' => self::STATE_SCHEMA,
                'campaign_id' => $campaignId,
                'matrix_cells_sha256' => $matrix['cells_sha256'],
                'inputs_sha256' => $inputsSha256,
                'minimum_certified_trades_per_cell' => $matrix['minimum_certified_trades_per_cell'],
                'certification_status' => 'not_evaluated',
                'total_cells' => count($cells),
                'completed_cells' => 0,
                'status' => 'pending',
                'current_cell_index' => null,
                'blocker' => null,
                'cells' => $expectedCellStates,
            ];
            $this->states->save($statePath, $state);
        } else {
            $this->assertResumeState($state, $campaignId, $matrix, $inputsSha256, $expectedCellStates);
        }

        $state['status'] = 'running';
        $state['blocker'] = null;
        foreach ($cells as $index => $cell) {
            $state['current_cell_index'] = $index;
            $state['cells'][$index]['status'] = 'readiness_running';
            $state['cells'][$index]['attempts']++;
            $state['cells'][$index]['blocker'] = null;
            $state['completed_cells'] = $this->completedCount($state['cells']);
            $this->states->save($statePath, $state);

            $runId = $state['cells'][$index]['run_id'];
            $baseArguments = $this->cellArguments(
                $datasets[$this->scopeKey($cell)],
                $configurationPath,
                $cell,
                $runId,
            );
            $readiness = $this->processes->execute([
                $this->phpBinary,
                $this->projectDirectory . '/bin/console',
                'app:paper-market:runtime-check',
                ...$baseArguments,
            ], $timeoutSeconds);
            if ($readiness->timedOut) {
                return $this->fail($statePath, $state, $index, 'paper_campaign_process_timeout');
            }
            if ($readiness->exitCode !== 0) {
                return $this->fail(
                    $statePath,
                    $state,
                    $index,
                    $this->readinessBlocker($readiness->stdout) ?? 'paper_campaign_readiness_failed',
                );
            }
            $evidence = $this->readinessEvidence($readiness->stdout, $cell, $runId);
            if ($evidence === null) {
                return $this->fail($statePath, $state, $index, 'paper_campaign_readiness_identity_mismatch');
            }
            $state['cells'][$index]['readiness'] = $evidence;
            $state['cells'][$index]['status'] = 'replay_running';
            $this->states->save($statePath, $state);

            $replay = $this->processes->execute([
                $this->phpBinary,
                $this->projectDirectory . '/bin/console',
                'app:paper-market:replay',
                ...$baseArguments,
            ], $timeoutSeconds);
            if ($replay->timedOut) {
                return $this->fail($statePath, $state, $index, 'paper_campaign_process_timeout');
            }
            if ($replay->exitCode !== 0) {
                return $this->fail($statePath, $state, $index, 'paper_campaign_replay_failed');
            }
            $state['cells'][$index]['status'] = 'completed';
            $state['completed_cells'] = $this->completedCount($state['cells']);
            $this->states->save($statePath, $state);
        }

        $state['status'] = 'completed';
        $state['current_cell_index'] = null;
        $state['blocker'] = null;
        $state['completed_cells'] = count($cells);
        $this->states->save($statePath, $state);

        return $state;
    }

    /**
     * @param array<string, mixed> $matrix
     * @return list<array<string, string>>
     */
    private function validatedCells(array $matrix): array
    {
        $expected = ['schema_version', 'minimum_certified_trades_per_cell', 'cells_sha256', 'expected_cell_count_by_mode', 'cells'];
        $keys = array_keys($matrix);
        sort($expected, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($keys !== $expected || $matrix['schema_version'] !== 'paper-certification-matrix-v1'
            || $matrix['minimum_certified_trades_per_cell'] !== 50
            || !is_array($matrix['cells']) || !array_is_list($matrix['cells']) || $matrix['cells'] === []
        ) {
            throw new \InvalidArgumentException('paper_campaign_matrix_invalid');
        }
        $cells = [];
        foreach ($matrix['cells'] as $cell) {
            if (!is_array($cell) || array_is_list($cell) || array_keys($cell) !== self::CELL_FIELDS) {
                throw new \InvalidArgumentException('paper_campaign_matrix_invalid');
            }
            foreach (self::CELL_FIELDS as $field) {
                if (!is_string($cell[$field]) || $cell[$field] === '') {
                    throw new \InvalidArgumentException('paper_campaign_matrix_invalid');
                }
            }
            /** @var array<string, string> $cell */
            $cells[] = $cell;
        }
        $encoded = json_encode($cells, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($matrix['cells_sha256'] !== 'sha256:' . hash('sha256', $encoded)) {
            throw new \InvalidArgumentException('paper_campaign_matrix_digest_mismatch');
        }

        return $cells;
    }

    /** @param array<string, string> $cell */
    private function runId(string $campaignId, string $matrixHash, string $inputsHash, array $cell): string
    {
        $digest = hash('sha256', CanonicalJson::encode([
            'campaign_id' => $campaignId,
            'matrix_cells_sha256' => $matrixHash,
            'inputs_sha256' => $inputsHash,
            'cell' => $cell,
        ]));

        return 'paper132-' . $campaignId . '-' . substr($digest, 0, 16);
    }

    /**
     * @param array<string, string> $cell
     * @return list<string>
     */
    private function cellArguments(string $dataset, string $configuration, array $cell, string $runId): array
    {
        return [
            '--dataset=' . $dataset,
            '--configuration=' . $configuration,
            '--mode-id=' . $cell['mode_id'],
            '--mode-version=' . $cell['mode_version'],
            '--setup-id=' . $cell['setup_id'],
            '--setup-version=' . $cell['setup_version'],
            '--side=' . $cell['canonical_side'],
            '--run-id=' . $runId,
            '--no-interaction',
        ];
    }

    /**
     * @param array<string, string> $cell
     * @return array<string, string>|null
     */
    private function readinessEvidence(string $stdout, array $cell, string $runId): ?array
    {
        try {
            $payload = json_decode(trim($stdout), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload)
            || ($payload['schema_version'] ?? null) !== 'paper-replay-readiness-v1'
            || ($payload['ready'] ?? null) !== true
            || ($payload['runtime_ready'] ?? null) !== true
            || ($payload['baseline_eligible'] ?? null) !== true
            || ($payload['profile_eligibility'] ?? null) !== 'baseline_eligible'
            || ($payload['profile'] ?? null) !== $cell['mode_id']
            || ($payload['run_id'] ?? null) !== $runId
            || !is_array($payload['source'] ?? null)
            || ($payload['source']['kind'] ?? null) !== 'verified_public_replay'
            || ($payload['source']['network'] ?? null) !== $cell['paper_network']
            || ($payload['source']['venue'] ?? null) !== $cell['market_data_venue']
            || !is_array($payload['clock'] ?? null)
            || ($payload['clock']['type'] ?? null) !== 'paper_replay_clock'
            || ($payload['clock']['controlled'] ?? null) !== true
            || !is_array($payload['execution'] ?? null)
            || ($payload['execution']['mode'] ?? null) !== 'paper'
            || ($payload['execution']['exchange'] ?? null) !== 'fake'
            || ($payload['execution']['private_clients_enabled'] ?? null) !== false
            || ($payload['execution']['mainnet_write_enabled'] ?? null) !== false
            || ($payload['execution']['demo_testnet_write_enabled'] ?? null) !== false
            || !is_array($payload['strategy'] ?? null)
            || ($payload['strategy']['schema_version'] ?? null) !== 2
            || ($payload['strategy']['mode_id'] ?? null) !== $cell['mode_id']
            || ($payload['strategy']['mode_version'] ?? null) !== $cell['mode_version']
            || ($payload['strategy']['setup_id'] ?? null) !== $cell['setup_id']
            || ($payload['strategy']['setup_version'] ?? null) !== $cell['setup_version']
            || ($payload['strategy']['side'] ?? null) !== $cell['canonical_side']
        ) {
            return null;
        }
        $evidence = [
            'execution_cell_id' => $payload['execution_cell_id'] ?? null,
            'configuration_snapshot_id' => $payload['configuration_snapshot_id'] ?? null,
            'dataset_id' => $payload['source']['dataset_id'] ?? null,
            'events_file_sha256' => $payload['source']['events_file_sha256'] ?? null,
            'config_hash' => $payload['strategy']['config_hash'] ?? null,
            'condition_catalog_hash' => $payload['strategy']['condition_catalog_hash'] ?? null,
        ];
        foreach ($evidence as $key => $value) {
            $pattern = $key === 'events_file_sha256' ? '/\A[a-f0-9]{64}\z/D' : '/\Asha256:[a-f0-9]{64}\z/D';
            if ($key === 'dataset_id') {
                $pattern = '/\A[a-z0-9][a-z0-9._-]{2,127}\z/D';
            }
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                return null;
            }
        }

        /** @var array<string, string> $evidence */
        return $evidence;
    }

    private function readinessBlocker(string $stdout): ?string
    {
        try {
            $payload = json_decode(trim($stdout), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $blocker = is_array($payload) ? ($payload['blocker'] ?? null) : null;

        return is_string($blocker) && preg_match('/\A[a-z0-9_]{3,96}\z/D', $blocker) === 1 ? $blocker : null;
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $matrix
     * @param list<array<string, mixed>> $expectedCells
     */
    private function assertResumeState(array $state, string $campaignId, array $matrix, string $inputsSha256, array $expectedCells): void
    {
        $expectedStateKeys = [
            'schema_version',
            'campaign_id',
            'matrix_cells_sha256',
            'inputs_sha256',
            'minimum_certified_trades_per_cell',
            'certification_status',
            'total_cells',
            'completed_cells',
            'status',
            'current_cell_index',
            'blocker',
            'cells',
        ];
        $actualStateKeys = array_keys($state);
        sort($expectedStateKeys, SORT_STRING);
        sort($actualStateKeys, SORT_STRING);
        if ($actualStateKeys !== $expectedStateKeys) {
            throw new \LogicException('paper_campaign_state_invalid');
        }
        if (($state['schema_version'] ?? null) !== self::STATE_SCHEMA
            || ($state['campaign_id'] ?? null) !== $campaignId
            || ($state['matrix_cells_sha256'] ?? null) !== $matrix['cells_sha256']
            || ($state['inputs_sha256'] ?? null) !== $inputsSha256
            || ($state['minimum_certified_trades_per_cell'] ?? null) !== 50
            || ($state['certification_status'] ?? null) !== 'not_evaluated'
            || ($state['total_cells'] ?? null) !== count($expectedCells)
            || !is_array($state['cells'] ?? null)
            || count($state['cells']) !== count($expectedCells)
        ) {
            throw new \LogicException('paper_campaign_input_conflict');
        }
        if (!is_int($state['completed_cells'])
            || $state['completed_cells'] < 0
            || $state['completed_cells'] > count($expectedCells)
            || !in_array($state['status'], ['pending', 'running', 'completed', 'failed'], true)
            || ($state['current_cell_index'] !== null
                && (!is_int($state['current_cell_index'])
                    || $state['current_cell_index'] < 0
                    || $state['current_cell_index'] >= count($expectedCells)))
            || ($state['blocker'] !== null
                && (!is_string($state['blocker'])
                    || preg_match('/\A[a-z0-9_]{3,96}\z/D', $state['blocker']) !== 1))
        ) {
            throw new \LogicException('paper_campaign_state_invalid');
        }
        $expectedCellKeys = ['identity', 'run_id', 'status', 'attempts', 'readiness', 'blocker'];
        sort($expectedCellKeys, SORT_STRING);
        foreach ($expectedCells as $index => $expected) {
            $actual = $state['cells'][$index] ?? null;
            $actualCellKeys = is_array($actual) ? array_keys($actual) : [];
            sort($actualCellKeys, SORT_STRING);
            if (!is_array($actual)
                || $actualCellKeys !== $expectedCellKeys
                || ($actual['identity'] ?? null) !== $expected['identity']
                || ($actual['run_id'] ?? null) !== $expected['run_id']
                || !is_int($actual['attempts'] ?? null)
                || $actual['attempts'] < 0
                || !in_array($actual['status'] ?? null, ['pending', 'readiness_running', 'replay_running', 'completed', 'failed'], true)
                || (($actual['blocker'] ?? null) !== null
                    && (!is_string($actual['blocker'])
                        || preg_match('/\A[a-z0-9_]{3,96}\z/D', $actual['blocker']) !== 1))
                || !$this->persistedReadinessIsValid($actual['readiness'] ?? null)
            ) {
                throw new \LogicException('paper_campaign_state_invalid');
            }
        }
        $completed = $this->completedCount($state['cells']);
        if ($state['completed_cells'] !== $completed
            || ($state['status'] === 'completed' && $completed !== count($expectedCells))
        ) {
            throw new \LogicException('paper_campaign_state_invalid');
        }
    }

    private function persistedReadinessIsValid(mixed $readiness): bool
    {
        if ($readiness === null) {
            return true;
        }
        if (!is_array($readiness) || array_is_list($readiness)) {
            return false;
        }
        $expectedKeys = [
            'execution_cell_id',
            'configuration_snapshot_id',
            'dataset_id',
            'events_file_sha256',
            'config_hash',
            'condition_catalog_hash',
        ];
        $keys = array_keys($readiness);
        sort($expectedKeys, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($keys !== $expectedKeys) {
            return false;
        }
        foreach ($readiness as $key => $value) {
            $pattern = $key === 'events_file_sha256' ? '/\A[a-f0-9]{64}\z/D' : '/\Asha256:[a-f0-9]{64}\z/D';
            if ($key === 'dataset_id') {
                $pattern = '/\A[a-z0-9][a-z0-9._-]{2,127}\z/D';
            }
            if (!is_string($value) || preg_match($pattern, $value) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function fail(string $statePath, array $state, int $index, string $blocker): array
    {
        $state['status'] = 'failed';
        $state['blocker'] = $blocker;
        $state['current_cell_index'] = $index;
        $state['cells'][$index]['status'] = 'failed';
        $state['cells'][$index]['blocker'] = $blocker;
        $state['completed_cells'] = $this->completedCount($state['cells']);
        $this->states->save($statePath, $state);

        return $state;
    }

    /** @param list<array<string, mixed>> $cells */
    private function completedCount(array $cells): int
    {
        return count(array_filter($cells, static fn (array $cell): bool => $cell['status'] === 'completed'));
    }

    /** @param array<string, string> $cell */
    private function scopeKey(array $cell): string
    {
        return $cell['paper_network'] . '/' . $cell['market_data_venue'];
    }

    private function assertDirectory(string $directory): void
    {
        if (!str_starts_with($directory, DIRECTORY_SEPARATOR)
            || !is_dir($directory)
            || !is_readable($directory)
            || realpath($directory) !== $directory
        ) {
            throw new \RuntimeException('paper_campaign_dataset_invalid');
        }
        $this->assertNoSymlinkComponents($directory, 'paper_campaign_dataset_invalid');
    }

    private function fingerprintFile(string $path, string $error, bool $private = false): string
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException($error);
        }
        $this->assertNoSymlinkComponents($path, $error);
        $before = @lstat($path);
        if ($before === false
            || ($before['mode'] & 0170000) !== 0100000
            || ($private && (($before['mode'] & 0077) !== 0 || $before['size'] < 2 || $before['size'] > 1_048_576))
        ) {
            throw new \RuntimeException($error);
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException($error);
        }
        try {
            $opened = fstat($handle);
            $context = hash_init('sha256');
            $bytes = hash_update_stream($context, $handle);
            $after = @lstat($path);
            if ($opened === false
                || ($opened['mode'] & 0170000) !== 0100000
                || $opened['dev'] !== $before['dev']
                || $opened['ino'] !== $before['ino']
                || $opened['size'] !== $before['size']
                || $bytes !== $opened['size']
                || $after === false
                || $after['dev'] !== $opened['dev']
                || $after['ino'] !== $opened['ino']
                || $after['size'] !== $opened['size']
            ) {
                throw new \RuntimeException($error);
            }
            $hash = hash_final($context);
        } finally {
            fclose($handle);
        }

        return $hash;
    }

    private function assertNoSymlinkComponents(string $path, string $error): void
    {
        $current = DIRECTORY_SEPARATOR;
        foreach (array_filter(explode(DIRECTORY_SEPARATOR, $path)) as $part) {
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new \RuntimeException($error);
            }
        }
    }
}
