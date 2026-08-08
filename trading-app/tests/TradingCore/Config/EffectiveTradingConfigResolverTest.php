<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Exception\TradingConfigException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveTradingConfigResolver::class)]
final class EffectiveTradingConfigResolverTest extends TestCase
{
    public function testPublishedDraftContractsRejectEffectiveRuntimeResolutionBeforeLegacyLayersLoad(): void
    {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('not executable');

        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'okx', 'demo', 'long',
        ));
    }

    public function testBlockedMicroSetupRejectsEffectiveRuntimeResolution(): void
    {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('not executable');

        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'micro_scalping', '1.0.0', 'micro_scalping.momentum_ofi.long', '1.0.0', 'hyperliquid', 'testnet', 'long',
        ));
    }

    public function testCrashDecisionWithNoCompatibleModernModeRejectsBeforeStatusFallback(): void
    {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('not listed by mode');

        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.0.0', 'crash_short', '1.1.0', 'fake', 'test', 'short',
        ));
    }

    public function testSetupSideMustMatchRequestExactly(): void
    {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('side');

        (new EffectiveTradingConfigResolver())->resolve(new EffectiveTradingConfigRequest(
            'scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'short',
        ));
    }

    public function testLegacyResolveSignatureFailsClosed(): void
    {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('EffectiveTradingConfigRequest');

        (new EffectiveTradingConfigResolver())->resolve('scalper');
    }
}
