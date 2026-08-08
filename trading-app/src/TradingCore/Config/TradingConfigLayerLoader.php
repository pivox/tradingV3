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

    public function requireExchange(string $exchange): TradingConfigLayer
    {
        return $this->load('exchange', $exchange, sprintf('exchange/%s.yaml', $exchange), true);
    }

    public function requireModeExchange(string $mode, string $modeVersion, string $exchange): TradingConfigLayer
    {
        $name = sprintf('%s.%s.%s', $mode, $modeVersion, $exchange);

        return $this->load('mode_exchange', $name, sprintf('mode_exchange/%s.yaml', $name), true);
    }

    public function requireEnvironment(string $environment): TradingConfigLayer
    {
        return $this->load('environment', $environment, sprintf('env/%s.yaml', $environment), true);
    }

    private function load(string $type, string $name, string $relativePath, bool $required): ?TradingConfigLayer
    {
        $this->assertSafeLayerName($type, $name);

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

    private function assertSafeLayerName(string $type, string $name): void
    {
        $pattern = $type === 'mode_exchange'
            ? '/^[a-z0-9][a-z0-9_-]*(?:\.[a-z0-9][a-z0-9_-]*)+$/'
            : '/^[a-z0-9][a-z0-9_-]*$/';

        if (preg_match($pattern, $name) === 1) {
            return;
        }

        throw new TradingConfigException(sprintf(
            'Invalid trading config layer name for "%s": "%s"',
            $type,
            $name,
        ));
    }
}
