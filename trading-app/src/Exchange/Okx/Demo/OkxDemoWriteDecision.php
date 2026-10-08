<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

final readonly class OkxDemoWriteDecision
{
    /**
     * @param list<string> $reasons
     */
    public function __construct(
        public bool $allowed,
        public array $reasons,
    ) {
    }

    public static function allow(): self
    {
        return new self(true, []);
    }

    /**
     * @param list<string> $reasons
     */
    public static function refuse(array $reasons): self
    {
        return new self(false, array_values(array_unique($reasons)));
    }
}
