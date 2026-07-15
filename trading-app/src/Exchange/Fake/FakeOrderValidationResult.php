<?php

declare(strict_types=1);

namespace App\Exchange\Fake;

final readonly class FakeOrderValidationResult
{
    /**
     * @param array<string,mixed> $metadata
     */
    private function __construct(
        public bool $accepted,
        public ?string $reason,
        public array $metadata,
    ) {
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function accepted(array $metadata = []): self
    {
        return new self(true, null, $metadata);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public static function rejected(string $reason, array $metadata = []): self
    {
        return new self(false, $reason, $metadata);
    }
}
