<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Lifecycle;

final readonly class OkxNormalizedErrorDto
{
    /**
     * @param list<string> $qualityFlags
     * @param array<string,mixed> $redactedPayload
     */
    public function __construct(
        public OkxLifecycleStatus $status,
        public string $code,
        public string $message,
        public ?string $exchangeOrderId,
        public ?string $clientOrderId,
        public array $qualityFlags,
        public array $redactedPayload,
    ) {
    }
}
