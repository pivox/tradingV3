<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Configuration;

use App\Trading\Paper\MarketData\PaperMarketEventRedactor;

final class PaperPrivateConfigurationReader
{
    private const MAX_BYTES = 1_048_576;

    /** @return array<string, mixed> */
    public function read(#[\SensitiveParameter] string $path): array
    {
        $this->assertAbsolute($path);
        $this->assertNoSymlinkComponents($path);
        $before = @lstat($path);
        if ($before === false || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0077) !== 0) {
            throw new \RuntimeException('paper_execution_configuration_not_private');
        }
        $size = $before['size'];
        if ($size < 2 || $size > self::MAX_BYTES) {
            throw new \RuntimeException('paper_execution_configuration_invalid');
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('paper_execution_configuration_invalid');
        }
        try {
            $opened = fstat($handle);
            if ($opened === false
                || ($opened['mode'] & 0170000) !== 0100000
                || ($opened['mode'] & 0077) !== 0
                || $opened['dev'] !== $before['dev']
                || $opened['ino'] !== $before['ino']
                || $opened['size'] !== $size
            ) {
                throw new \RuntimeException('paper_execution_configuration_changed');
            }
            $contents = stream_get_contents($handle);
            if (!is_string($contents) || strlen($contents) !== $size) {
                throw new \RuntimeException('paper_execution_configuration_invalid');
            }
            $after = @lstat($path);
            if ($after === false
                || ($after['mode'] & 0170000) !== 0100000
                || $after['dev'] !== $opened['dev']
                || $after['ino'] !== $opened['ino']
                || $after['size'] !== $opened['size']
            ) {
                throw new \RuntimeException('paper_execution_configuration_changed');
            }
        } finally {
            fclose($handle);
        }
        try {
            $decoded = json_decode($contents, true, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('paper_execution_configuration_invalid');
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new \RuntimeException('paper_execution_configuration_invalid');
        }
        PaperMarketEventRedactor::assertSafe($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function assertAbsolute(string $path): void
    {
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('paper_execution_path_must_be_absolute');
        }
    }

    private function assertNoSymlinkComponents(string $path): void
    {
        $current = DIRECTORY_SEPARATOR;
        foreach (array_values(array_filter(explode(DIRECTORY_SEPARATOR, $path), static fn (string $part): bool => $part !== '')) as $part) {
            $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            if (is_link($current)) {
                throw new \RuntimeException('paper_execution_symlink_rejected');
            }
        }
    }
}
