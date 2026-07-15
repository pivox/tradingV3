<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final class FakeExchangeInjectedException extends \RuntimeException
{
    public function __construct(public readonly FakeExchangeFault $fault)
    {
        parent::__construct($fault->kind->value);
    }

    public function outcomeUnknown(): bool
    {
        return $this->fault->outcome === FakeExchangeFaultOutcome::AppliedResponseLost;
    }

    /**
     * @return array{injected_error:string,operation:string,outcome:string,outcome_unknown:bool,http_status:?int,retry_after_seconds:?int}
     */
    public function context(): array
    {
        return [
            'injected_error' => $this->fault->kind->value,
            'operation' => $this->fault->operation->value,
            'outcome' => $this->fault->outcome->value,
            'outcome_unknown' => $this->outcomeUnknown(),
            'http_status' => $this->fault->kind->httpStatus(),
            'retry_after_seconds' => $this->fault->retryAfterSeconds,
        ];
    }
}
