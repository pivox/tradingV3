<?php

declare(strict_types=1);

namespace App\TradingCore\Config;

use App\TradingCore\Config\Exception\TradingConfigException;

final readonly class EffectiveTradingConfigReadService
{
    public function __construct(
        private EffectiveTradingConfigResolver $resolver,
    ) {
    }

    /**
     * @return array{
     *     request: array{mode: string, exchange: string, env: string},
     *     config: array<string, mixed>,
     *     config_hash: string,
     *     layers: list<array{type: string, name: string, path: string, required: bool}>,
     *     missing_optional_layers: list<array{type: string, name: string, path: string, required: bool}>,
     *     provenance: array<string, array{type: string, name: string, path: string, required: bool}>
     * }
     */
    public function describe(string $mode, string $exchange, string $env): array
    {
        $this->assertSafeLayerName('mode', $mode);
        $this->assertSafeLayerName('exchange', $exchange);
        $this->assertSafeLayerName('env', $env);
        $this->assertSupportedMode($mode);
        $this->assertSupportedExchangeEnvironment($exchange, $env);

        $resolved = $this->resolver->resolve($mode, $exchange, $env);

        return [
            'request' => [
                'mode' => $mode,
                'exchange' => $exchange,
                'env' => $env,
            ],
            'config' => $resolved['config'],
            'config_hash' => $resolved['config_hash'],
            'layers' => $resolved['layers'],
            'missing_optional_layers' => $resolved['missing_optional_layers'],
            'provenance' => $resolved['provenance'],
        ];
    }

    private function assertSupportedMode(string $mode): void
    {
        if (in_array($mode, ['regular', 'scalper', 'scalper_micro'], true)) {
            return;
        }

        throw new TradingConfigException('Unsupported trading mode: COMMON-002 supports only regular, scalper and scalper_micro.');
    }

    private function assertSupportedExchangeEnvironment(string $exchange, string $env): void
    {
        if (($exchange === 'okx' && $env === 'demo') || ($exchange === 'hyperliquid' && $env === 'testnet')) {
            return;
        }

        throw new TradingConfigException('Unsupported exchange/env pair: COMMON-002 supports only okx/demo and hyperliquid/testnet.');
    }

    private function assertSafeLayerName(string $type, string $name): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) === 1) {
            return;
        }

        throw new TradingConfigException(sprintf(
            'Invalid trading config layer name for "%s": "%s"',
            $type,
            $name,
        ));
    }
}
