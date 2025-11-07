<?php

declare(strict_types=1);

namespace App\MtfValidator\Runtime\Concurrency\Dto;

/**
 * DTO interne pour la configuration des commutateurs
 */
final class SwitchConfigDto
{
    public function __construct(
        public readonly string $name,
        public readonly bool $defaultState,
        public readonly ?string $description = null,
        public readonly array $metadata = []
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'default_state' => $this->defaultState,
            'description' => $this->description,
            'metadata' => $this->metadata
        ];
    }
}
