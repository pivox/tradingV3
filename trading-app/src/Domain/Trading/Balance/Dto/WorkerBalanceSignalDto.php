<?php

declare(strict_types=1);

namespace App\Domain\Trading\Balance\Dto;

/**
 * DTO représentant un signal de balance reçu du ws-worker.
 */
final readonly class WorkerBalanceSignalDto
{
    private function __construct(
        public string $asset,
        public string $availableBalance,
        public string $frozenBalance,
        public string $equity,
        public ?string $unrealizedPnl,
        public ?string $positionDeposit,
        public ?string $bonus,
        public \DateTimeImmutable $timestamp,
        public string $traceId,
        public int $retryCount,
        public array $context,
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        // Validation des champs requis
        $required = ['asset', 'available_balance', 'frozen_balance', 'equity', 'timestamp', 'trace_id'];
        $missing = array_filter($required, fn(string $field) => !isset($data[$field]));
        
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                sprintf('WorkerBalanceSignalDto missing required fields: %s', implode(', ', $missing))
            );
        }

        // Validation de l'asset
        if (strtoupper((string)$data['asset']) !== 'USDT') {
            throw new \InvalidArgumentException(
                sprintf('Invalid asset: %s (only USDT is supported)', $data['asset'])
            );
        }

        // Parse le timestamp
        $timestamp = self::parseTimestamp($data['timestamp']);

        return new self(
            asset: strtoupper((string)$data['asset']),
            availableBalance: (string)$data['available_balance'],
            frozenBalance: (string)$data['frozen_balance'],
            equity: (string)$data['equity'],
            unrealizedPnl: isset($data['unrealized_pnl']) ? (string)$data['unrealized_pnl'] : null,
            positionDeposit: isset($data['position_deposit']) ? (string)$data['position_deposit'] : null,
            bonus: isset($data['bonus']) ? (string)$data['bonus'] : null,
            timestamp: $timestamp,
            traceId: (string)$data['trace_id'],
            retryCount: (int)($data['retry_count'] ?? 0),
            context: (array)($data['context'] ?? []),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'asset' => $this->asset,
            'available_balance' => $this->availableBalance,
            'frozen_balance' => $this->frozenBalance,
            'equity' => $this->equity,
            'unrealized_pnl' => $this->unrealizedPnl,
            'position_deposit' => $this->positionDeposit,
            'bonus' => $this->bonus,
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'trace_id' => $this->traceId,
            'retry_count' => $this->retryCount,
            'context' => $this->context,
        ];
    }

    private static function parseTimestamp(mixed $value): \DateTimeImmutable
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof \DateTime) {
            return \DateTimeImmutable::createFromMutable($value);
        }

        if (is_string($value)) {
            try {
                return new \DateTimeImmutable($value);
            } catch (\Exception $e) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid timestamp format: %s', $value),
                    0,
                    $e
                );
            }
        }

        throw new \InvalidArgumentException(
            sprintf('Invalid timestamp type: %s', get_debug_type($value))
        );
    }

    public function getAvailableBalanceFloat(): float
    {
        return (float) $this->availableBalance;
    }

    public function getFrozenBalanceFloat(): float
    {
        return (float) $this->frozenBalance;
    }

    public function getEquityFloat(): float
    {
        return (float) $this->equity;
    }

    public function getUnrealizedPnlFloat(): ?float
    {
        return $this->unrealizedPnl !== null ? (float) $this->unrealizedPnl : null;
    }
}

