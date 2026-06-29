<?php

declare(strict_types=1);

namespace App\Provider\Okx;

final class OkxProviderNotReadyException extends \RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $operation,
    ) {
        parent::__construct(sprintf('OKX provider skeleton is not ready for "%s": %s', $operation, $reason));
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
