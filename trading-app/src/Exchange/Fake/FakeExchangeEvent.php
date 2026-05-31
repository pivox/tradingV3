<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeExchangeEvent
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $type,
        public string $symbol,
        public \DateTimeImmutable $occurredAt,
        public array $payload = [],
    ) {
        if (trim($this->type) === '') {
            throw new \InvalidArgumentException('event type cannot be blank');
        }
        if (trim($this->symbol) === '') {
            throw new \InvalidArgumentException('event symbol cannot be blank');
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'symbol' => $this->symbol,
            'occurred_at' => $this->occurredAt->format(\DateTimeInterface::ATOM),
            'payload' => $this->payload,
        ];
    }
}
