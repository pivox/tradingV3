<?php

declare(strict_types=1);

namespace App\Provider\Okx;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangeReadinessEvaluator;
use App\Exchange\Readiness\ExchangeReadinessInput;
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

        return $this->evaluator->evaluate(new ExchangeReadinessInput(
            exchange: $input->exchange,
            marketType: $input->marketType,
            environment: $input->environment,
            publicConnectivity: $input->publicConnectivity,
            privateReadConnectivity: false,
            privateObservability: false,
            privateObservabilityStatus: $input->privateObservabilityStatus,
            instrumentsLoaded: $input->instrumentsLoaded,
            metadataValid: $input->metadataValid,
            precisionValid: $input->precisionValid,
            accountReadable: false,
            permissionsRead: false,
            permissionsTrade: false,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $input->demoTestnetWriteGuard,
            demoTestnetWriteEnabled: $input->demoTestnetWriteEnabled,
            stopLossCapability: false,
            killSwitch: $input->killSwitch,
            dryRun: $input->dryRun,
            allowedSymbols: $input->allowedSymbols,
            allowedMarkets: $input->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: $input->blockingErrors,
            warnings: $input->warnings,
        ));
    }
}
