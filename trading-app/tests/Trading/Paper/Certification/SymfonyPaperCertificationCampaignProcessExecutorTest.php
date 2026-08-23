<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Certification;

use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessResult;
use App\Trading\Paper\Certification\Campaign\SymfonyPaperCertificationCampaignProcessExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyPaperCertificationCampaignProcessExecutor::class)]
#[CoversClass(PaperCertificationCampaignProcessResult::class)]
final class SymfonyPaperCertificationCampaignProcessExecutorTest extends TestCase
{
    public function testExecutesAnArgumentVectorWithPaperExplicitlyEnabled(): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor(dirname(__DIR__, 4));

        $result = $executor->execute([
            PHP_BINARY,
            '-r',
            'echo getenv("PAPER_EXECUTION_ENABLED");',
            'literal argument with spaces;$(false)',
        ], 5);

        self::assertSame(0, $result->exitCode);
        self::assertSame('1', $result->stdout);
        self::assertFalse($result->timedOut);
    }

    public function testReturnsAStableTimeoutResultWithoutThrowingChildOutput(): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor(dirname(__DIR__, 4));

        $result = $executor->execute([PHP_BINARY, '-r', 'usleep(1500000);'], 1);

        self::assertSame(124, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertTrue($result->timedOut);
    }
}
