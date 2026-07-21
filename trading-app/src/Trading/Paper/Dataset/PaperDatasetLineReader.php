<?php

declare(strict_types=1);

namespace App\Trading\Paper\Dataset;

final readonly class PaperDatasetLineReader
{
    public function __construct(private PaperDatasetRecorderFilesystem $filesystem)
    {
    }

    /** @param resource $handle */
    public function read($handle, string $operation, string $invalidLineError): string|false
    {
        $line = $this->filesystem->readLine(
            $handle,
            PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES + 1,
            $operation,
        );
        if ($line === false) {
            return false;
        }
        if (strlen($line) > PaperDatasetFormatLimits::MAX_CANONICAL_EVENT_LINE_BYTES
            || !str_ends_with($line, "\n")
        ) {
            throw new \RuntimeException($invalidLineError);
        }

        return $line;
    }
}
