<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TradingConfigService
{
    private array $config;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $configPath = $parameterBag->get('kernel.project_dir') . '/config/trading.yml';
        $this->config = Yaml::parseFile($configPath);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getTimeframes(): array
    {
        return array_keys($this->config['timeframes'] ?? []);
    }

    public function getValidationRules(string $timeframe): array
    {
        return $this->config['validation']['timeframe'][$timeframe] ?? [];
    }

    public function getMinBars(string $timeframe): int
    {
        return $this->config['timeframes'][$timeframe]['guards']['min_bars'] ?? 50;
    }

    public function getIndicatorsConfig(): array
    {
        return $this->config['indicators'] ?? [];
    }

    public function getRiskConfig(): array
    {
        return $this->config['risk'] ?? [];
    }

    public function getLeverageConfig(): array
    {
        return $this->config['leverage'] ?? [];
    }

    public function getAtrConfig(): array
    {
        return $this->config['atr'] ?? [];
    }

    public function getConvictionHighConfig(): array
    {
        return $this->config['conviction_high'] ?? [];
    }

    public function getReversalProtectionConfig(): array
    {
        return $this->config['reversal_protection'] ?? [];
    }

    public function getScalpModeConfig(): array
    {
        return $this->config['scalp_mode_trigger'] ?? [];
    }

    public function isTimeframeValid(string $timeframe): bool
    {
        return in_array($timeframe, $this->getTimeframes());
    }

    public function getIndicatorCalculationConfig(): array
    {
        return $this->config['indicator_calculation'] ?? [];
    }

    public function getIndicatorCalculationMode(): string
    {
        $config = $this->getIndicatorCalculationConfig();
        return $config['mode'] ?? 'sql';
    }

    public function isIndicatorCalculationFallbackEnabled(): bool
    {
        $config = $this->getIndicatorCalculationConfig();
        return $config['fallback_to_php'] ?? true;
    }

    public function getIndicatorCalculationPerformanceThreshold(): int
    {
        $config = $this->getIndicatorCalculationConfig();
        return $config['performance_threshold_ms'] ?? 100;
    }

    public function getTimeframeValidationRules(string $timeframe): array
    {
        if (!$this->isTimeframeValid($timeframe)) {
            return [];
        }

        $rules = $this->getValidationRules($timeframe);
        return [
            'long' => $rules['long'] ?? [],
            'short' => $rules['short'] ?? [],
            'min_bars' => $this->getMinBars($timeframe)
        ];
    }

    public function getMetaInfo(): array
    {
        return $this->config['meta'] ?? [];
    }

    public function getVersion(): string
    {
        return $this->config['version'] ?? '1.0';
    }
}

