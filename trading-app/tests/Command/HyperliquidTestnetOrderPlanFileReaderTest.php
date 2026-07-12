<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\HyperliquidTestnetOrderPlanFileReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidTestnetOrderPlanFileReader::class)]
final class HyperliquidTestnetOrderPlanFileReaderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->paths) as $path) {
            if (is_link($path) || is_file($path)) {
                @unlink($path);
            }
        }
    }

    public function testReadsOwnedLockedStableRegularFileToEof(): void
    {
        $path = $this->file('{"schema_version":1}');

        self::assertSame('{"schema_version":1}', (new HyperliquidTestnetOrderPlanFileReader())->read($path));
    }

    #[DataProvider('unsafeModes')]
    public function testRejectsGroupOrWorldWritableFile(int $mode): void
    {
        $path = $this->file('{}');
        chmod($path, $mode);

        $this->expectException(\InvalidArgumentException::class);

        (new HyperliquidTestnetOrderPlanFileReader())->read($path);
    }

    /** @return iterable<string, array{int}> */
    public static function unsafeModes(): iterable
    {
        yield 'group writable' => [0660];
        yield 'world writable' => [0602];
    }

    public function testRejectsFileLockedForMutation(): void
    {
        $path = $this->file('{}');
        $handle = fopen($path, 'rb+');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, \LOCK_EX | \LOCK_NB));

        try {
            $this->expectException(\InvalidArgumentException::class);
            (new HyperliquidTestnetOrderPlanFileReader())->read($path);
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }

    public function testRejectsSymlinkDeviceAndOversizedFile(): void
    {
        $reader = new HyperliquidTestnetOrderPlanFileReader();
        $target = $this->file('{}');
        $link = $target . '.link';
        symlink($target, $link);
        $this->paths[] = $link;

        foreach ([$link, '/dev/null', $this->file(str_repeat('x', 65_537))] as $path) {
            try {
                $reader->read($path);
                self::fail('Unsafe file was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function file(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hl-reader-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        chmod($path, 0600);
        $this->paths[] = $path;

        return $path;
    }
}
