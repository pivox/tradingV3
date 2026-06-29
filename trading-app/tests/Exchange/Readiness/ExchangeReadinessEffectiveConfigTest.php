<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Readiness;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Readiness\ExchangeReadinessEvaluator;
use App\Exchange\Readiness\ExchangeReadinessInput;
use App\Exchange\Readiness\ExchangeReadinessLevel;
use App\Exchange\Readiness\ExchangeReadinessReport;
use App\Exchange\Readiness\ExchangeRuntimeCheckInterface;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\TradingConfigLayerLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExchangeReadinessEvaluator::class)]
#[CoversClass(ExchangeReadinessInput::class)]
#[CoversClass(ExchangeReadinessLevel::class)]
#[CoversClass(ExchangeReadinessReport::class)]
#[CoversClass(ExchangeRuntimeCheckInterface::class)]
#[CoversClass(EffectiveTradingConfigResolver::class)]
#[CoversClass(TradingConfigLayerLoader::class)]
final class ExchangeReadinessEffectiveConfigTest extends TestCase
{
    public function testOkxDemoEffectiveConfigCanProduceCandidateReadinessReport(): void
    {
        $resolved = (new EffectiveTradingConfigResolver(new TradingConfigLayerLoader()))
            ->resolve('scalper', 'okx', 'demo');

        $report = (new ExchangeReadinessEvaluator())->evaluate($this->inputFromConfig(
            exchange: Exchange::OKX,
            env: 'demo',
            resolved: $resolved,
        ));

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertSame('okx', $report->toArray()['exchange']);
        self::assertSame('demo', $report->toArray()['environment']);
        self::assertSame($resolved['config_hash'], $report->toArray()['config_hash']);
        self::assertTrue($report->mainnetWriteGuard);
        self::assertTrue($report->demoTestnetWriteGuard);
        self::assertTrue($report->killSwitch);
        self::assertSame([
            'private_observability_absent_for_dry_run',
            'demo_testnet_write_not_enabled',
        ], $report->warnings);
    }

    public function testHyperliquidTestnetEffectiveConfigCanProduceCandidateReadinessReport(): void
    {
        $resolved = (new EffectiveTradingConfigResolver(new TradingConfigLayerLoader()))
            ->resolve('scalper', 'hyperliquid', 'testnet');

        $report = (new ExchangeReadinessEvaluator())->evaluate($this->inputFromConfig(
            exchange: Exchange::HYPERLIQUID,
            env: 'testnet',
            resolved: $resolved,
        ));

        self::assertSame(ExchangeReadinessLevel::DemoTestnetCandidate, $report->readyLevel);
        self::assertSame('hyperliquid', $report->toArray()['exchange']);
        self::assertSame('testnet', $report->toArray()['environment']);
        self::assertSame($resolved['config_hash'], $report->toArray()['config_hash']);
        self::assertTrue($report->mainnetWriteGuard);
        self::assertTrue($report->demoTestnetWriteGuard);
        self::assertTrue($report->killSwitch);
        self::assertSame([
            'private_observability_absent_for_dry_run',
            'demo_testnet_write_not_enabled',
        ], $report->warnings);
    }

    /**
     * @param array{
     *     config: array<string,mixed>,
     *     config_hash: string,
     *     layers: list<array{type: string, name: string, path: string, required: bool}>,
     *     missing_optional_layers: list<array{type: string, name: string, path: string, required: bool}>,
     *     provenance: array<string, array{type: string, name: string, path: string, required: bool}>
     * } $resolved
     */
    private function inputFromConfig(Exchange $exchange, string $env, array $resolved): ExchangeReadinessInput
    {
        $trading = $resolved['config']['trading'];
        self::assertIsArray($trading);
        $execution = $trading['execution'];
        self::assertIsArray($execution);

        return new ExchangeReadinessInput(
            exchange: $exchange,
            marketType: MarketType::PERPETUAL,
            environment: $env,
            publicConnectivity: true,
            privateReadConnectivity: true,
            privateObservability: false,
            instrumentsLoaded: true,
            metadataValid: true,
            precisionValid: true,
            accountReadable: true,
            permissionsRead: true,
            permissionsTrade: false,
            mainnetWriteGuard: ($execution['mainnet_write_enabled'] ?? true) === false,
            demoTestnetWriteGuard: array_key_exists('demo_testnet_write_enabled', $execution),
            demoTestnetWriteEnabled: ($execution['demo_testnet_write_enabled'] ?? false) === true,
            stopLossCapability: ($execution['require_stop_loss'] ?? false) === true,
            killSwitch: ($execution['kill_switch_enabled'] ?? true) === true,
            allowedSymbols: $this->stringList($execution['allowed_symbols'] ?? []),
            allowedMarkets: $this->stringList($execution['allowed_markets'] ?? []),
            maxNotional: is_float($execution['max_notional'] ?? null) ? $execution['max_notional'] : null,
            configHash: $resolved['config_hash'],
        );
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_string($item)));
    }
}
