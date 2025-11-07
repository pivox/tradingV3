<?php

declare(strict_types=1);

namespace App\MtfValidator\Runtime\Concurrency\Dto;

/**
 * DTO interne pour les résultats de verrou
 */
final class LockResultDto
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $error = null,
        public readonly ?string $identifier = null,
        public readonly ?int $ttl = null
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function hasError(): bool
    {
        return $this->error !== null;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'error' => $this->error,
            'identifier' => $this->identifier,
            'ttl' => $this->ttl
        ];
    }
}
