<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class MtfValidationConfig
{
    private readonly string $path;
    private array $config;

    public function __construct(?string $path = null)
    {
        // Nouveau chemin par défaut: src/MtfValidator/config/validations.yaml
        $this->path = $path ?? \dirname(__DIR__, 2) . '/src/MtfValidator/config/validations.yaml';
        $parsed = Yaml::parseFile($this->path) ?? [];
        $this->config = $parsed['mtf_validation'] ?? [];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getRules(): array
    {
        return $this->config['rules'] ?? [];
    }

    public function getValidation(): array
    {
        return $this->config['validation'] ?? [];
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
}
