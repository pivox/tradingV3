<?php

namespace App\Service;

use App\Config\TradeEntryConfig;
use Symfony\Component\Yaml\Yaml;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class TradingConfigService
{
    private array $config;
    private ?string $cachedVersion = null;
    private readonly string $configPath;

    public function __construct(
        ParameterBagInterface $parameterBag,
        private readonly ?TradeEntryConfig $tradeEntryConfig = null
    ) {
        // Charger depuis trade_entry.yaml (migration depuis trading.yml)
        $tradeEntryPath = $parameterBag->get('kernel.project_dir') . '/config/app/trade_entry.yaml';
        $this->configPath = $tradeEntryPath; // Ancien chemin trading.yml conservé pour compatibilité
        
        if ($this->tradeEntryConfig !== null && is_file($tradeEntryPath)) {
            $this->config = Yaml::parseFile($tradeEntryPath) ?? [];
            $this->cachedVersion = $this->config['version'] ?? null;
        } elseif (is_file($this->configPath)) {
            $this->config = Yaml::parseFile($this->configPath);
            $this->cachedVersion = $this->config['version'] ?? null;
        } else {
            $this->config = [];
            $this->cachedVersion = null;
        }
    }

    public function getConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config;
    }

    /**
     * @deprecated Utiliser SignalConfig::getTimeframes() à la place
     */
    public function getTimeframes(): array
    {
        $this->checkVersionAndRefresh();
        return array_keys($this->config['timeframes'] ?? []);
    }

    /**
     * @deprecated Cette méthode n'est plus utilisée
     */
    public function getValidationRules(string $timeframe): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['validation']['timeframe'][$timeframe] ?? [];
    }

    /**
     * @deprecated Utiliser SignalConfig::getMinBars() à la place
     */
    public function getMinBars(string $timeframe): int
    {
        $this->checkVersionAndRefresh();
        return $this->config['timeframes'][$timeframe]['guards']['min_bars'] ?? 50;
    }

    /**
     * @deprecated Utiliser IndicatorConfig::getIndicators() à la place
     */
    public function getIndicatorsConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['indicators'] ?? [];
    }

    /**
     * @deprecated Utiliser TradeEntryConfig::getRisk() à la place
     */
    public function getRiskConfig(): array
    {
        if ($this->tradeEntryConfig !== null) {
            return $this->tradeEntryConfig->getRisk();
        }
        $this->checkVersionAndRefresh();
        return $this->config['risk'] ?? [];
    }

    /**
     * @deprecated Utiliser TradeEntryConfig::getLeverage() à la place
     */
    public function getLeverageConfig(): array
    {
        if ($this->tradeEntryConfig !== null) {
            return $this->tradeEntryConfig->getLeverage();
        }
        $this->checkVersionAndRefresh();
        return $this->config['leverage'] ?? [];
    }

    /**
     * @deprecated Utiliser IndicatorConfig::getAtr() à la place
     */
    public function getAtrConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['atr'] ?? [];
    }

    /**
     * @deprecated Utiliser SignalConfig::getConvictionHigh() à la place
     */
    public function getConvictionHighConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['conviction_high'] ?? [];
    }

    /**
     * @deprecated Utiliser SignalConfig::getReversalProtection() à la place
     */
    public function getReversalProtectionConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['reversal_protection'] ?? [];
    }

    /**
     * @deprecated Utiliser SignalConfig::getScalpModeTrigger() à la place
     */
    public function getScalpModeConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['scalp_mode_trigger'] ?? [];
    }

    /**
     * @deprecated Utiliser SignalConfig::isTimeframeValid() à la place
     */
    public function isTimeframeValid(string $timeframe): bool
    {
        return in_array($timeframe, $this->getTimeframes());
    }

    /**
     * @deprecated Utiliser IndicatorConfig::getCalculation() à la place
     */
    public function getIndicatorCalculationConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['indicator_calculation'] ?? [];
    }

    /**
     * @deprecated Utiliser IndicatorConfig::getCalculationMode() à la place
     */
    public function getIndicatorCalculationMode(): string
    {
        $config = $this->getIndicatorCalculationConfig();
        return $config['mode'] ?? 'sql';
    }

    /**
     * @deprecated Utiliser IndicatorConfig::isCalculationFallbackEnabled() à la place
     */
    public function isIndicatorCalculationFallbackEnabled(): bool
    {
        $config = $this->getIndicatorCalculationConfig();
        return $config['fallback_to_php'] ?? true;
    }

    /**
     * @deprecated Utiliser IndicatorConfig::getCalculationPerformanceThreshold() à la place
     */
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
        if ($this->tradeEntryConfig !== null) {
            return $this->tradeEntryConfig->getMeta();
        }
        $this->checkVersionAndRefresh();
        return $this->config['meta'] ?? [];
    }

    public function getVersion(): string
    {
        if ($this->tradeEntryConfig !== null) {
            return $this->tradeEntryConfig->getVersion();
        }
        $this->checkVersionAndRefresh();
        return $this->config['version'] ?? '1.0';
    }

    /**
     * Vérifie si la version a changé et rafraîchit le cache si nécessaire
     */
    private function checkVersionAndRefresh(): void
    {
        // Déterminer le fichier à charger
        $fileToCheck = $this->configPath;
        if ($this->tradeEntryConfig !== null) {
            $tradeEntryPath = \dirname(__DIR__, 2) . '/config/app/trade_entry.yaml';
            if (is_file($tradeEntryPath)) {
                $fileToCheck = $tradeEntryPath;
            }
        }

        // Vérifier si le fichier a été modifié
        if (!is_file($fileToCheck)) {
            return;
        }
        
        $currentConfig = Yaml::parseFile($fileToCheck) ?? [];
        $currentVersion = $currentConfig['version'] ?? null;

        // Si la version a changé, rafraîchir le cache
        if ($currentVersion !== $this->cachedVersion) {
            $this->config = $currentConfig;
            $this->cachedVersion = $currentVersion;
        }
    }
}

