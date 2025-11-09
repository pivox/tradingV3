<?php
// src/Config/MtfContractsConfig.php
namespace App\Config;

use Symfony\Component\Yaml\Yaml;

final class MtfContractsConfig
{

    const STATUS = 'status';
    const DELIST_TIME = 'delist_time';
    const VOLUME_24_H_USDT = 'volume_24h_usdt';
    const QUOTE_CURRENCY = 'quote_currency';
    const OPEN_TIME = 'open_time';
    const SORT_BY = 'sort_by';
    const ORDER = 'order';
    const LIMIT = 'limit';

    private ?array $cache = null;
    private ?string $cachedVersion = null;
    private array $config;

    public function __construct(private readonly string $path) {
        if (!is_file($this->path)) {
            throw new \RuntimeException(sprintf('Configuration file not found: %s', $this->path));
        }
        $parsed = $this->parseYamlFile($this->path);
        $this->cache = $parsed;
        $this->cachedVersion = $parsed['version'] ?? null;
        // Support ancien format (mtf_contracts) et nouveau (contracts)
        if (isset($parsed['contracts'])) {
            $this->config = $parsed['contracts'];
        } elseif (isset($parsed['mtf_contracts'])) {
            $this->config = $parsed['mtf_contracts'];
        } else {
            $this->config = $parsed; // Fallback si structure plate
        }
    }

    private function sel(): array   { return $this->config['selection'] ?? []; }
    private function f(): array     { return $this->sel()['filters'] ?? []; }
    private function l(): array     { return $this->sel()['limits'] ?? []; }
    private function o(): array     { return $this->sel()['order'] ?? []; }

    public function all(): array
    {
        $this->checkVersionAndRefresh();
        return $this->cache ?? [];
    }

    public function get(string $key, array|string|int|float|bool|null $default = null): mixed
    {
        $this->checkVersionAndRefresh();
        return $this->config['selection'][$key] ?? $default;
    }

    public function getFilter(string $key, array|string|int|float|bool|null $default = null): mixed
    {
        $this->checkVersionAndRefresh();
        return $this->config['selection']['filters'][$key] ?? $default;
    }

    public function getLimit(string $key, ?int $default = null): mixed
    {
        $this->checkVersionAndRefresh();
        return $this->config['selection']['limits'][$key] ?? $default;
    }

    public function getOrder(string $key, string $default = 'DESC'): string
    {
        $this->checkVersionAndRefresh();
        return strtoupper($this->config['selection']['order'][$key] ?? $default);
    }

    public function getRefreshInterval(): int
    {
        $this->checkVersionAndRefresh();
        return (int)($this->config['selection']['refresh_interval_minutes'] ?? 60);
    }

    /**
     * Vérifie si la version a changé et rafraîchit le cache si nécessaire
     */
    private function checkVersionAndRefresh(): void
    {
        if ($this->cache === null) {
            $parsed = $this->parseYamlFile($this->path);
            $this->cache = $parsed;
            $this->cachedVersion = $parsed['version'] ?? null;
            // Support ancien format (mtf_contracts) et nouveau (contracts)
            if (isset($parsed['contracts'])) {
                $this->config = $parsed['contracts'];
            } elseif (isset($parsed['mtf_contracts'])) {
                $this->config = $parsed['mtf_contracts'];
            } else {
                $this->config = $parsed; // Fallback si structure plate
            }
            return;
        }

        // Vérifier si le fichier a été modifié
        $currentConfig = $this->parseYamlFile($this->path);
        $currentVersion = $currentConfig['version'] ?? null;

        // Si la version a changé, rafraîchir le cache
        if ($currentVersion !== $this->cachedVersion) {
            $this->cache = $currentConfig;
            $this->cachedVersion = $currentVersion;
            // Support ancien format (mtf_contracts) et nouveau (contracts)
            if (isset($currentConfig['contracts'])) {
                $this->config = $currentConfig['contracts'];
            } elseif (isset($currentConfig['mtf_contracts'])) {
                $this->config = $currentConfig['mtf_contracts'];
            } else {
                $this->config = $currentConfig; // Fallback si structure plate
            }
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
