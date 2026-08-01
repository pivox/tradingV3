<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigComposer;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Config\TradingConfigLayer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveTradingConfigComposer::class)]
#[CoversClass(EffectiveTradingConfigRequest::class)]
#[CoversClass(EffectiveTradingConfigSnapshot::class)]
final class CanonicalEffectiveTradingConfigTest extends TestCase
{
    public function testComposesExactlySixRequiredLayersWithStableHashAndLeafProvenance(): void
    {
        $request = new EffectiveTradingConfigRequest(
            'scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long',
        );
        $layers = $this->layers($request);

        $first = (new EffectiveTradingConfigComposer())->compose($request, $layers, str_repeat('a', 64));
        $second = (new EffectiveTradingConfigComposer())->compose($request, $layers, str_repeat('a', 64));
        $differentLayers = $layers;
        $differentSetup = $differentLayers[2]->config;
        $differentSetup['setup']['condition_catalog_hash'] = str_repeat('b', 64);
        $differentSetup['setup']['data_condition_contract']['condition_catalog_hash']['value'] = str_repeat('b', 64);
        $differentLayers[2] = new TradingConfigLayer('setup', 'scalping.pullback.long@1.0.0', '/setup.yaml', true, $differentSetup);
        $differentCatalog = (new EffectiveTradingConfigComposer())->compose($request, $differentLayers, str_repeat('b', 64));

        self::assertSame(['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'], array_column($first->orderedLayers(), 'type'));
        self::assertSame($first->configHash, $second->configHash);
        self::assertNotSame($first->configHash, $differentCatalog->configHash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first->configHash);
        self::assertSame('mode_exchange', $first->provenance()['mode.risk.trade_budget.value']['type']);
        self::assertSame('setup', $first->provenance()['setup.side']['type']);
        self::assertSame('environment', $first->provenance()['environment.dry_run']['type']);
        self::assertSame(str_repeat('a', 64), $first->conditionCatalogHash);
        self::assertTrue($first->executable);
    }

    public function testSnapshotDoesNotExposeMutableInternalPayload(): void
    {
        $snapshot = (new EffectiveTradingConfigComposer())->compose(
            new EffectiveTradingConfigRequest('scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long'),
            $this->layers(new EffectiveTradingConfigRequest('scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long')),
            str_repeat('a', 64),
        );
        $copy = $snapshot->payload();
        $copy['safety']['kill_switch_enabled'] = false;

        self::assertTrue($snapshot->payload()['safety']['kill_switch_enabled']);
    }

    public function testConfigHashCoversNestedCanonicalSetupAst(): void
    {
        $request = new EffectiveTradingConfigRequest('scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long');
        $layers = $this->layers($request);
        $first = (new EffectiveTradingConfigComposer())->compose($request, $layers, str_repeat('a', 64));
        $setup = $layers[2]->config;
        $setup['setup']['ast']['confirmations']['nodes'][0]['condition'] = 'changed_condition';
        $layers[2] = new TradingConfigLayer('setup', 'scalping.pullback.long@1.0.0', '/setup.yaml', true, $setup);
        $changed = (new EffectiveTradingConfigComposer())->compose($request, $layers, str_repeat('a', 64));

        self::assertNotSame($first->configHash, $changed->configHash);
        self::assertSame('changed_condition', $changed->payload()['setup']['ast']['confirmations']['nodes'][0]['condition']);
    }

