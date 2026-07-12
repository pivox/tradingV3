<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

final readonly class HyperliquidMutationReadinessConfig
{
    /**
     * @param list<string> $allowedSymbols
     * @param list<string> $allowedMarkets
     */
    public function __construct(
        public array $allowedSymbols,
        public array $allowedMarkets,
        public ?float $maxNotional,
        public bool $killSwitchEnabled,
        public ?string $configHash,
    ) {
    }

    public static function failClosed(): self
    {
        return new self([], [], null, true, null);
    }
}
