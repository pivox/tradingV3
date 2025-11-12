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
