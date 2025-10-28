<?php

declare(strict_types=1);

namespace App\Contract\Runtime\Dto;

/**
 * DTO pour l'état d'un commutateur
 */
final class SwitchStateDto
{
    public function __construct(
        public readonly string $name,
        public readonly bool $enabled,
        public readonly bool $isDefault,
        public readonly ?string $reason,
        public readonly \DateTimeImmutable $lastModified
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            enabled: $data['enabled'],
            isDefault: $data['is_default'] ?? false,
            reason: $data['reason'] ?? null,
            lastModified: new \DateTimeImmutable($data['last_modified'])
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'is_default' => $this->isDefault,
            'reason' => $this->reason,
            'last_modified' => $this->lastModified->format('Y-m-d H:i:s')
        ];
    }
}
