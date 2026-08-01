<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

final readonly class PaperExecutionCheckpoint
{
    public function __construct(
        public string $cellId,
        public int $nextSourcePosition,
        public int $journalOrdinal,
        public string $journalChecksum,
        public int $fakeEventCursor,
        public bool $killed,
        public int $lockVersion,
    ) {
    }
}
