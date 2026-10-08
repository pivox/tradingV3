<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Certification;

use App\Trading\Paper\Certification\Campaign\CompletedPaperCertificationCampaignProcess;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignAsyncProcessExecutorInterface;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessExecutorInterface;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessResult;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignCellDatabases;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignEnvironmentProcessExecutorInterface;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignRunner;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignRunningProcessInterface;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignStateStore;
use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use App\Trading\Paper\Dataset\PaperDatasetReceiptDescriberInterface;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCertificationCampaignRunner::class)]
#[CoversClass(PaperCertificationCampaignStateStore::class)]
#[CoversClass(CompletedPaperCertificationCampaignProcess::class)]
final class PaperCertificationCampaignRunnerTest extends TestCase
{
    private string $root;
    private string $configuration;
    private string $state;

    /** @var array<string, string> */
    private array $datasets;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/paper-campaign-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0700, true));
        $resolvedRoot = realpath($this->root);
        self::assertIsString($resolvedRoot);
        $this->root = $resolvedRoot;
        $this->configuration = $this->root . '/configuration.json';
        file_put_contents($this->configuration, '{"risk":{"max_notional":"1000"}}');
        chmod($this->configuration, 0600);
        $this->state = $this->root . '/state.json';
        $this->datasets = [];
        foreach (['mainnet/hyperliquid', 'mainnet/okx'] as $scope) {
            $directory = $this->root . '/' . str_replace('/', '-', $scope);
            self::assertTrue(mkdir($directory, 0700, true));
            file_put_contents($directory . '/manifest.json', '{"scope":"' . $scope . '"}');
            file_put_contents($directory . '/events.ndjson', "{\"event\":1}\n");
            $this->datasets[$scope] = $directory;
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testRunsAllExactCellsInFreshProcessesAndResumesWithTheSameRunIds(): void
    {
        $executor = $this->acceptingExecutor();
        $runner = $this->runner($executor);

        $first = $runner->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('completed', $first['status']);
        self::assertSame('not_evaluated', $first['certification_status']);
        self::assertSame(12, $first['completed_cells']);
        self::assertCount(26, $executor->calls);
        self::assertSame(
            ['app:paper-market:dataset-receipt', 'app:paper-market:dataset-receipt'],
            [$executor->calls[0][4], $executor->calls[1][4]],
            'Each dataset is verified once, before any cell.',
        );
        $firstRunIds = [];
        foreach (array_chunk(array_slice($executor->calls, 2), 2) as $pair) {
            self::assertStringContainsString('app:paper-market:runtime-check', implode(' ', $pair[0]));
            self::assertStringContainsString('app:paper-market:replay', implode(' ', $pair[1]));
            $readinessRunId = self::option($pair[0], '--run-id');
            self::assertSame($readinessRunId, self::option($pair[1], '--run-id'));
            self::assertSame(self::option($pair[0], '--dataset-receipt'), self::option($pair[1], '--dataset-receipt'));
            $firstRunIds[] = $readinessRunId;
        }
        self::assertCount(12, array_unique($firstRunIds));
        self::assertContainsOnly('string', $firstRunIds);
        foreach ($first['cells'] as $cell) {
            self::assertSame('completed', $cell['status']);
            self::assertSame(['event_count', 'journal_ordinal', 'journal_checksum', 'fake_state_sha256'], array_keys($cell['completion']));
        }

        $second = $runner->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('completed', $second['status']);
        self::assertCount(50, $executor->calls, 'Completed cells are revalidated; valid receipts are reused.');
        self::assertSame($firstRunIds, array_map(
            static fn (array $pair): string => self::option($pair[0], '--run-id'),
            array_chunk(array_slice($executor->calls, 26), 2),
        ));
        self::assertSame(2, $second['cells'][0]['attempts']);
        self::assertSame($first['dataset_receipts'], $second['dataset_receipts']);

        $persisted = file_get_contents($this->state);
        self::assertIsString($persisted);
        self::assertStringNotContainsString($this->root, $persisted);
        self::assertStringNotContainsString('max_notional', $persisted);
        self::assertSame(0600, fileperms($this->state) & 0777);
        self::assertSame(0700, fileperms($this->state . '.receipts') & 0777);
    }

    public function testEveryChildProcessRunsWithTheExplicitCampaignMemoryLimit(): void
    {
        $executor = $this->acceptingExecutor();

        $state = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('completed', $state['status']);
        self::assertSame('3072M', PaperCertificationCampaignRunner::CHILD_MEMORY_LIMIT);
        self::assertCount(26, $executor->calls);
        foreach ($executor->calls as $call) {
            self::assertSame(['/usr/bin/php', '-d', 'memory_limit=3072M', '/opt/trading-app/bin/console'], array_slice($call, 0, 4));
            self::assertContains($call[4], ['app:paper-market:dataset-receipt', 'app:paper-market:runtime-check', 'app:paper-market:replay']);
        }
    }

    public function testRejectsMissingOrAdditionalDatasetScopesBeforeStartingAProcess(): void
    {
        $executor = $this->acceptingExecutor();
        $datasets = $this->datasets;
        unset($datasets['mainnet/okx']);
        $datasets['testnet/hyperliquid'] = $this->datasets['mainnet/hyperliquid'];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_campaign_dataset_scopes_mismatch');
        try {
            $this->runner($executor)->run(
                $this->matrix(),
                'first-baseline-aug23',
                $this->configuration,
                $datasets,
                $this->state,
                60,
            );
        } finally {
            self::assertSame([], $executor->calls);
        }
    }

    public function testStopsOnUnsafeReadinessBeforeReplayOrLaterCells(): void
    {
        $executor = new AcceptingPaperCampaignExecutor(readinessOverride: static fn (array $argv): string => json_encode([
            'schema_version' => 'paper-replay-readiness-v1',
            'ready' => true,
            'runtime_ready' => true,
            'baseline_eligible' => true,
            'profile_eligibility' => 'baseline_eligible',
            'run_id' => PaperCertificationCampaignRunnerTest::option($argv, '--run-id'),
            'configuration_snapshot_id' => 'sha256:' . str_repeat('a', 64),
            'execution_cell_id' => 'sha256:' . str_repeat('b', 64),
            'source' => [
                'dataset_id' => 'dataset-001',
                'events_file_sha256' => str_repeat('c', 64),
                'network' => PaperCertificationCampaignRunnerTest::option($argv, '--mode-id'),
                'venue' => PaperCertificationCampaignRunnerTest::option($argv, '--setup-id'),
            ],
            'execution' => [
                'mode' => 'paper',
                'exchange' => 'fake',
                'private_clients_enabled' => false,
                'mainnet_write_enabled' => false,
                'demo_testnet_write_enabled' => false,
            ],
            'strategy' => [],
        ], JSON_THROW_ON_ERROR));

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_campaign_readiness_identity_mismatch', $result['blocker']);
        self::assertCount(3, $executor->calls);
        self::assertSame('app:paper-market:runtime-check', $executor->calls[2][4]);
    }

    public function testStopsAtFirstReplayFailureAndPersistsOnlyRedactedFailureState(): void
    {
        $executor = $this->acceptingExecutor(replay: 'fail');

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_campaign_replay_failed', $result['blocker']);
        self::assertCount(4, $executor->calls);
        self::assertStringNotContainsString('private-child-error', (string) file_get_contents($this->state));
    }

    public function testExitCodeZeroWithoutAPositiveCompletionProofNeverCompletesACell(): void
    {
        $executor = $this->acceptingExecutor(replay: 'no-proof');

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_campaign_replay_completion_unproven', $result['blocker']);
        self::assertSame(0, $result['completed_cells']);
        self::assertSame('failed', $result['cells'][0]['status']);
        self::assertNull($result['cells'][0]['completion']);
    }

    public function testCompletionProofOfAnotherCellNeverCompletesACell(): void
    {
        $executor = $this->acceptingExecutor(replay: 'foreign-proof');

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('paper_campaign_replay_completion_unproven', $result['blocker']);
        self::assertSame(0, $result['completed_cells']);
    }

    public function testASignalledReplayChildNeverCompletesACellEvenWithAPrintedProof(): void
    {
        $executor = $this->acceptingExecutor(replay: 'sigterm');

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('paper_campaign_replay_failed', $result['blocker']);
        self::assertSame(0, $result['completed_cells']);
        self::assertSame('failed', $result['cells'][0]['status']);
    }

    public function testChangedInputCannotResumeAnExistingCampaign(): void
    {
        $executor = $this->acceptingExecutor();
        $runner = $this->runner($executor);
        $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);
        file_put_contents($this->configuration, '{"risk":{"max_notional":"999"}}');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_campaign_input_conflict');
        $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);
    }

    public function testStopsWhenAnInputChangesWhileAChildProcessIsRunning(): void
    {
        $configuration = $this->configuration;
        $delegate = $this->acceptingExecutor();
        $executor = new class($configuration, $delegate) implements PaperCertificationCampaignProcessExecutorInterface {
            public int $calls = 0;

            public function __construct(
                private readonly string $configuration,
                private readonly AcceptingPaperCampaignExecutor $delegate,
            ) {
            }

            public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
            {
                ++$this->calls;
                if ($this->calls === 3) {
                    file_put_contents($this->configuration, '{"risk":{"max_notional":"changed-during-child"}}');
                }

                return $this->delegate->execute($argv, $timeoutSeconds);
            }
        };

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_campaign_inputs_changed', $result['blocker']);
        self::assertSame(3, $executor->calls);
        self::assertStringNotContainsString('changed-during-child', (string) file_get_contents($this->state));
    }

    public function testTimeoutStopsTheCampaignWithAStableRedactedBlocker(): void
    {
        $executor = new class implements PaperCertificationCampaignProcessExecutorInterface {
            public int $calls = 0;

            public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
            {
                ++$this->calls;

                return new PaperCertificationCampaignProcessResult(124, '', true, '/private/path/in/stderr');
            }
        };

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('paper_campaign_process_timeout', $result['blocker']);
        self::assertSame(1, $executor->calls, 'A dataset verification timeout stops before any cell.');
        self::assertStringNotContainsString('/private/path', (string) file_get_contents($this->state));
    }

    public function testRejectsUnexpectedPersistedStateFieldsBeforeStartingAResume(): void
    {
        $executor = $this->acceptingExecutor();
        $runner = $this->runner($executor);
        $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);
        $state = json_decode((string) file_get_contents($this->state), true, 64, JSON_THROW_ON_ERROR);
        $state['private_path'] = $this->root;
        file_put_contents($this->state, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($this->state, 0600);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_campaign_state_invalid');
        try {
            $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);
        } finally {
            self::assertCount(26, $executor->calls);
        }
    }

    public function testACompletedCellWithoutItsCompletionProofCannotBeResumed(): void
    {
        $executor = $this->acceptingExecutor();
        $runner = $this->runner($executor);
        $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);
        $state = json_decode((string) file_get_contents($this->state), true, 64, JSON_THROW_ON_ERROR);
        $state['cells'][3]['completion'] = null;
        file_put_contents($this->state, json_encode($state, JSON_THROW_ON_ERROR));
        chmod($this->state, 0600);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('paper_campaign_state_invalid');
        $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);
    }

    public function testAFailedDatasetVerificationStopsBeforeAnyCell(): void
    {
        $executor = $this->acceptingExecutor(receipt: 'fail');

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_dataset_checksum_mismatch', $result['blocker']);
        self::assertCount(1, $executor->calls);
        self::assertSame(['mainnet/hyperliquid' => null, 'mainnet/okx' => null], $result['dataset_receipts']);
        foreach ($result['cells'] as $cell) {
            self::assertSame('pending', $cell['status']);
        }
    }

    public function testAReceiptReplacedBetweenRunsBlocksTheResume(): void
    {
        $executor = $this->acceptingExecutor();
        $runner = $this->runner($executor);
        $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);
        $receipt = $this->state . '.receipts/mainnet-okx.json';
        $contents = json_decode((string) file_get_contents($receipt), true, 16, JSON_THROW_ON_ERROR);
        $contents['version'] = 2;
        file_put_contents($receipt, json_encode($contents, JSON_THROW_ON_ERROR));

        $result = $runner->run($this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60);

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_campaign_dataset_receipt_changed', $result['blocker']);
        self::assertCount(26, $executor->calls, 'No child runs after a receipt changed under the campaign.');
    }

    public function testAnInvalidLeftoverReceiptIsReissuedBeforeTheFirstRunRecordsIt(): void
    {
        $executor = $this->acceptingExecutor();
        mkdir($this->state . '.receipts', 0700);
        file_put_contents($this->state . '.receipts/mainnet-okx.json', 'stale');

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('completed', $result['status']);
        self::assertSame('app:paper-market:dataset-receipt', $executor->calls[1][4]);
        self::assertSame(1, $result['dataset_receipts']['mainnet/okx']['event_count']);
    }

    public function testParallelCellsReachTheSameStateAsSequentialCells(): void
    {
        $sequential = $this->acceptingExecutor();
        $sequentialState = $this->runner($sequential)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );
        $sequentialFile = (string) file_get_contents($this->state);
        unlink($this->state);

        $parallel = new AsyncPaperCampaignExecutor(new AcceptingPaperCampaignExecutor());
        $parallelState = $this->runner($parallel)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
            3,
        );

        self::assertSame('completed', $parallelState['status']);
        self::assertSame(3, $parallel->maximumConcurrency);
        self::assertSame($sequentialState, $parallelState);
        self::assertSame($sequentialFile, (string) file_get_contents($this->state));
        self::assertCount(26, $parallel->calls);
        foreach ($parallel->runIds() as $runId => $commands) {
            self::assertSame(['app:paper-market:runtime-check', 'app:paper-market:replay'], $commands, $runId);
        }
    }

    public function testAParallelFailureStartsNoNewCellButLetsRunningCellsFinish(): void
    {
        $parallel = new AsyncPaperCampaignExecutor(new AcceptingPaperCampaignExecutor(replay: 'fail-hyperliquid-day-trading'));

        $result = $this->runner($parallel)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
            3,
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_campaign_replay_failed', $result['blocker']);
        self::assertSame([], $result['running_cell_indexes']);
        $failed = $result['current_cell_index'];
        self::assertIsInt($failed);
        self::assertSame(['hyperliquid', 'day_trading'], [
            $result['cells'][$failed]['identity']['market_data_venue'],
            $result['cells'][$failed]['identity']['mode_id'],
        ]);
        self::assertSame('failed', $result['cells'][$failed]['status']);
        $failureAt = array_search(['done', 'app:paper-market:replay', $result['cells'][$failed]['run_id'], 2], $parallel->log, true);
        self::assertIsInt($failureAt);
        $started = [];
        foreach ($parallel->log as $position => $entry) {
            if ($entry[0] === 'start' && $entry[1] === 'app:paper-market:runtime-check') {
                self::assertLessThan($failureAt, $position, 'No cell starts after the first failure.');
                $started[] = $entry[2];
            }
        }
        $completed = 0;
        foreach ($result['cells'] as $index => $cell) {
            if ($index === $failed) {
                continue;
            }
            if (in_array($cell['run_id'], $started, true)) {
                self::assertSame('completed', $cell['status'], 'A cell running at the failure finishes with its own outcome.');
                self::assertNotNull($cell['completion']);
                ++$completed;
            } else {
                self::assertSame('pending', $cell['status']);
                self::assertSame(0, $cell['attempts']);
            }
        }
        self::assertSame($completed, $result['completed_cells']);
        self::assertLessThan(11, $completed);
    }

    public function testAnInterruptionStopsRunningChildrenAndCompletesNoRunningCell(): void
    {
        $parallel = new AsyncPaperCampaignExecutor(new AcceptingPaperCampaignExecutor(), slowCommand: 'app:paper-market:replay');
        $polls = 0;

        $result = $this->runner($parallel)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
            2,
            static function () use (&$polls): bool {
                return ++$polls > 12;
            },
        );

        self::assertSame('failed', $result['status']);
        self::assertSame('paper_campaign_interrupted', $result['blocker']);
        self::assertSame(2, $parallel->stopped);
        self::assertSame(0, $result['completed_cells']);
        self::assertSame(['failed', 'failed'], [$result['cells'][0]['status'], $result['cells'][1]['status']]);
        self::assertSame('paper_campaign_interrupted', $result['cells'][1]['blocker']);
        foreach (array_slice($result['cells'], 2) as $cell) {
            self::assertSame('pending', $cell['status']);
        }
    }

    public function testParallelismNeedsAnExecutorThatStartsChildrenWithoutWaiting(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('paper_campaign_parallelism_unsupported');
        $this->runner($this->acceptingExecutor())->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
            2,
        );
    }

    /** #132: one dedicated PostgreSQL instance per cell, so cells can trade the same symbol and side. */
    public function testEveryCellChildRunsInItsOwnDedicatedDatabase(): void
    {
        $cellCount = count($this->matrix()['cells']);
        $databases = PaperCertificationCampaignCellDatabases::fromDocument($this->cellDatabases($cellCount, 'secret-a'));
        $executor = new DatabaseAwarePaperCampaignExecutor(new AsyncPaperCampaignExecutor(new AcceptingPaperCampaignExecutor()));

        $result = $this->runner($executor)->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
            3,
            cellDatabases: $databases,
        );

        self::assertSame('completed', $result['status']);
        foreach ($result['cells'] as $index => $cell) {
            $expected = $databases->urlFor($index);
            self::assertSame(
                ['app:paper-market:runtime-check' => $expected, 'app:paper-market:replay' => $expected],
                $executor->databasesByRunId[$cell['run_id']] ?? null,
                'cell ' . $index,
            );
        }
        self::assertCount($cellCount, array_unique(array_map(
            static fn (array $commands): string => $commands['app:paper-market:replay'],
            $executor->databasesByRunId,
        )));
        $persisted = (string) file_get_contents($this->state);
        self::assertStringNotContainsString('secret-a', $persisted);
        self::assertStringNotContainsString('postgresql://', $persisted);
    }

    public function testCellDatabasesNeedOneDatabasePerCellAndAnExecutorThatCanAssignThem(): void
    {
        $cellCount = count($this->matrix()['cells']);
        foreach ([
            'paper_campaign_cell_databases_mismatch' => [
                new DatabaseAwarePaperCampaignExecutor(new AsyncPaperCampaignExecutor(new AcceptingPaperCampaignExecutor())),
                $cellCount - 1,
            ],
            'paper_campaign_cell_databases_unsupported' => [
                new AsyncPaperCampaignExecutor(new AcceptingPaperCampaignExecutor()),
                $cellCount,
            ],
        ] as $reason => [$executor, $count]) {
            try {
                $this->runner($executor)->run(
                    $this->matrix(),
                    'first-baseline-aug23',
                    $this->configuration,
                    $this->datasets,
                    $this->state,
                    60,
                    cellDatabases: PaperCertificationCampaignCellDatabases::fromDocument($this->cellDatabases($count, 'secret-a')),
                );
                self::fail('Accepted: ' . $reason);
            } catch (\InvalidArgumentException $exception) {
                self::assertSame($reason, $exception->getMessage());
            }
            self::assertSame([], $executor->calls, $reason);
            self::assertFileDoesNotExist($this->state);
        }
    }

    public function testACampaignResumesOnlyWithTheSameInstancesWhateverTheirPasswords(): void
    {
        $cellCount = count($this->matrix()['cells']);
        $executor = new DatabaseAwarePaperCampaignExecutor(new AsyncPaperCampaignExecutor(new AcceptingPaperCampaignExecutor()));
        $runner = $this->runner($executor);
        $first = $runner->run(
            $this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60,
            cellDatabases: PaperCertificationCampaignCellDatabases::fromDocument($this->cellDatabases($cellCount, 'secret-a')),
        );
        $rotated = $runner->run(
            $this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60,
            cellDatabases: PaperCertificationCampaignCellDatabases::fromDocument($this->cellDatabases($cellCount, 'secret-b')),
        );
        self::assertSame('completed', $first['status']);
        self::assertSame($first['inputs_sha256'], $rotated['inputs_sha256']);
        self::assertSame('completed', $rotated['status']);

        $moved = $this->cellDatabases($cellCount, 'secret-a');
        $moved['databases'] = array_reverse($moved['databases']);
        $this->expectException(\LogicException::class);
        $runner->run(
            $this->matrix(), 'first-baseline-aug23', $this->configuration, $this->datasets, $this->state, 60,
            cellDatabases: PaperCertificationCampaignCellDatabases::fromDocument($moved),
        );
    }

    /** @return array{schema_version: string, databases: list<string>} */
    private function cellDatabases(int $count, string $password): array
    {
        $databases = [];
        for ($index = 0; $index < $count; ++$index) {
            $databases[] = sprintf(
                'postgresql://postgres:%s@127.0.0.1:%d/trading_paper?serverVersion=15&charset=utf8',
                $password,
                5440 + $index,
            );
        }

        return ['schema_version' => PaperCertificationCampaignCellDatabases::SCHEMA_VERSION, 'databases' => $databases];
    }

    private function runner(PaperCertificationCampaignProcessExecutorInterface $executor): PaperCertificationCampaignRunner
    {
        return new PaperCertificationCampaignRunner(
            $executor,
            new PaperCertificationCampaignStateStore(),
            '/opt/trading-app',
            '/usr/bin/php',
            new FakePaperCampaignReceipts(),
            1000,
            0,
        );
    }

    /** @return array<string, mixed> */
    private function matrix(): array
    {
        return (new PaperCertificationMatrixBuilder(new ModeContractLoader(), new SetupContractLoader()))
            ->build([
                'schema_version' => 'paper-certification-matrix-input-v1',
                'minimum_certified_trades_per_cell' => 50,
                'scopes' => [
                    ['paper_network' => 'mainnet', 'market_data_venue' => 'okx'],
                    ['paper_network' => 'mainnet', 'market_data_venue' => 'hyperliquid'],
                ],
                'mode_versions' => [
                    'day_trading' => '1.1.0',
                    'scalping' => '1.1.0',
                    'micro_scalping' => '1.1.0',
                ],
                'setup_versions' => [
                    'day_trading.trend_continuation.long' => '1.1.0',
                    'day_trading.trend_continuation.short' => '1.0.0',
                    'scalping.trend_continuation.long' => '1.1.0',
                    'scalping.pullback.long' => '1.1.0',
                    'scalping.trend_momentum.short' => '1.1.0',
                    'micro_scalping.momentum_ofi.long' => '1.1.0',
                    'micro_scalping.momentum_ofi.short' => '1.1.0',
                ],
            ]);
    }

    private function acceptingExecutor(string $replay = 'proof', string $receipt = 'issue'): AcceptingPaperCampaignExecutor
    {
        return new AcceptingPaperCampaignExecutor($replay, $receipt);
    }

    /** @param list<string> $argv */
    public static function option(array $argv, string $name): string
    {
        $prefix = $name . '=';
        foreach ($argv as $argument) {
            if (str_starts_with($argument, $prefix)) {
                return substr($argument, strlen($prefix));
            }
        }

        self::fail('Missing option ' . $name);
    }
}

