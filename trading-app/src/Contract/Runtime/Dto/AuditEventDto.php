<?php

declare(strict_types=1);

namespace App\Contract\Runtime\Dto;

/**
 * DTO pour les événements d'audit
 */
final class AuditEventDto
{
    public function __construct(
        public readonly string $action,
        public readonly string $entity,
        public readonly mixed $entityId,
        public readonly array $data,
        public readonly ?string $userId,
        public readonly ?string $ipAddress,
        public readonly \DateTimeImmutable $timestamp
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            action: $data['action'],
            entity: $data['entity'],
            entityId: $data['entity_id'],
            data: $data['data'] ?? [],
            userId: $data['user_id'] ?? null,
            ipAddress: $data['ip_address'] ?? null,
            timestamp: new \DateTimeImmutable($data['timestamp'])
        );
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'entity' => $this->entity,
            'entity_id' => $this->entityId,
            'data' => $this->data,
            'user_id' => $this->userId,
            'ip_address' => $this->ipAddress,
            'timestamp' => $this->timestamp->format('Y-m-d H:i:s')
        ];
    }
}
