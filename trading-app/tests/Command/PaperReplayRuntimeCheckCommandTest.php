<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PaperReplayRuntimeCheckCommand;
use App\Trading\Paper\Dataset\PaperDatasetVerifier;
use App\Trading\Paper\Execution\Configuration\PaperConfigurationSnapshotFactory;
use App\Trading\Paper\Execution\Configuration\PaperPrivateConfigurationReader;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\Execution\PaperEventCoordinatorInterface;
use App\Trading\Paper\Execution\Profile\PaperProfileEligibility;
use App\Trading\Paper\Execution\Profile\PaperProfileRegistry;
use App\Trading\Paper\MarketData\PaperMarketEvent;
use App\Trading\Paper\Replay\PaperReplayClock;
use App\Trading\Paper\Runtime\PaperReplayReadinessService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperReplayRuntimeCheckCommand::class)]
#[CoversClass(PaperReplayReadinessService::class)]
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
        $tester = new CommandTester($this->command($this->acceptingCoordinator()));

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

    private function command(PaperEventCoordinatorInterface $coordinator, ?PaperReplayClock $clock = null): PaperReplayRuntimeCheckCommand
    {
        return new PaperReplayRuntimeCheckCommand(new PaperReplayReadinessService(
            new PaperDatasetVerifier(),
            new PaperPrivateConfigurationReader(),
            new PaperConfigurationSnapshotFactory(),
            new PaperProfileRegistry(),
            $clock ?? new PaperReplayClock(),
            $coordinator,
        ));
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
