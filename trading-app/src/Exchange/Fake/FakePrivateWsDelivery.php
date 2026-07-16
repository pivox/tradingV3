<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakePrivateWsDelivery
{
    public function __construct(
        public string $fixtureEntryId,
        public string $sequence,
        public FakeExchangeEvent $event,
        public string $fingerprint,
    ) {
        if (trim($this->fixtureEntryId) === '') {
            throw new \InvalidArgumentException('fake_private_ws_delivery_fixture_entry_id_invalid');
        }
        if (trim($this->sequence) === '') {
            throw new \InvalidArgumentException('fake_private_ws_delivery_sequence_invalid');
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', $this->fingerprint)) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_fingerprint_invalid');
        }
        if ($this->sequence !== self::eventSequence($this->event)) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_sequence_invalid');
        }
        if (!hash_equals(self::fingerprint($this->event), $this->fingerprint)) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_fingerprint_invalid');
        }
    }

    public static function fromEvent(string $fixtureEntryId, FakeExchangeEvent $event): self
    {
        $sequence = self::eventSequence($event);

        return new self(
            fixtureEntryId: $fixtureEntryId,
            sequence: $sequence,
            event: $event,
            fingerprint: self::fingerprint($event),
        );
    }

    /**
     * @return array{
     *     fixture_entry_id:string,
     *     sequence:string,
     *     event:array{type:string,symbol:string,occurred_at:string,payload:array<string,mixed>},
     *     fingerprint:string
     * }
     */
    public function toArray(): array
    {
        return [
            'fixture_entry_id' => $this->fixtureEntryId,
            'sequence' => $this->sequence,
            'event' => $this->event->toArray(),
            'fingerprint' => $this->fingerprint,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $fixtureEntryId = $payload['fixture_entry_id'] ?? null;
        $sequence = $payload['sequence'] ?? null;
        $eventPayload = $payload['event'] ?? null;
        $fingerprint = $payload['fingerprint'] ?? null;
        if (
            !\is_string($fixtureEntryId)
            || !\is_string($sequence)
            || !\is_array($eventPayload)
            || !\is_string($fingerprint)
        ) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_shape_invalid');
        }

        $event = self::eventFromArray($eventPayload);

        return new self($fixtureEntryId, $sequence, $event, $fingerprint);
    }

    private static function eventSequence(FakeExchangeEvent $event): string
    {
        $sequence = $event->payload['event_sequence'] ?? null;
        if (\is_int($sequence)) {
            return (string) $sequence;
        }
        if (\is_string($sequence) && trim($sequence) !== '') {
            return $sequence;
        }

        throw new \InvalidArgumentException('fake_private_ws_delivery_sequence_invalid');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function eventFromArray(array $payload): FakeExchangeEvent
    {
        $type = $payload['type'] ?? null;
        $symbol = $payload['symbol'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;
        $eventPayload = $payload['payload'] ?? null;
        if (
            !\is_string($type)
            || !\is_string($symbol)
            || !\is_string($occurredAt)
            || !\is_array($eventPayload)
        ) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_event_invalid');
        }

        try {
            $timestamp = new \DateTimeImmutable($occurredAt);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_event_invalid', previous: $exception);
        }

        try {
            return new FakeExchangeEvent($type, $symbol, $timestamp, $eventPayload);
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_event_invalid', previous: $exception);
        }
    }

    private static function fingerprint(FakeExchangeEvent $event): string
    {
        try {
            $canonical = json_encode([
                'type' => $event->type,
                'symbol' => strtoupper($event->symbol),
                'occurred_at' => $event->occurredAt->format(\DateTimeInterface::ATOM),
                'payload' => self::canonicalize($event->payload),
            ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('fake_private_ws_delivery_event_invalid', previous: $exception);
        }

        return hash('sha256', $canonical);
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}
