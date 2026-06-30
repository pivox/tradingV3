<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangeReadinessEvaluator;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Exchange\Readiness\ExchangeRuntimeCheckInterface;

final readonly class OkxRuntimeCheck implements ExchangeRuntimeCheckInterface
{
    public function __construct(
        private ExchangeReadinessEvaluator $evaluator = new ExchangeReadinessEvaluator(),
    ) {
    }

    public function check(ExchangeReadinessInput $input): ExchangeReadinessReport
    {
        if ($input->exchange !== Exchange::OKX) {
            throw new \InvalidArgumentException('OkxRuntimeCheck only accepts exchange=okx.');
        }

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
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $input->demoTestnetWriteGuard,
            demoTestnetWriteEnabled: $input->demoTestnetWriteEnabled,
            stopLossCapability: $input->stopLossCapability,
            killSwitch: $input->killSwitch,
            dryRun: true,
            allowedSymbols: $input->allowedSymbols,
            allowedMarkets: $input->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: $input->blockingErrors,
            warnings: $input->warnings,
        ));

        if ($report->readyLevel === ExchangeReadinessLevel::DemoTestnetCandidate && !$input->demoTestnetWriteEnabled) {
            return $this->capAtLocalDryRun($report);
        }

        return $report;
    }

    private function capAtLocalDryRun(ExchangeReadinessReport $report): ExchangeReadinessReport
    {
        return new ExchangeReadinessReport(
            exchange: $report->exchange,
            marketType: $report->marketType,
            environment: $report->environment,
            readyLevel: ExchangeReadinessLevel::LocalDryRunReady,
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
            warnings: $report->warnings,
        );
    }
}
