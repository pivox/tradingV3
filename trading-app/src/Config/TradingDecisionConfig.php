<?php
declare(strict_types=1);

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class TradingDecisionConfig
{
    private readonly string $path;
    private array $config;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? \dirname(__DIR__, 2) . '/config/app/trading_decision.yaml';
        $parsed = is_file($this->path) ? Yaml::parseFile($this->path) : [];
        $this->config = $parsed['mtf_decision'] ?? [];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}

