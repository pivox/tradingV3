<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class IndicatorConfig
{
    private readonly string $path;
    private array $config;

    public function __construct(?string $path = null)
    {
        // Chemin par défaut: config/app/indicator.yaml
        $this->path = $path ?? \dirname(__DIR__, 2) . '/config/app/indicator.yaml';
        if (!is_file($this->path)) {
            throw new \RuntimeException(sprintf('Configuration file not found: %s', $this->path));
        }
        $parsed = Yaml::parseFile($this->path) ?? [];
        $this->config = $parsed['indicator'] ?? [];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getIndicators(): array
    {
        return $this->config['indicators'] ?? [];
    }

    public function getAtr(): array
    {
        return $this->config['atr'] ?? [];
    }

    public function getAtrPeriod(): int
    {
        $atr = $this->getAtr();
        return (int)($atr['period'] ?? 14);
    }

    public function getAtrMethod(): string
    {
        $atr = $this->getAtr();
        return (string)($atr['method'] ?? 'wilder');
    }

    public function getAtrTimeframe(): string
    {
        $atr = $this->getAtr();
        return (string)($atr['timeframe'] ?? '5m');
    }

    public function getAtrSlMultiplier(): float
    {
        $atr = $this->getAtr();
        return (float)($atr['sl_multiplier'] ?? 1.5);
    }

    public function getAtrRMultiple(): float
    {
        $atr = $this->getAtr();
        return (float)($atr['r_multiple'] ?? 2.0);
    }

    public function getAtrPctThresholds(): array
    {
        $atr = $this->getAtr();
        return (array)($atr['pct_thresholds'] ?? []);
    }

    public function getCalculation(): array
    {
        return $this->config['calculation'] ?? [];
    }

    public function getCalculationMode(): string
    {
        $calc = $this->getCalculation();
        return (string)($calc['mode'] ?? 'php');
    }

    public function isCalculationFallbackEnabled(): bool
    {
        $calc = $this->getCalculation();
        return (bool)($calc['fallback_to_php'] ?? true);
    }

    public function getCalculationPerformanceThreshold(): int
    {
        $calc = $this->getCalculation();
        return (int)($calc['performance_threshold_ms'] ?? 10);
    }

    public function getRules(): array
    {
        return $this->config['rules'] ?? [];
    }

    public function getValidation(): array
    {
        return $this->config['validation'] ?? [];
    }
}

