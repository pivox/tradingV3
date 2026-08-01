<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Persistence;

final readonly class PaperSourceClaim
{
    public const ACCEPTED = 'accepted';
    public const REPLAYED = 'replayed';

    public function __construct(
        public string $status,
        public int $sourcePosition,
        public int $journalOrdinal,
    ) {
        if (!in_array($status, [self::ACCEPTED, self::REPLAYED], true)) {
            throw new \InvalidArgumentException('paper_execution_source_claim_status_invalid');
        }
    }
}
