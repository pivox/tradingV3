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
    public function __construct(
        private ExchangeReadinessEvaluator $evaluator = new ExchangeReadinessEvaluator(),
        private ?HyperliquidMutationReadinessProbeInterface $probe = null,
    ) {
    }

    public function check(ExchangeReadinessInput $input): ExchangeReadinessReport
    {
        if ($input->exchange !== Exchange::HYPERLIQUID) {
            throw new \InvalidArgumentException('HyperliquidRuntimeCheck only accepts exchange=hyperliquid.');
        }
        if ($this->probe instanceof HyperliquidMutationReadinessProbeInterface) {
            return $this->probe->current();
        }

        $warnings = $input->warnings;
        if (!$input->publicConnectivity || !$input->instrumentsLoaded || !$input->metadataValid || !$input->precisionValid) {
            $warnings[] = 'hyperliquid_public_read_not_ready';
        }
        if (!$input->privateReadConnectivity || !$input->accountReadable || !$input->permissionsRead) {
            $warnings[] = 'hyperliquid_account_read_not_ready';
        }
        $warnings[] = 'hyperliquid_agent_wallet_trade_permission_not_proven';
        if (!$input->signerConfigured) {
            $warnings[] = 'hyperliquid_agent_wallet_not_configured';
        }
        if (!$input->signerMatchesAccount) {
            $warnings[] = 'hyperliquid_agent_wallet_account_relation_not_ready';
        }
        if (!$input->nonceStoreReady) {
            $warnings[] = 'hyperliquid_nonce_store_not_ready';
        }
        if (!$input->collateralReadable) {
            $warnings[] = 'hyperliquid_collateral_not_readable';
        }
        if (!$input->pollingReady) {
            $warnings[] = 'hyperliquid_polling_not_ready';
        }

        $guardedReadiness = $input->demoTestnetWriteGuard
            && $input->signerConfigured
            && $input->signerMatchesAccount
            && $input->nonceStoreReady
            && $input->collateralReadable
            && $input->pollingReady;

        $report = $this->evaluator->evaluate(new ExchangeReadinessInput(
            exchange: $input->exchange,
            marketType: $input->marketType,
            environment: $input->environment,
            publicConnectivity: $input->publicConnectivity,
            privateReadConnectivity: $input->privateReadConnectivity,
            privateObservability: $input->privateObservability,
            privateObservabilityStatus: $input->privateObservabilityStatus,
            instrumentsLoaded: $input->instrumentsLoaded,
            metadataValid: $input->metadataValid,
            precisionValid: $input->precisionValid,
            accountReadable: $input->accountReadable,
            permissionsRead: $input->permissionsRead,
            permissionsTrade: false,
            signerConfigured: $input->signerConfigured,
            signerMatchesAccount: $input->signerMatchesAccount,
            nonceStoreReady: $input->nonceStoreReady,
            collateralReadable: $input->collateralReadable,
            pollingReady: $input->pollingReady,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $guardedReadiness,
            demoTestnetWriteEnabled: $input->demoTestnetWriteEnabled,
            stopLossCapability: $input->stopLossCapability,
            killSwitch: $input->killSwitch,
            dryRun: true,
            allowedSymbols: $input->allowedSymbols,
            allowedMarkets: $input->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: $input->blockingErrors,
            warnings: $warnings,
            configProfile: $input->configProfile,
        ));

        $readyLevel = $report->readyLevel;
        if ($readyLevel === ExchangeReadinessLevel::DemoTestnetCandidate && !$input->demoTestnetWriteEnabled) {
            $readyLevel = ExchangeReadinessLevel::LocalDryRunReady;
        }

        return new ExchangeReadinessReport(
            exchange: $input->exchange,
            marketType: $input->marketType,
            environment: $input->environment,
            readyLevel: $readyLevel,
            publicConnectivity: $report->publicConnectivity,
            privateReadConnectivity: $report->privateReadConnectivity,
            privateObservability: $report->privateObservability,
            privateObservabilityStatus: $report->privateObservabilityStatus,
            instrumentsLoaded: $report->instrumentsLoaded,
            metadataValid: $report->metadataValid,
            precisionValid: $report->precisionValid,
            accountReadable: $report->accountReadable,
            permissionsRead: $report->permissionsRead,
            permissionsTrade: false,
            signerConfigured: $report->signerConfigured,
            signerMatchesAccount: $report->signerMatchesAccount,
            nonceStoreReady: $report->nonceStoreReady,
            collateralReadable: $report->collateralReadable,
            pollingReady: $report->pollingReady,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $report->demoTestnetWriteGuard,
            stopLossCapability: $report->stopLossCapability,
            killSwitch: $report->killSwitch,
            allowedSymbols: $report->allowedSymbols,
            allowedMarkets: $report->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: $report->readyLevel === ExchangeReadinessLevel::NotReady
                ? $this->blockingErrors($report)
                : $report->blockingErrors,
            warnings: $report->warnings,
            configProfile: $input->configProfile,
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
