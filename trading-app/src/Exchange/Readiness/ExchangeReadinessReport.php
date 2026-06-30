<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;

final readonly class ExchangeReadinessReport
{
    private const SENSITIVE_PATTERN = '/(api[_-]?key|secret|private[_-]?key|passphrase|password|authorization|cookie|token|signature|sign|credentials?|memo)/i';

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
        public ExchangeReadinessLevel $readyLevel,
        public bool $publicConnectivity,
        public bool $privateReadConnectivity,
        public bool $privateObservability,
        public ?ExchangePrivateObservabilityStatus $privateObservabilityStatus,
        public bool $instrumentsLoaded,
        public bool $metadataValid,
        public bool $precisionValid,
        public bool $accountReadable,
        public bool $permissionsRead,
        public bool $permissionsTrade,
        public bool $signerConfigured,
        public bool $signerMatchesAccount,
        public bool $nonceStoreReady,
        public bool $collateralReadable,
        public bool $pollingReady,
        public bool $mainnetWriteGuard,
        public bool $demoTestnetWriteGuard,
        public bool $stopLossCapability,
        public bool $killSwitch,
        public array $allowedSymbols,
        public array $allowedMarkets,
        public ?float $maxNotional,
        public ?string $configHash,
        public array $blockingErrors,
        public array $warnings,
    ) {
    }

    /**
     * @return array{
     *     exchange: string,
     *     market_type: string,
     *     environment: string,
     *     ready_level: string,
     *     public_connectivity: bool,
     *     private_read_connectivity: bool,
     *     private_observability: bool,
     *     private_observability_status: ?array<string,mixed>,
     *     instruments_loaded: bool,
     *     metadata_valid: bool,
     *     precision_valid: bool,
     *     account_readable: bool,
     *     permissions_read: bool,
     *     permissions_trade: bool,
     *     signer_configured: bool,
     *     signer_matches_account: bool,
     *     nonce_store_ready: bool,
     *     collateral_readable: bool,
     *     polling_ready: bool,
     *     mainnet_write_guard: bool,
     *     demo_testnet_write_guard: bool,
     *     stop_loss_capability: bool,
     *     kill_switch: bool,
     *     allowed_symbols: list<string>,
     *     allowed_markets: list<string>,
     *     max_notional: ?float,
     *     config_hash: ?string,
     *     blocking_errors: list<string>,
     *     warnings: list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'exchange' => $this->exchange->value,
            'market_type' => $this->marketType->value,
            'environment' => $this->environment,
            'ready_level' => $this->readyLevel->value,
            'public_connectivity' => $this->publicConnectivity,
            'private_read_connectivity' => $this->privateReadConnectivity,
            'private_observability' => $this->privateObservability,
            'private_observability_status' => $this->privateObservabilityStatus?->toArray(),
            'instruments_loaded' => $this->instrumentsLoaded,
            'metadata_valid' => $this->metadataValid,
            'precision_valid' => $this->precisionValid,
            'account_readable' => $this->accountReadable,
            'permissions_read' => $this->permissionsRead,
            'permissions_trade' => $this->permissionsTrade,
            'signer_configured' => $this->signerConfigured,
            'signer_matches_account' => $this->signerMatchesAccount,
            'nonce_store_ready' => $this->nonceStoreReady,
            'collateral_readable' => $this->collateralReadable,
            'polling_ready' => $this->pollingReady,
            'mainnet_write_guard' => $this->mainnetWriteGuard,
            'demo_testnet_write_guard' => $this->demoTestnetWriteGuard,
            'stop_loss_capability' => $this->stopLossCapability,
            'kill_switch' => $this->killSwitch,
            'allowed_symbols' => $this->allowedSymbols,
            'allowed_markets' => $this->allowedMarkets,
            'max_notional' => $this->maxNotional,
            'config_hash' => $this->configHash,
            'blocking_errors' => $this->redactMessages($this->blockingErrors),
            'warnings' => $this->redactMessages($this->warnings),
        ];
    }

    /**
     * @param list<string> $messages
     * @return list<string>
     */
    private function redactMessages(array $messages): array
    {
        return array_map(
            static fn (string $message): string => preg_match(self::SENSITIVE_PATTERN, $message) === 1 ? '[redacted]' : $message,
            $messages,
        );
    }
}
