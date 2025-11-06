<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class SignalConfig
{
    private readonly string $path;
    private array $config;

    public function __construct(?string $path = null)
    {
        // Chemin par défaut: config/app/signal.yaml
        $this->path = $path ?? \dirname(__DIR__, 2) . '/config/app/signal.yaml';
        $parsed = Yaml::parseFile($this->path) ?? [];
        $this->config = $parsed['signal'] ?? [];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getTimeframes(): array
    {
        return array_keys($this->config['timeframes'] ?? []);
    }

    public function getTimeframeConfig(string $timeframe): array
    {
        return $this->config['timeframes'][$timeframe] ?? [];
    }

    public function getMinBars(string $timeframe): int
    {
        $tfConfig = $this->getTimeframeConfig($timeframe);
        return (int)($tfConfig['guards']['min_bars'] ?? 50);
    }

    public function isTimeframeValid(string $timeframe): bool
    {
        return in_array($timeframe, $this->getTimeframes(), true);
    }

    public function getMtf(): array
    {
        return $this->config['mtf'] ?? [];
    }

    public function getConvictionHigh(): array
    {
        return $this->config['conviction_high'] ?? [];
    }

    public function getReversalProtection(): array
    {
        return $this->config['reversal_protection'] ?? [];
    }

    public function getScalpModeTrigger(): array
    {
        return $this->config['scalp_mode_trigger'] ?? [];
    }

    public function getRuntime(): array
    {
        return $this->config['runtime'] ?? [];
    }
}