    /** @dataProvider supportedTargetProvider */
    public function testEverySupportedTargetRemainsWriteDisabledWithStopLossRequired(string $exchange, string $environment): void
    {
        $request = new EffectiveTradingConfigRequest('scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', $exchange, $environment, 'long');
        $payload = (new EffectiveTradingConfigComposer())->compose($request, $this->layers($request), str_repeat('a', 64))->payload();

        self::assertFalse($payload['environment']['write_enabled']);
        self::assertTrue($payload['environment']['require_stop_loss']);
        self::assertTrue($payload['exchange']['capabilities']['stop_loss']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function supportedTargetProvider(): iterable
    {
        yield 'fake local' => ['fake', 'local'];
        yield 'fake test' => ['fake', 'test'];
        yield 'OKX demo' => ['okx', 'demo'];
        yield 'Hyperliquid testnet' => ['hyperliquid', 'testnet'];
    }

    /** @return iterable<string, array{callable(list<TradingConfigLayer>): list<TradingConfigLayer>, string}> */
    public static function invalidLayerProvider(): iterable
    {
        yield 'missing setup' => [static function (array $layers): array { unset($layers[2]); return array_values($layers); }, 'six required layers'];
        yield 'wrong order' => [static function (array $layers): array { [$layers[1], $layers[2]] = [$layers[2], $layers[1]]; return $layers; }, 'layer order'];
        yield 'wrong owner' => [static function (array $layers): array { $layers[3] = new TradingConfigLayer('exchange', 'fake', '/exchange.yaml', true, ['environment' => ['id' => 'test']]); return $layers; }, 'not owned'];
        yield 'unknown base key' => [static function (array $layers): array { $layers[0] = new TradingConfigLayer('base', 'base', '/base.yaml', true, ['schema_version' => 'effective-trading-config.v2', 'units' => ['percent' => 'percentage_points'], 'safety' => ['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true], 'mystery' => true]); return $layers; }, 'not owned'];
        yield 'scalar type mismatch' => [static function (array $layers): array { $layers[4] = new TradingConfigLayer('mode_exchange', 'scalping.1.0.0.fake', '/pair.yaml', true, ['mode_id' => 'scalping', 'mode_version' => '1.0.0', 'exchange' => 'fake', 'overrides' => ['mode.risk.trade_budget.value' => ['bad']]]); return $layers; }, 'type mismatch'];
        yield 'environment writes true' => [static function (array $layers): array { $config = $layers[5]->config; $config['environment']['write_enabled'] = true; $layers[5] = new TradingConfigLayer('environment', 'test', '/test.yaml', true, $config); return $layers; }, 'write_enabled=false'];
        yield 'environment write gate missing' => [static function (array $layers): array { $config = $layers[5]->config; unset($config['environment']['write_enabled']); $layers[5] = new TradingConfigLayer('environment', 'test', '/test.yaml', true, $config); return $layers; }, 'unknown key'];
        yield 'exchange stop loss false' => [static function (array $layers): array { $config = $layers[3]->config; $config['exchange']['capabilities']['stop_loss'] = false; $layers[3] = new TradingConfigLayer('exchange', 'fake', '/exchange.yaml', true, $config); return $layers; }, 'stop_loss=true'];
        yield 'exchange stop loss missing' => [static function (array $layers): array { $config = $layers[3]->config; unset($config['exchange']['capabilities']['stop_loss']); $layers[3] = new TradingConfigLayer('exchange', 'fake', '/exchange.yaml', true, $config); return $layers; }, 'stop_loss=true'];
        yield 'environment stop loss false' => [static function (array $layers): array { $config = $layers[5]->config; $config['environment']['require_stop_loss'] = false; $layers[5] = new TradingConfigLayer('environment', 'test', '/test.yaml', true, $config); return $layers; }, 'require_stop_loss=true'];
        yield 'environment stop loss missing' => [static function (array $layers): array { $config = $layers[5]->config; unset($config['environment']['require_stop_loss']); $layers[5] = new TradingConfigLayer('environment', 'test', '/test.yaml', true, $config); return $layers; }, 'unknown key'];
        yield 'lossy setup snapshot' => [static function (array $layers): array { $config = $layers[2]->config; unset($config['setup']['ast']['confirmations']); $layers[2] = new TradingConfigLayer('setup', 'scalping.pullback.long@1.0.0', '/setup.yaml', true, $config); return $layers; }, 'compiled setup AST'];
    }

    /** @dataProvider invalidLayerProvider */
    public function testRejectsMissingMisorderedWrongOwnerUnknownAndTypeMismatchedLayers(callable $mutate, string $message): void
    {
        $request = new EffectiveTradingConfigRequest('scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long');
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage($message);
        (new EffectiveTradingConfigComposer())->compose($request, $mutate($this->layers($request)), str_repeat('a', 64));
    }

    public function testRejectsLegacyAliasesAndInvalidIdentityFields(): void
    {
        foreach ([
            ['regular', '1.0.0', 'day_trading.trend_continuation.long', '1.0.0', 'fake', 'test', 'long'],
            ['scalper', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long'],
            ['scalper_micro', '1.0.0', 'micro_scalping.momentum_ofi.long', '1.0.0', 'fake', 'test', 'long'],
            ['scalping', 'latest', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long'],
            ['scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'bitmart', 'test', 'long'],
            ['scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'buy'],
        ] as $arguments) {
            try {
                new EffectiveTradingConfigRequest(...$arguments);
                self::fail('Invalid canonical identity was accepted: ' . json_encode($arguments));
            } catch (TradingConfigException) {
                self::assertTrue(true);
            }
        }
    }

    /** @return list<TradingConfigLayer> */
    private function layers(EffectiveTradingConfigRequest $request): array
    {
        return [
            new TradingConfigLayer('base', 'base', '/base.yaml', true, [
                'schema_version' => 'effective-trading-config.v2',
                'units' => ['percent' => 'percentage_points'],
                'safety' => ['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true],
            ]),
            new TradingConfigLayer('mode', 'scalping@1.0.0', '/mode.yaml', true, ['mode' => [
                'mode_id' => 'scalping', 'mode_version' => '1.0.0',
                'risk' => ['trade_budget' => ['value' => 1.0]],
            ]]),
            new TradingConfigLayer('setup', 'scalping.pullback.long@1.0.0', '/setup.yaml', true, ['setup' => [
                'schema_version' => 'compiled-setup.v1',
                'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'status' => 'paper',
                'side' => 'long', 'executable' => true, 'publishable' => true, 'family' => 'pullback',
                'thesis' => 'synthetic thesis', 'hypothesis' => 'synthetic hypothesis',
                'mode_versions' => ['scalping' => '1.0.0'], 'mode_compatibility' => ['state' => 'defined'],
                'ast' => [
                    'kind' => 'setup', 'side' => 'long', 'regime' => ['op' => 'all_of', 'nodes' => []],
                    'context' => ['op' => 'all_of', 'nodes' => []], 'trigger' => ['op' => 'all_of', 'nodes' => []],
                    'confirmations' => ['op' => 'all_of', 'nodes' => [['condition' => 'synthetic_condition']]],
                    'filters' => ['op' => 'all_of', 'nodes' => []], 'no_trade_rules' => ['op' => 'all_of', 'nodes' => []],
                    'execution' => ['side' => 'long', 'entry_zone' => [], 'invalidation' => [], 'stop' => [], 'targets' => [], 'minimum_net_r' => [], 'time_stop' => [], 'cost_contract' => []],
                ],
                'missing_data_policy' => ['absent' => 'reject', 'stale' => 'reject', 'critical' => 'reject'],
                'data_condition_contract' => ['required_data' => ['ohlcv'], 'missing_conditions' => [], 'external_dependencies' => [], 'condition_catalog_hash' => ['state' => 'defined', 'value' => str_repeat('a', 64)], 'unknown_condition_policy' => 'reject'],
                'validity_window' => ['state' => 'defined'], 'governance' => ['activation_requires_trace' => true],
                'known_defects' => [], 'ownership_model' => 'setup-contract-ownership-v1',
                'source_origins' => [['file' => 'synthetic.yaml', 'line_range' => '1-10', 'content_sha256' => str_repeat('e', 64), 'commit' => str_repeat('f', 40)]],
                'contract_provenance' => ['context.trigger' => 'synthetic.yaml:1-10'], 'contract_hash' => str_repeat('d', 64),
                'condition_catalog_hash' => str_repeat('a', 64), 'blockers' => [],
            ]]),
            new TradingConfigLayer('exchange', $request->exchange, '/exchange.yaml', true, ['exchange' => [
                'id' => $request->exchange, 'capabilities' => ['orders' => true, 'stop_loss' => true], 'fees' => ['maker_rate' => 0.0],
                'funding' => ['enabled' => false], 'precision' => ['price_decimals' => 2], 'limits' => ['max_orders' => 10],
            ]]),
            new TradingConfigLayer('mode_exchange', 'scalping.1.0.0.' . $request->exchange, '/pair.yaml', true, [
                'mode_id' => 'scalping', 'mode_version' => '1.0.0', 'exchange' => $request->exchange,
                'overrides' => ['mode.risk.trade_budget.value' => 0.5],
            ]),
            new TradingConfigLayer('environment', $request->environment, '/environment.yaml', true, ['environment' => [
                'id' => $request->environment, 'allowed_symbols' => ['BTCUSDT'], 'allowed_markets' => ['perpetual'],
                'max_notional' => 10.0, 'dry_run' => true, 'write_enabled' => false, 'kill_switch_enabled' => true,
                'require_stop_loss' => true,
            ]]),
        ];
    }
}
