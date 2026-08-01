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
        $differentCatalog = (new EffectiveTradingConfigComposer())->compose($request, $layers, str_repeat('b', 64));

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

    /** @return iterable<string, array{callable(list<TradingConfigLayer>): list<TradingConfigLayer>, string}> */
    public static function invalidLayerProvider(): iterable
    {
        yield 'missing setup' => [static function (array $layers): array { unset($layers[2]); return array_values($layers); }, 'six required layers'];
        yield 'wrong order' => [static function (array $layers): array { [$layers[1], $layers[2]] = [$layers[2], $layers[1]]; return $layers; }, 'layer order'];
        yield 'wrong owner' => [static function (array $layers): array { $layers[3] = new TradingConfigLayer('exchange', 'fake', '/exchange.yaml', true, ['environment' => ['id' => 'test']]); return $layers; }, 'not owned'];
        yield 'unknown base key' => [static function (array $layers): array { $layers[0] = new TradingConfigLayer('base', 'base', '/base.yaml', true, ['schema_version' => 'effective-trading-config.v2', 'units' => ['percent' => 'percentage_points'], 'safety' => ['mainnet_write_enabled' => false, 'demo_testnet_write_enabled' => false, 'require_stop_loss' => true, 'kill_switch_enabled' => true], 'mystery' => true]); return $layers; }, 'not owned'];
        yield 'scalar type mismatch' => [static function (array $layers): array { $layers[4] = new TradingConfigLayer('mode_exchange', 'scalping.1.0.0.fake', '/pair.yaml', true, ['mode_id' => 'scalping', 'mode_version' => '1.0.0', 'exchange' => 'fake', 'overrides' => ['mode.risk.trade_budget.value' => ['bad']]]); return $layers; }, 'type mismatch'];
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
                'setup_id' => 'scalping.pullback.long', 'setup_version' => '1.0.0', 'side' => 'long',
                'hypothesis' => 'synthetic', 'regime' => [], 'trigger' => [], 'invalidation' => [], 'entry_zone' => [], 'stop' => [], 'targets' => [],
            ]]),
            new TradingConfigLayer('exchange', 'fake', '/exchange.yaml', true, ['exchange' => [
                'id' => 'fake', 'capabilities' => ['orders' => true], 'fees' => ['maker_rate' => 0.0],
                'funding' => ['enabled' => false], 'precision' => ['price_decimals' => 2], 'limits' => ['max_orders' => 10],
            ]]),
            new TradingConfigLayer('mode_exchange', 'scalping.1.0.0.fake', '/pair.yaml', true, [
                'mode_id' => 'scalping', 'mode_version' => '1.0.0', 'exchange' => 'fake',
                'overrides' => ['mode.risk.trade_budget.value' => 0.5],
            ]),
            new TradingConfigLayer('environment', 'test', '/test.yaml', true, ['environment' => [
                'id' => 'test', 'allowed_symbols' => ['BTCUSDT'], 'allowed_markets' => ['perpetual'],
                'max_notional' => 10.0, 'dry_run' => true, 'write_enabled' => false, 'kill_switch_enabled' => true,
            ]]),
        ];
    }
}
