<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

final readonly class OkxDemoWriteDecision
{
    /**
     * @param list<string> $reasons
     * @param list<string> $exemptedReasons
     */
    public function __construct(
        public bool $allowed,
        public array $reasons,
        public ?OkxDemoWriteKind $kind = null,
        public array $exemptedReasons = [],
    ) {
    }

    public function exemptionApplied(): bool
    {
        return $this->exemptedReasons !== [];
    }

    /**
     * @param list<string> $exemptedReasons
     */
    public static function allow(OkxDemoWriteKind $kind, array $exemptedReasons = []): self
    {
        return new self(true, [], $kind, $exemptedReasons);
    }

    /**
     * @param list<string> $reasons
     */
    public static function refuse(array $reasons, ?OkxDemoWriteKind $kind = null): self
    {
        return new self(false, array_values(array_unique($reasons)), $kind);
    }
}
