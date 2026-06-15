<?php
declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\TradingCore\Execution\Dto\ExecutionResult;
use App\TradingCore\Execution\Enum\ExecutionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExecutionResult::class)]
final class ExecutionResultTest extends TestCase
{
    public function testUsesExecutionStatusEnum(): void
    {
        $result = new ExecutionResult(
            status: ExecutionStatus::DryRun,
            clientOrderId: 'CID123',
        );

        self::assertSame(ExecutionStatus::DryRun, $result->status);
        self::assertSame('CID123', $result->clientOrderId);
    }
}
