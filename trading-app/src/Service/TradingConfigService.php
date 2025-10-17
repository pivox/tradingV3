<?php

namespace App\Service;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TradingConfigService
{
    private array $config;
    private ?string $cachedVersion = null;
    private readonly string $configPath;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $this->configPath = $parameterBag->get('kernel.project_dir') . '/config/trading.yml';
        $this->config = Yaml::parseFile($this->configPath);
        $this->cachedVersion = $this->config['version'] ?? null;
    }

    public function getConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config;
    }

    public function getTimeframes(): array
    {
        $this->checkVersionAndRefresh();
        return array_keys($this->config['timeframes'] ?? []);
    }

    public function getValidationRules(string $timeframe): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['validation']['timeframe'][$timeframe] ?? [];
    }

    public function getMinBars(string $timeframe): int
    {
        $this->checkVersionAndRefresh();
        return $this->config['timeframes'][$timeframe]['guards']['min_bars'] ?? 50;
    }

    public function getIndicatorsConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['indicators'] ?? [];
    }

    public function getRiskConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['risk'] ?? [];
    }

    public function getLeverageConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['leverage'] ?? [];
    }

    public function getAtrConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['atr'] ?? [];
    }

    public function getConvictionHighConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['conviction_high'] ?? [];
    }

    public function getReversalProtectionConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['reversal_protection'] ?? [];
    }

    public function getScalpModeConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['scalp_mode_trigger'] ?? [];
    }

    public function isTimeframeValid(string $timeframe): bool
    {
        return in_array($timeframe, $this->getTimeframes());
    }

    public function getIndicatorCalculationConfig(): array
    {
        $this->checkVersionAndRefresh();
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
        $this->checkVersionAndRefresh();
        return $this->config['meta'] ?? [];
    }

    public function getVersion(): string
    {
        $this->checkVersionAndRefresh();
        return $this->config['version'] ?? '1.0';
    }

    /**
     * Vérifie si la version a changé et rafraîchit le cache si nécessaire
     */
    private function checkVersionAndRefresh(): void
    {
        // Vérifier si le fichier a été modifié
        $currentConfig = Yaml::parseFile($this->configPath);
        $currentVersion = $currentConfig['version'] ?? null;

        // Si la version a changé, rafraîchir le cache
        if ($currentVersion !== $this->cachedVersion) {
            $this->config = $currentConfig;
            $this->cachedVersion = $currentVersion;
        }
    }
}

