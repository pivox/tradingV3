<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PaperExecutionReplayCommand;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\Persistence\PaperExecutionStoreInterface;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayReader;
use App\Trading\Paper\Replay\PaperReplayCheckpointStore;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Tests\Trading\Paper\Execution\InMemoryPaperExecutionStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperExecutionReplayCommand::class)]
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
            self::assertStringContainsString('paper_execution_legacy_dataset_forbidden', $tester->getDisplay());
        } finally {
            foreach (glob($dataset . '/*') ?: [] as $file) { @unlink($file); }
            @rmdir($dataset);
            @unlink($root . '/configuration.json');
            @rmdir($root);
        }
    }

    private function command(?PaperDatasetVerifier $verifier = null, ?PaperReplayReader $reader = null): PaperExecutionReplayCommand
    {
        $coordinator = new class implements PaperEventCoordinatorInterface {
            public function consumeAt(PaperExecutionCell $cell, PaperProfileEligibility $eligibility, string $datasetId, int $sourcePosition, PaperMarketEvent $event): void { throw new \LogicException('not_called'); }
        };
        /** @var PaperExecutionStoreInterface $store */
        $store = new InMemoryPaperExecutionStore();

        return new PaperExecutionReplayCommand(
            $verifier ?? (new \ReflectionClass(PaperDatasetVerifier::class))->newInstanceWithoutConstructor(),
            $reader ?? (new \ReflectionClass(PaperReplayReader::class))->newInstanceWithoutConstructor(),
            new PaperConfigurationSnapshotFactory(),
            $store,
            $coordinator,
        );
    }
}
