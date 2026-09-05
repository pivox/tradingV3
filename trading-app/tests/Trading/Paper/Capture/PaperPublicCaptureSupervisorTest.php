<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\PaperPublicCaptureAttemptExecutorInterface;
use App\Trading\Paper\Capture\PaperPublicCaptureAttemptResult;
use App\Trading\Paper\Capture\PaperPublicCaptureSupervisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

#[CoversClass(PaperPublicCaptureSupervisor::class)]
final class PaperPublicCaptureSupervisorTest extends TestCase
{
    public function testStartsFreshUniqueDatasetsUntilFirstSuccess(): void
    {
        $executor = new SupervisorAttemptExecutor([1, 1, 0, 0]);

        $logger = new CaptureSupervisorTestLogger();
        $result = (new PaperPublicCaptureSupervisor($executor, $logger))->run(
            'hyperliquid',
            'representative-hyperliquid-20260905',
            86_400,
            4,
        );

        self::assertTrue($result->ok);
        self::assertSame(3, $result->attemptsUsed);
        self::assertMatchesRegularExpression(
            '/\Arepresentative-hyperliquid-20260905-run-[0-9a-f]{32}-attempt-003-mainnet\z/D',
            $result->datasetId ?? '',
        );
        self::assertCount(3, $executor->calls);
        self::assertCount(3, $logger->records);
        self::assertSame(
            $result->datasetId,
            $logger->records[2]['context']['dataset_id'] ?? null,
        );
        $runIds = [];
        foreach ($executor->calls as $index => $call) {
            self::assertSame('hyperliquid', $call[0]);
            self::assertSame(86_400, $call[2]);
            self::assertMatchesRegularExpression(
                sprintf('/\Arepresentative-hyperliquid-20260905-run-([0-9a-f]{32})-attempt-%03d-mainnet\z/D', $index + 1),
                $call[1],
            );
            preg_match('/-run-([0-9a-f]{32})-attempt-/', $call[1], $matches);
            $runIds[] = $matches[1] ?? null;
        }
        self::assertCount(1, array_unique($runIds));
    }

    public function testStopsExactlyAtBoundAfterTerminalFailures(): void
    {
        $executor = new SupervisorAttemptExecutor([1, 2, 3, 0]);

        $result = (new PaperPublicCaptureSupervisor($executor))->run(
            'okx',
            'representative-okx-20260905',
            300,
            3,
        );

        self::assertFalse($result->ok);
        self::assertSame(3, $result->attemptsUsed);
        self::assertNull($result->datasetId);
        self::assertSame('paper_public_capture_attempts_exhausted', $result->blocker);
        self::assertCount(3, $executor->calls);
    }

    public function testUsesAUniqueRunScopeAcrossSupervisorInvocations(): void
    {
        $executor = new SupervisorAttemptExecutor([0, 0]);
        $supervisor = new PaperPublicCaptureSupervisor($executor);

        $first = $supervisor->run('okx', 'representative-okx-20260905', 300, 1);
        $second = $supervisor->run('okx', 'representative-okx-20260905', 300, 1);

        self::assertNotNull($first->datasetId);
        self::assertNotNull($second->datasetId);
        self::assertNotSame($first->datasetId, $second->datasetId);
    }

    public function testStopsBeforeASecondAttemptWhenAnOrphanCannotBeFinalized(): void
    {
        $executor = new class implements PaperPublicCaptureAttemptExecutorInterface {
            public int $calls = 0;

            public function execute(
                string $venue,
                string $datasetId,
                int $durationSeconds,
            ): PaperPublicCaptureAttemptResult {
                ++$this->calls;

                return new PaperPublicCaptureAttemptResult(
                    exitCode: 137,
                    termSignal: 9,
                    orphanFinalized: false,
                );
            }
        };

        $result = (new PaperPublicCaptureSupervisor($executor))->run(
            'okx',
            'representative-okx-20260905',
            300,
            8,
        );

        self::assertFalse($result->ok);
        self::assertSame(1, $result->attemptsUsed);
        self::assertSame('paper_public_capture_orphan_finalization_failed', $result->blocker);
        self::assertSame(1, $executor->calls);
    }
}

final class CaptureSupervisorTestLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }
}

final class SupervisorAttemptExecutor implements PaperPublicCaptureAttemptExecutorInterface
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
