<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\BoundedDuplicateAwareJsonDecoder;
use App\Command\PaperCertificationCampaignCommand;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessExecutorInterface;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessResult;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignRunner;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignStateStore;
use App\Trading\Paper\Certification\PaperCertificationMatrixBuilder;
use App\TradingCore\Mode\ModeContractLoader;
use App\TradingCore\Setup\SetupContractLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperCertificationCampaignCommand::class)]
final class PaperCertificationCampaignCommandTest extends TestCase
{
    public function testRequiresTheVersionedMatrixSpecification(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame([
            'schema_version' => PaperCertificationCampaignRunner::STATE_SCHEMA,
            'status' => 'failed',
            'blocker' => '--spec is required',
        ], json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR));
    }

    public function testRejectsDuplicateScopeMappingsBeforeCampaignExecution(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([
            '--spec' => dirname(__DIR__, 2) . '/config/trading/paper_certification/first-baseline-v1.json',
            '--configuration' => '/private/configuration.json',
            '--dataset' => ['mainnet/okx=/private/okx', 'mainnet/okx=/private/duplicate'],
            '--campaign-id' => 'first-baseline-aug23',
            '--state' => '/private/state.json',
        ]));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('paper_campaign_dataset_mapping_invalid', $payload['blocker']);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidParallelism(): iterable
    {
        yield 'zero' => ['0'];
        yield 'above the maximum' => ['9'];
        yield 'not an integer' => ['two'];
    }

    #[DataProvider('invalidParallelism')]
    public function testRejectsAnInvalidParallelismBeforeAnyProcess(string $parallelism): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::INVALID, $tester->execute([
            '--spec' => dirname(__DIR__, 2) . '/config/trading/paper_certification/first-baseline-v1.json',
            '--configuration' => '/private/configuration.json',
            '--dataset' => ['mainnet/okx=/private/okx', 'mainnet/hyperliquid=/private/hyperliquid'],
            '--campaign-id' => 'first-baseline-aug23',
            '--state' => '/private/state.json',
            '--max-parallel-cells' => $parallelism,
        ]));
        $payload = json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR);
        self::assertSame('paper_campaign_parallelism_invalid', $payload['blocker']);
    }

    public function testASignalStopsTheCampaignWithoutExitingSuccessfully(): void
    {
        $root = sys_get_temp_dir() . '/paper-campaign-signal-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root, 0700));
        $root = (string) realpath($root);
        try {
            file_put_contents($root . '/configuration.json', '{"risk":{"max_notional":"1000"}}');
            chmod($root . '/configuration.json', 0600);
            $datasets = [];
            foreach (['mainnet/okx', 'mainnet/hyperliquid'] as $scope) {
                $directory = $root . '/' . str_replace('/', '-', $scope);
                self::assertTrue(mkdir($directory, 0700));
                file_put_contents($directory . '/manifest.json', '{}');
                file_put_contents($directory . '/events.ndjson', "{}\n");
                $datasets[] = $scope . '=' . $directory;
            }
            $executor = new class implements PaperCertificationCampaignProcessExecutorInterface {
                public ?PaperCertificationCampaignCommand $command = null;
                public int $calls = 0;

                public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
                {
                    ++$this->calls;
                    $this->command?->handleSignal(\defined('SIGTERM') ? \SIGTERM : 15);

                    return new PaperCertificationCampaignProcessResult(143, '', false);
                }
            };
            $command = $this->command($executor);
            $executor->command = $command;
            self::assertContains(\defined('SIGTERM') ? \SIGTERM : 15, $command->getSubscribedSignals());
            self::assertFalse($command->handleSignal(\defined('SIGINT') ? \SIGINT : 2), 'The handler never exits by itself.');
            $command = $this->command($executor);
            $executor->command = $command;
            $tester = new CommandTester($command);

            $exitCode = $tester->execute([
                '--spec' => dirname(__DIR__, 2) . '/config/trading/paper_certification/first-baseline-v1.json',
                '--configuration' => $root . '/configuration.json',
                '--dataset' => $datasets,
                '--campaign-id' => 'first-baseline-aug23',
                '--state' => $root . '/state.json',
            ]);

            self::assertSame(128 + (\defined('SIGTERM') ? \SIGTERM : 15), $exitCode);
            self::assertSame(1, $executor->calls);
            $payload = json_decode(trim($tester->getDisplay()), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame('failed', $payload['status']);
            self::assertSame(0, $payload['completed_cells']);
        } finally {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($root);
        }
    }

    private function command(?PaperCertificationCampaignProcessExecutorInterface $executor = null): PaperCertificationCampaignCommand
    {
        $executor ??= new class implements PaperCertificationCampaignProcessExecutorInterface {
            public function execute(array $argv, int $timeoutSeconds): PaperCertificationCampaignProcessResult
            {
                throw new \LogicException('process_must_not_start');
            }
        };

        return new PaperCertificationCampaignCommand(
            new BoundedDuplicateAwareJsonDecoder(),
            new PaperCertificationMatrixBuilder(new ModeContractLoader(), new SetupContractLoader()),
            new PaperCertificationCampaignRunner(
                $executor,
                new PaperCertificationCampaignStateStore(),
                '/opt/trading-app',
                '/usr/bin/php',
            ),
        );
    }
}
