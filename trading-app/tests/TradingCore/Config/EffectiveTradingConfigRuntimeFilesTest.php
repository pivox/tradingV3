<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\TradingConfigLayerLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveTradingConfigResolver::class)]
#[CoversClass(TradingConfigLayerLoader::class)]
final class EffectiveTradingConfigRuntimeFilesTest extends TestCase
{
    public function testOkxDemoScalperConfigIsSafeAndAuditable(): void
    {
        $resolved = $this->resolver()->resolve('scalper', 'okx', 'demo');
        $trading = $resolved['config']['trading'];

        self::assertSame('okx', $trading['exchange']);
        self::assertArrayHasKey('environment', $trading);
        self::assertSame('demo', $trading['environment']);
        self::assertSame('perpetual', $trading['market_type']);
        self::assertTrue($trading['execution']['dry_run']);
        self::assertFalse($trading['execution']['mainnet_write_enabled']);
        self::assertFalse($trading['execution']['demo_testnet_write_enabled']);
        self::assertTrue($trading['execution']['kill_switch_enabled']);
        self::assertTrue($trading['execution']['require_stop_loss']);
        self::assertNotSame([], $trading['execution']['allowed_symbols']);
        self::assertNotSame([], $trading['execution']['allowed_markets']);
        self::assertIsFloat($trading['execution']['max_notional']);
        self::assertGreaterThan(0.0, $trading['execution']['max_notional']);
        self::assertIsInt($trading['risk']['max_leverage_cap']);
        self::assertGreaterThan(0, $trading['risk']['max_leverage_cap']);
        self::assertSame('demo', $trading['account_mode']);
        self::assertArrayHasKey('rate_limit_profile', $trading);
        self::assertArrayHasKey('retry_policy', $trading);
        self::assertContains('mode_exchange', array_column($resolved['layers'], 'type'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $resolved['config_hash']);
    }

    public function testHyperliquidTestnetScalperConfigIsSafeAndAuditable(): void
    {
        $resolved = $this->resolver()->resolve('scalper', 'hyperliquid', 'testnet');
        $trading = $resolved['config']['trading'];

        self::assertSame('hyperliquid', $trading['exchange']);
        self::assertArrayHasKey('environment', $trading);
        self::assertSame('testnet', $trading['environment']);
        self::assertSame('perpetual', $trading['market_type']);
        self::assertTrue($trading['execution']['dry_run']);
        self::assertFalse($trading['execution']['mainnet_write_enabled']);
        self::assertFalse($trading['execution']['demo_testnet_write_enabled']);
        self::assertTrue($trading['execution']['kill_switch_enabled']);
        self::assertTrue($trading['execution']['require_stop_loss']);
        self::assertNotSame([], $trading['execution']['allowed_symbols']);
        self::assertNotSame([], $trading['execution']['allowed_markets']);
        self::assertIsFloat($trading['execution']['max_notional']);
        self::assertGreaterThan(0.0, $trading['execution']['max_notional']);
        self::assertIsInt($trading['risk']['max_leverage_cap']);
        self::assertGreaterThan(0, $trading['risk']['max_leverage_cap']);
        self::assertSame('cross', $trading['margin_mode']);
        self::assertArrayHasKey('rate_limit_profile', $trading);
        self::assertArrayHasKey('retry_policy', $trading);
        self::assertContains('mode_exchange', array_column($resolved['layers'], 'type'));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $resolved['config_hash']);
    }

    public function testBitmartLegacyEffectiveConfigRemainsLegacyRuntimeOnly(): void
    {
        $resolved = $this->resolver()->resolve('scalper', 'bitmart', 'prod');
        $trading = $resolved['config']['trading'];

        self::assertSame('bitmart', $trading['exchange']);
        self::assertSame('legacy_runtime_only', $trading['exchange_status']);
        self::assertTrue($trading['execution']['dry_run']);
        self::assertFalse($trading['execution']['live_enabled']);
        self::assertTrue($resolved['config']['compatibility']['legacy_runtime_dependency']);
    }

    private function resolver(): EffectiveTradingConfigResolver
    {
        return new EffectiveTradingConfigResolver(new TradingConfigLayerLoader());
    }
}
