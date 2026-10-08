<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Strategy;

use App\Trading\Paper\Execution\Strategy\PaperPlanRejectionDiagnostics;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperPlanRejectionDiagnostics::class)]
final class PaperPlanRejectionDiagnosticsTest extends TestCase
{
    public function testExclusivePrivateFileAndReset(): void
    {
        $path = sys_get_temp_dir() . '/paper-plan-rejections-' . bin2hex(random_bytes(8)) . '.ndjson';
        $writer = new PaperPlanRejectionDiagnostics();
        try {
            $writer->record(['reason_code' => 'inactive']);
            self::assertFileDoesNotExist($path);
            $writer->start($path);
            self::assertSame(0600, fileperms($path) & 0777);
            $writer->record(['reason_code' => 'expected']);
            self::assertSame('{"reason_code":"expected"}' . "\n", file_get_contents($path));
            $writer->close();
            $writer->record(['reason_code' => 'inactive']);
            self::assertSame('{"reason_code":"expected"}' . "\n", file_get_contents($path));
            $this->expectException(\RuntimeException::class);
            $writer->start($path);
        } finally {
            $writer->close();
            @unlink($path);
        }
    }

    public function testOpenFailureIsExplicit(): void
    {
        $writer = new PaperPlanRejectionDiagnostics();
        $this->expectException(\RuntimeException::class);
        $writer->start('/nonexistent-paper-plan-dir-' . bin2hex(random_bytes(8)) . '/refusals.ndjson');
    }

    public function testWriteFailureIsExplicit(): void
    {
        if (!is_writable('/dev/full')) {
            self::markTestSkipped('/dev/full unavailable');
        }
        $writer = new PaperPlanRejectionDiagnostics();
        $full = fopen('/dev/full', 'wb');
        self::assertIsResource($full);
        (new \ReflectionProperty($writer, 'handle'))->setValue($writer, $full);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('paper_plan_diagnostics_write_failed');
            $writer->record(['reason_code' => 'expected']);
        } finally {
            $writer->close();
        }
    }
}
