<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

class PaperDatasetRecorderFilesystem
{
    /** @param resource $handle */
    public function write($handle, #[\SensitiveParameter] string $contents, string $operation): int|false
    {
        return fwrite($handle, $contents);
    }

    /** @param resource $handle */
    public function read($handle, int $length, string $operation): string|false
    {
        return fread($handle, $length);
    }

    /** @param resource $handle */
    public function readLine($handle, string $operation): string|false
    {
        return fgets($handle);
    }

    /** @param resource $handle */
    public function seek($handle, int $offset, int $whence, string $operation): bool
    {
        return fseek($handle, $offset, $whence) === 0;
    }

    /** @param resource $handle */
    public function flush($handle, string $operation): bool
    {
        return fflush($handle);
    }

    /** @param resource $handle */
    public function sync($handle, string $operation): bool
    {
        return fsync($handle);
    }

    /**
     * @param resource $handle
     *
     * @return array<string, mixed>|false
     */
    public function stat($handle, string $operation): array|false
    {
        return fstat($handle);
    }

    /** @param resource $handle */
    public function truncate($handle, int $size, string $operation): bool
    {
        return ftruncate($handle, $size);
    }

    /**
     * @param resource $handle
     *
     * @return array{checksum: string, bytes: int}
     */
    public function checksum($handle, string $operation): array
    {
        $context = hash_init('sha256');
        $bytes = hash_update_stream($context, $handle);

        return ['checksum' => hash_final($context), 'bytes' => $bytes];
    }
}
