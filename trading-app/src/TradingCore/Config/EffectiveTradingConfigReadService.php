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

    private function assertSupportedExchangeEnvironment(string $exchange, string $env): void
    {
        if ($exchange === 'okx' && $env !== 'demo') {
            throw new TradingConfigException('Unsupported exchange/env pair: OKX is available only with env=demo in this series.');
        }

        if ($exchange === 'hyperliquid' && $env !== 'testnet') {
            throw new TradingConfigException('Unsupported exchange/env pair: Hyperliquid is available only with env=testnet in this series.');
        }
    }
}
