<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final class FakeMonetaryLedgerException extends \LogicException
{
    public function __construct(
        public readonly string $detailReason,
        public readonly int $monetaryEventCount = 0,
        public readonly int $duplicateEventCount = 0,
        public readonly int $invalidEventCount = 1,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('fake_monetary_ledger_not_computable', 0, $previous);
    }
}
