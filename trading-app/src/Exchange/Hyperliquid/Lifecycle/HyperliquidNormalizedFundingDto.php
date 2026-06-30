<?php

declare(strict_types=1);

namespace App\Exchange\Hyperliquid\Lifecycle;

final readonly class HyperliquidNormalizedFundingDto
{
    /**
     * @param array<string,mixed> $redactedPayload
     */
    public function __construct(
        public string $symbol,
        public float $amount,
        public string $currency,
        public string $role,
        public ?float $fundingRate,
        public \DateTimeImmutable $occurredAt,
        public array $redactedPayload,
    ) {
    }
}
