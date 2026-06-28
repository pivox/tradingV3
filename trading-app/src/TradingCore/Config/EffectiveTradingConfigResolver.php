<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use Psr\Log\LoggerInterface;

final readonly class EffectiveTradingConfigResolver
{
    public function __construct(
        private ?TradingConfigLayerLoader $loader = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Resolves trading configuration as:
     * base < mode < exchange < mode_exchange < env.
     *
     * @return array{
     *     config: array<string, mixed>,
     *     config_hash: string,
     *     layers: list<array{type: string, name: string, path: string, required: bool}>,
     *     missing_optional_layers: list<array{type: string, name: string, path: string, required: bool}>,
     *     provenance: array<string, array{type: string, name: string, path: string, required: bool}>
     * }
     */
    public function resolve(string $mode, string $exchange, string $env): array
    {
        $loader = $this->loader ?? new TradingConfigLayerLoader();

        $candidates = [
            ['layer' => $loader->loadBase(), 'missing' => null],
            ['layer' => $loader->loadMode($mode), 'missing' => $loader->describeOptional('mode', $mode)],
            ['layer' => $loader->loadExchange($exchange), 'missing' => $loader->describeOptional('exchange', $exchange)],
            ['layer' => $loader->loadModeExchange($mode, $exchange), 'missing' => $loader->describeOptional('mode_exchange', sprintf('%s.%s', $mode, $exchange))],
            ['layer' => $loader->loadEnv($env), 'missing' => $loader->describeOptional('env', $env)],
        ];

        $effectiveConfig = [];
        $usedLayers = [];
        $missingOptionalLayers = [];
        $provenance = [];

        foreach ($candidates as $candidate) {
            $layer = $candidate['layer'];
            if ($layer instanceof TradingConfigLayer) {
                $layerContext = $layer->toLogContext();
                $effectiveConfig = $this->mergeConfig($effectiveConfig, $layer->config, $provenance, $layerContext);
                $usedLayers[] = $layerContext;
                continue;
            }

            if (is_array($candidate['missing'])) {
                $missingOptionalLayers[] = $candidate['missing'];
            }
        }

        $this->logger?->info('trading_config.effective_resolved', [
            'mode' => $mode,
            'exchange' => $exchange,
            'env' => $env,
            'layers' => $usedLayers,
            'missing_optional_layers' => $missingOptionalLayers,
        ]);

        return [
            'config' => $effectiveConfig,
            'config_hash' => $this->hashConfig($effectiveConfig),
            'layers' => $usedLayers,
            'missing_optional_layers' => $missingOptionalLayers,
            'provenance' => $provenance,
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @param array<string, array{type: string, name: string, path: string, required: bool}> $provenance
     * @param array{type: string, name: string, path: string, required: bool} $layerContext
     * @return array<string, mixed>
     */
    private function mergeConfig(array $base, array $override, array &$provenance, array $layerContext, string $prefix = ''): array
    {
        foreach ($override as $key => $value) {
            $path = $prefix === '' ? $key : $prefix . '.' . $key;

            if (
                array_key_exists($key, $base)
                && is_array($base[$key])
                && is_array($value)
                && $this->isAssociative($base[$key])
                && $this->isAssociative($value)
            ) {
                /** @var array<string, mixed> $baseValue */
                $baseValue = $base[$key];
                /** @var array<string, mixed> $overrideValue */
                $overrideValue = $value;
                $base[$key] = $this->mergeConfig($baseValue, $overrideValue, $provenance, $layerContext, $path);
                continue;
            }

            $base[$key] = $value;
            $this->recordProvenance($provenance, $path, $value, $layerContext);
        }

        return $base;
    }

    /**
     * @param array<string, array{type: string, name: string, path: string, required: bool}> $provenance
     * @param array{type: string, name: string, path: string, required: bool} $layerContext
     */
    private function recordProvenance(array &$provenance, string $path, mixed $value, array $layerContext): void
    {
        if (is_array($value) && $this->isAssociative($value)) {
            /** @var array<string, mixed> $value */
            foreach ($value as $childKey => $childValue) {
                $this->recordProvenance($provenance, $path . '.' . $childKey, $childValue, $layerContext);
            }

            return;
        }

        $provenance[$path] = $layerContext;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function hashConfig(array $config): string
    {
        $normalized = $this->sortRecursively($config);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (!$this->isAssociative($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociative(array $value): bool
    {
        return $value === [] || array_keys($value) !== range(0, count($value) - 1);
    }
}
