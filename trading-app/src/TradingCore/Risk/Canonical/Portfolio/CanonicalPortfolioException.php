<?php

declare(strict_types=1);

namespace App\TradingCore\Risk\Canonical\Portfolio;

final class CanonicalPortfolioException extends \RuntimeException
{
    /** @param array<string, int|float|string|bool|null> $evidence */
    public function __construct(
        public readonly string $reasonCode,
        public readonly array $evidence = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reasonCode, 0, $previous);
    }
}
