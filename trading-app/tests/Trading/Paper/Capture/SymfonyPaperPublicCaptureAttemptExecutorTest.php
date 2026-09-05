<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Capture;

use App\Trading\Paper\Capture\SymfonyPaperPublicCaptureAttemptExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

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

        self::assertSame(23, $result->exitCode);
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
}
