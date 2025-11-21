<?php

declare(strict_types=1);

namespace App\MtfValidator\Decision;

/**
 * DTO pour la décision de contexte (context_side)
 */
final class ContextDecision
{
    /**
     * @param array<string,string> $validSides tf => 'LONG' | 'SHORT'
     */
    public function __construct(
        private readonly bool $ok,
        private readonly ?string $side,       // 'LONG' | 'SHORT' | null
        private readonly ?string $reason,
        private readonly array $validSides = [],
    ) {
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function getSide(): ?string
    {
        return $this->side;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    /**
     * @return array<string,string> tf => side
     */
    public function getValidSides(): array
    {
        return $this->validSides;
    }
}

