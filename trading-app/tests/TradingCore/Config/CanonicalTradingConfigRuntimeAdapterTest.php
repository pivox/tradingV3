<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\CanonicalTradingConfigRuntimeAdapter;
use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolverInterface;
use App\TradingCore\Config\EffectiveTradingConfigSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CanonicalTradingConfigRuntimeAdapter::class)]
final class CanonicalTradingConfigRuntimeAdapterTest extends TestCase
{
    public function testMtfAndTradeEntryReceiveTheSameImmutableSnapshotAndHash(): void
    {
        $request = new EffectiveTradingConfigRequest('scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', 'fake', 'test', 'long');
        $snapshot = new EffectiveTradingConfigSnapshot(
            $request, ['canonical' => true], 'sha256:' . str_repeat('b', 64), 'sha256:' . str_repeat('c', 64), [], [],
        );
        $resolver = new class($snapshot) implements EffectiveTradingConfigResolverInterface {
            public function __construct(private readonly EffectiveTradingConfigSnapshot $snapshot) {}
            public function resolve(EffectiveTradingConfigRequest|string $request): EffectiveTradingConfigSnapshot { return $this->snapshot; }
        };
        $adapter = new CanonicalTradingConfigRuntimeAdapter($resolver);

        self::assertSame($snapshot, $adapter->forMtf($request));
        self::assertSame($snapshot, $adapter->forTradeEntry($request));
        self::assertSame($adapter->forMtf($request)->configHash, $adapter->forTradeEntry($request)->configHash);
    }
}
