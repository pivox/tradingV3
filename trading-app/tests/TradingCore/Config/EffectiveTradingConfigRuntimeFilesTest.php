<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Exception\NonExecutableTradingConfigException;
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
}
