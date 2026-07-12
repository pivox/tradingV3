<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Execution;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\TradingCore\Execution\Hyperliquid\HyperliquidMutationReadinessGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(HyperliquidMutationReadinessGate::class)]
final class HyperliquidMutationReadinessGateTest extends TestCase
{
    #[DataProvider('blockedCandidates')]
    public function testRejectsEveryMissingMutationReadinessCondition(
        ExchangeReadinessReport $report,
        HyperliquidConfig $config,
        string $reason,
    ): void {
        self::assertSame([$reason], (new HyperliquidMutationReadinessGate())->blockingReasons($report, $config));
    }

    /** @return iterable<string, array{ExchangeReadinessReport, HyperliquidConfig, string}> */
    public static function blockedCandidates(): iterable
    {
        yield 'exchange' => [self::report(exchange: Exchange::OKX), self::config(), 'hyperliquid_exchange_required'];
        yield 'perpetual market' => [self::report(marketType: MarketType::SPOT), self::config(), 'perpetual_market_required'];
        yield 'report environment' => [self::report(environment: 'mainnet'), self::config(), 'testnet_environment_required'];
        yield 'configured environment' => [self::report(), self::config(environment: 'mainnet'), 'hyperliquid_testnet_environment_required'];
        yield 'network' => [self::report(), self::config(network: 'mainnet'), 'hyperliquid_testnet_network_required'];
        yield 'endpoint' => [self::report(), self::config(apiBaseUri: 'https://api.hyperliquid-testnet.xyz.attacker.invalid'), 'hyperliquid_testnet_endpoint_required'];
        yield 'global demo flag' => [self::report(), self::config(globalDemoTradingEnabled: false), 'global_demo_trading_must_be_enabled'];
        yield 'trading feature flag' => [self::report(), self::config(testnetTradingEnabled: false), 'hyperliquid_testnet_trading_must_be_enabled'];
        yield 'candidate level' => [self::report(readyLevel: ExchangeReadinessLevel::LocalDryRunReady), self::config(), 'demo_testnet_candidate_required'];
        yield 'account readable' => [self::report(accountReadable: false), self::config(), 'account_readable_not_proven'];
        yield 'read permission' => [self::report(permissionsRead: false), self::config(), 'read_permission_not_proven'];
        yield 'trade permission' => [self::report(permissionsTrade: false), self::config(), 'trade_permission_not_proven'];
        yield 'collateral readable' => [self::report(collateralReadable: false), self::config(), 'collateral_readable_not_proven'];
        yield 'private observability' => [self::report(privateObservability: false), self::config(), 'private_observability_not_ready'];
        yield 'polling ready' => [self::report(pollingReady: false), self::config(), 'hyperliquid_polling_not_ready'];
        yield 'demo write guard' => [self::report(demoTestnetWriteGuard: false), self::config(), 'demo_testnet_write_guard_not_ready'];
        yield 'stop loss' => [self::report(stopLossCapability: false), self::config(), 'stop_loss_capability_not_ready'];
        yield 'signer configured' => [self::report(signerConfigured: false), self::config(), 'hyperliquid_signer_not_configured'];
        yield 'account agent relation' => [self::report(signerMatchesAccount: false), self::config(), 'hyperliquid_signer_account_relation_not_ready'];
        yield 'nonce store' => [self::report(nonceStoreReady: false), self::config(), 'hyperliquid_nonce_store_not_ready'];
        yield 'mainnet guard' => [self::report(mainnetWriteGuard: false), self::config(), 'mainnet_write_guard_not_ready'];
        yield 'mainnet enabled' => [self::report(), self::config(mainnetEnabled: true), 'hyperliquid_mainnet_must_be_disabled'];
        yield 'durable or environment kill switch' => [self::report(killSwitch: true), self::config(), 'kill_switch_enabled'];
        yield 'effective profile' => [self::report(configProfile: null), self::config(), 'effective_config_profile_required'];
        yield 'config hash' => [self::report(configHash: null), self::config(), 'effective_config_hash_required'];
        yield 'allow list' => [self::report(allowedMarkets: []), self::config(), 'market_allow_list_required'];
        yield 'null max notional' => [self::report(maxNotional: null), self::config(), 'positive_max_notional_required'];
        yield 'zero max notional' => [self::report(maxNotional: 0.0), self::config(), 'positive_max_notional_required'];
    }

    public function testAcceptsOnlyTheExactProvenCandidate(): void
    {
        self::assertSame([], (new HyperliquidMutationReadinessGate())->blockingReasons(self::report(), self::config()));
    }

