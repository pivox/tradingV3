<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid\Lifecycle;

final readonly class HyperliquidNormalizedErrorDto
{
    /**
     * @param list<string> $qualityFlags
     * @param array<string,mixed> $redactedPayload
     */
    public function __construct(
        public HyperliquidLifecycleStatus $status,
        public string $code,
        public string $message,
        public ?string $exchangeOrderId,
        public ?string $clientOrderId,
        public array $qualityFlags,
        public array $redactedPayload,
    ) {
    }
}
