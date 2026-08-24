<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

final readonly class PaperDatasetLineReader
{
    public const READ_CHUNK_BYTES = 65_536;

    public function __construct(private PaperDatasetRecorderFilesystem $filesystem)
    {
    }

    /** @param resource $handle */
    public function read($handle, string $operation, string $invalidLineError): string|false
    {
        $chunks = [];
        $bytes = 0;
        while ($bytes < PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES) {
            $remaining = PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES - $bytes;
            $chunk = $this->filesystem->readLine(
                $handle,
                min(self::READ_CHUNK_BYTES, $remaining) + 1,
                $operation,
            );
            if ($chunk === false) {
                if ($chunks === []) {
                    return false;
                }

                throw new \RuntimeException($invalidLineError);
            }
            if ($chunk === '') {
                throw new \RuntimeException($invalidLineError);
            }
            $bytes += \strlen($chunk);
            if ($bytes > PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES) {
                throw new \RuntimeException($invalidLineError);
            }
            $chunks[] = $chunk;
            if (str_ends_with($chunk, "\n")) {
                return \count($chunks) === 1 ? $chunk : implode('', $chunks);
            }
        }

        throw new \RuntimeException($invalidLineError);
    }
}
