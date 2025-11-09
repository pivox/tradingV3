<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class MtfValidationConfig
{
    private readonly string $path;
    private ?array $cache = null;
    private ?string $cachedVersion = null;
    private array $config;

    public function __construct(?string $path = null)
    {
        // Nouveau chemin par défaut: src/MtfValidator/config/validations.yaml
        $this->path = $path ?? \dirname(__DIR__, 2) . '/src/MtfValidator/config/validations.yaml';
        if (!is_file($this->path)) {
            throw new \RuntimeException(sprintf('Configuration file not found: %s', $this->path));
        }
        $parsed = $this->parseYamlFile($this->path);
        $this->cache = $parsed;
        $this->cachedVersion = $parsed['version'] ?? null;
        $this->config = $parsed['mtf_validation'] ?? [];
    }

    public function getConfig(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config;
    }

    public function getRules(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['rules'] ?? [];
    }

    public function getValidation(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['validation'] ?? [];
    }

    public function getDefaults(): array
    {
        $this->checkVersionAndRefresh();
        return $this->config['defaults'] ?? [];
    }

    public function getDefault(string $key, mixed $default = null): mixed
    {
        $this->checkVersionAndRefresh();
        $defaults = $this->getDefaults();
        return $defaults[$key] ?? $default;
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
            $this->config = $parsed['mtf_validation'] ?? [];
            return;
        }

        // Vérifier si le fichier a été modifié
        $currentConfig = $this->parseYamlFile($this->path);
        $currentVersion = $currentConfig['version'] ?? null;

        // Si la version a changé, rafraîchir le cache
        if ($currentVersion !== $this->cachedVersion) {
            $this->cache = $currentConfig;
            $this->cachedVersion = $currentVersion;
            $this->config = $currentConfig['mtf_validation'] ?? [];
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
