<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Exception\NonExecutableTradingConfigException;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveTradingConfigResolver::class)]
final class EffectiveTradingConfigRuntimeFilesTest extends TestCase
{
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
}
