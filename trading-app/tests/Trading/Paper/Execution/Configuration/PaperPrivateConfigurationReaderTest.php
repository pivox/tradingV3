<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Execution\Configuration;

use App\Trading\Paper\Execution\Configuration\PaperPrivateConfigurationReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PaperPrivateConfigurationReader::class)]
final class PaperPrivateConfigurationReaderTest extends TestCase
{
    public function testReadsPrivateRedactedObject(): void
    {
        $path = $this->temporaryFile('{"strategy":{"mode":"day_trading"}}');
        try {
            self::assertSame(
                ['strategy' => ['mode' => 'day_trading']],
                (new PaperPrivateConfigurationReader())->read($path),
            );
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsNonPrivateFile(): void
    {
        $path = $this->temporaryFile('{"strategy":{}}');
        chmod($path, 0644);

        try {
            $this->expectExceptionMessage('paper_execution_configuration_not_private');
            (new PaperPrivateConfigurationReader())->read($path);
        } finally {
            @unlink($path);
        }
    }

    public function testRejectsSymlink(): void
    {
        $path = $this->temporaryFile('{"strategy":{}}');
        $link = $path . '.link';
        symlink($path, $link);

        try {
            $this->expectExceptionMessage('paper_execution_symlink_rejected');
            (new PaperPrivateConfigurationReader())->read($link);
        } finally {
            @unlink($link);
            @unlink($path);
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/paper_configuration_' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, $contents);
        chmod($path, 0600);

        return $path;
    }
}
