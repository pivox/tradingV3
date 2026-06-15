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
     *     layers: list<array{type: string, name: string, path: string, required: bool}>,
     *     missing_optional_layers: list<array{type: string, name: string, path: string, required: bool}>
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

        foreach ($candidates as $candidate) {
            $layer = $candidate['layer'];
            if ($layer instanceof TradingConfigLayer) {
                $effectiveConfig = $this->mergeConfig($effectiveConfig, $layer->config);
                $usedLayers[] = $layer->toLogContext();
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
            'layers' => $usedLayers,
            'missing_optional_layers' => $missingOptionalLayers,
        ];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
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
                $base[$key] = $this->mergeConfig($baseValue, $overrideValue);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<mixed> $value
     */
    private function isAssociative(array $value): bool
    {
        return $value === [] || array_keys($value) !== range(0, count($value) - 1);
    }
}
