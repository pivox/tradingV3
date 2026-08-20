<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Exception\NonExecutableTradingConfigException;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveTradingConfigResolver::class)]
final class EffectiveTradingConfigRuntimeFilesTest extends TestCase
{
    private const SCALPING_SETUPS = [
        ['scalping.trend_continuation.long', 'long'],
        ['scalping.pullback.long', 'long'],
        ['scalping.trend_momentum.short', 'short'],
    ];

    /** @dataProvider modernSafeTargetProvider */
    public function testKnownModernTargetsReachContractGateButCannotExecuteDrafts(string $exchange, string $environment): void
    {
        $this->expectException(NonExecutableTradingConfigException::class);
        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', $exchange, $environment, 'long',
        ));
    }

    /** @return iterable<string,array{string,string}> */
    public static function modernSafeTargetProvider(): iterable
    {
        yield 'fake test' => ['fake', 'test'];
        yield 'OKX demo' => ['okx', 'demo'];
        yield 'Hyperliquid testnet' => ['hyperliquid', 'testnet'];
    }

    /** @dataProvider dayTradingShadowTargetProvider */
    public function testDayTradingShadowResolvesThroughSixRealModernLayers(string $exchange, string $environment): void
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            $exchange, $environment, 'long', ShadowExecutionCapability::Paper,
        ));

        self::assertSame(
            ['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'],
            array_column($snapshot->orderedLayers(), 'type'),
        );
        self::assertFalse($snapshot->payload()['environment']['write_enabled']);
        self::assertTrue($snapshot->payload()['environment']['dry_run']);
        self::assertSame($exchange, $snapshot->payload()['exchange']['id']);
        self::assertSame('paper', $snapshot->request->toArray()['execution_capability']);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $snapshot->configHash);
        self::assertStringNotContainsString(
            'config/trading/mode/regular.yaml',
            json_encode([$snapshot->orderedLayers(), $snapshot->provenance()], JSON_THROW_ON_ERROR),
        );
    }

    /** @return iterable<string,array{string,string}> */
    public static function dayTradingShadowTargetProvider(): iterable
    {
        yield 'Fake local' => ['fake', 'local'];
        yield 'Fake test' => ['fake', 'test'];
        yield 'OKX demo Paper' => ['okx', 'demo'];
        yield 'OKX mainnet Paper read-only' => ['okx', 'mainnet'];
        yield 'Hyperliquid testnet Paper' => ['hyperliquid', 'testnet'];
        yield 'Hyperliquid mainnet Paper read-only' => ['hyperliquid', 'mainnet'];
    }

    public function testDayTradingShadowRejectsPrivateMainnetCapabilityBeforeResolution(): void
    {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('private_mainnet_execution_forbidden');

        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'okx', 'mainnet', 'long', ShadowExecutionCapability::PrivateMainnet,
        ));
    }

    /** @dataProvider rejectedDayTradingCapabilityProvider */
    public function testDayTradingShadowPreservesExactCapabilityFailureReasons(
        ?ShadowExecutionCapability $capability,
        string $reason,
    ): void {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage($reason);

        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            $capability === ShadowExecutionCapability::Backtest ? 'okx' : 'fake',
            $capability === ShadowExecutionCapability::Backtest ? 'demo' : 'test',
            'long', $capability,
        ));
    }

    /** @return iterable<string,array{?ShadowExecutionCapability,string}> */
    public static function rejectedDayTradingCapabilityProvider(): iterable
    {
        yield 'missing capability' => [null, 'day_trading_shadow_capability_required'];
        yield 'non-fake backtest' => [ShadowExecutionCapability::Backtest, 'day_trading_backtest_requires_fake_exchange'];
    }

    /** @dataProvider scalpingShadowTargetProvider */
    public function testScalpingShadowResolvesThroughSixStrictModernLayers(
        string $setupId,
        string $side,
        string $exchange,
        string $environment,
        ShadowExecutionCapability $capability,
    ): void {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', $setupId, '1.1.0',
            $exchange, $environment, $side, $capability,
        ));
        $payload = $snapshot->payload();
        $serializedLayers = json_encode($snapshot->orderedLayers(), JSON_THROW_ON_ERROR);

        self::assertSame(
            ['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'],
            array_column($snapshot->orderedLayers(), 'type'),
        );
        self::assertSame('scalping', $payload['mode']['mode_id']);
        self::assertSame('1.1.0', $payload['mode']['mode_version']);
        self::assertSame($setupId, $payload['setup']['setup_id']);
        self::assertSame('1.1.0', $payload['setup']['setup_version']);
        self::assertSame($side, $payload['setup']['side']);
        self::assertSame($exchange, $payload['exchange']['id']);
        self::assertSame(25.0, $payload['exchange']['limits']['max_notional']);
        self::assertSame(3.0, $payload['mode']['leverage']['value']);
        self::assertFalse($payload['environment']['write_enabled']);
        self::assertTrue($payload['environment']['dry_run']);
        self::assertSame($capability->value, $snapshot->request->toArray()['execution_capability']);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $snapshot->configHash);
        self::assertDoesNotMatchRegularExpression('/\bscalper\b/', $serializedLayers);
    }

    /** @return iterable<string,array{string,string,string,string,ShadowExecutionCapability}> */
    public static function scalpingShadowTargetProvider(): iterable
    {
        $targets = [
            'Fake test' => ['fake', 'test', ShadowExecutionCapability::Fake],
            'OKX demo Paper' => ['okx', 'demo', ShadowExecutionCapability::Paper],
            'Hyperliquid testnet Paper' => ['hyperliquid', 'testnet', ShadowExecutionCapability::Paper],
            'OKX mainnet public read-only' => ['okx', 'mainnet', ShadowExecutionCapability::Paper],
            'Hyperliquid mainnet public read-only' => ['hyperliquid', 'mainnet', ShadowExecutionCapability::Paper],
        ];

        foreach (self::SCALPING_SETUPS as [$setupId, $side]) {
            foreach ($targets as $targetName => [$exchange, $environment, $capability]) {
                yield $setupId . ' / ' . $targetName => [$setupId, $side, $exchange, $environment, $capability];
            }
        }
    }

    /** @dataProvider rejectedScalpingCapabilityProvider */
    public function testScalpingShadowCapabilityGateRejectsBeforeContractOrLayerResolution(
        ?ShadowExecutionCapability $capability,
        string $exchange,
        string $environment,
        string $reason,
    ): void {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage($reason);

        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.pullback.long', '9.9.9',
            $exchange, $environment, 'long', $capability,
        ));
    }

    /** @return iterable<string,array{?ShadowExecutionCapability,string,string,string}> */
    public static function rejectedScalpingCapabilityProvider(): iterable
    {
        yield 'missing capability' => [null, 'fake', 'test', 'scalping_shadow_capability_required'];
        yield 'private mainnet forbidden' => [ShadowExecutionCapability::PrivateMainnet, 'okx', 'mainnet', 'private_mainnet_execution_forbidden'];
        yield 'OKX backtest forbidden' => [ShadowExecutionCapability::Backtest, 'okx', 'demo', 'scalping_backtest_requires_fake_exchange'];
        yield 'Hyperliquid backtest forbidden' => [ShadowExecutionCapability::Backtest, 'hyperliquid', 'testnet', 'scalping_backtest_requires_fake_exchange'];
    }

    public function testScalpingBacktestResolvesOnlyAgainstFakeExchange(): void
    {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.pullback.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Backtest,
        ));

        self::assertSame('fake', $snapshot->payload()['exchange']['id']);
        self::assertSame('backtest', $snapshot->request->toArray()['execution_capability']);
        self::assertFalse($snapshot->payload()['environment']['write_enabled']);
    }

    /** @dataProvider microScalpingShadowTargetProvider */
    public function testMicroScalpingShadowResolvesOnlyPublicVenueLayers(
        string $setupId,
        string $side,
        string $exchange,
        string $environment,
    ): void {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'micro_scalping', '1.1.0', $setupId, '1.1.0',
            $exchange, $environment, $side, ShadowExecutionCapability::Paper,
        ));
        $payload = $snapshot->payload();

        self::assertSame(['base', 'mode', 'setup', 'exchange', 'mode_exchange', 'environment'], array_column($snapshot->orderedLayers(), 'type'));
        self::assertSame(10.0, $payload['exchange']['limits']['max_notional']);
        self::assertSame(2.0, $payload['mode']['leverage']['value']);
        self::assertSame(0.4, $payload['mode']['risk']['trade_budget']['value']);
        self::assertFalse($payload['environment']['write_enabled']);
        self::assertTrue($payload['environment']['dry_run']);
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function microScalpingShadowTargetProvider(): iterable
    {
        foreach ([
            ['micro_scalping.momentum_ofi.long', 'long'],
            ['micro_scalping.momentum_ofi.short', 'short'],
        ] as [$setupId, $side]) {
            yield $setupId . ' / OKX demo' => [$setupId, $side, 'okx', 'demo'];
            yield $setupId . ' / OKX mainnet read-only' => [$setupId, $side, 'okx', 'mainnet'];
            yield $setupId . ' / Hyperliquid testnet' => [$setupId, $side, 'hyperliquid', 'testnet'];
            yield $setupId . ' / Hyperliquid mainnet read-only' => [$setupId, $side, 'hyperliquid', 'mainnet'];
        }
    }

    #[DataProvider('microScalpingBacktestTargetProvider')]
    public function testMicroScalpingBacktestUsesAuthenticatedPublicVenueLayers(
        string $setupId,
        string $side,
        string $exchange,
        string $environment,
    ): void {
        $snapshot = (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'micro_scalping', '1.1.0', $setupId, '1.1.0',
            $exchange, $environment, $side, ShadowExecutionCapability::Backtest,
        ));

        self::assertSame(ShadowExecutionCapability::Backtest, $snapshot->request->capability);
        self::assertSame($exchange, $snapshot->payload()['exchange']['id']);
        self::assertFalse($snapshot->payload()['environment']['write_enabled']);
        self::assertTrue($snapshot->payload()['environment']['dry_run']);
    }

    /** @return iterable<string,array{string,string,string,string}> */
    public static function microScalpingBacktestTargetProvider(): iterable
    {
        yield 'long / OKX mainnet public replay' => ['micro_scalping.momentum_ofi.long', 'long', 'okx', 'mainnet'];
        yield 'short / Hyperliquid mainnet public replay' => ['micro_scalping.momentum_ofi.short', 'short', 'hyperliquid', 'mainnet'];
    }

    public function testMicroScalpingShadowRejectsPrivateMainnetAndHasNoFakeOverlay(): void
    {
        try {
            (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
                'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0',
                'okx', 'mainnet', 'long', ShadowExecutionCapability::PrivateMainnet,
            ));
            self::fail('Private mainnet execution was accepted.');
        } catch (TradingConfigException $exception) {
            self::assertSame('private_mainnet_execution_forbidden', $exception->getMessage());
        }

        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('Required trading config layer "mode_exchange" is missing');
        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'micro_scalping', '1.1.0', 'micro_scalping.momentum_ofi.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));
    }
}
