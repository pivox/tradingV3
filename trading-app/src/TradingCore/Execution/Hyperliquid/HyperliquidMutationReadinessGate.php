<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;

final class HyperliquidMutationReadinessGate
{
    private const TESTNET_ENDPOINT = 'https://api.hyperliquid-testnet.xyz';

    /** @return list<string> */
    public function blockingReasons(ExchangeReadinessReport $report, HyperliquidConfig $config): array
    {
        $reasons = [];

        $report->exchange === Exchange::HYPERLIQUID || $reasons[] = 'hyperliquid_exchange_required';
        $report->marketType === MarketType::PERPETUAL || $reasons[] = 'perpetual_market_required';
        $report->environment === 'testnet' || $reasons[] = 'testnet_environment_required';
        $config->configuredEnvironment() === 'testnet' || $reasons[] = 'hyperliquid_testnet_environment_required';
        $config->normalizedNetwork() === 'testnet' || $reasons[] = 'hyperliquid_testnet_network_required';
        $config->apiBaseUri() === self::TESTNET_ENDPOINT || $reasons[] = 'hyperliquid_testnet_endpoint_required';
        $config->globalDemoTradingEnabled || $reasons[] = 'global_demo_trading_must_be_enabled';
        $config->testnetTradingEnabled || $reasons[] = 'hyperliquid_testnet_trading_must_be_enabled';
        $report->readyLevel === ExchangeReadinessLevel::DemoTestnetCandidate || $reasons[] = 'demo_testnet_candidate_required';
        $report->accountReadable || $reasons[] = 'account_readable_not_proven';
        $report->permissionsRead || $reasons[] = 'read_permission_not_proven';
        $report->permissionsTrade || $reasons[] = 'trade_permission_not_proven';
        $report->collateralReadable || $reasons[] = 'collateral_readable_not_proven';
        $report->privateObservability || $reasons[] = 'private_observability_not_ready';
        $report->pollingReady || $reasons[] = 'hyperliquid_polling_not_ready';
        $report->demoTestnetWriteGuard || $reasons[] = 'demo_testnet_write_guard_not_ready';
        $report->stopLossCapability || $reasons[] = 'stop_loss_capability_not_ready';
        $report->signerConfigured || $reasons[] = 'hyperliquid_signer_not_configured';
        $report->signerMatchesAccount || $reasons[] = 'hyperliquid_signer_account_relation_not_ready';
        $report->nonceStoreReady || $reasons[] = 'hyperliquid_nonce_store_not_ready';
        $report->mainnetWriteGuard || $reasons[] = 'mainnet_write_guard_not_ready';
        !$config->mainnetEnabled || $reasons[] = 'hyperliquid_mainnet_must_be_disabled';
        !$report->killSwitch || $reasons[] = 'kill_switch_enabled';
        $this->hasProfileEvidence($report) || $reasons[] = 'effective_config_profile_required';
        $this->hasConfigHash($report) || $reasons[] = 'effective_config_hash_required';
        $this->hasAllowList($report) || $reasons[] = 'market_allow_list_required';
        $this->hasPositiveMaxNotional($report) || $reasons[] = 'positive_max_notional_required';

        return array_values(array_unique($reasons));
    }

    private function hasProfileEvidence(ExchangeReadinessReport $report): bool
    {
        return is_string($report->configProfile) && trim($report->configProfile) !== '';
    }

    private function hasConfigHash(ExchangeReadinessReport $report): bool
    {
        return is_string($report->configHash)
            && preg_match('/^[a-f0-9]{64}$/D', $report->configHash) === 1;
    }

    private function hasAllowList(ExchangeReadinessReport $report): bool
    {
        foreach (array_merge($report->allowedSymbols, $report->allowedMarkets) as $value) {
            if (trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasPositiveMaxNotional(ExchangeReadinessReport $report): bool
    {
        return $report->maxNotional !== null
            && is_finite($report->maxNotional)
            && $report->maxNotional > 0.0;
    }
}
