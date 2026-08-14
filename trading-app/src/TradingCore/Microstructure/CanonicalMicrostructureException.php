<?php

declare(strict_types=1);

namespace App\TradingCore\Microstructure;

final class CanonicalMicrostructureException extends \RuntimeException
{
    /** @param array<string, mixed> $evidence */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $evidence = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reasonCode, 0, $previous);
    }
}
