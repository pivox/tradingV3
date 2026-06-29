<?php
declare(strict_types=1);

namespace App\TradingCore\Execution\Safety;

use App\Common\Enum\Exchange;
use App\Exchange\Readiness\ExchangePrivateObservabilityPolicy;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;

final readonly class DemoTradingKillSwitchService
{
    public function __construct(
        private DemoTradingSafetyPolicyEvaluator $evaluator,
        private ExchangePrivateObservabilityPolicy $privateObservabilityPolicy,
        private DemoTradingAuditSinkInterface $auditSink,
        private bool $globalDemoTradingEnabled = false,
        private bool $okxDemoTradingEnabled = false,
        private bool $hyperliquidTestnetTradingEnabled = false,
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
        $privateObservabilityDecision = $this->privateObservabilityPolicy->evaluate(
            $attempt->privateObservabilityStatus ?? ExchangePrivateObservabilityStatus::absent($attempt->exchange, $attempt->environment->value),
            dryRun: false,
            expectedExchange: $attempt->exchange,
            expectedEnvironment: $attempt->environment->value,
        );
        $reasons = $this->mergeReasons(
            $this->mergeReasons($switchReasons, $safetyDecision->blockingErrors),
            $privateObservabilityDecision->blockingErrors,
        );
        $allowed = $safetyDecision->allowed && $reasons === [];
        $auditEvent = $this->auditEvent(
            $attempt,
            $safetyDecision,
            $privateObservabilityDecision->toArray(),
            $attempt->privateObservabilityStatus === null,
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
            'correlation_ids' => self::redact($attempt->correlationIds),
            'safety' => [
                'level' => $safetyDecision->level->value,
                'allowed' => $safetyDecision->allowed,
                'blocking_errors' => $safetyDecision->blockingErrors,
                'warnings' => $safetyDecision->warnings,
            ],
            'private_observability' => $privateObservabilityMissing
                ? ['status_available' => false] + $privateObservabilityDecision
                : $privateObservabilityDecision,
            'audit_context' => self::redact($attempt->auditContext),
        ];
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && self::isSensitiveKey($key)) {
            return '[redacted]';
        }

        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = self::redact(
                $childValue,
                is_string($childKey) ? $childKey : null,
            );
        }

        return $redacted;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($key)), '_');
        $compacted = str_replace('_', '', $normalized);

        foreach (['secret', 'token', 'api_key', 'private_key', 'passphrase', 'password', 'signature', 'authorization', 'cookie', 'memo', 'credential'] as $needle) {
            if (str_contains($normalized, $needle) || str_contains($compacted, str_replace('_', '', $needle))) {
                return true;
            }
        }

        return $normalized === 'key'
            || $normalized === 'sign'
            || str_ends_with($normalized, '_key')
            || str_ends_with($normalized, '_sign')
            || str_ends_with($compacted, 'key');
    }
}
