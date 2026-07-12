<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use App\Exchange\Hyperliquid\HyperliquidPollingObservabilityPolicy;
use App\TradingCore\Execution\Hyperliquid\HyperliquidKillSwitchAuditSanitizer;

final readonly class DemoTradingKillSwitchService
{
    public function __construct(
        private DemoTradingSafetyPolicyEvaluator $evaluator,
        private ExchangePrivateObservabilityPolicy $privateObservabilityPolicy,
        private DemoTradingAuditSinkInterface $auditSink,
        private bool $globalDemoTradingEnabled = false,
        private bool $okxDemoTradingEnabled = false,
        private bool $hyperliquidTestnetTradingEnabled = false,
        private ?HyperliquidPollingObservabilityPolicy $hyperliquidPollingPolicy = null,
        private ?HyperliquidKillSwitchAuditSanitizer $auditSanitizer = null,
    ) {
    }

    public function evaluate(DemoTradingMutationAttempt $attempt): DemoTradingKillSwitchDecision
    {
        $switchReasons = $this->switchReasons($attempt);
        $policy = new DemoTradingSafetyPolicy(
            environment: $attempt->environment,
            dryRun: false,
            mainnetWriteEnabled: $attempt->mainnetWriteEnabled,
            demoTestnetWriteEnabled: $attempt->demoTestnetWriteEnabled,
            killSwitchEnabled: $attempt->effectiveKillSwitchEnabled || $switchReasons !== [],
            requireStopLoss: $attempt->requireStopLoss,
            allowedSymbols: $attempt->allowedSymbols,
            allowedMarkets: $attempt->allowedMarkets,
            maxNotional: $attempt->maxNotional,
            requestedSymbol: $attempt->symbol,
            requestedMarket: $attempt->market,
            requestedNotional: $attempt->notional,
            stopLossPresent: $attempt->stopLossPresent,
            auditContext: $attempt->auditContext,
        );

        $safetyDecision = $this->evaluator->evaluate($policy);
        $isHyperliquidTestnet = $attempt->exchange === Exchange::HYPERLIQUID
            && $attempt->environment === ExchangeRuntimeEnvironment::TESTNET;
        if ($isHyperliquidTestnet) {
            $pollingReasons = $attempt->hyperliquidPollingObservabilityStatus === null
                ? ['hyperliquid_polling_status_missing']
                : ($this->hyperliquidPollingPolicy?->blockingReasons($attempt->hyperliquidPollingObservabilityStatus)
                    ?? ['hyperliquid_polling_policy_unavailable']);
            $privateObservability = [
                'mechanism' => 'hyperliquid_polling',
                'allowed' => $pollingReasons === [],
                'blocking_errors' => $pollingReasons,
                'warnings' => [],
                'status' => $attempt->hyperliquidPollingObservabilityStatus?->toArray(),
            ];
            $privateObservabilityMissing = $attempt->hyperliquidPollingObservabilityStatus === null;
        } else {
            $privateObservabilityDecision = $this->privateObservabilityPolicy->evaluate(
                $attempt->privateObservabilityStatus ?? ExchangePrivateObservabilityStatus::absent($attempt->exchange, $attempt->environment->value),
                dryRun: false,
                expectedExchange: $attempt->exchange,
                expectedEnvironment: $attempt->environment->value,
            );
            $pollingReasons = $privateObservabilityDecision->blockingErrors;
            $privateObservability = $privateObservabilityDecision->toArray();
            $privateObservabilityMissing = $attempt->privateObservabilityStatus === null;
        }
        $reasons = $this->mergeReasons(
            $this->mergeReasons($switchReasons, $safetyDecision->blockingErrors),
            $pollingReasons,
        );
        $allowed = $safetyDecision->allowed && $reasons === [];
        $auditEvent = $this->auditEvent(
            $attempt,
            $safetyDecision,
            $privateObservability,
            $privateObservabilityMissing,
            $allowed,
            $reasons,
        );

        try {
            $this->auditSink->recordDemoTradingAttempt($auditEvent);
        } catch (\Throwable) {
            $reasons = $this->mergeReasons($reasons, ['audit_failed']);
            $auditEvent = array_merge($auditEvent, [
                'allowed' => false,
                'outcome' => 'blocked',
                'reasons' => $reasons,
            ]);

            return new DemoTradingKillSwitchDecision(
                allowed: false,
                reasons: $reasons,
                safetyDecision: $safetyDecision,
                auditEvent: $auditEvent,
            );
        }

        return new DemoTradingKillSwitchDecision(
            allowed: $allowed,
            reasons: $reasons,
            safetyDecision: $safetyDecision,
            auditEvent: $auditEvent,
        );
    }

    /**
     * @return list<string>
     */
    private function switchReasons(DemoTradingMutationAttempt $attempt): array
    {
        $reasons = [];

        if (!$this->globalDemoTradingEnabled && $attempt->environment->isDemoOrTestnet()) {
            $reasons[] = 'demo_trading_disabled';
        }

        if ($attempt->environment->isDemoOrTestnet() && !$this->isSupportedDemoTestnetPair($attempt)) {
            $reasons[] = 'exchange_environment_pair_unsupported';
        }

        if ($attempt->exchange === Exchange::OKX
            && $attempt->environment === ExchangeRuntimeEnvironment::DEMO
            && !$this->okxDemoTradingEnabled
        ) {
            $reasons[] = 'okx_demo_trading_disabled';
        }

        if ($attempt->exchange === Exchange::HYPERLIQUID
            && $attempt->environment === ExchangeRuntimeEnvironment::TESTNET
            && !$this->hyperliquidTestnetTradingEnabled
        ) {
            $reasons[] = 'hyperliquid_testnet_trading_disabled';
        }

        if ($attempt->effectiveKillSwitchEnabled) {
            $reasons[] = 'effective_kill_switch_enabled';
        }

        if ($attempt->clientOrderId === null) {
            $reasons[] = 'client_order_id_required';
        }

        return $reasons;
    }

    private function isSupportedDemoTestnetPair(DemoTradingMutationAttempt $attempt): bool
    {
        return ($attempt->exchange === Exchange::OKX && $attempt->environment === ExchangeRuntimeEnvironment::DEMO)
            || ($attempt->exchange === Exchange::HYPERLIQUID && $attempt->environment === ExchangeRuntimeEnvironment::TESTNET);
    }

    /**
     * @param list<string> $first
     * @param list<string> $second
     * @return list<string>
     */
    private function mergeReasons(array $first, array $second): array
    {
        return array_values(array_unique(array_merge($first, $second)));
    }

    /**
     * @param list<string> $reasons
     * @param array<string,mixed> $privateObservabilityDecision
     * @return array<string,mixed>
     */
    private function auditEvent(
        DemoTradingMutationAttempt $attempt,
        DemoTradingSafetyDecision $safetyDecision,
        array $privateObservabilityDecision,
        bool $privateObservabilityMissing,
        bool $allowed,
        array $reasons,
    ): array {
        return [
            'exchange' => $attempt->exchange->value,
            'environment' => $attempt->environment->value,
            'mode' => $attempt->mode,
            'profile' => $attempt->profile,
            'symbol' => $attempt->symbol,
            'market' => $attempt->market,
            'notional' => $attempt->notional,
            'client_order_id' => $attempt->clientOrderId,
            'action' => $attempt->action,
            'allowed' => $allowed,
            'outcome' => $allowed ? 'allowed' : 'blocked',
            'reasons' => $reasons,
            'correlation_ids' => $this->sanitizeAuditPayload($attempt->correlationIds),
            'safety' => [
                'level' => $safetyDecision->level->value,
                'allowed' => $safetyDecision->allowed,
                'blocking_errors' => $safetyDecision->blockingErrors,
                'warnings' => $safetyDecision->warnings,
            ],
            'private_observability' => $privateObservabilityMissing
                ? ['status_available' => false] + $privateObservabilityDecision
                : $privateObservabilityDecision,
            'audit_context' => $this->sanitizeAuditPayload($attempt->auditContext),
        ];
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function sanitizeAuditPayload(array $value): array
    {
        return ($this->auditSanitizer ?? new HyperliquidKillSwitchAuditSanitizer())->sanitizeAuditPayload($value);
    }
}
