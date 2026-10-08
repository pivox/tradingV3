<?php

declare(strict_types=1);

namespace App\Trading\Paper\Certification\Campaign;

use App\Trading\Paper\Dataset\PaperDatasetReceiptDescriberInterface;
use App\Trading\Paper\Dataset\PaperDatasetVerificationReceipt;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\Replay\PaperReplayReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PaperCertificationCampaignRunner
{
    public const STATE_SCHEMA = 'paper-certification-campaign-state-v2';

    /**
     * Each child's memory limit, whatever the host php.ini says (128M by default).
     * A cell child holds at most one O(events) structure at a time: the verifier's
     * identity set, then the replay order index (~150 bytes per event, measured). At
     * PaperReplayReader::DEFAULT_EVENT_LIMIT events that stays under half this limit.
     * The campaign process itself never verifies or indexes a dataset: the one full
     * verification of each dataset runs in a receipt child with this same limit.
     */
    public const CHILD_MEMORY_LIMIT = '3072M';

    public const MAX_PARALLEL_CELLS = 8;

    private const COMPLETION_SCHEMA = 'paper-replay-completion-v1';
    private const RECEIPT_RESULT_SCHEMA = 'paper-dataset-receipt-result-v1';
    private const POLL_MICROSECONDS = 200_000;

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
        private PaperDatasetReceiptDescriberInterface $receipts = new PaperDatasetVerificationReceipt(),
        private int $eventLimit = PaperReplayReader::DEFAULT_EVENT_LIMIT,
        private int $pollMicroseconds = self::POLL_MICROSECONDS,
    ) {
    }

    /**
     * @param array<string, mixed> $matrix
     * @param array<string, string> $datasets keyed by network/venue
     * @param (\Closure(): bool)|null $stopRequested polled between child state changes; true
     *        stops every running child and leaves the campaign failed/interrupted
     * @param PaperCertificationCampaignCellDatabases|null $cellDatabases one dedicated database
     *        per cell, in cell order; null: every cell uses the campaign process DATABASE_URL
     * @return array<string, mixed>
     */
    public function run(
        array $matrix,
        string $campaignId,
        #[\SensitiveParameter] string $configurationPath,
        #[\SensitiveParameter] array $datasets,
        #[\SensitiveParameter] string $statePath,
        int $timeoutSeconds,
        int $maxParallelCells = 1,
        ?\Closure $stopRequested = null,
        #[\SensitiveParameter] ?PaperCertificationCampaignCellDatabases $cellDatabases = null,
    ): array {
        $cells = $this->validatedCells($matrix);
        if ($cellDatabases !== null) {
            if (!$this->processes instanceof PaperCertificationCampaignEnvironmentProcessExecutorInterface) {
                throw new \InvalidArgumentException('paper_campaign_cell_databases_unsupported');
            }
            if ($cellDatabases->count() !== \count($cells)) {
                throw new \InvalidArgumentException('paper_campaign_cell_databases_mismatch');
            }
        }
        if (preg_match('/\A[a-z0-9][a-z0-9._-]{2,47}\z/D', $campaignId) !== 1) {
            throw new \InvalidArgumentException('paper_campaign_id_invalid');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 86_400) {
            throw new \InvalidArgumentException('paper_campaign_timeout_invalid');
        }
        if ($maxParallelCells < 1 || $maxParallelCells > self::MAX_PARALLEL_CELLS) {
            throw new \InvalidArgumentException('paper_campaign_parallelism_invalid');
        }
        if ($maxParallelCells > 1 && !$this->processes instanceof PaperCertificationCampaignAsyncProcessExecutorInterface) {
            throw new \InvalidArgumentException('paper_campaign_parallelism_unsupported');
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
        $inputs = [
            'campaign_id' => $campaignId,
            'matrix_cells_sha256' => $matrix['cells_sha256'],
            'configuration_sha256' => $configurationSha256,
            'datasets' => $datasetEvidence,
        ];
        if ($cellDatabases !== null) {
            // A resumed campaign must find every cell in the same dedicated database.
            $inputs['cell_databases'] = $cellDatabases->fingerprint();
        }
        $inputsSha256 = 'sha256:' . hash('sha256', CanonicalJson::encode($inputs));

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
                'completion' => null,
                'blocker' => null,
            ];
        }
        $receiptDirectory = $this->receiptDirectory($statePath);
        $state = $this->states->load($statePath);
        if ($state === null) {
            $this->discardReceipts($receiptDirectory, $expectedScopes);
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
                'running_cell_indexes' => [],
                'blocker' => null,
                'dataset_receipts' => array_fill_keys($expectedScopes, null),
                'cells' => $expectedCellStates,
            ];
            $this->states->save($statePath, $state);
        } else {
            $this->assertResumeState($state, $campaignId, $matrix, $inputsSha256, $expectedCellStates, $expectedScopes);
        }

        $state['status'] = 'running';
        $state['blocker'] = null;
        $this->states->save($statePath, $state);

        $context = [
            'configuration_path' => $configurationPath,
            'configuration_sha256' => $configurationSha256,
            'datasets' => $datasets,
            'dataset_evidence' => $datasetEvidence,
            'receipt_directory' => $receiptDirectory,
            'timeout' => $timeoutSeconds,
            'cell_databases' => $cellDatabases,
        ];
        $receiptBlocker = $this->ensureReceipts($state, $statePath, $context, $expectedScopes, $maxParallelCells, $stopRequested);
        if ($receiptBlocker !== null) {
            $state['status'] = 'failed';
            $state['blocker'] = $receiptBlocker;
            $state['current_cell_index'] = null;
            $state['running_cell_indexes'] = [];
            $this->states->save($statePath, $state);

            return $state;
        }

        return $this->runCells($state, $statePath, $cells, $context, $maxParallelCells, $stopRequested);
    }

    /**
     * The one full verification of each dataset, in a child process, before any cell.
     * A receipt kept from an earlier run is accepted only if it still validates (same
     * bytes, same verification code) and is the one this campaign recorded.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $context
     * @param list<string> $scopes
     */
    private function ensureReceipts(
        array &$state,
        string $statePath,
        array $context,
        array $scopes,
        int $maxParallelCells,
        ?\Closure $stopRequested,
    ): ?string {
        $pending = [];
        foreach ($scopes as $scope) {
            $receipt = $this->receiptPath($context['receipt_directory'], $scope);
            $recorded = $state['dataset_receipts'][$scope] ?? null;
            if (file_exists($receipt) || is_link($receipt)) {
                try {
                    $description = $this->receipts->describe($receipt, $context['datasets'][$scope], $this->eventLimit);
                } catch (\RuntimeException) {
                    $description = null;
                }
                if ($description !== null && ($recorded === null || $recorded === $description)) {
                    $state['dataset_receipts'][$scope] = $description;
                    continue;
                }
                if ($recorded !== null) {
                    return 'paper_campaign_dataset_receipt_changed';
                }
                if (is_link($receipt) || !@unlink($receipt)) {
                    return 'paper_campaign_dataset_receipt_invalid';
                }
            } elseif ($recorded !== null) {
                return 'paper_campaign_dataset_receipt_changed';
            }
            $pending[] = $scope;
        }
        $this->states->save($statePath, $state);

        /** @var array<string, PaperCertificationCampaignRunningProcessInterface> $running */
        $running = [];
        $blocker = null;
        while ($pending !== [] || $running !== []) {
            if ($stopRequested !== null && $stopRequested()) {
                foreach ($running as $process) {
                    $process->stop();
                }

                return 'paper_campaign_interrupted';
            }
            while ($blocker === null && $pending !== [] && count($running) < $maxParallelCells) {
                $scope = array_shift($pending);
                if (!$this->inputsAreUnchanged($context)) {
                    $blocker = 'paper_campaign_inputs_changed';
                    break;
                }
                $running[$scope] = $this->start([
                    $this->phpBinary,
                    '-d',
                    'memory_limit=' . self::CHILD_MEMORY_LIMIT,
                    $this->projectDirectory . '/bin/console',
                    'app:paper-market:dataset-receipt',
                    '--dataset=' . $context['datasets'][$scope],
                    '--receipt=' . $this->receiptPath($context['receipt_directory'], $scope),
                    '--no-interaction',
                ], $context['timeout']);
            }
            $progressed = false;
            foreach ($running as $scope => $process) {
                $result = $process->poll();
                if ($result === null) {
                    continue;
                }
                $progressed = true;
                unset($running[$scope]);
                $outcome = $this->receiptOutcome($result, $context, $scope);
                if (is_string($outcome)) {
                    $blocker ??= $outcome;
                    continue;
                }
                $state['dataset_receipts'][$scope] = $outcome;
                $this->states->save($statePath, $state);
            }
            if (!$progressed && $running !== []) {
                usleep($this->pollMicroseconds);
            }
            if ($blocker !== null && $running === []) {
                break;
            }
        }

        return $blocker;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>|string the recorded receipt identity, or a blocker
     */
    private function receiptOutcome(PaperCertificationCampaignProcessResult $result, array $context, string $scope): array|string
    {
        if (!$this->inputsAreUnchanged($context)) {
            return 'paper_campaign_inputs_changed';
        }
        if ($result->timedOut) {
            return 'paper_campaign_process_timeout';
        }
        $payload = $this->lastJsonLine($result->stdout);
        if ($result->exitCode !== 0
            || ($payload['schema_version'] ?? null) !== self::RECEIPT_RESULT_SCHEMA
            || ($payload['issued'] ?? null) !== true
        ) {
            $blocker = $payload['blocker'] ?? null;

            return is_string($blocker) && preg_match('/\A[a-z0-9_]{3,96}\z/D', $blocker) === 1
                ? $blocker
                : 'paper_campaign_dataset_verification_failed';
        }
        try {
            $description = $this->receipts->describe(
                $this->receiptPath($context['receipt_directory'], $scope),
                $context['datasets'][$scope],
                $this->eventLimit,
            );
        } catch (\RuntimeException) {
            return 'paper_campaign_dataset_receipt_invalid';
        }
        if ($description['events_sha256'] !== $context['dataset_evidence'][$scope]['events_sha256']
            || ($payload['events_sha256'] ?? null) !== $description['events_sha256']
            || ($payload['dataset_id'] ?? null) !== $description['dataset_id']
            || ($payload['event_count'] ?? null) !== $description['event_count']
        ) {
            return 'paper_campaign_dataset_receipt_invalid';
        }

        return $description;
    }

    /**
     * Runs every cell (readiness, then replay) with at most $maxParallelCells cells at once.
     * The campaign process is the only state writer. After the first failure no further
     * cell starts; cells already running finish and keep their own outcome.
     *
     * @param array<string, mixed> $state
     * @param list<array<string, string>> $cells
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function runCells(
        array $state,
        string $statePath,
        array $cells,
        array $context,
        int $maxParallelCells,
        ?\Closure $stopRequested,
    ): array {
        $queue = array_keys($cells);
        /** @var array<int, array{step: string, process: PaperCertificationCampaignRunningProcessInterface}> $running */
        $running = [];
        $firstFailure = null;
        while ($running !== [] || ($queue !== [] && $firstFailure === null)) {
            if ($stopRequested !== null && $stopRequested()) {
                foreach ($running as $index => $slot) {
                    $slot['process']->stop();
                    $state['cells'][$index]['status'] = 'failed';
                    $state['cells'][$index]['blocker'] = 'paper_campaign_interrupted';
                }
                $firstFailure ??= ['index' => array_key_first($running), 'blocker' => 'paper_campaign_interrupted'];
                $running = [];
                $queue = [];
                break;
            }
            while ($firstFailure === null && $queue !== [] && count($running) < $maxParallelCells) {
                $index = array_shift($queue);
                $state['current_cell_index'] = $index;
                $state['cells'][$index]['status'] = 'readiness_running';
                $state['cells'][$index]['attempts']++;
                $state['cells'][$index]['blocker'] = null;
                $state['cells'][$index]['completion'] = null;
                $state['running_cell_indexes'] = $this->runningIndexes([...array_keys($running), $index]);
                $state['completed_cells'] = $this->completedCount($state['cells']);
                $this->states->save($statePath, $state);
                if (!$this->inputsAreUnchanged($context)) {
                    $firstFailure = $this->failCell($state, $index, 'paper_campaign_inputs_changed');
                    break;
                }
                $running[$index] = [
                    'step' => 'readiness',
                    'process' => $this->startCell($index, $this->cellCommand('app:paper-market:runtime-check', $cells[$index], $state['cells'][$index]['run_id'], $context), $context),
                ];
            }
            $progressed = false;
            foreach ($running as $index => $slot) {
                $result = $slot['process']->poll();
                if ($result === null) {
                    continue;
                }
                $progressed = true;
                unset($running[$index]);
                $runId = $state['cells'][$index]['run_id'];
                if ($slot['step'] === 'readiness') {
                    $blocker = $this->readinessOutcome($result, $context, $cells[$index], $runId, $state['cells'][$index]);
                    if ($blocker === null) {
                        $state['cells'][$index]['status'] = 'replay_running';
                        $this->states->save($statePath, $state);
                        if (!$this->inputsAreUnchanged($context)) {
                            $blocker = 'paper_campaign_inputs_changed';
                        } else {
                            $running[$index] = [
                                'step' => 'replay',
                                'process' => $this->startCell($index, $this->cellCommand('app:paper-market:replay', $cells[$index], $runId, $context), $context),
                            ];
                            continue;
                        }
                    }
                } else {
                    $blocker = $this->replayOutcome($result, $context, $runId, $state['cells'][$index]);
                    if ($blocker === null) {
                        $state['cells'][$index]['status'] = 'completed';
                        $state['running_cell_indexes'] = $this->runningIndexes(array_keys($running));
                        $state['completed_cells'] = $this->completedCount($state['cells']);
                        $this->states->save($statePath, $state);
                        continue;
                    }
                }
                $failure = $this->failCell($state, $index, $blocker);
                $firstFailure ??= $failure;
                $state['running_cell_indexes'] = $this->runningIndexes(array_keys($running));
                $this->states->save($statePath, $state);
            }
            if (!$progressed && $running !== []) {
                usleep($this->pollMicroseconds);
            }
        }

        $state['running_cell_indexes'] = [];
        $state['completed_cells'] = $this->completedCount($state['cells']);
        if ($firstFailure !== null) {
            $state['status'] = 'failed';
            $state['blocker'] = $firstFailure['blocker'];
            $state['current_cell_index'] = $firstFailure['index'];
        } else {
            $state['status'] = 'completed';
            $state['current_cell_index'] = null;
            $state['blocker'] = null;
            $state['completed_cells'] = count($cells);
        }
        $this->states->save($statePath, $state);

        return $state;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $cell
     * @param array<string, mixed> $cellState updated with the readiness evidence
     */
    private function readinessOutcome(
        PaperCertificationCampaignProcessResult $result,
        array $context,
        array $cell,
        string $runId,
        array &$cellState,
    ): ?string {
        if (!$this->inputsAreUnchanged($context)) {
            return 'paper_campaign_inputs_changed';
        }
        if ($result->timedOut) {
            return 'paper_campaign_process_timeout';
        }
        if ($result->exitCode !== 0) {
            return $this->readinessBlocker($result->stdout) ?? 'paper_campaign_readiness_failed';
        }
        $evidence = $this->readinessEvidence($result->stdout, $cell, $runId);
        if ($evidence === null) {
            return 'paper_campaign_readiness_identity_mismatch';
        }
        $cellState['readiness'] = $evidence;

        return null;
    }

    /**
     * A replay counts as completed only with its positive completion proof: exit code 0 is
     * not enough (a signalled or crashed child must never complete a cell).
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $cellState updated with the completion proof
     */
    private function replayOutcome(
        PaperCertificationCampaignProcessResult $result,
        array $context,
        string $runId,
        array &$cellState,
    ): ?string {
        if (!$this->inputsAreUnchanged($context)) {
            return 'paper_campaign_inputs_changed';
        }
        if ($result->timedOut) {
            return 'paper_campaign_process_timeout';
        }
        if ($result->exitCode !== 0) {
            return 'paper_campaign_replay_failed';
        }
        $completion = $this->completionProof($result->stdout, $runId, $cellState['readiness'] ?? null);
        if ($completion === null) {
            return 'paper_campaign_replay_completion_unproven';
        }
        $cellState['completion'] = $completion;

        return null;
    }

    /**
     * @param mixed $readiness
     * @return array<string, int|string>|null
     */
    private function completionProof(string $stdout, string $runId, mixed $readiness): ?array
    {
        $payload = $this->lastJsonLine($stdout);
        if (!is_array($readiness)
            || ($payload['schema_version'] ?? null) !== self::COMPLETION_SCHEMA
            || ($payload['completed'] ?? null) !== true
            || ($payload['run_id'] ?? null) !== $runId
            || ($payload['cell_id'] ?? null) !== ($readiness['execution_cell_id'] ?? false)
            || ($payload['dataset_id'] ?? null) !== ($readiness['dataset_id'] ?? false)
            || ($payload['events_file_sha256'] ?? null) !== ($readiness['events_file_sha256'] ?? false)
            || !is_int($payload['event_count'] ?? null)
            || $payload['event_count'] < 0
            || ($payload['next_source_position'] ?? null) !== $payload['event_count']
            || !is_int($payload['journal_ordinal'] ?? null)
            || $payload['journal_ordinal'] < 0
            || !is_string($payload['journal_checksum'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $payload['journal_checksum']) !== 1
            || !is_string($payload['fake_state_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $payload['fake_state_sha256']) !== 1
        ) {
            return null;
        }

        return [
            'event_count' => $payload['event_count'],
            'journal_ordinal' => $payload['journal_ordinal'],
            'journal_checksum' => $payload['journal_checksum'],
            'fake_state_sha256' => $payload['fake_state_sha256'],
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastJsonLine(string $stdout): ?array
    {
        $lines = preg_split('/\R/', trim($stdout));
        $last = is_array($lines) ? (string) end($lines) : '';
        try {
            $payload = json_decode($last, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($payload) && !array_is_list($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{index: int|null, blocker: string}
     */
    private function failCell(array &$state, int $index, string $blocker): array
    {
        $state['cells'][$index]['status'] = 'failed';
        $state['cells'][$index]['blocker'] = $blocker;
        $state['completed_cells'] = $this->completedCount($state['cells']);

        return ['index' => $index, 'blocker' => $blocker];
    }

    /**
     * A cell child: in its own dedicated database when the campaign has one per cell.
     *
     * @param list<string> $argv
     * @param array<string, mixed> $context
     */
    private function startCell(int $index, array $argv, array $context): PaperCertificationCampaignRunningProcessInterface
    {
        $databases = $context['cell_databases'];
        if ($databases instanceof PaperCertificationCampaignCellDatabases
            && $this->processes instanceof PaperCertificationCampaignEnvironmentProcessExecutorInterface
        ) {
            return $this->processes->startWithEnvironment(
                $argv,
                $context['timeout'],
                ['DATABASE_URL' => $databases->urlFor($index)],
            );
        }

        return $this->start($argv, $context['timeout']);
    }

    /**
     * @param list<string> $argv
     */
    private function start(array $argv, int $timeoutSeconds): PaperCertificationCampaignRunningProcessInterface
    {
        return $this->processes instanceof PaperCertificationCampaignAsyncProcessExecutorInterface
            ? $this->processes->start($argv, $timeoutSeconds)
            : new CompletedPaperCertificationCampaignProcess($this->processes->execute($argv, $timeoutSeconds));
    }

    /**
     * @param array<string, string> $cell
     * @param array<string, mixed> $context
     * @return list<string>
     */
    private function cellCommand(string $command, array $cell, string $runId, array $context): array
    {
        $scope = $this->scopeKey($cell);

        return [
            $this->phpBinary,
            '-d',
            'memory_limit=' . self::CHILD_MEMORY_LIMIT,
            $this->projectDirectory . '/bin/console',
            $command,
            ...$this->cellArguments($context['datasets'][$scope], $context['configuration_path'], $cell, $runId),
            '--dataset-receipt=' . $this->receiptPath($context['receipt_directory'], $scope),
        ];
    }

    /**
     * @param list<int> $indexes
     * @return list<int>
     */
    private function runningIndexes(array $indexes): array
    {
        $indexes = array_values(array_unique($indexes));
        sort($indexes, SORT_NUMERIC);

        return $indexes;
    }

    private function receiptDirectory(string $statePath): string
    {
        $directory = $statePath . '.receipts';
        if (is_link($directory)) {
            throw new \RuntimeException('paper_campaign_receipt_directory_invalid');
        }
        if (!is_dir($directory)) {
            $previous = umask(0077);
            try {
                if (!@mkdir($directory, 0700) && !is_dir($directory)) {
                    throw new \RuntimeException('paper_campaign_receipt_directory_invalid');
                }
            } finally {
                umask($previous);
            }
        }
        $statistics = @lstat($directory);
        if ($statistics === false
            || ($statistics['mode'] & 0170000) !== 0040000
            || ($statistics['mode'] & 0077) !== 0
            || realpath($directory) !== $directory
        ) {
            throw new \RuntimeException('paper_campaign_receipt_directory_invalid');
        }

        return $directory;
    }

    private function receiptPath(string $directory, string $scope): string
    {
        return $directory . '/' . str_replace('/', '-', $scope) . '.json';
    }

    /** @param list<string> $scopes */
    private function discardReceipts(string $directory, array $scopes): void
    {
        foreach ($scopes as $scope) {
            $receipt = $this->receiptPath($directory, $scope);
            if ((file_exists($receipt) || is_link($receipt)) && !@unlink($receipt)) {
                throw new \RuntimeException('paper_campaign_receipt_directory_invalid');
            }
        }
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
     * @param list<string> $scopes
     */
    private function assertResumeState(
        array $state,
        string $campaignId,
        array $matrix,
        string $inputsSha256,
        array $expectedCells,
        array $scopes,
    ): void {
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
            'running_cell_indexes',
            'blocker',
            'dataset_receipts',
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
            || !is_array($state['running_cell_indexes'])
            || !array_is_list($state['running_cell_indexes'])
            || array_filter(
                $state['running_cell_indexes'],
                static fn (mixed $index): bool => !is_int($index) || $index < 0 || $index >= count($expectedCells),
            ) !== []
            || ($state['blocker'] !== null
                && (!is_string($state['blocker'])
                    || preg_match('/\A[a-z0-9_]{3,96}\z/D', $state['blocker']) !== 1))
            || !$this->persistedReceiptsAreValid($state['dataset_receipts'], $scopes)
        ) {
            throw new \LogicException('paper_campaign_state_invalid');
        }
        $expectedCellKeys = ['identity', 'run_id', 'status', 'attempts', 'readiness', 'completion', 'blocker'];
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
                || !$this->persistedCompletionIsValid($actual['completion'] ?? null)
                || ($actual['status'] === 'completed' && $actual['completion'] === null)
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

    /** @param list<string> $scopes */
    private function persistedReceiptsAreValid(mixed $receipts, array $scopes): bool
    {
        if (!is_array($receipts) || array_is_list($receipts) && $receipts !== []) {
            return false;
        }
        $keys = array_keys($receipts);
        sort($keys, SORT_STRING);
        if ($keys !== $scopes) {
            return false;
        }
        foreach ($receipts as $receipt) {
            if ($receipt === null) {
                continue;
            }
            if (!is_array($receipt)
                || array_keys($receipt) !== ['receipt_sha256', 'dataset_id', 'event_count', 'events_sha256', 'code_tree_sha256', 'event_limit']
                || !is_string($receipt['receipt_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['receipt_sha256']) !== 1
                || !is_string($receipt['dataset_id']) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $receipt['dataset_id']) !== 1
                || !is_int($receipt['event_count']) || $receipt['event_count'] < 0
                || !is_string($receipt['events_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['events_sha256']) !== 1
                || !is_string($receipt['code_tree_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt['code_tree_sha256']) !== 1
                || $receipt['event_limit'] !== $this->eventLimit
            ) {
                return false;
            }
        }

        return true;
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

    private function persistedCompletionIsValid(mixed $completion): bool
    {
        if ($completion === null) {
            return true;
        }

        return is_array($completion)
            && array_keys($completion) === ['event_count', 'journal_ordinal', 'journal_checksum', 'fake_state_sha256']
            && is_int($completion['event_count']) && $completion['event_count'] >= 0
            && is_int($completion['journal_ordinal']) && $completion['journal_ordinal'] >= 0
            && is_string($completion['journal_checksum']) && preg_match('/\A[a-f0-9]{64}\z/D', $completion['journal_checksum']) === 1
            && is_string($completion['fake_state_sha256']) && preg_match('/\A[a-f0-9]{64}\z/D', $completion['fake_state_sha256']) === 1;
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

    /**
     * @param array<string, mixed> $context
     * @phpstan-impure
     */
    private function inputsAreUnchanged(array $context): bool
    {
        try {
            if (!hash_equals(
                $context['configuration_sha256'],
                $this->fingerprintFile($context['configuration_path'], 'paper_campaign_configuration_invalid', true),
            )) {
                return false;
            }
            foreach ($context['dataset_evidence'] as $scope => $expected) {
                $directory = $context['datasets'][$scope] ?? null;
                if (!is_string($directory)) {
                    return false;
                }
                $this->assertDirectory($directory);
                if (!hash_equals(
                    $expected['manifest_sha256'],
                    $this->fingerprintFile($directory . '/manifest.json', 'paper_campaign_dataset_invalid'),
                ) || !hash_equals(
                    $expected['events_sha256'],
                    $this->fingerprintFile($directory . '/events.ndjson', 'paper_campaign_dataset_invalid'),
                )) {
                    return false;
                }
            }
        } catch (\InvalidArgumentException|\RuntimeException) {
            return false;
        }

        return true;
    }

    private function fingerprintFile(string $path, string $error, bool $private = false): string
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException($error);
        }
        // The campaign process lives for hours: never compare against cached stat results.
        clearstatcache(true);
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
