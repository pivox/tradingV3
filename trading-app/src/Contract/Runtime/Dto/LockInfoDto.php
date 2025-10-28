<?php

declare(strict_types=1);

namespace App\Contract\Runtime\Dto;

/**
 * DTO pour les informations de verrou
 */
final class LockInfoDto
{
    public function __construct(
        public readonly string $key,
        public readonly string $identifier,
        public readonly int $ttl,
        public readonly int $expiresAt,
        public readonly \DateTimeImmutable $createdAt
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            identifier: $data['identifier'],
            ttl: $data['ttl'],
            expiresAt: $data['expires_at'],
            createdAt: new \DateTimeImmutable($data['created_at'])
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'identifier' => $this->identifier,
            'ttl' => $this->ttl,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s')
        ];
    }
}
