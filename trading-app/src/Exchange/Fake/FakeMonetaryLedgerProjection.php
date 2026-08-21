<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeMonetaryLedgerProjection
{
    public const SOURCE = 'fake_paper_monetary_ledger';
    public const SOURCE_VERSION = '1.0.0';

    public function __construct(
        public \DateTimeImmutable $observedAt,
        public ?\DateTimeImmutable $startInclusive,
        public ?\DateTimeImmutable $endExclusive,
        public string $netUsdt,
        public int $monetaryEventCount,
        public int $duplicateEventCount,
        public int $lastEventSequence,
        public string $inputHash,
    ) {
        if (($startInclusive === null) !== ($endExclusive === null)
            || ($startInclusive !== null && $startInclusive >= $endExclusive)
            || preg_match('/\A-?(?:0|[1-9]\d*)\.\d{12}\z/D', $netUsdt) !== 1
            || $monetaryEventCount < 0
            || $duplicateEventCount < 0
            || $lastEventSequence < 0
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $inputHash) !== 1
        ) {
            throw new \InvalidArgumentException('fake_monetary_ledger_projection_invalid');
        }
    }
}
