<?php

declare(strict_types=1);

namespace App\Domain\Common\Dto;

use App\Domain\Common\Enum\Timeframe;

final readonly class ValidationStateDto
{
    public function __construct(
        public string $symbol,
        public Timeframe $timeframe,
        public string $status,
        public \DateTimeImmutable $klineTime,
        public \DateTimeImmutable $expiresAt,
        public array $details = []
    ) {
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe->value,
            'status' => $this->status,
            'kline_time' => $this->klineTime->format('Y-m-d H:i:s'),
            'expires_at' => $this->expiresAt->format('Y-m-d H:i:s'),
            'details' => $this->details,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            symbol: $data['symbol'],
            timeframe: Timeframe::from($data['timeframe']),
            status: $data['status'],
            klineTime: new \DateTimeImmutable($data['kline_time'], new \DateTimeZone('UTC')),
            expiresAt: new \DateTimeImmutable($data['expires_at'], new \DateTimeZone('UTC')),
            details: $data['details'] ?? []
        );
    }

    public function isValid(): bool
    {
        return $this->status === 'VALID';
    }

    public function isInvalid(): bool
    {
        return $this->status === 'INVALID';
    }

    public function isPending(): bool
    {
        return $this->status === 'PENDING';
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function getCacheKey(): string
    {
        return sprintf(
            'mtf_validation_%s_%s_%s',
            $this->symbol,
            $this->timeframe->value,
            $this->klineTime->format('Y-m-d_H-i-s')
        );
    }

    public function withStatus(string $status): self
    {
        return new self(
            symbol: $this->symbol,
            timeframe: $this->timeframe,
            status: $status,
            klineTime: $this->klineTime,
            expiresAt: $this->expiresAt,
            details: $this->details
        );
    }

    public function withDetails(array $details): self
    {
        return new self(
            symbol: $this->symbol,
            timeframe: $this->timeframe,
            status: $this->status,
            klineTime: $this->klineTime,
            expiresAt: $this->expiresAt,
            details: $details
        );
    }
}




