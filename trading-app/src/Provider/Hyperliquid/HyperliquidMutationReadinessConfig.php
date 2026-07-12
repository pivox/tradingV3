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
        public ?string $profile,
        public array $allowedSymbols,
        public array $allowedMarkets,
        public ?float $maxNotional,
        public bool $dryRun,
        public bool $liveEnabled,
        public bool $runtimeCheckRequired,
        public bool $mainnetWriteEnabled,
        public bool $demoTestnetWriteEnabled,
        public bool $killSwitchEnabled,
        public bool $requireStopLoss,
        public ?string $configHash,
    ) {
    }

    public function authorizesTestnetMutation(): bool
    {
        return is_string($this->profile)
            && trim($this->profile) !== ''
            && is_string($this->configHash)
            && preg_match('/^[a-f0-9]{64}$/D', $this->configHash) === 1
            && !$this->dryRun
            && !$this->liveEnabled
            && $this->runtimeCheckRequired
            && !$this->mainnetWriteEnabled
            && $this->demoTestnetWriteEnabled
            && !$this->killSwitchEnabled
            && $this->requireStopLoss;
    }

    public static function failClosed(): self
    {
        return new self(
            profile: null,
            allowedSymbols: [],
            allowedMarkets: [],
            maxNotional: null,
            dryRun: true,
            liveEnabled: false,
            runtimeCheckRequired: true,
            mainnetWriteEnabled: false,
            demoTestnetWriteEnabled: false,
            killSwitchEnabled: true,
            requireStopLoss: true,
            configHash: null,
        );
    }
}
