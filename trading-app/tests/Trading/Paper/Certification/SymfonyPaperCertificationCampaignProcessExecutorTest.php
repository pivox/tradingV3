<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Certification;

use App\Trading\Paper\Certification\Campaign\CompletedPaperCertificationCampaignProcess;
use App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignProcessResult;
use App\Trading\Paper\Certification\Campaign\SymfonyPaperCertificationCampaignProcessExecutor;
use App\Trading\Paper\Certification\Campaign\SymfonyPaperCertificationCampaignRunningProcess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymfonyPaperCertificationCampaignProcessExecutor::class)]
#[CoversClass(SymfonyPaperCertificationCampaignRunningProcess::class)]
#[CoversClass(CompletedPaperCertificationCampaignProcess::class)]
#[CoversClass(PaperCertificationCampaignProcessResult::class)]
final class SymfonyPaperCertificationCampaignProcessExecutorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function childCommands(): iterable
    {
        yield 'replay' => ['app:paper-market:replay', '1'];
        yield 'runtime check' => ['app:paper-market:runtime-check', '1'];
        yield 'dataset receipt' => ['app:paper-market:dataset-receipt', '0'];
        yield 'anything else' => ['literal argument with spaces;$(false)', '0'];
    }

    #[DataProvider('childCommands')]
    public function testEnablesPaperExecutionOnlyForTheReplayAndRuntimeCheckChildren(string $command, string $expected): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor(dirname(__DIR__, 4));

        $result = $executor->execute([PHP_BINARY, '-r', 'echo getenv("PAPER_EXECUTION_ENABLED");', $command], 5);

        self::assertSame(0, $result->exitCode);
        self::assertSame($expected, $result->stdout);
        self::assertFalse($result->timedOut);

        $running = $executor->start([PHP_BINARY, '-r', 'echo getenv("PAPER_EXECUTION_ENABLED");', $command], 5);
        self::assertSame($expected, $this->await($running)->stdout);
    }

    public function testAChildReceivesItsOwnDatabaseAndNeverAnotherVariable(): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor(dirname(__DIR__, 4));
        $script = 'echo getenv("DATABASE_URL"), "|", getenv("PAPER_EXECUTION_ENABLED");';

        $running = $executor->startWithEnvironment(
            [PHP_BINARY, '-r', $script, 'app:paper-market:replay'],
            5,
            ['DATABASE_URL' => 'postgresql://postgres:cell-secret@127.0.0.1:5441/trading_paper'],
        );
        self::assertSame('postgresql://postgres:cell-secret@127.0.0.1:5441/trading_paper|1', $this->await($running)->stdout);

        foreach ([
            ['PAPER_EXECUTION_ENABLED' => '1'],
            ['OKX_LIVE_ENABLED' => '1'],
            ['DATABASE_URL' => ''],
        ] as $environment) {
            try {
                $executor->startWithEnvironment([PHP_BINARY, '-r', $script], 5, $environment);
                self::fail('Accepted child environment ' . implode(',', array_keys($environment)));
            } catch (\InvalidArgumentException $exception) {
                self::assertSame('paper_campaign_child_environment_invalid', $exception->getMessage());
            }
        }
    }

    public function testReturnsAStableTimeoutResultWithoutThrowingChildOutput(): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor(dirname(__DIR__, 4));

        $result = $executor->execute([PHP_BINARY, '-r', 'usleep(1500000);'], 1);

        self::assertSame(124, $result->exitCode);
        self::assertSame('', $result->stdout);
        self::assertTrue($result->timedOut);

        $started = $executor->start([PHP_BINARY, '-r', 'echo "partial"; usleep(3000000);'], 1);
        $timedOut = $this->await($started);
        self::assertSame(124, $timedOut->exitCode);
        self::assertSame('', $timedOut->stdout);
        self::assertTrue($timedOut->timedOut);
    }

    public function testAStartedChildRunsWithoutBlockingAndReportsItsOwnExitCode(): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor(dirname(__DIR__, 4));

        $first = $executor->start([PHP_BINARY, '-r', 'usleep(300000); echo "first"; exit(3);'], 5);
        $second = $executor->start([PHP_BINARY, '-r', 'echo "second";'], 5);

        self::assertNull($first->poll(), 'start() returns while the child still runs.');
        $secondResult = $this->await($second);
        $firstResult = $this->await($first);
        self::assertSame([0, 'second'], [$secondResult->exitCode, $secondResult->stdout]);
        self::assertSame([3, 'first'], [$firstResult->exitCode, $firstResult->stdout]);
        self::assertSame($firstResult, $first->poll(), 'The result is stable once known.');
    }

    public function testStoppingAChildSendsSigtermAndNeverReportsSuccess(): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor(dirname(__DIR__, 4));
        $started = microtime(true);

        $running = $executor->start([PHP_BINARY, '-r', 'echo "started\n"; sleep(30); echo "never";'], 60);
        usleep(300_000);
        $result = $running->stop();

        self::assertSame(143, $result->exitCode);
        self::assertFalse($result->timedOut);
        self::assertStringNotContainsString('never', $result->stdout);
        self::assertLessThan(10.0, microtime(true) - $started);
        self::assertSame($result, $running->poll());
    }

    public function testAChildThatCannotStartIsAFailedResult(): void
    {
        $executor = new SymfonyPaperCertificationCampaignProcessExecutor('/nonexistent-' . bin2hex(random_bytes(4)));

        $result = $this->await($executor->start([PHP_BINARY, '-r', 'echo 1;'], 5));

        self::assertSame(127, $result->exitCode);
        self::assertSame('', $result->stdout);
    }

    private function await(\App\Trading\Paper\Certification\Campaign\PaperCertificationCampaignRunningProcessInterface $process): PaperCertificationCampaignProcessResult
    {
        $deadline = microtime(true) + 20.0;
        while (($result = $process->poll()) === null) {
            self::assertLessThan($deadline, microtime(true), 'child did not finish');
            usleep(20_000);
        }

        return $result;
    }
}