/** Reads the receipts written by AcceptingPaperCampaignExecutor (no real verification). */
final class FakePaperCampaignReceipts implements PaperDatasetReceiptDescriberInterface
{
    public function describe(string $receiptPath, string $datasetDirectory, int $eventLimit): array
    {
        $contents = @file_get_contents($receiptPath);
        $receipt = is_string($contents) ? json_decode($contents, true, 16) : null;
        if (!is_array($receipt) || ($receipt['dataset'] ?? null) !== $datasetDirectory || $eventLimit !== 1000) {
            throw new \RuntimeException('paper_dataset_receipt_invalid');
        }
        $venue = str_contains($datasetDirectory, 'hyperliquid') ? 'hyperliquid' : 'okx';

        return [
            'receipt_sha256' => hash('sha256', (string) $contents),
            'dataset_id' => 'dataset-' . $venue,
            'event_count' => 1,
            'events_sha256' => hash('sha256', (string) file_get_contents($datasetDirectory . '/events.ndjson')),
            'code_tree_sha256' => str_repeat('f', 64),
            'event_limit' => $eventLimit,
        ];
    }
}

final class AcceptingPaperCampaignExecutor implements PaperCertificationCampaignProcessExecutorInterface
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @param (\Closure(list<string>): string)|null $readinessOverride */
    public function __construct(
        private readonly string $replay = 'proof',
        private readonly string $receipt = 'issue',
        private readonly ?\Closure $readinessOverride = null,
    ) {
    }

    public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
    {
        $this->calls[] = $argv;
        if (in_array('app:paper-market:dataset-receipt', $argv, true)) {
            return $this->receipt($argv);
        }
        $dataset = PaperCertificationCampaignRunnerTest::option($argv, '--dataset');
        $venue = str_contains($dataset, 'hyperliquid') ? 'hyperliquid' : 'okx';
        $runId = PaperCertificationCampaignRunnerTest::option($argv, '--run-id');
        $eventsSha256 = hash('sha256', (string) file_get_contents($dataset . '/events.ndjson'));
        if (in_array('app:paper-market:replay', $argv, true)) {
            $proof = json_encode([
                'schema_version' => 'paper-replay-completion-v1',
                'completed' => true,
                'cell_id' => 'sha256:' . hash('sha256', $this->replay === 'foreign-proof' ? 'other' : $runId),
                'run_id' => $runId,
                'dataset_id' => 'dataset-' . $venue,
                'events_file_sha256' => $eventsSha256,
                'event_count' => 1,
                'next_source_position' => 1,
                'journal_ordinal' => 2,
                'journal_checksum' => hash('sha256', 'journal-' . $runId),
                'fake_state_sha256' => hash('sha256', 'state-' . $runId),
                'timings' => ['consume_seconds' => 0.1],
            ], JSON_THROW_ON_ERROR);

            return match ($this->replay) {
                'fail' => new PaperCertificationCampaignProcessResult(2, '', false, 'private-child-error'),
                'fail-hyperliquid-day-trading' => $venue === 'hyperliquid'
                    && PaperCertificationCampaignRunnerTest::option($argv, '--mode-id') === 'day_trading'
                    ? new PaperCertificationCampaignProcessResult(2, '', false, 'private-child-error')
                    : new PaperCertificationCampaignProcessResult(0, "cell=ok\n" . $proof, false),
                'no-proof' => new PaperCertificationCampaignProcessResult(0, 'cell=ok', false),
                'sigterm' => new PaperCertificationCampaignProcessResult(143, "cell=ok\n" . $proof, false),
                default => new PaperCertificationCampaignProcessResult(0, "cell=ok\n" . $proof, false),
            };
        }
        if ($this->readinessOverride !== null) {
            return new PaperCertificationCampaignProcessResult(0, ($this->readinessOverride)($argv), false);
        }
        $mode = PaperCertificationCampaignRunnerTest::option($argv, '--mode-id');
        $modeVersion = PaperCertificationCampaignRunnerTest::option($argv, '--mode-version');
        $setup = PaperCertificationCampaignRunnerTest::option($argv, '--setup-id');
        $setupVersion = PaperCertificationCampaignRunnerTest::option($argv, '--setup-version');
        $side = PaperCertificationCampaignRunnerTest::option($argv, '--side');

        return new PaperCertificationCampaignProcessResult(0, json_encode([
            'schema_version' => 'paper-replay-readiness-v1',
            'ready' => true,
            'runtime_ready' => true,
            'baseline_eligible' => true,
            'profile_eligibility' => 'baseline_eligible',
            'profile' => $mode,
            'run_id' => $runId,
            'configuration_snapshot_id' => 'sha256:' . hash('sha256', 'configuration'),
            'execution_cell_id' => 'sha256:' . hash('sha256', $runId),
            'source' => [
                'kind' => 'verified_public_replay',
                'dataset_id' => 'dataset-' . $venue,
                'events_file_sha256' => $eventsSha256,
                'network' => 'mainnet',
                'venue' => $venue,
            ],
            'execution' => [
                'mode' => 'paper',
                'exchange' => 'fake',
                'private_clients_enabled' => false,
                'mainnet_write_enabled' => false,
                'demo_testnet_write_enabled' => false,
            ],
            'clock' => [
                'type' => 'paper_replay_clock',
                'controlled' => true,
            ],
            'strategy' => [
                'schema_version' => 2,
                'mode_id' => $mode,
                'mode_version' => $modeVersion,
                'setup_id' => $setup,
                'setup_version' => $setupVersion,
                'side' => $side,
                'config_hash' => 'sha256:' . str_repeat('d', 64),
                'condition_catalog_hash' => 'sha256:' . str_repeat('e', 64),
            ],
        ], JSON_THROW_ON_ERROR), false);
    }

    /** @param list<string> $argv */
    private function receipt(array $argv): PaperCertificationCampaignProcessResult
    {
        if ($this->receipt === 'fail') {
            return new PaperCertificationCampaignProcessResult(1, json_encode([
                'schema_version' => 'paper-dataset-receipt-result-v1',
                'issued' => false,
                'blocker' => 'paper_dataset_checksum_mismatch',
            ], JSON_THROW_ON_ERROR), false, '/private/dataset/path');
        }
        $dataset = PaperCertificationCampaignRunnerTest::option($argv, '--dataset');
        $receipt = PaperCertificationCampaignRunnerTest::option($argv, '--receipt');
        if (file_exists($receipt)) {
            return new PaperCertificationCampaignProcessResult(1, '{"issued":false,"blocker":"paper_dataset_receipt_unwritable"}', false);
        }
        file_put_contents($receipt, json_encode(['dataset' => $dataset, 'version' => 1], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        chmod($receipt, 0600);
        $venue = str_contains($dataset, 'hyperliquid') ? 'hyperliquid' : 'okx';

        return new PaperCertificationCampaignProcessResult(0, json_encode([
            'schema_version' => 'paper-dataset-receipt-result-v1',
            'issued' => true,
            'dataset_id' => 'dataset-' . $venue,
            'event_count' => 1,
            'events_sha256' => hash('sha256', (string) file_get_contents($dataset . '/events.ndjson')),
            'code_tree_sha256' => str_repeat('f', 64),
            'event_limit' => 1000,
        ], JSON_THROW_ON_ERROR), false);
    }
}

/**
 * Starts children without waiting: each one completes after a deterministic number of polls
 * (varying per child) with the result of the synchronous delegate.
 */
final class AsyncPaperCampaignExecutor implements PaperCertificationCampaignAsyncProcessExecutorInterface
{
    /** @var list<list<string>> */
    public array $calls = [];
    public int $maximumConcurrency = 0;
    public int $stopped = 0;
    public int $running = 0;

    /** @var list<array{0: string, 1: string, 2: string, 3?: int}> */
    public array $log = [];

    public function __construct(
        private readonly AcceptingPaperCampaignExecutor $delegate,
        private readonly ?string $slowCommand = null,
    ) {
    }

    public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
    {
        $this->calls[] = $argv;

        return $this->delegate->execute($argv, $timeoutSeconds);
    }

    public function start(array $argv, int $timeoutSeconds): PaperCertificationCampaignRunningProcessInterface
    {
        $this->calls[] = $argv;
        ++$this->running;
        $this->maximumConcurrency = max($this->maximumConcurrency, $this->running);
        $this->log[] = ['start', $argv[4], self::runIdOf($argv)];
        $polls = $this->slowCommand !== null && in_array($this->slowCommand, $argv, true)
            ? PHP_INT_MAX
            : 1 + (count($this->calls) * 7) % 5;
        $executor = $this;

        return new class($argv, $timeoutSeconds, $polls, $this->delegate, $executor) implements PaperCertificationCampaignRunningProcessInterface {
            private ?PaperCertificationCampaignProcessResult $result = null;

            /** @param list<string> $argv */
            public function __construct(
                private readonly array $argv,
                private readonly int $timeoutSeconds,
                private int $polls,
                private readonly AcceptingPaperCampaignExecutor $delegate,
                private readonly AsyncPaperCampaignExecutor $executor,
            ) {
            }

            public function poll(): ?PaperCertificationCampaignProcessResult
            {
                if ($this->result !== null) {
                    return $this->result;
                }
                if (--$this->polls > 0) {
                    return null;
                }
                --$this->executor->running;
                $this->result = $this->delegate->execute($this->argv, $this->timeoutSeconds);
                $this->executor->log[] = ['done', $this->argv[4], AsyncPaperCampaignExecutor::runIdOf($this->argv), $this->result->exitCode];

                return $this->result;
            }

            public function stop(): PaperCertificationCampaignProcessResult
            {
                if ($this->result === null) {
                    ++$this->executor->stopped;
                    --$this->executor->running;
                    $this->result = new PaperCertificationCampaignProcessResult(143, '', false);
                }

                return $this->result;
            }
        };
    }

    /** @param list<string> $argv */
    public static function runIdOf(array $argv): string
    {
        foreach ($argv as $argument) {
            if (str_starts_with($argument, '--run-id=')) {
                return substr($argument, 9);
            }
        }

        return '';
    }

    /** @return array<string, list<string>> */
    public function runIds(): array
    {
        $commands = [];
        foreach ($this->calls as $argv) {
            if (!in_array('app:paper-market:dataset-receipt', $argv, true)) {
                $commands[PaperCertificationCampaignRunnerTest::option($argv, '--run-id')][] = $argv[4];
            }
        }

        return $commands;
    }
}

/** Records the dedicated database each cell child was given (start() children get none). */
final class DatabaseAwarePaperCampaignExecutor implements PaperCertificationCampaignEnvironmentProcessExecutorInterface
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var array<string, array<string, string>> run id => command => DATABASE_URL */
    public array $databasesByRunId = [];

    public function __construct(private readonly AsyncPaperCampaignExecutor $delegate)
    {
    }

    public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
    {
        $this->calls[] = $argv;

        return $this->delegate->execute($argv, $timeoutSeconds);
    }

    public function start(array $argv, int $timeoutSeconds): PaperCertificationCampaignRunningProcessInterface
    {
        // Dataset receipt children read files only; every cell child needs its own database.
        if (!in_array('app:paper-market:dataset-receipt', $argv, true)) {
            throw new \LogicException('A cell child must be started with its own database.');
        }
        $this->calls[] = $argv;

        return $this->delegate->start($argv, $timeoutSeconds);
    }

    public function startWithEnvironment(array $argv, int $timeoutSeconds, array $environment): PaperCertificationCampaignRunningProcessInterface
    {
        $this->calls[] = $argv;
        self::assertOnlyDatabase($environment);
        $this->databasesByRunId[AsyncPaperCampaignExecutor::runIdOf($argv)][$argv[4]] = $environment['DATABASE_URL'];

        return $this->delegate->start($argv, $timeoutSeconds);
    }

    /** @param array<string, string> $environment */
    private static function assertOnlyDatabase(array $environment): void
    {
        if (array_keys($environment) !== ['DATABASE_URL']) {
            throw new \LogicException('A cell child receives its database and nothing else.');
        }
    }
}
