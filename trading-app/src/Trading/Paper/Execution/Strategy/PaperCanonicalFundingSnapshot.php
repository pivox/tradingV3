<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

final readonly class PaperCanonicalFundingSnapshot
{
    public function __construct(
        public string $source,
        public float $rate,
        public int $intervalSeconds,
        public \DateTimeImmutable $observedAt,
        public string $inputHash,
    ) {
        if ($source !== 'venue_schedule'
            || !is_finite($rate) || $rate <= -1.0 || $rate >= 1.0
            || $intervalSeconds < 1
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1
        ) {
            throw new \InvalidArgumentException('paper_canonical_funding_snapshot_invalid');
        }
    }
}
