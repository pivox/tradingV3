<?php

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\MtfValidationConfigProvider;
use App\Config\TradeEntryConfigProvider;
use App\TradingCore\Config\Exception\TradingConfigException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

#[CoversClass(TradeEntryConfigProvider::class)]
#[CoversClass(MtfValidationConfigProvider::class)]
final class ModernConfigLegacyQuarantineTest extends TestCase
{
    /** @dataProvider modernIdProvider */
    public function testModernIdsNeverOpenLegacyTradeEntryYaml(string $modeId): void
    {
        $provider = new TradeEntryConfigProvider(new ParameterBag(['kernel.project_dir' => '/definitely/not/a/project', 'mode' => []]));
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('canonical snapshot');
        $provider->getConfigForMode($modeId);
    }

    /** @dataProvider modernIdProvider */
    public function testModernIdsNeverOpenLegacyMtfYaml(string $modeId): void
    {
        $provider = new MtfValidationConfigProvider(new ParameterBag(['kernel.project_dir' => '/definitely/not/a/project', 'mode' => []]));
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('canonical snapshot');
        $provider->getConfigForMode($modeId);
    }

    /** @return iterable<string,array{string}> */
    public static function modernIdProvider(): iterable
    {
        yield 'day trading' => ['day_trading'];
        yield 'scalping' => ['scalping'];
        yield 'micro scalping' => ['micro_scalping'];
    }
}
