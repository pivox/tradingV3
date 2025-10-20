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

    private array $config;
    public function __construct(private readonly string $path) {
        $this->config = Yaml::parseFile($this->path) ?? [];
    }

    private function sel(): array   { return $this->config['selection'] ?? []; }
    private function f(): array     { return $this->sel()['filters'] ?? []; }
    private function l(): array     { return $this->sel()['limits'] ?? []; }
    private function o(): array     { return $this->sel()['order'] ?? []; }


    public function all(): array
    {
        return Yaml::parseFile($this->path) ?? [];
    }
    public function get(string $key, array|string|int|float|bool|null $default = null): mixed
    {
        return $this->config['selection'][$key] ?? $default;
    }

    public function getFilter(string $key, array|string|int|float|bool|null $default = null): mixed
    {
        return $this->config['selection']['filters'][$key] ?? $default;
    }

    public function getLimit(string $key, ?int $default = null): mixed
    {
        return $this->config['selection']['limits'][$key] ?? $default;
    }

    public function getOrder(string $key, string $default = 'DESC'): string
    {
        return strtoupper($this->config['selection']['order'][$key] ?? $default);
    }

    public function getRefreshInterval(): int
    {
        return (int)($this->config['selection']['refresh_interval_minutes'] ?? 60);
    }
}
