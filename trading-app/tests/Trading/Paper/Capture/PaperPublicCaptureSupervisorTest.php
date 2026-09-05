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
        self::assertSame(
            'representative-hyperliquid-20260905-attempt-003-mainnet',
            $result->datasetId,
        );
        self::assertSame([
            ['hyperliquid', 'representative-hyperliquid-20260905-attempt-001-mainnet', 86_400],
            ['hyperliquid', 'representative-hyperliquid-20260905-attempt-002-mainnet', 86_400],
            ['hyperliquid', 'representative-hyperliquid-20260905-attempt-003-mainnet', 86_400],
        ], $executor->calls);
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
