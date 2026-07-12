<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Contract\Provider\ExchangeProviderRegistryInterface;
use App\Exchange\Contract\ExchangeAdapterRegistryInterface;
use App\Exchange\Dto\ExchangeCapabilities;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityPolicy;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityStatus;
use App\Exchange\Hyperliquid\HyperliquidReadinessInfoClientInterface;
use App\Exchange\Hyperliquid\HyperliquidSignedActionClientInterface;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessEvaluator;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Provider\Context\ExchangeContext;
use App\Provider\Registry\ExchangeProviderBundle;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchTripInterface;
use Psr\Clock\ClockInterface;

final readonly class HyperliquidMutationReadinessProbe implements HyperliquidMutationReadinessProbeInterface
{
    private const TESTNET_ENDPOINT = 'https://api.hyperliquid-testnet.xyz';
    private const ADDRESS_PATTERN = '/^0x[0-9a-f]{40}$/D';

    public function __construct(
        private HyperliquidConfig $config,
        private ExchangeAdapterRegistryInterface $adapters,
        private ExchangeProviderRegistryInterface $providers,
        private HyperliquidReadinessInfoClientInterface $readinessInfoClient,
        private HyperliquidSignedActionClientInterface $signedClient,
        private HyperliquidNonceManagerInterface $nonceManager,
        private HyperliquidKillSwitchTripInterface $durableKillSwitch,
        private HyperliquidPollingObservabilityPolicy $pollingPolicy,
        private ClockInterface $clock,
        private HyperliquidReconciliationStatusInterface $reconciliationStatus,
        private HyperliquidMutationReadinessConfigSourceInterface $readinessConfig,
    ) {
    }

    public function current(): ExchangeReadinessReport
    {
        $warnings = [];
        $runtimeConfig = $this->runtimeConfig($warnings);
        $maxNotional = $runtimeConfig->maxNotional;
        if ($maxNotional !== null && (!is_finite($maxNotional) || $maxNotional <= 0.0)) {
            $warnings[] = 'positive_max_notional_required';
            $maxNotional = null;
        }
        $endpointReady = $this->config->configuredEnvironment() === 'testnet'
            && $this->config->normalizedNetwork() === 'testnet'
            && $this->config->apiBaseUri() === self::TESTNET_ENDPOINT
            && !$this->config->mainnetEnabled;
        if (!$endpointReady) {
            $warnings[] = 'hyperliquid_testnet_endpoint_guard_not_ready';
        }

        [$bundle, $capabilities] = $endpointReady
            ? $this->collaborators($warnings)
            : [null, new ExchangeCapabilities()];
        [$publicConnectivity, $instrumentsLoaded] = $this->publicRead($bundle, $warnings);
        [$accountReadable, $collateralReadable] = $this->accountRead($bundle, $warnings);
        $ordersReady = $this->ordersRead($bundle, $warnings);
        $fillsReady = $this->fillsRead($bundle, $warnings);
        $positionsReady = $this->positionsRead($bundle, $warnings);

        $accountAddress = $this->config->signingAccountAddress();
        $agentAddress = $this->config->signerAddress();
        $addressesReady = $this->validAddress($accountAddress)
            && $this->validAddress($agentAddress)
            && $accountAddress !== $agentAddress;
        if (!$addressesReady) {
            $warnings[] = 'hyperliquid_agent_wallet_account_relation_not_ready';
        }

        $masterAccountReady = $endpointReady
            && $addressesReady
            && $this->masterAccountRole($accountAddress, $warnings);
        $permissionsTrade = $masterAccountReady
            && $this->tradePermission($accountAddress, $agentAddress, $warnings);
        $sidecarReady = $addressesReady && $this->sidecarReady($warnings);
        $nonceReady = $addressesReady && $this->nonceReady($accountAddress, $agentAddress, $warnings);
        $durableKillSwitch = $this->durableKillSwitch($warnings);
        $reconciliationInFlight = $this->reconciliationInFlight($warnings);

        $pollingStatus = new HyperliquidPollingObservabilityStatus(
            exchange: Exchange::HYPERLIQUID,
            environment: $this->config->configuredEnvironment(),
            endpoint: $this->config->apiBaseUri(),
            initialSnapshotLoaded: $accountReadable,
            ordersReady: $ordersReady,
            fillsReady: $fillsReady,
            positionsReady: $positionsReady,
            reconciliationInFlight: $reconciliationInFlight,
            observedAt: $this->clock->now(),
        );
        $pollingReasons = $this->pollingPolicy->blockingReasons($pollingStatus);
        $pollingReady = $pollingReasons === [];

        $killSwitch = $durableKillSwitch || $runtimeConfig->killSwitchEnabled;
        $stopLossCapability = $capabilities->supportsTriggerOrders || $capabilities->supportsAttachedStopLossOnEntry;
        $mutationEvidenceReady = $endpointReady
            && $runtimeConfig->authorizesTestnetMutation()
            && $this->config->testnetTradingEnabled
            && $this->config->globalDemoTradingEnabled
            && $accountReadable
            && $collateralReadable
            && $permissionsTrade
            && $sidecarReady
            && $nonceReady
            && $pollingReady
            && $stopLossCapability
            && !$killSwitch;
        $report = (new ExchangeReadinessEvaluator())->evaluate(new ExchangeReadinessInput(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: $this->config->configuredEnvironment(),
            publicConnectivity: $publicConnectivity,
            privateReadConnectivity: $accountReadable,
            privateObservability: $pollingReady,
            instrumentsLoaded: $instrumentsLoaded,
            metadataValid: $instrumentsLoaded,
            precisionValid: $instrumentsLoaded,
            accountReadable: $accountReadable,
            permissionsRead: $accountReadable,
            permissionsTrade: $permissionsTrade,
            signerConfigured: $sidecarReady,
            signerMatchesAccount: $sidecarReady,
            nonceStoreReady: $nonceReady,
            collateralReadable: $collateralReadable,
            pollingReady: $pollingReady,
            mainnetWriteGuard: !$this->config->mainnetEnabled,
            demoTestnetWriteGuard: $mutationEvidenceReady,
            demoTestnetWriteEnabled: $this->config->testnetTradingEnabled,
            stopLossCapability: $stopLossCapability,
            killSwitch: $killSwitch,
            dryRun: true,
            allowedSymbols: $runtimeConfig->allowedSymbols,
            allowedMarkets: $runtimeConfig->allowedMarkets,
            maxNotional: $maxNotional,
            configHash: $runtimeConfig->configHash,
            configProfile: $runtimeConfig->profile,
            warnings: array_values(array_unique(array_merge($warnings, $pollingReasons))),
        ));

        return new ExchangeReadinessReport(
            exchange: $report->exchange,
            marketType: $report->marketType,
            environment: $report->environment,
            readyLevel: $report->readyLevel,
            publicConnectivity: $report->publicConnectivity,
            privateReadConnectivity: $report->privateReadConnectivity,
            privateObservability: $report->privateObservability,
            privateObservabilityStatus: $report->privateObservabilityStatus,
            instrumentsLoaded: $report->instrumentsLoaded,
            metadataValid: $report->metadataValid,
            precisionValid: $report->precisionValid,
            accountReadable: $report->accountReadable,
            permissionsRead: $report->permissionsRead,
            permissionsTrade: $report->permissionsTrade,
            signerConfigured: $report->signerConfigured,
            signerMatchesAccount: $report->signerMatchesAccount,
            nonceStoreReady: $report->nonceStoreReady,
            collateralReadable: $report->collateralReadable,
            pollingReady: $report->pollingReady,
            mainnetWriteGuard: $report->mainnetWriteGuard,
            demoTestnetWriteGuard: $report->demoTestnetWriteGuard,
            stopLossCapability: $report->stopLossCapability,
            killSwitch: $report->killSwitch,
            allowedSymbols: $report->allowedSymbols,
            allowedMarkets: $report->allowedMarkets,
            maxNotional: $report->maxNotional,
            configHash: $report->configHash,
            blockingErrors: $report->blockingErrors,
            warnings: $pollingReady
                ? array_values(array_diff($report->warnings, ['private_observability_absent_for_dry_run']))
                : $report->warnings,
            configProfile: $report->configProfile,
            hyperliquidPollingObservabilityStatus: $pollingStatus,
        );
    }

    /**
     * @param list<string> $warnings
     * @return array{0: ?ExchangeProviderBundle, 1: ExchangeCapabilities}
     */
    private function collaborators(array &$warnings): array
    {
        try {
            $context = new ExchangeContext(Exchange::HYPERLIQUID, MarketType::PERPETUAL);
            $bundle = $this->providers->get($context);
            $capabilities = $this->adapters->get(Exchange::HYPERLIQUID, MarketType::PERPETUAL)->capabilities();

            return [$bundle, $capabilities];
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_runtime_collaborators_not_ready';

            return [null, new ExchangeCapabilities()];
        }
    }

    /**
     * @param list<string> $warnings
     * @return array{0: bool, 1: bool}
     */
    private function publicRead(?ExchangeProviderBundle $bundle, array &$warnings): array
    {
        if (!$bundle instanceof ExchangeProviderBundle) {
            return [false, false];
        }

        try {
            $loaded = $bundle->contract()->getContracts() !== [];

            return [true, $loaded];
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_public_read_probe_failed';

            return [false, false];
        }
    }

    /**
     * @param list<string> $warnings
     * @return array{0: bool, 1: bool}
     */
    private function accountRead(?ExchangeProviderBundle $bundle, array &$warnings): array
    {
        if (!$bundle instanceof ExchangeProviderBundle) {
            return [false, false];
        }

        try {
            $account = $bundle->account()->getAccountInfo();
            if ($account !== null) {
                if ($this->collateralReadable($account)) {
                    return [true, true];
                }

                $warnings[] = 'hyperliquid_collateral_read_probe_failed';

                return [true, false];
            }
        } catch (\Throwable) {
        }

        $warnings[] = 'hyperliquid_account_read_probe_failed';

        return [false, false];
    }

    private function collateralReadable(\App\Contract\Provider\Dto\AccountDto $account): bool
    {
        if (strtoupper($account->currency) !== 'USDC') {
            return false;
        }

        foreach ([$account->availableBalance, $account->equity, $account->positionDeposit] as $value) {
            if (!is_finite($value->toFloat())) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $warnings */
    private function ordersRead(?ExchangeProviderBundle $bundle, array &$warnings): bool
    {
        if (!$bundle instanceof ExchangeProviderBundle) {
            return false;
        }

        try {
            $bundle->order()->getOpenOrdersOrFail();

            return true;
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_open_orders_poll_failed';

            return false;
        }
    }

    /** @param list<string> $warnings */
    private function fillsRead(?ExchangeProviderBundle $bundle, array &$warnings): bool
    {
        if (!$bundle instanceof ExchangeProviderBundle) {
            return false;
        }

        try {
            $bundle->account()->getTrades(limit: 20);

            return true;
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_fills_poll_failed';

            return false;
        }
    }

    /** @param list<string> $warnings */
    private function positionsRead(?ExchangeProviderBundle $bundle, array &$warnings): bool
    {
        if (!$bundle instanceof ExchangeProviderBundle) {
            return false;
        }

        try {
            $bundle->account()->getOpenPositionsOrFail();

            return true;
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_positions_poll_failed';

            return false;
        }
    }

    /** @param list<string> $warnings */
    private function masterAccountRole(string $accountAddress, array &$warnings): bool
    {
        try {
            $role = $this->readinessInfoClient->readinessInfo(['type' => 'userRole', 'user' => $accountAddress]);
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_user_role_probe_failed';

            return false;
        }

        if (array_is_list($role) || !isset($role['role']) || !is_string($role['role'])) {
            $warnings[] = 'hyperliquid_user_role_response_malformed';

            return false;
        }
        if ($role['role'] === 'subAccount') {
            $warnings[] = 'hyperliquid_subaccount_not_supported';

            return false;
        }
        if ($role['role'] !== 'user') {
            $warnings[] = 'hyperliquid_master_account_role_not_proven';

            return false;
        }
        if (array_keys($role) !== ['role']) {
            $warnings[] = 'hyperliquid_user_role_response_malformed';

            return false;
        }

        return true;
    }

    /** @param list<string> $warnings */
    private function tradePermission(string $accountAddress, string $agentAddress, array &$warnings): bool
    {
        try {
            $rows = $this->readinessInfoClient->readinessInfo(['type' => 'extraAgents', 'user' => $accountAddress]);
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_extra_agents_probe_failed';

            return false;
        }

        if (!array_is_list($rows)) {
            $warnings[] = 'hyperliquid_extra_agents_response_malformed';

            return false;
        }

        $nowMilliseconds = $this->milliseconds($this->clock->now());
        $matches = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_is_list($row)
                || !isset($row['address'], $row['validUntil'])
                || !is_string($row['address'])
                || !is_int($row['validUntil'])
                || !$this->validAddress(strtolower($row['address']))
                || (array_key_exists('name', $row) && !is_string($row['name']))
                || array_diff(array_keys($row), ['address', 'validUntil', 'name']) !== []) {
                $warnings[] = 'hyperliquid_extra_agents_response_malformed';

                return false;
            }

            if (strtolower($row['address']) !== $agentAddress) {
                continue;
            }
            $matches[] = $row;
        }

        if (count($matches) > 1) {
            $warnings[] = 'hyperliquid_extra_agents_response_ambiguous';

            return false;
        }
        if ($matches === []) {
            $warnings[] = 'hyperliquid_agent_wallet_trade_permission_not_proven';

            return false;
        }
        if ($matches[0]['validUntil'] <= $nowMilliseconds) {
            $warnings[] = 'hyperliquid_agent_wallet_trade_permission_expired';

            return false;
        }

        return true;
    }

    /** @param list<string> $warnings */
    private function sidecarReady(array &$warnings): bool
    {
        try {
            if ($this->signedClient->health()) {
                return true;
            }
        } catch (\Throwable) {
        }

        $warnings[] = 'hyperliquid_signer_sidecar_not_ready';

        return false;
    }

    /** @param list<string> $warnings */
    private function nonceReady(string $accountAddress, string $agentAddress, array &$warnings): bool
    {
        try {
            if ($this->nonceManager->isReady(new HyperliquidNonceScope(
                environment: 'testnet',
                network: 'testnet',
                accountAddress: $accountAddress,
                signerAddress: $agentAddress,
            ))) {
                return true;
            }
        } catch (\Throwable) {
        }

        $warnings[] = 'hyperliquid_nonce_store_not_ready';

        return false;
    }

    /** @param list<string> $warnings */
    private function durableKillSwitch(array &$warnings): bool
    {
        try {
            return $this->durableKillSwitch->isTripped();
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_durable_kill_switch_probe_failed';

            return true;
        }
    }

    /** @param list<string> $warnings */
    private function reconciliationInFlight(array &$warnings): bool
    {
        try {
            return $this->reconciliationStatus->isInFlight();
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_reconciliation_status_unavailable';

            return true;
        }
    }

    /** @param list<string> $warnings */
    private function runtimeConfig(array &$warnings): HyperliquidMutationReadinessConfig
    {
        try {
            return $this->readinessConfig->current();
        } catch (\Throwable) {
            $warnings[] = 'hyperliquid_readiness_config_unavailable';

            return HyperliquidMutationReadinessConfig::failClosed();
        }
    }

    private function validAddress(string $address): bool
    {
        $address = strtolower($address);

        return preg_match(self::ADDRESS_PATTERN, $address) === 1
            && $address !== '0x0000000000000000000000000000000000000000';
    }

    private function milliseconds(\DateTimeInterface $dateTime): int
    {
        return ((int) $dateTime->format('U') * 1_000) + (int) $dateTime->format('v');
    }
}
