<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PaperExecutionReplayCommand;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Configuration\PaperPrivateConfigurationReader;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\MarketData\PaperMarketDataChannel;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use App\Trading\Paper\Runtime\PaperReplayCheckpointResolver;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\Tests\Trading\Paper\Execution\InMemoryPaperExecutionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperExecutionReplayCommand::class)]
#[CoversClass(PaperReplayCheckpointResolver::class)]
final class PaperExecutionReplayCommandTest extends TestCase
{
    public function testEveryOperatorOptionIsMandatory(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertStringContainsString('--dataset', $tester->getDisplay());
    }

    public function testRelativePathsFailBeforeDatasetIteration(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([
            '--dataset' => 'relative/dataset',
            '--configuration' => 'relative/config.json',
            '--profile' => 'regular',
            '--run-id' => 'paper-run-001',
        ]));
        self::assertStringContainsString('paper_execution_path_must_be_absolute', $tester->getDisplay());
    }

    public function testLegacyDatasetIsRejectedBeforeCoordinatorConsumption(): void
    {
        $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/paper_command_' . bin2hex(random_bytes(5));
        $dataset = $root . '/complete-dataset';
        try {
            mkdir($dataset, 0700, true);
            foreach (['manifest.json', 'events.ndjson'] as $file) {
                copy(__DIR__ . '/../Fixtures/PaperMarketData/complete-dataset/' . $file, $dataset . '/' . $file);
                chmod($dataset . '/' . $file, 0600);
            }
            $configuration = $root . '/configuration.json';
            file_put_contents($configuration, '{"strategy":{}}');
            chmod($configuration, 0600);
            $verifier = new PaperDatasetVerifier();
            $command = $this->command($verifier, new PaperReplayReader($verifier, new PaperReplayCheckpointStore(), new PaperReplayClock()));
            $tester = new CommandTester($command);

            self::assertSame(Command::INVALID, $tester->execute([
                '--dataset' => $dataset,
                '--configuration' => $configuration,
                '--profile' => 'regular',
                '--run-id' => 'paper-run-001',
            ]));
            self::assertStringContainsString('paper_dataset_network_provenance_uncertifiable', $tester->getDisplay());
        } finally {
            foreach (glob($dataset . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dataset);
            @unlink($root . '/configuration.json');
            @rmdir($root);
        }
    }

    public function testPendingEffectResumesBeforeItsClaimedSourceInsteadOfSkippingIt(): void
    {
        $store = new InMemoryPaperExecutionStore();
        $cell = PaperExecutionCell::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'sha256:' . str_repeat('a', 64),
            'scalper_micro',
            'paper-run-001',
        );
        $first = $this->event(0);
        $pending = $this->event(1);
        $store->claimSource($cell, 0, $first);
        $store->claimSource($cell, 1, $pending);
        $store->appendEffect($cell, 1, 'sha256:' . str_repeat('1', 64), ['pending' => true]);
        $manifestPath = __DIR__ . '/../Fixtures/PaperExecution/hyperliquid-testnet-cell/manifest.json';
        $manifest = (new PaperDatasetManifestCodec())->decode((string) file_get_contents($manifestPath));
        self::assertNotNull($manifest->eventsFileSha256);
        $store->bindDataset($cell, $manifest->datasetId, $manifest->eventsFileSha256);
        $checkpoint = (new PaperReplayCheckpointResolver($store))->resolve(
            $cell,
            $manifest,
            'paper-consumer',
        );

        self::assertNotNull($checkpoint);
        self::assertSame(0, $checkpoint->eventIndex);
        self::assertSame($first->eventId, $checkpoint->eventId);
    }

    public function testSafetyGuardRunsBeforeAnyPaperStateRegistration(): void
    {
        $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/paper_command_guard_' . bin2hex(random_bytes(5));
        $dataset = $root . '/dataset';
        try {
            mkdir($dataset, 0700, true);
            foreach (['manifest.json', 'events.ndjson'] as $file) {
                copy(__DIR__ . '/../Fixtures/PaperExecution/okx-mainnet-cell/' . $file, $dataset . '/' . $file);
                chmod($dataset . '/' . $file, 0600);
            }
            $configuration = $root . '/configuration.json';
            file_put_contents($configuration, '{"strategy":{}}');
            chmod($configuration, 0600);
            $store = new InMemoryPaperExecutionStore();
            $coordinator = new class implements PaperEventCoordinatorInterface {
                public function assertReady(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, array $symbols): void { throw new \LogicException('paper_execution_disabled'); }
                public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void { throw new \LogicException('consume_at_must_not_be_reached'); }
            };
            $verifier = new PaperDatasetVerifier();
            $reader = new PaperReplayReader($verifier, new PaperReplayCheckpointStore(), new PaperReplayClock());
            $tester = new CommandTester($this->command($verifier, $reader, $store, $coordinator));

            self::assertSame(Command::INVALID, $tester->execute([
                '--dataset' => $dataset,
                '--configuration' => $configuration,
                '--profile' => 'scalper_micro',
                '--run-id' => 'paper-run-guard',
            ]));
            self::assertStringContainsString('paper_execution_disabled', $tester->getDisplay());
            self::assertSame(0, $store->registrationWrites);
        } finally {
            foreach (glob($dataset . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dataset);
            @unlink($root . '/configuration.json');
            @rmdir($root);
        }
    }

    public function testModernReplayStopsBeforeAnyPaperStateRegistration(): void
    {
        $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/paper_command_modern_' . bin2hex(random_bytes(5));
        $dataset = $root . '/dataset';
        try {
            mkdir($dataset, 0700, true);
            foreach (['manifest.json', 'events.ndjson'] as $file) {
                copy(__DIR__ . '/../Fixtures/PaperExecution/okx-mainnet-cell/' . $file, $dataset . '/' . $file);
                chmod($dataset . '/' . $file, 0600);
            }
            $configuration = $root . '/configuration.json';
            file_put_contents($configuration, '{"strategy":{"mode":"day_trading"}}');
            chmod($configuration, 0600);
            $store = new InMemoryPaperExecutionStore();
            $verifier = new PaperDatasetVerifier();
            $reader = new PaperReplayReader($verifier, new PaperReplayCheckpointStore(), new PaperReplayClock());
            $tester = new CommandTester($this->command($verifier, $reader, $store));

            self::assertSame(Command::INVALID, $tester->execute([
                '--dataset' => $dataset,
                '--configuration' => $configuration,
                '--mode-id' => 'day_trading',
                '--mode-version' => '1.1.0',
                '--setup-id' => 'day_trading.trend_continuation.long',
                '--setup-version' => '1.1.0',
                '--side' => 'long',
                '--run-id' => 'paper-modern-run-001',
            ]));
            self::assertStringContainsString('paper_modern_strategy_bridge_unavailable', $tester->getDisplay());
            self::assertSame(0, $store->registrationWrites);
        } finally {
            foreach (glob($dataset . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dataset);
            @unlink($root . '/configuration.json');
            @rmdir($root);
        }
    }

    public function testReplayPersistsExactVerifiedRecorderVersionWithoutEmittingIt(): void
    {
        $root = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/paper_command_source_build_' . bin2hex(random_bytes(5));
        $dataset = $root . '/dataset';
        try {
            mkdir($dataset, 0700, true);
            foreach (['manifest.json', 'events.ndjson'] as $file) {
                copy(__DIR__ . '/../Fixtures/PaperExecution/okx-mainnet-cell/' . $file, $dataset . '/' . $file);
                chmod($dataset . '/' . $file, 0600);
            }
            $configuration = $root . '/configuration.json';
            file_put_contents($configuration, '{"strategy":{}}');
            chmod($configuration, 0600);
            $store = new InMemoryPaperExecutionStore();
            $coordinator = new class implements PaperEventCoordinatorInterface {
                public ?PaperExecutionCell $cell = null;

                public function assertReady(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, array $symbols): void
                {
                    $this->cell = $cell;
                }

                public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void
                {
                }
            };
            $verifier = new PaperDatasetVerifier();
            $reader = new PaperReplayReader($verifier, new PaperReplayCheckpointStore(), new PaperReplayClock());
            $tester = new CommandTester($this->command($verifier, $reader, $store, $coordinator));

            self::assertSame(Command::SUCCESS, $tester->execute([
                '--dataset' => $dataset,
                '--configuration' => $configuration,
                '--profile' => 'regular',
                '--run-id' => 'paper-source-build-run',
            ]));
            self::assertNotNull($coordinator->cell);
            self::assertSame('1.0.0', $store->datasetIdentity($coordinator->cell)['source_build_version']);
            self::assertStringNotContainsString('1.0.0', $tester->getDisplay());
        } finally {
            foreach (glob($dataset . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dataset);
            @unlink($root . '/configuration.json');
            @rmdir($root);
        }
    }

    private function event(int $second): PaperMarketEvent
    {
        $timestamp = new \DateTimeImmutable(sprintf('2026-08-01T10:00:%02dZ', $second));

        return PaperMarketEvent::create(
            PaperMarketDataNetwork::TESTNET,
            PaperMarketDataVenue::HYPERLIQUID,
            'BTCUSDT',
            PaperMarketDataChannel::TOP_OF_BOOK,
            $timestamp,
            $timestamp,
            (string) $second,
            ['bid_price' => '99', 'ask_price' => '101'],
        );
    }

    private function command(?PaperDatasetVerifier $verifier = null, ?PaperReplayReader $reader = null, ?PaperExecutionStoreInterface $store = null, ?PaperEventCoordinatorInterface $coordinator = null): PaperExecutionReplayCommand
    {
        $coordinator ??= new class implements PaperEventCoordinatorInterface {
            public function assertReady(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, array $symbols): void {}
            public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void { throw new \LogicException('not_called'); }
        };
        $store ??= new InMemoryPaperExecutionStore();

        $verifier ??= (new \ReflectionClass(PaperDatasetVerifier::class))->newInstanceWithoutConstructor();

        $reader ??= (new \ReflectionClass(PaperReplayReader::class))->newInstanceWithoutConstructor();

        return new PaperExecutionReplayCommand(
            new PaperReplayReadinessService(
                $verifier,
                new PaperPrivateConfigurationReader(),
                new PaperConfigurationSnapshotFactory(),
                new PaperProfileRegistry(),
                new PaperReplayClock(),
                $coordinator,
                $reader,
                $store,
                new PaperReplayCheckpointResolver($store),
                new EffectiveTradingConfigResolver(),
            ),
            $reader,
            $store,
            $coordinator,
        );
    }
}
