<?php

declare(strict_types=1);

namespace App\Trading\Backfill;

final readonly class BackfillDivergenceCriteria
{
    public function __construct(
        public ?\DateTimeImmutable $from = null,
        public ?\DateTimeImmutable $to = null,
        public ?string $profile = null,
        public ?string $exchange = null,
        public ?string $marketType = null,
        public ?string $symbol = null,
        public int $limit = 500,
        public int $batchSize = 100,
        public ?int $resumeCursor = null,
        public bool $dryRun = true,
    ) {
    }
}
