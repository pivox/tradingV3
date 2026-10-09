<?php
declare(strict_types=1);
namespace App\Tests\Command;

use App\Command\ResearchSignalWorkerCommand;
use App\Tests\TradingCore\Backtesting\Research\ResearchRollingWindowsTest;
use App\Tests\TradingCore\Backtesting\Research\ResearchSignalSessionTest;
use App\TradingCore\Backtesting\Json\StrictJsonObjectDecoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

#[\PHPUnit\Framework\Attributes\CoversClass(ResearchSignalWorkerCommand::class)]
final class ResearchSignalWorkerCommandTest extends TestCase
{
    private function runWorker(string $payload): CommandTester
    {
        $stream = fopen('php://memory', 'w+b'); fwrite($stream, $payload); rewind($stream);
        $tester = new CommandTester(new ResearchSignalWorkerCommand(ResearchSignalSessionTest::session(), new StrictJsonObjectDecoder(), static fn () => $stream));
        $tester->execute([]);
        return $tester;
    }

    /** @param array<string, mixed> $frame */
    private function encode(array $frame): string { return json_encode($frame, JSON_THROW_ON_ERROR) . "\n"; }

    public function testOpenBatchCloseProduceDeterministicProtocolOnly(): void
    {
        self::assertTrue(class_exists(ResearchSignalWorkerCommand::class));
        $open = ResearchSignalSessionTest::openFrame(minutes: 15);
        $batch = ['schema_version' => 'research-signal-candles.v1', 'session_id' => $open['session_id'], 'candles' => array_map(ResearchRollingWindowsTest::candle(...), range(0, 14))];
        $payload = $this->encode($open) . $this->encode($batch) . $this->encode(['schema_version' => 'research-signal-close.v1', 'session_id' => $open['session_id']]);
        $first = $this->runWorker($payload); $second = $this->runWorker($payload);
        self::assertSame(0, $first->getStatusCode());
        self::assertSame($first->getDisplay(), $second->getDisplay());
        $lines = array_map(static fn ($line) => json_decode($line, true, 128, JSON_THROW_ON_ERROR), explode("\n", trim($first->getDisplay())));
        self::assertSame(['research-signal-opened.v1', 'research-signal-batch-accepted.v1', 'research-signal-summary.v1'], array_column($lines, 'schema_version'));
        self::assertSame('complete', $lines[2]['completion']);
        self::assertSame(1, $lines[2]['warmup_unavailable']);
    }

    public function testMalformedBoundsSessionMismatchesAndEarlyEofTerminateWithStructuredError(): void
    {
        self::assertTrue(class_exists(ResearchSignalWorkerCommand::class));
        $open = ResearchSignalSessionTest::openFrame(minutes: 15); $encoded = $this->encode($open);
        $cases = [
            'no session' => '', 'early EOF' => $encoded, 'invalid JSON' => "{oops}\n",
            'duplicate key' => '{"schema_version":"x","schema_version":"y"}' . "\n",
            'oversized line' => str_repeat(' ', 2097153) . "\n", 'duplicate open' => $encoded . $encoded,
            'wrong session' => $encoded . $this->encode(['schema_version' => 'research-signal-close.v1', 'session_id' => 'wrong']),
            'oversized batch' => $encoded . $this->encode(['schema_version' => 'research-signal-candles.v1', 'session_id' => $open['session_id'], 'candles' => array_fill(0, 1441, ResearchRollingWindowsTest::candle(0))]),
            'gap' => $encoded . $this->encode(['schema_version' => 'research-signal-candles.v1', 'session_id' => $open['session_id'], 'candles' => [ResearchRollingWindowsTest::candle(1)]]),
        ];
        foreach ($cases as $name => $payload) {
            $tester = $this->runWorker($payload);
            self::assertNotSame(0, $tester->getStatusCode(), $name);
            $lines = explode("\n", trim($tester->getDisplay()));
            $error = json_decode(end($lines), true, 128, JSON_THROW_ON_ERROR);
            self::assertSame('research-signal-error.v1', $error['schema_version'], $name);
            self::assertMatchesRegularExpression('/\A[a-z0-9_]{1,100}\z/D', $error['reason_code']);
        }
    }

    public function testFreshWorkerProcessesProduceIdenticalBytesIncludingCanonicalDecisions(): void
    {
        $open = ResearchSignalSessionTest::openFrame(minutes: 60000);
        $payload = $this->encode($open);
        for ($offset = 0; $offset < 60000; $offset += 1440) {
            $records = [];
            for ($i = $offset; $i < min(60000, $offset + 1440); ++$i) { $records[] = ResearchRollingWindowsTest::candle($i); }
            $payload .= $this->encode(['schema_version' => 'research-signal-candles.v1', 'session_id' => $open['session_id'], 'candles' => $records]);
        }
        $payload .= $this->encode(['schema_version' => 'research-signal-close.v1', 'session_id' => $open['session_id']]);
        $script = 'require "vendor/autoload.php"; $app = new Symfony\\Component\\Console\\Application(); $app->add(new App\\Command\\ResearchSignalWorkerCommand(App\\Tests\\TradingCore\\Backtesting\\Research\\ResearchSignalSessionTest::session(), new App\\TradingCore\\Backtesting\\Json\\StrictJsonObjectDecoder())); $app->setAutoExit(false); exit($app->run(new Symfony\\Component\\Console\\Input\\ArrayInput(["command" => "app:research:signals"])));';
        $outputs = [];
        for ($i = 0; $i < 2; ++$i) {
            $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script], dirname(__DIR__, 2));
            $process->setTimeout(60); $process->setInput($payload); $process->run();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertSame('', $process->getErrorOutput());
            self::assertStringContainsString('research-signal-result.v1', $process->getOutput());
            $outputs[] = $process->getOutput();
        }
        self::assertSame($outputs[0], $outputs[1]);
    }

    public function testActualProductionConsoleUsesNoDatabaseForResearchSignals(): void
    {
        $open = ResearchSignalSessionTest::openFrame(minutes: 15);
        $payload = $this->encode($open)
            . $this->encode(['schema_version' => 'research-signal-candles.v1', 'session_id' => $open['session_id'], 'candles' => array_map(ResearchRollingWindowsTest::candle(...), range(0, 14))])
            . $this->encode(['schema_version' => 'research-signal-close.v1', 'session_id' => $open['session_id']]);
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-d', 'display_errors=stderr', 'bin/console', 'app:research:signals', '--no-debug'], dirname(__DIR__, 2), [
            'APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_SECRET' => 'research-integration-test',
            'SYMFONY_DOTENV_PATH' => '/dev/null',
            'DATABASE_URL' => 'postgresql://research:research@127.0.0.1:1/research?serverVersion=16&charset=utf8',
        ]);
        $process->setTimeout(60);
        $process->setInput($payload);
        $process->run();
        self::assertTrue($process->isSuccessful(), $process->getOutput() . $process->getErrorOutput());
        $frames = array_map(static fn (string $line): array => json_decode($line, true, 128, JSON_THROW_ON_ERROR), explode("\n", trim($process->getOutput())));
        self::assertSame(['research-signal-opened.v1', 'research-signal-batch-accepted.v1', 'research-signal-summary.v1'], array_column($frames, 'schema_version'));
        self::assertSame('complete', $frames[2]['completion']);
        self::assertSame(15, $frames[2]['consumed_candles']);
    }
}
