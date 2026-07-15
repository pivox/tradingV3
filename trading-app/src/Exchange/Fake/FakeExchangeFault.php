<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeExchangeFault
{
    public function __construct(
        public FakeExchangeOperation $operation,
        public FakeExchangeFaultKind $kind,
        public FakeExchangeFaultOutcome $outcome = FakeExchangeFaultOutcome::NotApplied,
        public ?int $retryAfterSeconds = null,
    ) {
        if ($kind === FakeExchangeFaultKind::Http429 && ($retryAfterSeconds === null || $retryAfterSeconds < 1)) {
            throw new \InvalidArgumentException('fake_exchange_fault_retry_after_invalid');
        }
        if ($kind !== FakeExchangeFaultKind::Http429 && $retryAfterSeconds !== null) {
            throw new \InvalidArgumentException('fake_exchange_fault_retry_after_unexpected');
        }
        if ($outcome === FakeExchangeFaultOutcome::AppliedResponseLost && !$operation->isMutation()) {
            throw new \InvalidArgumentException('fake_exchange_fault_applied_outcome_requires_mutation');
        }
        if (
            $outcome === FakeExchangeFaultOutcome::AppliedResponseLost
            && !\in_array($kind, [FakeExchangeFaultKind::NetworkTimeout, FakeExchangeFaultKind::TransportError], true)
        ) {
            throw new \InvalidArgumentException('fake_exchange_fault_applied_outcome_requires_transport_failure');
        }
    }

    /**
     * @return array{operation:string,kind:string,outcome:string,retry_after_seconds:?int}
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'kind' => $this->kind->value,
            'outcome' => $this->outcome->value,
            'retry_after_seconds' => $this->retryAfterSeconds,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $operation = $payload['operation'] ?? null;
        $kind = $payload['kind'] ?? null;
        $outcome = $payload['outcome'] ?? null;
        $retryAfterSeconds = $payload['retry_after_seconds'] ?? null;
        if (
            !\is_string($operation)
            || !\is_string($kind)
            || !\is_string($outcome)
            || ($retryAfterSeconds !== null && !\is_int($retryAfterSeconds))
        ) {
            throw new \InvalidArgumentException('fake_exchange_fault_payload_invalid');
        }

        try {
            return new self(
                FakeExchangeOperation::from($operation),
                FakeExchangeFaultKind::from($kind),
                FakeExchangeFaultOutcome::from($outcome),
                $retryAfterSeconds,
            );
        } catch (\ValueError $exception) {
            throw new \InvalidArgumentException('fake_exchange_fault_payload_invalid', previous: $exception);
        }
    }
}
