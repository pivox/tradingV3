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

            $allowedSymbols = $this->stringList($execution['allowed_symbols'] ?? null);
            $allowedMarkets = $this->stringList($execution['allowed_markets'] ?? null);
            $maxNotional = $execution['max_notional'] ?? null;
            $killSwitch = $execution['kill_switch_enabled'] ?? null;
            if (($allowedSymbols === [] && $allowedMarkets === [])
                || !is_numeric($maxNotional)
                || !is_bool($killSwitch)) {
                return HyperliquidMutationReadinessConfig::failClosed();
            }

            $maxNotional = (float) $maxNotional;
            if (!is_finite($maxNotional) || $maxNotional <= 0.0) {
                return HyperliquidMutationReadinessConfig::failClosed();
            }

            return new HyperliquidMutationReadinessConfig(
                $allowedSymbols,
                $allowedMarkets,
                $maxNotional,
                $killSwitch,
                $resolved['config_hash'],
            );
        } catch (\Throwable) {
            return HyperliquidMutationReadinessConfig::failClosed();
        }
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
