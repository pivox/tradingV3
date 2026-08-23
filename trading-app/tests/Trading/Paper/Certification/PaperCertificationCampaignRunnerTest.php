<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Certification;

use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessExecutorInterface;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessResult;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignRunner;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignStateStore;
use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperCertificationCampaignRunner::class)]
#[CoversClass(PaperCertificationCampaignStateStore::class)]
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
        self::assertCount(24, $executor->calls);
        $firstRunIds = [];
        foreach (array_chunk($executor->calls, 2) as $pair) {
            self::assertStringContainsString('app:paper-market:runtime-check', implode(' ', $pair[0]));
            self::assertStringContainsString('app:paper-market:replay', implode(' ', $pair[1]));
            $readinessRunId = self::option($pair[0], '--run-id');
            self::assertSame($readinessRunId, self::option($pair[1], '--run-id'));
            $firstRunIds[] = $readinessRunId;
        }
        self::assertCount(12, array_unique($firstRunIds));
        self::assertContainsOnly('string', $firstRunIds);

        $second = $runner->run(
            $this->matrix(),
            'first-baseline-aug23',
            $this->configuration,
            $this->datasets,
            $this->state,
            60,
        );

        self::assertSame('completed', $second['status']);
        self::assertCount(48, $executor->calls, 'Completed cells must be revalidated against the database authority.');
        self::assertSame($firstRunIds, array_map(
            static fn (array $pair): string => self::option($pair[0], '--run-id'),
            array_chunk(array_slice($executor->calls, 24), 2),
        ));
        self::assertSame(2, $second['cells'][0]['attempts']);

        $persisted = file_get_contents($this->state);
        self::assertIsString($persisted);
        self::assertStringNotContainsString($this->root, $persisted);
        self::assertStringNotContainsString('max_notional', $persisted);
        self::assertSame(0600, fileperms($this->state) & 0777);
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
        $executor = new class implements PaperCertificationCampaignProcessExecutorInterface {
            /** @var list<list<string>> */
            public array $calls = [];

            public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
            {
                $this->calls[] = $argv;

                return new PaperCertificationCampaignProcessResult(0, json_encode([
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
                ], JSON_THROW_ON_ERROR), false);
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
        self::assertSame('paper_campaign_readiness_identity_mismatch', $result['blocker']);
        self::assertCount(1, $executor->calls);
    }

    public function testStopsAtFirstReplayFailureAndPersistsOnlyRedactedFailureState(): void
    {
        $executor = $this->acceptingExecutor(failReplay: true);

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
        self::assertCount(2, $executor->calls);
        self::assertStringNotContainsString('private-child-error', (string) file_get_contents($this->state));
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
        self::assertSame(1, $executor->calls);
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
            self::assertCount(24, $executor->calls);
        }
    }

    private function runner(PaperCertificationCampaignProcessExecutorInterface $executor): PaperCertificationCampaignRunner
    {
        return new PaperCertificationCampaignRunner(
            $executor,
            new PaperCertificationCampaignStateStore(),
            '/opt/trading-app',
            '/usr/bin/php',
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

    private function acceptingExecutor(bool $failReplay = false): AcceptingPaperCampaignExecutor
    {
        return new AcceptingPaperCampaignExecutor($failReplay);
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

final class AcceptingPaperCampaignExecutor implements PaperCertificationCampaignProcessExecutorInterface
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly bool $failReplay)
    {
    }

    public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
    {
        $this->calls[] = $argv;
        if (in_array('app:paper-market:replay', $argv, true)) {
            return $this->failReplay
                ? new PaperCertificationCampaignProcessResult(2, '', false, 'private-child-error')
                : new PaperCertificationCampaignProcessResult(0, 'cell=ok', false);
        }
        $dataset = PaperCertificationCampaignRunnerTest::option($argv, '--dataset');
        $venue = str_contains($dataset, 'hyperliquid') ? 'hyperliquid' : 'okx';
        $mode = PaperCertificationCampaignRunnerTest::option($argv, '--mode-id');
        $modeVersion = PaperCertificationCampaignRunnerTest::option($argv, '--mode-version');
        $setup = PaperCertificationCampaignRunnerTest::option($argv, '--setup-id');
        $setupVersion = PaperCertificationCampaignRunnerTest::option($argv, '--setup-version');
        $side = PaperCertificationCampaignRunnerTest::option($argv, '--side');
        $runId = PaperCertificationCampaignRunnerTest::option($argv, '--run-id');

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
                'events_file_sha256' => hash('sha256', $venue),
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
}
