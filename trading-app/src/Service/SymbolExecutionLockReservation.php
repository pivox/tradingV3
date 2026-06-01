<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SymbolExecutionLock;

final class SymbolExecutionLockReservation
{
    private function __construct(
        public readonly SymbolExecutionLock $lock,
        public readonly bool $created,
        public readonly bool $blocked,
        public readonly array $metadata = [],
        public readonly bool $syntheticLockCreated = false,
    ) {
    }

    public static function created(SymbolExecutionLock $lock): self
    {
        return new self($lock, true, false);
    }

    public static function blocked(SymbolExecutionLock $lock, array $metadata, bool $syntheticLockCreated = false): self
    {
        return new self($lock, false, true, $metadata, $syntheticLockCreated);
    }
}
