<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Execution\Enum\ShadowExecutionCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

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

    public function testResolutionLogContainsOnlyLayerCountInsteadOfLayerDocuments(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array<string,mixed>> */
            public array $contexts = [];
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        (new EffectiveTradingConfigResolver(logger: $logger))->resolve(new EffectiveTradingConfigRequest(
            'day_trading', '1.1.0', 'day_trading.trend_continuation.long', '1.1.0',
            'fake', 'test', 'long', ShadowExecutionCapability::Fake,
        ));

        self::assertCount(1, $logger->contexts);
        self::assertSame(6, $logger->contexts[0]['layer_count']);
        self::assertArrayNotHasKey('layers', $logger->contexts[0]);
        self::assertArrayNotHasKey('config', $logger->contexts[0]);
    }

    public function testExactRequestReusesTheImmutableSnapshotWithinTheProcess(): void
    {
        $resolver = new EffectiveTradingConfigResolver();
        $request = new EffectiveTradingConfigRequest(
            'scalping', '1.1.0', 'scalping.trend_continuation.long', '1.1.0',
            'hyperliquid', 'mainnet', 'long', ShadowExecutionCapability::Paper,
        );

        self::assertSame($resolver->resolve($request), $resolver->resolve($request));
    }
}
