<?php

namespace App\Config;

use Symfony\Component\Yaml\Yaml;

class MtfValidationConfig
{
    private readonly string $path;
    private array $config;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? \dirname(__DIR__, 2) . '/config/app/mtf_validations.yaml';
        $this->config = Yaml::parseFile($this->path)['mtf_validation'] ?? [];
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
}
