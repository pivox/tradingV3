<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final readonly class ExchangeReadinessInput
{
    /**
     * @param list<string> $allowedSymbols
     * @param list<string> $allowedMarkets
     * @param list<string> $blockingErrors
     * @param list<string> $warnings
     */
    public function __construct(
        public Exchange $exchange,
        public MarketType $marketType,
        public string $environment,
        public bool $publicConnectivity = false,
        public bool $privateReadConnectivity = false,
        public bool $privateObservability = false,
        public bool $instrumentsLoaded = false,
        public bool $metadataValid = false,
        public bool $precisionValid = false,
        public bool $accountReadable = false,
        public bool $permissionsRead = false,
        public bool $permissionsTrade = false,
        public bool $mainnetWriteGuard = false,
        public bool $demoTestnetWriteGuard = false,
        public bool $demoTestnetWriteEnabled = false,
        public bool $stopLossCapability = false,
        public bool $killSwitch = true,
        public bool $dryRun = true,
        public array $allowedSymbols = [],
        public array $allowedMarkets = [],
        public ?float $maxNotional = null,
        public ?string $configHash = null,
        public array $blockingErrors = [],
        public array $warnings = [],
    ) {
        if ($this->maxNotional !== null && (!is_finite($this->maxNotional) || $this->maxNotional <= 0.0)) {
            throw new \InvalidArgumentException('maxNotional must be positive and finite when provided.');
        }
    }
}
