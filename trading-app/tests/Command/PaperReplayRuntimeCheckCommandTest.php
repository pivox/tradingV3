<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PaperReplayRuntimeCheckCommand;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Configuration\PaperPrivateConfigurationReader;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Persistence\PaperExecutionCellState;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\MarketData\CanonicalJson;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use App\Trading\Paper\Runtime\PaperReplayCheckpointResolver;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\TradingConfigLayerLoader;
use App\Tests\Trading\Paper\Execution\InMemoryPaperExecutionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperReplayRuntimeCheckCommand::class)]
#[CoversClass(PaperReplayReadinessService::class)]
#[CoversClass(PaperExecutionCellState::class)]
#[CoversClass(PaperReplayCheckpointResolver::class)]
final class PaperReplayRuntimeCheckCommandTest extends TestCase
{
    private string $root;
    private string $dataset;
    private string $configuration;

    protected function setUp(): void
    {
        $this->root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/paper_readiness_' . bin2hex(random_bytes(5));
        $this->dataset = $this->root . '/dataset';
        mkdir($this->dataset, 0700, true);
        foreach (['manifest.json', 'events.ndjson'] as $file) {
            copy(__DIR__ . '/../Fixtures/PaperExecution/okx-mainnet-cell/' . $file, $this->dataset . '/' . $file);
            chmod($this->dataset . '/' . $file, 0600);
        }
        $this->configuration = $this->root . '/configuration.json';
        file_put_contents($this->configuration, '{"strategy":{"mode":"day_trading"}}');
        chmod($this->configuration, 0600);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dataset . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dataset);
        @unlink($this->configuration);
        @rmdir($this->root);
    }

    public function testReportsRedactedTechnicalReadinessWithoutPromotingReferenceProfileOrWritingState(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), store: $store));

        self::assertSame(Command::SUCCESS, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('paper-replay-readiness-v1', $payload['schema_version']);
        self::assertTrue($payload['ready']);
        self::assertTrue($payload['runtime_ready']);
        self::assertFalse($payload['baseline_eligible']);
        self::assertSame('reference_only', $payload['profile_eligibility']);
        self::assertSame('paper', $payload['execution']['mode']);
        self::assertSame('fake', $payload['execution']['exchange']);
        self::assertFalse($payload['execution']['private_clients_enabled']);
        self::assertFalse($payload['execution']['mainnet_write_enabled']);
        self::assertFalse($payload['execution']['demo_testnet_write_enabled']);
        self::assertSame('paper-exec-okx-mainnet-001', $payload['source']['dataset_id']);
        self::assertSame('mainnet', $payload['source']['network']);
        self::assertSame('okx', $payload['source']['venue']);
        self::assertTrue($payload['clock']['controlled']);
        self::assertStringNotContainsString($this->root, $tester->getDisplay());
        self::assertSame(0, $store->registrationWrites);
    }

    public function testModernIdentityIsResolvedExactlyButRemainsBlockedWithoutCanonicalBridge(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), store: $store));
        $options = $this->options();
        unset($options['--profile']);
        $options += [
            '--mode-id' => 'day_trading',
            '--mode-version' => '1.1.0',
            '--setup-id' => 'day_trading.trend_continuation.long',
            '--setup-version' => '1.1.0',
            '--side' => 'long',
        ];

        self::assertSame(Command::INVALID, $tester->execute($options));
        $payload = json_decode(trim($tester->getDisplay()), true, 32, JSON_THROW_ON_ERROR);

        self::assertFalse($payload['ready']);
        self::assertFalse($payload['runtime_ready']);
        self::assertFalse($payload['baseline_eligible']);
        self::assertSame('paper_modern_strategy_bridge_unavailable', $payload['blocker']);
        self::assertSame([
            'schema_version' => 2,
            'mode_id' => 'day_trading',
            'mode_version' => '1.1.0',
            'setup_id' => 'day_trading.trend_continuation.long',
            'setup_version' => '1.1.0',
            'side' => 'long',
            'config_hash' => $payload['strategy']['config_hash'],
            'condition_catalog_hash' => $payload['strategy']['condition_catalog_hash'],
        ], $payload['strategy']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $payload['strategy']['config_hash']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $payload['strategy']['condition_catalog_hash']);
        self::assertSame('mainnet', $payload['source']['network']);
        self::assertSame('okx', $payload['source']['venue']);
        self::assertSame(0, $store->registrationWrites);
        self::assertStringNotContainsString($this->root, $tester->getDisplay());
    }

    public function testProfileCannotBeMixedWithModernOptions(): void
    {
        $tester = new CommandTester($this->command($this->acceptingCoordinator()));
        $options = $this->options() + [
            '--mode-id' => 'day_trading',
            '--mode-version' => '1.1.0',
            '--setup-id' => 'day_trading.trend_continuation.long',
            '--setup-version' => '1.1.0',
            '--side' => 'long',
        ];

        self::assertSame(Command::INVALID, $tester->execute($options));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('paper_strategy_selection_ambiguous', $payload['blocker']);
    }

    public function testLegacyAliasIsNeverAcceptedAsAModernMode(): void
    {
        $tester = new CommandTester($this->command($this->acceptingCoordinator()));
        $options = $this->options();
        unset($options['--profile']);
        $options += [
            '--mode-id' => 'regular',
            '--mode-version' => '1.1.0',
            '--setup-id' => 'day_trading.trend_continuation.long',
            '--setup-version' => '1.1.0',
            '--side' => 'long',
        ];

        self::assertSame(Command::INVALID, $tester->execute($options));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('paper_modern_mode_id_invalid', $payload['blocker']);
        self::assertArrayNotHasKey('strategy', $payload);
    }

    public function testEffectiveConfigFailureNeverLeaksItsAbsoluteLayerPath(): void
    {
        $privateRoot = $this->root . '/private-effective-config';
        $tester = new CommandTester($this->command(
            $this->acceptingCoordinator(),
            resolver: new EffectiveTradingConfigResolver(loader: new TradingConfigLayerLoader($privateRoot)),
        ));
        $options = $this->options();
        unset($options['--profile']);
        $options += [
            '--mode-id' => 'day_trading',
            '--mode-version' => '1.1.0',
            '--setup-id' => 'day_trading.trend_continuation.long',
            '--setup-version' => '1.1.0',
            '--side' => 'long',
        ];

        self::assertSame(Command::INVALID, $tester->execute($options));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('paper_modern_effective_config_unavailable', $payload['blocker']);
        self::assertStringNotContainsString($privateRoot, $tester->getDisplay());
    }

    public function testFailureIsStableJsonAndDoesNotLeakPaths(): void
    {
        $coordinator = new class implements PaperEventCoordinatorInterface {
            public function assertReady(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, array $symbols): void
            {
                throw new \LogicException('paper_execution_disabled');
            }

            public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void
            {
                throw new \LogicException('not_called');
            }
        };
        $tester = new CommandTester($this->command($coordinator));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame([
            'schema_version' => 'paper-replay-readiness-v1',
            'ready' => false,
            'runtime_ready' => false,
            'blocker' => 'paper_execution_disabled',
        ], $payload);
        self::assertStringNotContainsString($this->root, $tester->getDisplay());
    }

    public function testControlledClockMustBeReadyForDatasetStart(): void
    {
        $clock = new PaperReplayClock(new \DateTimeImmutable('2026-08-01T10:00:01Z'));
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), $clock));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_replay_clock_regression', $payload['blocker']);
        self::assertSame('2026-08-01T10:00:01.000000Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function testDatasetOverTheEffectiveReplayLimitIsNotReady(): void
    {
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), eventLimit: 3));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_replay_event_limit_exceeded', $payload['blocker']);
    }

    public function testKilledExistingCellIsNotReadyAndTheCheckDoesNotMutateIt(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $cell = $this->cell();
        $store->registerCell($cell, PaperProfileEligibility::REFERENCE_ONLY);
        $store->kill($cell);
        $writes = $store->registrationWrites;
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), store: $store));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_execution_cell_killed', $payload['blocker']);
        self::assertSame($writes, $store->registrationWrites);
    }

    public function testExistingCellBoundToAnotherDatasetIsNotReady(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $cell = $this->cell();
        $store->registerCell($cell, PaperProfileEligibility::REFERENCE_ONLY);
        $store->bindDataset($cell, 'another-dataset', str_repeat('a', 64));
        $writes = $store->registrationWrites;
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), store: $store));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_execution_dataset_identity_conflict', $payload['blocker']);
        self::assertSame($writes, $store->registrationWrites);
    }

    public function testExistingCellBoundToAnotherSourceBuildIsNotReady(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $cell = $this->cell();
        $manifest = json_decode((string) file_get_contents($this->dataset . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsString($manifest['events_file_sha256']);
        $store->registerCell($cell, PaperProfileEligibility::REFERENCE_ONLY);
        $store->bindDataset(
            $cell,
            'paper-exec-okx-mainnet-001',
            $manifest['events_file_sha256'],
            'forged-recorder.v2',
        );
        $writes = $store->registrationWrites;
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), store: $store));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_execution_dataset_identity_conflict', $payload['blocker']);
        self::assertSame($writes, $store->registrationWrites);
    }

    public function testStoreFailureIsNormalizedWithoutLeakingDatabaseDetails(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $store->inspectionFailure = new \RuntimeException('postgres://user:password@private-host/database');
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), store: $store));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_execution_state_inspection_failed', $payload['blocker']);
        self::assertStringNotContainsString('private-host', $tester->getDisplay());
        self::assertStringNotContainsString('password', $tester->getDisplay());
    }

    public function testCheckpointResolutionFailureIsNormalizedWithoutLeakingDatabaseDetails(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $store->registerCell($this->cell(), PaperProfileEligibility::REFERENCE_ONLY);
        $store->checkpointFailure = new \RuntimeException('postgres://user:password@private-host/checkpoint');
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), store: $store));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_execution_state_inspection_failed', $payload['blocker']);
        self::assertStringNotContainsString('private-host', $tester->getDisplay());
        self::assertStringNotContainsString('password', $tester->getDisplay());
    }

    public function testFreeFormRecorderVersionIsNeverEmitted(): void
    {
        $manifestPath = $this->dataset . '/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        $manifest['recorder_version'] = 'ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        file_put_contents($manifestPath, CanonicalJson::encode($manifest));
        chmod($manifestPath, 0600);
        $tester = new CommandTester($this->command($this->acceptingCoordinator()));

        self::assertSame(Command::SUCCESS, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 32, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('recorder_version', $payload['source']);
        self::assertStringNotContainsString('ghp_', $tester->getDisplay());
    }

    public function testPersistedResumeAnchorMustExistInThePreparedDataset(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $cell = $this->cell();
        $store->registerCell($cell, PaperProfileEligibility::REFERENCE_ONLY);
        $manifest = json_decode((string) file_get_contents($this->dataset . '/manifest.json'), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertIsString($manifest['events_file_sha256']);
        $store->bindDataset($cell, 'paper-exec-okx-mainnet-001', $manifest['events_file_sha256']);
        $timestamp = new \DateTimeImmutable('2026-08-01T09:59:59Z');
        $store->claimSource($cell, 0, PaperMarketEvent::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $timestamp,
            $timestamp,
            '999',
            ['bid_price' => '99', 'ask_price' => '101'],
        ));
        $clock = new PaperReplayClock();
        $tester = new CommandTester($this->command($this->acceptingCoordinator(), $clock, $store));

        self::assertSame(Command::INVALID, $tester->execute($this->options()));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('paper_replay_checkpoint_event_not_found', $payload['blocker']);
        self::assertSame('1970-01-01T00:00:00.000000Z', $clock->now()->format('Y-m-d\TH:i:s.u\Z'));
    }

    /** @return array<string, string> */
    private function options(): array
    {
        return [
            '--dataset' => $this->dataset,
            '--configuration' => $this->configuration,
            '--profile' => 'regular',
            '--run-id' => 'paper-readiness-001',
        ];
    }

    private function command(
        PaperEventCoordinatorInterface $coordinator,
        ?PaperReplayClock $clock = null,
        ?PaperExecutionStoreInterface $store = null,
        int $eventLimit = PaperReplayReader::DEFAULT_EVENT_LIMIT,
        ?EffectiveTradingConfigResolver $resolver = null,
    ): PaperReplayRuntimeCheckCommand {
        $verifier = new PaperDatasetVerifier();
        $clock ??= new PaperReplayClock();
        $store ??= new InMemoryPaperExecutionStore();
        $reader = new PaperReplayReader($verifier, new PaperReplayCheckpointStore(), $clock, $eventLimit);

        return new PaperReplayRuntimeCheckCommand(new PaperReplayReadinessService(
            $verifier,
            new PaperPrivateConfigurationReader(),
            new PaperConfigurationSnapshotFactory(),
            new PaperProfileRegistry(),
            $clock,
            $coordinator,
            $reader,
            $store,
            new PaperReplayCheckpointResolver($store),
            $resolver ?? new EffectiveTradingConfigResolver(),
        ));
    }

    private function cell(): PaperExecutionCell
    {
        $snapshot = (new PaperConfigurationSnapshotFactory())->create([
            'strategy' => ['mode' => 'day_trading'],
        ]);

        return PaperExecutionCell::create(
            PaperMarketDataNetwork::MAINNET,
            PaperMarketDataVenue::OKX,
            $snapshot->id,
            'regular',
            'paper-readiness-001',
        );
    }

    private function acceptingCoordinator(): PaperEventCoordinatorInterface
    {
        return new class implements PaperEventCoordinatorInterface {
            public function assertReady(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, array $symbols): void {}

            public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void
            {
                throw new \LogicException('not_called');
            }
        };
    }
}
