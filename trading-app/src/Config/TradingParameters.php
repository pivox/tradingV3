<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class TradingParameters implements MtfConfigProviderInterface
{
    private ?array $cache = null;
    private ?string $cachedVersion = null;

    public function __construct(
        private readonly string $configFile,
    ){
        $this->cache = $this->parseYamlFile($this->configFile);
        $this->cachedVersion = $this->cache['version'] ?? null;
    }

    /**
     * Retourne un bloc de configuration depuis le fichier "trading" (même YAML par défaut).
     * @return array<string,mixed>
     */
    public function getTradingConf($key): array
    {
        $this->checkVersionAndRefresh();
        $val = $this->cache[$key] ?? [];
        return \is_array($val) ? $val : [];
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    /** Retourne la configuration complète (dont validation.context & validation.execution). */
    public function getConfig(): array
    {
        return $this->all();
    }

    /**
     * Charge la configuration principale (scalping/mtf) depuis config/trading.yml.
     * @return array<string,mixed>
     */
    public function all(): array
    {
        $this->checkVersionAndRefresh();
        return $this->cache;
    }

    public function riskPct(): float
    {
        $cfg = $this->all();
        return (float) (($cfg['risk']['fixed_risk_pct'] ?? 5.0) / 100.0);
    }

    public function atrPeriod(): int
    {
        $cfg = $this->all();
        return (int) ($cfg['atr']['period'] ?? 14);
    }

    public function slMult(): float
    {
        $cfg = $this->all();
        return (float) ($cfg['atr']['sl_multiplier'] ?? 1.5);
    }

    public function tp1R(): float
    {
        $cfg = $this->all();
        // clé optionnelle; fallback par défaut
        return (float) ($cfg['long']['take_profit']['tp1_r'] ?? 2.0);
    }

    public function getFetchLimitForTimeframe(string $tf): int
    {
        $cfg = $this->all();
        return (int)($cfg['timeframes'][$timeframe->value]['guards']['min_bars'] ?? 270);
    }

    /**
     * @return array<string,float>
     */
    public function getTimeframeMultipliers(): array
    {
        $cfg = $this->all();
        $multipliers = $cfg['leverage']['timeframe_multipliers'] ?? [];
        return \is_array($multipliers) ? $multipliers : [];
    }

    /**
     * Vérifie si la version a changé et rafraîchit le cache si nécessaire
     */
    private function checkVersionAndRefresh(): void
    {
        if ($this->cache === null) {
            $this->cache = $this->parseYamlFile($this->configFile);
            $this->cachedVersion = $this->cache['version'] ?? null;
            return;
        }

        // Vérifier si le fichier a été modifié
        $currentConfig = $this->parseYamlFile($this->configFile);
        $currentVersion = $currentConfig['version'] ?? null;

        // Si la version a changé, rafraîchir le cache
        if ($currentVersion !== $this->cachedVersion) {
            $this->cache = $currentConfig;
            $this->cachedVersion = $currentVersion;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function parseYamlFile(string $path): array
    {
        if (!\is_file($path)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (\Throwable $exception) {
            return [];
        }

        return \is_array($parsed) ? $parsed : [];
    }
}
