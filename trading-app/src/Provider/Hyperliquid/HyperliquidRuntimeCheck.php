<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Exchange\Readiness\ExchangeRuntimeCheckInterface;

final readonly class HyperliquidRuntimeCheck implements ExchangeRuntimeCheckInterface
{
    public function check(ExchangeReadinessInput $input): ExchangeReadinessReport
    {
        if ($input->exchange !== Exchange::HYPERLIQUID) {
            throw new \InvalidArgumentException('HyperliquidRuntimeCheck only accepts exchange=hyperliquid.');
        }

        return new ExchangeReadinessReport(
            exchange: $input->exchange,
            marketType: $input->marketType,
            environment: $input->environment,
            readyLevel: ExchangeReadinessLevel::NotReady,
            publicConnectivity: false,
            privateReadConnectivity: false,
            privateObservability: false,
            privateObservabilityStatus: null,
            instrumentsLoaded: false,
            metadataValid: false,
            precisionValid: false,
            accountReadable: false,
            permissionsRead: false,
            permissionsTrade: false,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $input->demoTestnetWriteGuard,
            stopLossCapability: false,
            killSwitch: true,
            allowedSymbols: $input->allowedSymbols,
            allowedMarkets: $input->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: array_values(array_unique([
                ...$input->blockingErrors,
                'hyperliquid_provider_bundle_skeleton_not_ready',
            ])),
            warnings: array_values(array_unique([
                ...$input->warnings,
                'hyperliquid_public_read_not_ready',
                'hyperliquid_account_read_not_ready',
                'hyperliquid_api_wallet_not_ready',
                'hyperliquid_nonce_manager_not_ready',
            ])),
        );
    }
}
