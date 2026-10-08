<?php

declare(strict_types=1);

namespace App\Exchange\Okx\Demo;

use App\Common\Enum\Exchange;
use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketObservabilityPolicy;
use App\Exchange\Okx\PrivateWebSocket\OkxPrivateWebSocketStatusStoreInterface;
use App\Exchange\Readiness\ExchangePrivateObservabilityStatus;
use App\TradingCore\Execution\Safety\DemoTradingKillSwitchDecision;
use App\TradingCore\Execution\Safety\DemoTradingKillSwitchService;
use App\TradingCore\Execution\Safety\DemoTradingMutationAttempt;
use App\TradingCore\Execution\Safety\ExchangeRuntimeEnvironment;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OkxDemoWriteGate
{
    public const MARKET = 'perpetual';
    public const NON_SIZING_NOTIONAL = 0.000001;

    public function __construct(
        private OkxConfig $config,
        private DemoTradingKillSwitchService $killSwitch,
        private OkxPrivateWebSocketObservabilityPolicy $observabilityPolicy,
        private ClockInterface $clock,
        #[Autowire('%app.okx_demo_max_notional%')] private float $maxNotional,
        private OkxDemoTripInterface $trip,
        private ?OkxPrivateWebSocketStatusStoreInterface $statusStore = null,
    ) {
    }

    public function isTripped(): bool
    {
        try {
            return $this->trip->isTripped();
        } catch (\Throwable) {
            return true;
        }
    }

    public function tripReason(): ?string
    {
        try {
            return $this->trip->isTripped() ? $this->trip->reason() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function trip(string $reason): void
    {
        $this->trip->trip($reason);
    }

    /**
     * @return array{OKX_ENV: string, OKX_SIMULATED_TRADING: bool, OKX_DEMO_TRADING_ENABLED: bool, DEMO_TRADING_ENABLED: bool, OKX_LIVE_ENABLED: bool}
     */
    public function flags(): array
    {
        return [
            'OKX_ENV' => strtolower(trim($this->config->environment)),
            'OKX_SIMULATED_TRADING' => $this->config->simulatedTrading,
            'OKX_DEMO_TRADING_ENABLED' => $this->config->demoTradingEnabled,
            'DEMO_TRADING_ENABLED' => $this->config->globalDemoTradingEnabled,
            'OKX_LIVE_ENABLED' => $this->config->liveEnabled,
        ];
    }

    /**
     * @return list<string>
     */
    public function environmentReasons(): array
    {
        $flags = $this->flags();
        $reasons = [];
        if ($flags['OKX_ENV'] !== 'demo') {
            $reasons[] = 'okx_env_not_demo';
        }
        if (!$flags['OKX_SIMULATED_TRADING']) {
            $reasons[] = 'okx_simulated_trading_disabled';
        }
        if (!$flags['OKX_DEMO_TRADING_ENABLED']) {
            $reasons[] = 'okx_demo_trading_disabled';
        }
        if (!$flags['DEMO_TRADING_ENABLED']) {
            $reasons[] = 'demo_trading_disabled';
        }
        if ($flags['OKX_LIVE_ENABLED']) {
            $reasons[] = 'okx_live_enabled';
        }
        if (!$this->restEndpointAllowed()) {
            $reasons[] = 'okx_private_rest_endpoint_not_allowed';
        }

        return $reasons;
    }

    public function restEndpointAllowed(): bool
    {
        try {
            $this->config->assertPrivateRestEndpointAllowed();
        } catch (\RuntimeException) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string,string> $correlationIds
     */
    public function evaluate(
        OkxDemoWriteKind $kind,
        string $action,
        ?string $symbol,
        ?float $notional,
        string $clientOrderId,
        array $correlationIds = [],
        bool $reduceOnly = true,
        ?bool $stopLossPresent = null,
    ): OkxDemoWriteDecision {
        if ($this->isTripped()) {
            return OkxDemoWriteDecision::refuse(['okx_demo_tripped'], $kind);
        }
        $reasons = $this->environmentReasons();
        if ($kind === OkxDemoWriteKind::PROTECTIVE && !$reduceOnly) {
            $reasons[] = 'protective_requires_reduce_only';
        }
        if ($kind === OkxDemoWriteKind::ENTRY && $stopLossPresent === false) {
            $reasons[] = 'stop_loss_required';
        }
        if ($reasons !== []) {
            return OkxDemoWriteDecision::refuse($reasons, $kind);
        }

        $decision = $this->killSwitchDecision($action, $symbol, $notional, $clientOrderId, $correlationIds, $kind === OkxDemoWriteKind::ENTRY ? ($stopLossPresent ?? true) : true);
        if ($decision->allowed) {
            return OkxDemoWriteDecision::allow($kind);
        }

        $reasons = $decision->reasons === [] ? ['kill_switch_blocked'] : $decision->reasons;
        if ($kind !== OkxDemoWriteKind::PROTECTIVE) {
            return OkxDemoWriteDecision::refuse($reasons, $kind);
        }

        $exempt = [...$this->observabilityReasons($decision), 'max_notional_exceeded'];
        $remaining = array_values(array_diff($reasons, $exempt));
        if ($remaining !== []) {
            return OkxDemoWriteDecision::refuse($remaining, $kind);
        }

        return OkxDemoWriteDecision::allow($kind, array_values(array_intersect($reasons, $exempt)));
    }

    /**
     * @return list<string>
     */
    private function observabilityReasons(DemoTradingKillSwitchDecision $decision): array
    {
        $observability = $decision->auditEvent['private_observability'] ?? null;
        $errors = \is_array($observability) ? ($observability['blocking_errors'] ?? null) : null;

        return \is_array($errors) ? array_values(array_filter($errors, \is_string(...))) : [];
    }

    /**
     * @param array<string,string> $correlationIds
     */
    public function killSwitchDecision(
        string $action,
        ?string $symbol,
        ?float $notional,
        string $clientOrderId,
        array $correlationIds = [],
        bool $stopLossPresent = true,
    ): DemoTradingKillSwitchDecision {
        return $this->killSwitch->evaluate(new DemoTradingMutationAttempt(
            exchange: Exchange::OKX,
            environment: ExchangeRuntimeEnvironment::DEMO,
            mode: 'okx_exchange_adapter',
            profile: 'okx_demo',
            market: self::MARKET,
            symbol: $symbol,
            notional: $notional,
            clientOrderId: $clientOrderId,
            action: $action,
            mainnetWriteEnabled: $this->config->liveEnabled,
            demoTestnetWriteEnabled: true,
            effectiveKillSwitchEnabled: false,
            requireStopLoss: true,
            stopLossPresent: $stopLossPresent,
            allowedMarkets: [self::MARKET],
            maxNotional: $this->maxNotional,
            correlationIds: $correlationIds,
            privateObservabilityStatus: $this->privateObservability(),
        ));
    }

    private function privateObservability(): ExchangePrivateObservabilityStatus
    {
        try {
            return $this->observabilityPolicy->evaluate($this->statusStore?->load(), $this->clock->now());
        } catch (\Throwable) {
            return ExchangePrivateObservabilityStatus::absent(Exchange::OKX, 'demo');
        }
    }
}
