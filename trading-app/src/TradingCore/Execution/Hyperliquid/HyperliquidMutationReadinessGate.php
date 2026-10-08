<?php

declare(strict_types=1);

namespace App\TradingCore\Execution\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidMutationReadinessProof;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

final class HyperliquidMutationReadinessGate
{
    private const TESTNET_ENDPOINT = 'https://api.hyperliquid-testnet.xyz';

    private readonly string $proofSecret;

    private readonly ClockInterface $clock;

    public function __construct(?ClockInterface $clock = null, private readonly int $proofLifetimeSeconds = 60)
    {
        $this->proofSecret = random_bytes(32);
        $this->clock = $clock ?? new Clock();
    }

    /** @return list<string> */
    public function blockingReasons(ExchangeReadinessReport $report, HyperliquidConfig $config): array
    {
        $reasons = [];
        foreach ($this->verdicts($report, $config) as $condition => $passed) {
            $passed || $reasons[] = $condition;
        }

        return $reasons;
    }

    public function issueProof(ExchangeReadinessReport $report, HyperliquidConfig $config, string $side): ?HyperliquidMutationReadinessProof
    {
        if ($this->blockingReasons($report, $config) !== [] || $report->blockingErrors !== [] || !in_array($side, ['long', 'short'], true)) {
            return null;
        }

        $profile = (string) $report->configProfile;
        $configHash = (string) $report->configHash;
        $symbols = array_values($report->allowedSymbols);
        $maxNotional = (float) $report->maxNotional;
        $issuedAt = $this->clock->now()->getTimestamp();

        return new HyperliquidMutationReadinessProof(
            $profile,
            $configHash,
            $side,
            $symbols,
            $maxNotional,
            $issuedAt,
            $this->mac($profile, $configHash, $side, $symbols, $maxNotional, $issuedAt),
        );
    }

    public function isGenuine(HyperliquidMutationReadinessProof $proof): bool
    {
        $age = $this->clock->now()->getTimestamp() - $proof->issuedAt;

        return $age >= 0
            && $age <= $this->proofLifetimeSeconds
            && hash_equals(
                $this->mac($proof->profile, $proof->configHash, $proof->side, $proof->allowedSymbols, $proof->maxNotional, $proof->issuedAt),
                $proof->mac,
            );
    }

    /** @param list<string> $symbols */
    private function mac(string $profile, string $configHash, string $side, array $symbols, float $maxNotional, int $issuedAt): string
    {
        return hash_hmac(
            'sha256',
            json_encode([$profile, $configHash, $side, $symbols, $maxNotional, $issuedAt], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            $this->proofSecret,
        );
    }

    /** @return array<string, bool> condition (the blocking reason it raises when failing) => passed */
    public function verdicts(ExchangeReadinessReport $report, HyperliquidConfig $config): array
    {
        return [
            'hyperliquid_exchange_required' => $report->exchange === Exchange::HYPERLIQUID,
            'perpetual_market_required' => $report->marketType === MarketType::PERPETUAL,
            'testnet_environment_required' => $report->environment === 'testnet',
            'hyperliquid_testnet_environment_required' => $config->configuredEnvironment() === 'testnet',
            'hyperliquid_testnet_network_required' => $config->normalizedNetwork() === 'testnet',
            'hyperliquid_testnet_endpoint_required' => $config->apiBaseUri() === self::TESTNET_ENDPOINT,
            'global_demo_trading_must_be_enabled' => $config->globalDemoTradingEnabled,
            'hyperliquid_testnet_trading_must_be_enabled' => $config->testnetTradingEnabled,
            'demo_testnet_candidate_required' => $report->readyLevel === ExchangeReadinessLevel::DemoTestnetCandidate,
            'account_readable_not_proven' => $report->accountReadable,
            'read_permission_not_proven' => $report->permissionsRead,
            'trade_permission_not_proven' => $report->permissionsTrade,
            'collateral_readable_not_proven' => $report->collateralReadable,
            'private_observability_not_ready' => $report->privateObservability,
            'hyperliquid_polling_not_ready' => $report->pollingReady,
            'demo_testnet_write_guard_not_ready' => $report->demoTestnetWriteGuard,
            'stop_loss_capability_not_ready' => $report->stopLossCapability,
            'hyperliquid_signer_not_configured' => $report->signerConfigured,
            'hyperliquid_signer_account_relation_not_ready' => $report->signerMatchesAccount,
            'hyperliquid_nonce_store_not_ready' => $report->nonceStoreReady,
            'mainnet_write_guard_not_ready' => $report->mainnetWriteGuard,
            'hyperliquid_mainnet_must_be_disabled' => !$config->mainnetEnabled,
            'kill_switch_enabled' => !$report->killSwitch,
            'effective_config_profile_required' => $this->hasProfileEvidence($report),
            'effective_config_hash_required' => $this->hasConfigHash($report),
            'market_allow_list_required' => $this->hasAllowList($report),
            'positive_max_notional_required' => $this->hasPositiveMaxNotional($report),
        ];
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
