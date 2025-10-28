<?php

declare(strict_types=1);

namespace App\Runtime\Audit\Dto;

/**
 * DTO interne pour le contexte d'audit
 */
final class AuditContextDto
{
    public function __construct(
        public readonly string $userId,
        public readonly string $ipAddress,
        public readonly string $userAgent,
        public readonly \DateTimeImmutable $timestamp,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s'),
            'metadata' => $this->metadata
        ];
    }
}
