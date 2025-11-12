<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class TradeEntryConfig
{
    private readonly string $path;
    private array $config;

    public function __construct(?string $path = null)
    {
        // Chemin par défaut: config/app/trade_entry.yaml
        $this->path = $path ?? \dirname(__DIR__, 2) . '/config/app/trade_entry.yaml';
        if (!is_file($this->path)) {
            throw new \RuntimeException(sprintf('Configuration file not found: %s', $this->path));
        }
        $parsed = Yaml::parseFile($this->path) ?? [];
        $this->config = $parsed['trade_entry'] ?? [];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getDefaults(): array
    {
        return $this->config['defaults'] ?? [];
    }

    public function getDefault(string $key, mixed $default = null): mixed
    {
        $defaults = $this->getDefaults();
        return $defaults[$key] ?? $default;
    }

    public function getEntry(): array
    {
        return $this->config['entry'] ?? [];
    }

    public function getPostValidation(): array
    {
        return $this->config['post_validation'] ?? [];
    }

    public function getRisk(): array
    {
        return $this->config['risk'] ?? [];
    }

    public function getLeverage(): array
    {
        return $this->config['leverage'] ?? [];
    }

    public function getDecision(): array
    {
        return $this->config['decision'] ?? [];
    }

    public function getMarketEntry(): array
    {
        return $this->config['market_entry'] ?? [];
    }

    public function getFallbackEndOfZoneConfig(): \App\TradeEntry\Dto\FallbackEndOfZoneConfig
    {
        $block = (array)($this->config['fallback_end_of_zone'] ?? []);

        $enabled = isset($block['enabled']) ? (bool)$block['enabled'] : true;
        $ttl = isset($block['ttl_threshold_sec']) && is_numeric($block['ttl_threshold_sec'])
            ? (int)$block['ttl_threshold_sec'] : 25;
        $maxSpreadBps = isset($block['max_spread_bps']) && is_numeric($block['max_spread_bps'])
            ? (float)$block['max_spread_bps'] : 8.0;
        $onlyIfWithinZone = isset($block['only_if_within_zone']) ? (bool)$block['only_if_within_zone'] : true;
        $takerOrderType = \is_string($block['taker_order_type'] ?? null) && $block['taker_order_type'] !== ''
            ? (string)$block['taker_order_type'] : 'market';
        $maxSlippageBps = isset($block['max_slippage_bps']) && is_numeric($block['max_slippage_bps'])
            ? (float)$block['max_slippage_bps'] : 10.0;

        return new \App\TradeEntry\Dto\FallbackEndOfZoneConfig(
            enabled: $enabled,
            ttlThresholdSec: $ttl,
            maxSpreadBps: $maxSpreadBps,
            onlyIfWithinZone: $onlyIfWithinZone,
            takerOrderType: $takerOrderType,
            maxSlippageBps: $maxSlippageBps,
        );
    }

    public function getVersion(): string
    {
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($this->path) ?? [];
        return (string)($parsed['version'] ?? '1.0');
    }

    public function getMeta(): array
    {
        $parsed = \Symfony\Component\Yaml\Yaml::parseFile($this->path) ?? [];
        return $parsed['meta'] ?? [];
    }
}
