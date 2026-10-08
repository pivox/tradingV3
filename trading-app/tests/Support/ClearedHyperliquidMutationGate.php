<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidMutationReadinessProof;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\TradingCore\Execution\Hyperliquid\HyperliquidMutationReadinessGate;

final class ClearedHyperliquidMutationGate
{
    public readonly HyperliquidMutationReadinessGate $gate;
    public readonly HyperliquidMutationReadinessProof $proof;
    private readonly float $maxNotional;

    public function __construct(
        string $profile = 'scalping_1_1_0.pullback.long_1_1_0.long',
        string $side = 'long',
        float $maxNotional = 1_000_000.0,
        ?\Psr\Clock\ClockInterface $clock = null,
        int $lifetime = 60,
    ) {
        $this->gate = new HyperliquidMutationReadinessGate($clock, $lifetime);
        $this->maxNotional = $maxNotional;
        $proof = $this->gate->issueProof($this->report($profile), new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
            mainnetEnabled: false,
            globalDemoTradingEnabled: true,
            testnetTradingEnabled: true,
            testnetAccountAddress: '0x1111111111111111111111111111111111111111',
            testnetAgentAddress: '0x2222222222222222222222222222222222222222',
        ), $side);
        $this->proof = $proof ?? throw new \LogicException('gate_not_cleared');
    }

    private function report(string $profile): ExchangeReadinessReport
    {
        return new ExchangeReadinessReport(
            exchange: Exchange::HYPERLIQUID,
            marketType: MarketType::PERPETUAL,
            environment: 'testnet',
            readyLevel: ExchangeReadinessLevel::DemoTestnetCandidate,
            publicConnectivity: true,
            privateReadConnectivity: true,
            privateObservability: true,
            privateObservabilityStatus: null,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            permissionsTrade: true,
            signerConfigured: true,
            signerMatchesAccount: true,
            nonceStoreReady: true,
            collateralReadable: true,
            pollingReady: true,
            mainnetWriteGuard: true,
            demoTestnetWriteGuard: true,
            stopLossCapability: true,
            killSwitch: false,
            allowedSymbols: ['BTCUSDT'],
            allowedMarkets: ['perpetual'],
            maxNotional: $this->maxNotional,
            configHash: str_repeat('a', 64),
            blockingErrors: [],
            warnings: [],
            configProfile: $profile,
        );
    }
}
