<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\PaperPublicCaptureAttemptExecutorInterface;
use App\Trading\Paper\Capture\PaperPublicCaptureAttemptResult;
use App\Trading\Paper\Capture\PaperPublicCaptureSupervisor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperPublicCaptureSupervisor::class)]
final class PaperPublicCaptureSupervisorTest extends TestCase
{
    public function testStartsFreshUniqueDatasetsUntilFirstSuccess(): void
    {
        $executor = new SupervisorAttemptExecutor([1, 1, 0, 0]);

        $result = (new PaperPublicCaptureSupervisor($executor))->run(
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
