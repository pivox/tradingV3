<?php

declare(strict_types=1);

namespace App\Exchange\Readiness;

final class ExchangeReadinessEvaluator implements ExchangeRuntimeCheckInterface
{
    public function check(ExchangeReadinessInput $input): ExchangeReadinessReport
    {
        return $this->evaluate($input);
    }

    public function evaluate(ExchangeReadinessInput $input): ExchangeReadinessReport
    {
        $blockingErrors = $input->blockingErrors;
        $warnings = $input->warnings;

        foreach ($this->notReadyErrors($input) as $error) {
            $blockingErrors[] = $error;
        }

        if (!$input->dryRun && !$this->isDemoOrTestnet($input->environment)) {
            $blockingErrors[] = 'demo_testnet_environment_required';
        }

        if ($blockingErrors !== []) {
            return $this->report($input, ExchangeReadinessLevel::NotReady, $blockingErrors, $warnings);
        }

        $readyLevel = ExchangeReadinessLevel::PublicReadOnly;

        if (!$this->hasPrivateRead($input)) {
            $warnings[] = 'private_read_not_ready';

            return $this->report($input, $readyLevel, [], $warnings);
        }

        $readyLevel = ExchangeReadinessLevel::PrivateReadOnly;

        if (!$this->hasGuardedReadiness($input)) {
            $warnings[] = 'local_dry_run_prerequisites_missing';

            return $this->report($input, $readyLevel, [], $warnings);
        }

        $readyLevel = ExchangeReadinessLevel::LocalDryRunReady;

        if (!$this->isDemoOrTestnet($input->environment)) {
            $warnings[] = 'demo_testnet_environment_required';

            return $this->report($input, $readyLevel, [], $warnings);
        }

        if (!$input->stopLossCapability) {
            $warnings[] = 'stop_loss_capability_required_for_demo_testnet_candidate';

            return $this->report($input, $readyLevel, [], $warnings);
        }

        $readyLevel = ExchangeReadinessLevel::DemoTestnetCandidate;

        if ($input->dryRun) {
            if (!$input->demoTestnetWriteEnabled) {
                $warnings[] = 'demo_testnet_write_not_enabled';
            }

            return $this->report($input, $readyLevel, [], $warnings);
        }

        if (!$input->demoTestnetWriteEnabled) {
            $warnings[] = 'demo_testnet_write_not_enabled';

            return $this->report($input, $readyLevel, [], $warnings);
        }

        if ($input->killSwitch) {
            $warnings[] = 'kill_switch_enabled_blocks_demo_testnet_enabled';

            return $this->report($input, $readyLevel, [], $warnings);
        }

        if (!$input->permissionsTrade) {
            $warnings[] = 'trade_permission_required_for_demo_testnet_enabled';

            return $this->report($input, $readyLevel, [], $warnings);
        }

        return $this->report($input, ExchangeReadinessLevel::DemoTestnetEnabled, [], $warnings);
    }

    /**
     * @return list<string>
     */
    private function notReadyErrors(ExchangeReadinessInput $input): array
    {
        $errors = [];

        if (!$input->mainnetWriteGuard) {
            $errors[] = 'mainnet_write_guard_missing';
        }

        if (!$input->publicConnectivity) {
            $errors[] = 'public_connectivity_unavailable';
        }

        if (!$input->instrumentsLoaded) {
            $errors[] = 'instruments_not_loaded';
        }

        if (!$input->metadataValid) {
            $errors[] = 'metadata_invalid';
        }

        if (!$input->precisionValid) {
            $errors[] = 'precision_invalid';
        }

        return $errors;
    }

    private function hasPrivateRead(ExchangeReadinessInput $input): bool
    {
        return $input->privateReadConnectivity
            && $input->accountReadable
            && $input->permissionsRead;
    }

    private function hasGuardedReadiness(ExchangeReadinessInput $input): bool
    {
        return $input->demoTestnetWriteGuard
            && ($input->allowedSymbols !== [] || $input->allowedMarkets !== [])
            && $input->maxNotional !== null;
    }

    private function isDemoOrTestnet(string $environment): bool
    {
        return in_array(strtolower($environment), ['demo', 'testnet'], true);
    }

    /**
     * @param list<string> $blockingErrors
     * @param list<string> $warnings
     */
    private function report(
        ExchangeReadinessInput $input,
        ExchangeReadinessLevel $readyLevel,
        array $blockingErrors,
        array $warnings,
    ): ExchangeReadinessReport {
        return new ExchangeReadinessReport(
            exchange: $input->exchange,
            marketType: $input->marketType,
            environment: $input->environment,
            readyLevel: $readyLevel,
            publicConnectivity: $input->publicConnectivity,
            privateReadConnectivity: $input->privateReadConnectivity,
            privateObservability: $input->privateObservability,
            instrumentsLoaded: $input->instrumentsLoaded,
            metadataValid: $input->metadataValid,
            precisionValid: $input->precisionValid,
            accountReadable: $input->accountReadable,
            permissionsRead: $input->permissionsRead,
            permissionsTrade: $input->permissionsTrade,
            mainnetWriteGuard: $input->mainnetWriteGuard,
            demoTestnetWriteGuard: $input->demoTestnetWriteGuard,
            stopLossCapability: $input->stopLossCapability,
            killSwitch: $input->killSwitch,
            allowedSymbols: $input->allowedSymbols,
            allowedMarkets: $input->allowedMarkets,
            maxNotional: $input->maxNotional,
            configHash: $input->configHash,
            blockingErrors: array_values(array_unique($blockingErrors)),
            warnings: array_values(array_unique($warnings)),
        );
    }
}
