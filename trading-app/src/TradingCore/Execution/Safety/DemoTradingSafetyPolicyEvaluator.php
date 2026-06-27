<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

final class DemoTradingSafetyPolicyEvaluator
{
    public function evaluate(DemoTradingSafetyPolicy $policy): DemoTradingSafetyDecision
    {
        $blockingErrors = [];
        $warnings = [];

        if ($policy->mainnetWriteEnabled) {
            $blockingErrors[] = 'mainnet_write_enabled_must_remain_false';
        }

        if ($policy->environment === ExchangeRuntimeEnvironment::MAINNET && $policy->isWriteRequested()) {
            $blockingErrors[] = 'mainnet_write_forbidden';
        }

        if ($policy->isWriteRequested()) {
            if ($policy->environment === ExchangeRuntimeEnvironment::LOCAL_DRY_RUN) {
                $blockingErrors[] = 'local_dry_run_cannot_write';
            }

            if ($policy->environment->isDemoOrTestnet()) {
                $blockingErrors = array_merge($blockingErrors, $this->demoTestnetWriteErrors($policy));
            }
        }

        if ($blockingErrors !== []) {
            return new DemoTradingSafetyDecision(
                allowed: false,
                level: DemoTradingSafetyLevel::Blocked,
                blockingErrors: array_values(array_unique($blockingErrors)),
                warnings: $warnings,
                policy: $policy,
            );
        }

        if (!$policy->isWriteRequested()) {
            if ($policy->environment->isDemoOrTestnet()) {
                $warnings[] = 'dry_run_no_exchange_order';

                return new DemoTradingSafetyDecision(
                    allowed: true,
                    level: DemoTradingSafetyLevel::DemoTestnetCandidate,
                    blockingErrors: [],
                    warnings: $warnings,
                    policy: $policy,
                );
            }

            return new DemoTradingSafetyDecision(
                allowed: true,
                level: DemoTradingSafetyLevel::LocalDryRun,
                blockingErrors: [],
                warnings: $warnings,
                policy: $policy,
            );
        }

        return new DemoTradingSafetyDecision(
            allowed: true,
            level: DemoTradingSafetyLevel::DemoTestnetEnabled,
            blockingErrors: [],
            warnings: $warnings,
            policy: $policy,
        );
    }

    /**
     * @return list<string>
     */
    private function demoTestnetWriteErrors(DemoTradingSafetyPolicy $policy): array
    {
        $errors = [];

        if (!$policy->demoTestnetWriteEnabled) {
            $errors[] = 'demo_testnet_write_not_enabled';
        }

        if ($policy->killSwitchEnabled) {
            $errors[] = 'kill_switch_enabled';
        }

        if (!$policy->requireStopLoss) {
            $errors[] = 'stop_loss_required';
        }

        if ($policy->allowedSymbols === [] && $policy->allowedMarkets === []) {
            $errors[] = 'allowed_symbols_or_markets_required';
        }

        if ($policy->maxNotional === null) {
            $errors[] = 'max_notional_required';
        } elseif ($policy->requestedNotional === null) {
            $errors[] = 'requested_notional_required';
        } elseif ($policy->requestedNotional > $policy->maxNotional) {
            $errors[] = 'max_notional_exceeded';
        }

        if ($policy->requestedSymbol === null && $policy->requestedMarket === null) {
            $errors[] = 'requested_symbol_or_market_required';
        } elseif (!$this->matchesAllowedSymbolOrMarket($policy)) {
            $errors[] = 'requested_symbol_or_market_not_allowed';
        }

        if ($policy->stopLossPresent === null) {
            $errors[] = 'stop_loss_presence_required';
        } elseif (!$policy->stopLossPresent) {
            $errors[] = 'stop_loss_missing';
        }

        return $errors;
    }

    private function matchesAllowedSymbolOrMarket(DemoTradingSafetyPolicy $policy): bool
    {
        if ($policy->requestedSymbol !== null && in_array($policy->requestedSymbol, $policy->allowedSymbols, true)) {
            return true;
        }

        return $policy->requestedMarket !== null && in_array($policy->requestedMarket, $policy->allowedMarkets, true);
    }
}
