<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\TradingCore\Config\Exception\TradingConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class TradingConfigLayerLoader
{
    public function __construct(
        private ?string $configRoot = null,
    ) {
    }

    public function loadBase(): TradingConfigLayer
    {
        return $this->load('base', 'base', 'base.yaml', true);
    }

    public function loadMode(string $mode): ?TradingConfigLayer
    {
        return $this->load('mode', $mode, sprintf('mode/%s.yaml', $mode), false);
    }

    public function loadExchange(string $exchange): ?TradingConfigLayer
    {
        return $this->load('exchange', $exchange, sprintf('exchange/%s.yaml', $exchange), false);
    }

    public function loadModeExchange(string $mode, string $exchange): ?TradingConfigLayer
    {
        $name = sprintf('%s.%s', $mode, $exchange);

        return $this->load('mode_exchange', $name, sprintf('mode_exchange/%s.yaml', $name), false);
    }

    public function loadEnv(string $env): ?TradingConfigLayer
    {
        return $this->load('env', $env, sprintf('env/%s.yaml', $env), false);
    }

    /**
     * @return array{type: string, name: string, path: string, required: bool}
     */
    public function describeOptional(string $type, string $name): array
    {
        return [
            'type' => $type,
            'name' => $name,
            'path' => $this->pathFor($this->relativePathFor($type, $name)),
            'required' => false,
        ];
    }

    private function load(string $type, string $name, string $relativePath, bool $required): ?TradingConfigLayer
    {
        $path = $this->pathFor($relativePath);

        if (!is_file($path)) {
            if ($required) {
                throw new TradingConfigException(sprintf(
                    'Required trading config layer "%s" is missing: %s',
                    $type,
                    $path,
                ));
            }

            return null;
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new TradingConfigException(sprintf(
                'Trading config layer "%s" could not be parsed: %s',
                $type,
                $path,
            ), previous: $exception);
        }

        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new TradingConfigException(sprintf(
                'Trading config layer "%s" must contain a YAML mapping: %s',
                $type,
                $path,
            ));
        }

        /** @var array<string, mixed> $parsed */
        return new TradingConfigLayer($type, $name, $path, $required, $parsed);
    }

    private function pathFor(string $relativePath): string
    {
        return rtrim($this->root(), '/') . '/' . ltrim($relativePath, '/');
    }

    private function root(): string
    {
        return $this->configRoot ?? dirname(__DIR__, 3) . '/config/trading';
    }

    private function relativePathFor(string $type, string $name): string
    {
        return match ($type) {
            'mode' => sprintf('mode/%s.yaml', $name),
            'exchange' => sprintf('exchange/%s.yaml', $name),
            'mode_exchange' => sprintf('mode_exchange/%s.yaml', $name),
            'env' => sprintf('env/%s.yaml', $name),
            default => sprintf('%s.yaml', $name),
        };
    }
}
