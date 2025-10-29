<?php

declare(strict_types=1);

namespace App\Order;

/**
 * Représente le signal REST envoyé à trading-app pour synchroniser un ordre.
 */
final class OrderSignal
{
    private const REQUIRED_FIELDS = [
        'kind',
        'status',
        'client_order_id',
        'symbol',
        'side',
        'type',
        'price',
        'size',
        'submitted_at',
    ];

    /**
     * @param array<string,mixed> $payload
     */
    private function __construct(private array $payload)
    {
        $missing = array_filter(self::REQUIRED_FIELDS, fn(string $field) => !isset($this->payload[$field]));
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                sprintf('OrderSignal payload missing required fields: %s', implode(', ', $missing))
            );
        }

        // Normalise les clés pour cohérence snake_case
        $normalised = [];
        foreach ($this->payload as $key => $value) {
            $normalised[$this->normaliseKey($key)] = $value;
        }
        $this->payload = $normalised;

        $this->payload['trace_id'] ??= bin2hex(random_bytes(16));
        $this->payload['retry_count'] ??= 0;
        $this->payload['payload_version'] ??= '1.0';
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self($payload);
    }

    public function withRetryCount(int $retryCount): self
    {
        $clone = clone $this;
        $clone->payload['retry_count'] = $retryCount;
        return $clone;
    }

    public function traceId(): string
    {
        return (string) $this->payload['trace_id'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->payload;
    }

    private function normaliseKey(string $key): string
    {
        if (str_contains($key, '-')) {
            $key = str_replace('-', '_', $key);
        }
        if (preg_match('/[A-Z]/', $key)) {
            $key = strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $key) ?? $key);
        }

        return $key;
    }
}
