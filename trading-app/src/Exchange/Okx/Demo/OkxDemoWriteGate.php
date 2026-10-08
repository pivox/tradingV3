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
        private ?OkxPrivateWebSocketStatusStoreInterface $statusStore = null,
    ) {
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
        string $action,
        ?string $symbol,
        ?float $notional,
        string $clientOrderId,
        array $correlationIds = [],
    ): OkxDemoWriteDecision {
        $reasons = $this->environmentReasons();
        if ($reasons !== []) {
            return OkxDemoWriteDecision::refuse($reasons);
        }

        $decision = $this->killSwitchDecision($action, $symbol, $notional, $clientOrderId, $correlationIds);

        return $decision->allowed
            ? OkxDemoWriteDecision::allow()
            : OkxDemoWriteDecision::refuse($decision->reasons === [] ? ['kill_switch_blocked'] : $decision->reasons);
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
            stopLossPresent: true,
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