    public function testReasonsAreStableOrderedAndDeduplicated(): void
    {
        $report = self::report(
            exchange: Exchange::OKX,
            marketType: MarketType::SPOT,
            environment: 'mainnet',
            readyLevel: ExchangeReadinessLevel::NotReady,
            permissionsTrade: false,
            accountReadable: false,
            permissionsRead: false,
            collateralReadable: false,
            privateObservability: false,
            pollingReady: false,
            demoTestnetWriteGuard: false,
            stopLossCapability: false,
            signerConfigured: false,
            signerMatchesAccount: false,
            nonceStoreReady: false,
            mainnetWriteGuard: false,
            killSwitch: true,
            allowedMarkets: [],
            maxNotional: 0.0,
            configProfile: null,
            configHash: null,
        );

        self::assertSame([
            'hyperliquid_exchange_required',
            'perpetual_market_required',
            'testnet_environment_required',
            'hyperliquid_testnet_environment_required',
            'hyperliquid_testnet_network_required',
            'hyperliquid_testnet_endpoint_required',
            'global_demo_trading_must_be_enabled',
            'hyperliquid_testnet_trading_must_be_enabled',
            'demo_testnet_candidate_required',
            'account_readable_not_proven',
            'read_permission_not_proven',
            'trade_permission_not_proven',
            'collateral_readable_not_proven',
            'private_observability_not_ready',
            'hyperliquid_polling_not_ready',
            'demo_testnet_write_guard_not_ready',
            'stop_loss_capability_not_ready',
            'hyperliquid_signer_not_configured',
            'hyperliquid_signer_account_relation_not_ready',
            'hyperliquid_nonce_store_not_ready',
            'mainnet_write_guard_not_ready',
            'hyperliquid_mainnet_must_be_disabled',
            'kill_switch_enabled',
            'effective_config_profile_required',
            'effective_config_hash_required',
            'market_allow_list_required',
            'positive_max_notional_required',
        ], (new HyperliquidMutationReadinessGate())->blockingReasons(
            $report,
            self::config(
                environment: 'mainnet',
                network: 'mainnet',
                apiBaseUri: 'https://api.hyperliquid.xyz',
                mainnetEnabled: true,
                globalDemoTradingEnabled: false,
                testnetTradingEnabled: false,
            ),
        ));
    }

    private static function config(
        string $environment = 'testnet',
        string $network = 'testnet',
        string $apiBaseUri = 'https://api.hyperliquid-testnet.xyz',
        bool $mainnetEnabled = false,
        bool $globalDemoTradingEnabled = true,
        bool $testnetTradingEnabled = true,
    ): HyperliquidConfig {
        return new HyperliquidConfig(
            environment: $environment,
            network: $network,
            apiBaseUri: $apiBaseUri,
            mainnetEnabled: $mainnetEnabled,
            globalDemoTradingEnabled: $globalDemoTradingEnabled,
            testnetAgentAddress: '0x0000000000000000000000000000000000000002',
            testnetAccountAddress: '0x0000000000000000000000000000000000000001',
            testnetTradingEnabled: $testnetTradingEnabled,
        );
    }

    /** @param list<string> $allowedMarkets */
    private static function report(
        Exchange $exchange = Exchange::HYPERLIQUID,
        MarketType $marketType = MarketType::PERPETUAL,
        string $environment = 'testnet',
        ExchangeReadinessLevel $readyLevel = ExchangeReadinessLevel::DemoTestnetCandidate,
        bool $accountReadable = true,
        bool $permissionsRead = true,
        bool $permissionsTrade = true,
        bool $collateralReadable = true,
        bool $privateObservability = true,
        bool $pollingReady = true,
        bool $demoTestnetWriteGuard = true,
        bool $stopLossCapability = true,
        bool $signerConfigured = true,
        bool $signerMatchesAccount = true,
        bool $nonceStoreReady = true,
        bool $mainnetWriteGuard = true,
        bool $killSwitch = false,
        array $allowedMarkets = ['perpetual'],
        ?float $maxNotional = 25.0,
        ?string $configProfile = 'scalper_micro',
        ?string $configHash = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ): ExchangeReadinessReport {
        return new ExchangeReadinessReport(
            exchange: $exchange,
            marketType: $marketType,
            environment: $environment,
            readyLevel: $readyLevel,
            publicConnectivity: true,
            privateReadConnectivity: true,
            privateObservability: $privateObservability,
            privateObservabilityStatus: null,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: $accountReadable,
            permissionsRead: $permissionsRead,
            permissionsTrade: $permissionsTrade,
            signerConfigured: $signerConfigured,
            signerMatchesAccount: $signerMatchesAccount,
            nonceStoreReady: $nonceStoreReady,
            collateralReadable: $collateralReadable,
            pollingReady: $pollingReady,
            mainnetWriteGuard: $mainnetWriteGuard,
            demoTestnetWriteGuard: $demoTestnetWriteGuard,
            stopLossCapability: $stopLossCapability,
            killSwitch: $killSwitch,
            allowedSymbols: [],
            allowedMarkets: $allowedMarkets,
            maxNotional: $maxNotional,
            configHash: $configHash,
            blockingErrors: [],
            warnings: [],
            configProfile: $configProfile,
        );
    }
}
