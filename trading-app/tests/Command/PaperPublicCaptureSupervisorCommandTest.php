<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\PaperPublicCaptureSupervisorCommand;
use App\Trading\Paper\Capture\PaperPublicCaptureAttemptExecutorInterface;
use App\Trading\Paper\Capture\PaperPublicCaptureAttemptResult;
use App\Trading\Paper\Capture\PaperPublicCaptureSupervisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(PaperPublicCaptureSupervisorCommand::class)]
final class PaperPublicCaptureSupervisorCommandTest extends TestCase
{
    public function testEmitsCanonicalSummaryAfterRetryToSuccess(): void
    {
        $executor = new SupervisorCommandAttemptExecutor([1, 0]);
        $tester = new CommandTester(new PaperPublicCaptureSupervisorCommand(
            new PaperPublicCaptureSupervisor($executor),
        ));

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--venue' => 'hyperliquid',
            '--dataset-prefix' => 'representative-hyperliquid-20260905',
            '--duration-sec' => '86400',
            '--attempts' => '3',
        ]));
        $payload = json_decode(trim($tester->getDisplay()), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertMatchesRegularExpression(
            '/\Arepresentative-hyperliquid-20260905-run-[0-9a-f]{32}-attempt-002-mainnet\z/D',
            $payload['dataset_id'] ?? '',
        );
        unset($payload['dataset_id']);
        self::assertSame([
            'attempts_used' => 2,
            'ok' => true,
            'schema_version' => 'paper-public-capture-supervision-result-v1',
            'source_venue' => 'hyperliquid',
        ], $payload);
    }

    public function testRejectsInvalidOptionsBeforeStartingAnAttempt(): void
    {
        $executor = new SupervisorCommandAttemptExecutor([0]);
        $tester = new CommandTester(new PaperPublicCaptureSupervisorCommand(
            new PaperPublicCaptureSupervisor($executor),
        ));

        self::assertSame(Command::FAILURE, $tester->execute([
            '--venue' => 'bitmart',
            '--dataset-prefix' => '/private/wallet-secret',
            '--duration-sec' => '299',
            '--attempts' => '0',
        ]));
        self::assertSame([], $executor->calls);
        self::assertSame([
            'blocker' => 'paper_public_capture_supervision_failed',
            'ok' => false,
            'schema_version' => 'paper-public-capture-supervision-result-v1',
        ], json_decode(trim($tester->getDisplay()), true, 8, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('wallet-secret', $tester->getDisplay());
    }
}

final class SupervisorCommandAttemptExecutor implements PaperPublicCaptureAttemptExecutorInterface
{
    /** @var list<array{string, string, int}> */
    public array $calls = [];

    /** @param list<int> $exitCodes */
    public function __construct(private array $exitCodes)
    {
    }

    public function execute(
        string $venue,
        string $datasetId,
        int $durationSeconds,
    ): PaperPublicCaptureAttemptResult {
        $this->calls[] = [$venue, $datasetId, $durationSeconds];

        return new PaperPublicCaptureAttemptResult(array_shift($this->exitCodes) ?? 1);
    }
}
