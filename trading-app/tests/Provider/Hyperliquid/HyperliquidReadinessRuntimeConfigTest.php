<?php

declare(strict_types=1);

namespace App\Tests\Provider\Hyperliquid;

use App\Provider\Hyperliquid\EffectiveTradingHyperliquidMutationReadinessConfigSource;
use App\Provider\Hyperliquid\FailClosedHyperliquidReconciliationStatus;
use App\TradingCore\Config\EffectiveTradingConfigReadService;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\TradingConfigLayerLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FailClosedHyperliquidReconciliationStatus::class)]
#[CoversClass(EffectiveTradingHyperliquidMutationReadinessConfigSource::class)]
final class HyperliquidReadinessRuntimeConfigTest extends TestCase
{
    public function testProductionReconciliationStatusIsAlwaysFailClosed(): void
    {
        self::assertTrue((new FailClosedHyperliquidReconciliationStatus())->isInFlight());
    }

    public function testReadsSafetyInputsFromDeterministicEffectiveTradingProfile(): void
    {
        $config = $this->source('scalper_micro')->current();

        self::assertSame(['BTCUSDT'], $config->allowedSymbols);
        self::assertSame(['perpetual'], $config->allowedMarkets);
        self::assertSame(10.0, $config->maxNotional);
        self::assertTrue($config->killSwitchEnabled);
        self::assertNotSame('', $config->configHash);
    }

    public function testMissingDeterministicProfileFailsClosed(): void
    {
        $config = $this->source('')->current();

        self::assertSame([], $config->allowedSymbols);
        self::assertSame([], $config->allowedMarkets);
        self::assertNull($config->maxNotional);
        self::assertTrue($config->killSwitchEnabled);
        self::assertNull($config->configHash);
    }

    private function source(string $profile): EffectiveTradingHyperliquidMutationReadinessConfigSource
    {
        $root = dirname(__DIR__, 3) . '/config/trading';

        return new EffectiveTradingHyperliquidMutationReadinessConfigSource(
            new EffectiveTradingConfigReadService(
                new EffectiveTradingConfigResolver(new TradingConfigLayerLoader($root)),
            ),
            $profile,
        );
    }
}
