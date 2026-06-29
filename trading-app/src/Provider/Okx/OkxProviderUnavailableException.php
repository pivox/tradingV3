<?php

declare(strict_types=1);

namespace App\Provider\Okx;

final class OkxProviderUnavailableException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $operation,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(sprintf('OKX provider public read failed for "%s": %s', $operation, $reason), 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
