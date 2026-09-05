<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\SymfonyPaperPublicCaptureAttemptExecutor;
use App\Trading\Paper\Capture\PaperPublicCaptureOrphanFinalizer;
use App\Trading\Paper\Capture\PaperPublicLiveManifestFactory;
use App\Trading\Paper\Dataset\PaperDatasetManifestCodec;
use App\Trading\Paper\Dataset\PaperDatasetRecorder;
use App\Trading\Paper\Dataset\PaperDatasetState;
use App\Trading\Paper\MarketData\PaperMarketDataVenue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversClass(SymfonyPaperPublicCaptureAttemptExecutor::class)]
final class SymfonyPaperPublicCaptureAttemptExecutorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/paper-capture-attempt-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/bin', 0700, true));
        $resolved = realpath($root);
        self::assertIsString($resolved);
        $this->root = $resolved;
    }

    protected function tearDown(): void
    {
        unset(
            $_ENV['PAPER_CAPTURE_TEST_TRACE'],
            $_SERVER['PAPER_CAPTURE_TEST_TRACE'],
        );
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testRunsOneIsolatedAttemptWithExecutionDisabled(): void
    {
        $trace = $this->root . '/trace.json';
        $_ENV['PAPER_CAPTURE_TEST_TRACE'] = $trace;
        $_SERVER['PAPER_CAPTURE_TEST_TRACE'] = $trace;
        self::assertNotFalse(file_put_contents(
            $this->root . '/bin/console',
            <<<'PHP'
<?php
file_put_contents((string) getenv('PAPER_CAPTURE_TEST_TRACE'), json_encode([
    'argv' => $argv,
    'execution_enabled' => getenv('PAPER_EXECUTION_ENABLED'),
], JSON_THROW_ON_ERROR));
fwrite(STDOUT, '/private/dataset-path wallet-secret');
fwrite(STDERR, '/private/error-path api-secret');
exit(23);
PHP,
        ));

        $result = (new SymfonyPaperPublicCaptureAttemptExecutor($this->root))->execute(
            'hyperliquid',
            'representative-hyperliquid-attempt-001-mainnet',
            86_400,
        );

        self::assertSame(23, $result->exitCode, $result->stderrTail);
        self::assertIsInt($result->pid);
        self::assertGreaterThan(0, $result->pid);
        self::assertNull($result->termSignal);
        self::assertNotSame('', $result->startedAt);
        self::assertNotSame('', $result->endedAt);
        self::assertStringNotContainsString('/private/', $result->stdoutTail);
        self::assertStringNotContainsString('secret', $result->stdoutTail);
        self::assertStringNotContainsString('/private/', $result->stderrTail);
        self::assertStringNotContainsString('secret', $result->stderrTail);
        self::assertLessThanOrEqual(8192, \strlen($result->stdoutTail));
        self::assertLessThanOrEqual(8192, \strlen($result->stderrTail));
        self::assertSame([
            'argv' => [
                $this->root . '/bin/console',
                'app:paper-market:public-capture',
                '--venue=hyperliquid',
                '--dataset-id=representative-hyperliquid-attempt-001-mainnet',
                '--duration-sec=86400',
                '--no-interaction',
            ],
            'execution_enabled' => '0',
        ], json_decode(file_get_contents($trace) ?: '', true, 16, JSON_THROW_ON_ERROR));
    }

    public function testForwardsSupervisorSignalAndSurvivesToReturnChildFailure(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            self::markTestSkipped('Signal support is unavailable.');
        }

        $ready = $this->root . '/ready';
        $result = $this->root . '/result';
        $helper = $this->root . '/helper.php';
        self::assertNotFalse(file_put_contents(
            $this->root . '/bin/console',
            <<<'PHP'
<?php
file_put_contents((string) getenv('PAPER_CAPTURE_SIGNAL_READY'), 'ready');
sleep(30);
PHP,
        ));
        $autoload = \dirname(__DIR__, 4) . '/vendor/autoload.php';
        self::assertNotFalse(file_put_contents(
            $helper,
            sprintf(
                <<<'PHP'
<?php
require %s;
$executor = new App\Trading\Paper\Capture\SymfonyPaperPublicCaptureAttemptExecutor($argv[1]);
$attempt = $executor->execute('okx', 'signal-forwarding-okx-mainnet', 300);
file_put_contents($argv[2], (string) $attempt->exitCode);
PHP,
                var_export($autoload, true),
            ),
        ));

        $supervisor = new Process([\PHP_BINARY, $helper, $this->root, $result], null, [
            'PAPER_CAPTURE_SIGNAL_READY' => $ready,
        ]);
        $supervisor->setTimeout(5.0);
        $supervisor->start();
        $deadline = microtime(true) + 2.0;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        self::assertFileExists($ready);
        $pid = $supervisor->getPid();
        self::assertIsInt($pid);
        self::assertTrue(posix_kill($pid, SIGTERM));
        $supervisor->wait();

        self::assertSame(0, $supervisor->getExitCode(), $supervisor->getErrorOutput());
        self::assertFileExists($result);
        self::assertNotSame('0', trim(file_get_contents($result) ?: ''));
    }

    public function testTerminalizesAnAuthenticatedRecordingManifestAfterFatalExit(): void
    {
        $dataRoot = $this->root . '/paper-data';
        $datasetId = 'fatal-okx-attempt-mainnet';
        $manifest = (new PaperPublicLiveManifestFactory())->create(
            PaperMarketDataVenue::OKX,
            $datasetId,
        );
        new PaperDatasetRecorder($dataRoot, $manifest);
        self::assertNotFalse(file_put_contents(
            $this->root . '/bin/console',
            "<?php fwrite(STDERR, 'simulated fatal'); exit(137);\n",
        ));

        $result = (new SymfonyPaperPublicCaptureAttemptExecutor(
            $this->root,
            new PaperPublicCaptureOrphanFinalizer($dataRoot),
        ))->execute('okx', $datasetId, 300);

        self::assertSame(137, $result->exitCode);
        self::assertTrue($result->orphanFinalized);
        $stored = (new PaperDatasetManifestCodec())->decode(
            (string) file_get_contents($dataRoot . '/' . $datasetId . '/manifest.json'),
        );
        self::assertSame(PaperDatasetState::INCOMPLETE, $stored->state);
    }
}
