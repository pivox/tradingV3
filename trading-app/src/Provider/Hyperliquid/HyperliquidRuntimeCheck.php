<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessEvaluator;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Exchange\Readiness\ExchangeRuntimeCheckInterface;

final readonly class HyperliquidRuntimeCheck implements ExchangeRuntimeCheckInterface
{
    public function check(ExchangeReadinessInput $input): ExchangeReadinessReport
    {
        if ($input->exchange !== Exchange::HYPERLIQUID) {
            throw new \InvalidArgumentException('HyperliquidRuntimeCheck only accepts exchange=hyperliquid.');
        }

        $warnings = $input->warnings;
        if (!$input->publicConnectivity || !$input->instrumentsLoaded || !$input->metadataValid || !$input->precisionValid) {
            $warnings[] = 'hyperliquid_public_read_not_ready';
        }
        if (!$input->privateReadConnectivity || !$input->accountReadable || !$input->permissionsRead) {
            $warnings[] = 'hyperliquid_account_read_not_ready';
        }
        if (!$input->permissionsTrade) {
            $warnings[] = 'hyperliquid_api_wallet_not_ready';
        }

        $report = (new ExchangeReadinessEvaluator())->evaluate(new ExchangeReadinessInput(
            exchange: $input->exchange,
            marketType: $input->marketType,
            environment: $input->environment,
            publicConnectivity: $input->publicConnectivity,
            privateReadConnectivity: $input->privateReadConnectivity,
            privateObservability: false,
            privateObservabilityStatus: null,
            instrumentsLoaded: $input->instrumentsLoaded,
            metadataValid: $input->metadataValid,
            precisionValid: $input->precisionValid,
            accountReadable: $input->accountReadable,
            permissionsRead: $input->permissionsRead,
            permissionsTrade: false,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $input->demoTestnetWriteGuard,
            demoTestnetWriteEnabled: false,
            stopLossCapability: false,
            killSwitch: true,
            dryRun: true,
            allowedSymbols: $input->allowedSymbols,
            allowedMarkets: $input->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: $input->blockingErrors,
            warnings: $warnings,
        ));

        return new ExchangeReadinessReport(
            exchange: $input->exchange,
            marketType: $input->marketType,
            environment: $input->environment,
            readyLevel: $report->readyLevel,
            publicConnectivity: $report->publicConnectivity,
            privateReadConnectivity: $report->privateReadConnectivity,
            privateObservability: false,
            privateObservabilityStatus: null,
            instrumentsLoaded: $report->instrumentsLoaded,
            metadataValid: $report->metadataValid,
            precisionValid: $report->precisionValid,
            accountReadable: $report->accountReadable,
            permissionsRead: $report->permissionsRead,
            permissionsTrade: false,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $input->demoTestnetWriteGuard,
            stopLossCapability: false,
            killSwitch: true,
            allowedSymbols: $report->allowedSymbols,
            allowedMarkets: $report->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: $report->readyLevel === ExchangeReadinessLevel::NotReady
                ? $this->blockingErrors($report)
                : $report->blockingErrors,
            warnings: $report->warnings,
        );
    }

    /**
     * @return list<string>
     */
    private function blockingErrors(ExchangeReadinessReport $report): array
    {
        $errors = $report->blockingErrors;
        if (
            in_array('public_connectivity_unavailable', $errors, true)
            || in_array('instruments_not_loaded', $errors, true)
            || in_array('metadata_invalid', $errors, true)
            || in_array('precision_invalid', $errors, true)
        ) {
            $errors[] = 'hyperliquid_provider_bundle_skeleton_not_ready';
        }

        return array_values(array_unique($errors));
    }
}
