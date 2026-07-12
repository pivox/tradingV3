<?php

declare(strict_types=1);

namespace App\Provider\Hyperliquid;

use App\TradingCore\Config\EffectiveTradingConfigReadService;

final readonly class EffectiveTradingHyperliquidMutationReadinessConfigSource implements HyperliquidMutationReadinessConfigSourceInterface
{
    public function __construct(
        private EffectiveTradingConfigReadService $configs,
        private string $profile,
    ) {
    }

    public function current(): HyperliquidMutationReadinessConfig
    {
        if (trim($this->profile) === '') {
            return HyperliquidMutationReadinessConfig::failClosed();
        }

        try {
            $resolved = $this->configs->describe($this->profile, 'hyperliquid', 'testnet');
            $trading = $resolved['config']['trading'] ?? null;
            $execution = is_array($trading) ? ($trading['execution'] ?? null) : null;
            if (!is_array($execution) || array_is_list($execution)) {
                return HyperliquidMutationReadinessConfig::failClosed();
            }

            $profile = is_array($trading) ? ($trading['profile'] ?? null) : null;
            if (!is_string($profile) || $profile !== $this->profile || $resolved['request']['mode'] !== $this->profile) {
                return HyperliquidMutationReadinessConfig::failClosed();
            }

            $allowedSymbols = $this->stringList($execution['allowed_symbols'] ?? null);
            $allowedMarkets = $this->stringList($execution['allowed_markets'] ?? null);
            $maxNotional = $execution['max_notional'] ?? null;
            $authorization = $this->authorization($execution);
            if (($allowedSymbols === [] && $allowedMarkets === [])
                || !is_numeric($maxNotional)
                || $authorization === null) {
                return HyperliquidMutationReadinessConfig::failClosed();
            }

            $maxNotional = (float) $maxNotional;
            if (!is_finite($maxNotional) || $maxNotional <= 0.0) {
                return HyperliquidMutationReadinessConfig::failClosed();
            }

            return new HyperliquidMutationReadinessConfig(
                profile: $profile,
                allowedSymbols: $allowedSymbols,
                allowedMarkets: $allowedMarkets,
                maxNotional: $maxNotional,
                dryRun: $authorization['dryRun'],
                liveEnabled: $authorization['liveEnabled'],
                runtimeCheckRequired: $authorization['runtimeCheckRequired'],
                mainnetWriteEnabled: $authorization['mainnetWriteEnabled'],
                demoTestnetWriteEnabled: $authorization['demoTestnetWriteEnabled'],
                killSwitchEnabled: $authorization['killSwitchEnabled'],
                requireStopLoss: $authorization['requireStopLoss'],
                configHash: $resolved['config_hash'],
            );
        } catch (\Throwable) {
            return HyperliquidMutationReadinessConfig::failClosed();
        }
    }

    /**
     * @param array<string,mixed> $execution
     * @return null|array{
     *   dryRun: bool,
     *   liveEnabled: bool,
     *   runtimeCheckRequired: bool,
     *   mainnetWriteEnabled: bool,
     *   demoTestnetWriteEnabled: bool,
     *   killSwitchEnabled: bool,
     *   requireStopLoss: bool
     * }
     */
    private function authorization(array $execution): ?array
    {
        $fields = [
            'dryRun' => 'dry_run',
            'liveEnabled' => 'live_enabled',
            'runtimeCheckRequired' => 'runtime_check_required',
            'mainnetWriteEnabled' => 'mainnet_write_enabled',
            'demoTestnetWriteEnabled' => 'demo_testnet_write_enabled',
            'killSwitchEnabled' => 'kill_switch_enabled',
            'requireStopLoss' => 'require_stop_loss',
        ];
        $authorization = [];
        foreach ($fields as $property => $key) {
            if (!array_key_exists($key, $execution) || !is_bool($execution[$key])) {
                return null;
            }
            $authorization[$property] = $execution[$key];
        }

        /** @var array{dryRun: bool, liveEnabled: bool, runtimeCheckRequired: bool, mainnetWriteEnabled: bool, demoTestnetWriteEnabled: bool, killSwitchEnabled: bool, requireStopLoss: bool} $authorization */
        return $authorization;
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                return [];
            }
            $normalized[] = trim($item);
        }

        return array_values(array_unique($normalized));
    }
}
