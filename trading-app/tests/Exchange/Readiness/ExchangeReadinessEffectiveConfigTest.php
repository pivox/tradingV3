<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Readiness;

use App\TradingCore\Config\EffectiveTradingConfigRequest;
use App\TradingCore\Config\Exception\TradingConfigException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveTradingConfigRequest::class)]
final class ExchangeReadinessEffectiveConfigTest extends TestCase
{
    /** @dataProvider forbiddenPairProvider */
    public function testCrossEnvironmentAndBitmartRequestsFailBeforeReadiness(string $exchange, string $environment): void
    {
        $this->expectException(TradingConfigException::class);
        new EffectiveTradingConfigRequest(
            'scalping', '1.0.0', 'scalping.pullback.long', '1.0.0', $exchange, $environment, 'long',
        );
    }

    /** @return iterable<string,array{string,string}> */
    public static function forbiddenPairProvider(): iterable
    {
        yield 'OKX testnet' => ['okx', 'testnet'];
        yield 'Hyperliquid demo' => ['hyperliquid', 'demo'];
        yield 'BitMart' => ['bitmart', 'demo'];
    }
}
