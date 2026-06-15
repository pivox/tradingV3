<?php

declare(strict_types=1);

namespace App\Tests\TradingCore\Config;

use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Config\TradingConfigLayer;
use App\TradingCore\Config\TradingConfigLayerLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversClass(EffectiveTradingConfigResolver::class)]
#[CoversClass(TradingConfigLayer::class)]
#[CoversClass(TradingConfigLayerLoader::class)]
#[CoversClass(TradingConfigException::class)]
final class EffectiveTradingConfigResolverTest extends TestCase
{
    private string $configRoot;

    protected function setUp(): void
    {
        $this->configRoot = sys_get_temp_dir() . '/trading-config-' . bin2hex(random_bytes(6));
        mkdir($this->configRoot . '/mode', 0777, true);
        mkdir($this->configRoot . '/exchange', 0777, true);
        mkdir($this->configRoot . '/mode_exchange', 0777, true);
        mkdir($this->configRoot . '/env', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->configRoot);
    }

    public function testMergesBaseAndModeLayers(): void
    {
        $this->writeYaml('base.yaml', <<<YAML
trading:
  market_type: perpetual
  risk:
    max_leverage: 3
  execution:
    dry_run: true
YAML);
        $this->writeYaml('mode/scalper.yaml', <<<YAML
trading:
  profile: scalper
  risk:
    risk_pct_percent: 2.5
YAML);

        $resolved = $this->resolver()->resolve('scalper', 'missing-exchange', 'missing-env');

        self::assertSame('scalper', $resolved['config']['trading']['profile']);
        self::assertSame('perpetual', $resolved['config']['trading']['market_type']);
        self::assertSame(3, $resolved['config']['trading']['risk']['max_leverage']);
        self::assertSame(2.5, $resolved['config']['trading']['risk']['risk_pct_percent']);
        self::assertSame(['base', 'mode'], array_column($resolved['layers'], 'type'));
    }

    public function testMergesBaseModeAndExchangeLayers(): void
    {
        $this->writeYaml('base.yaml', <<<YAML
trading:
  execution:
    dry_run: true
  fees:
    maker_rate: 0.0002
YAML);
        $this->writeYaml('mode/regular.yaml', <<<YAML
trading:
  profile: regular
YAML);
        $this->writeYaml('exchange/okx.yaml', <<<YAML
trading:
  exchange: okx
  fees:
    taker_rate: 0.0005
YAML);

        $resolved = $this->resolver()->resolve('regular', 'okx', 'missing-env');

        self::assertSame('okx', $resolved['config']['trading']['exchange']);
        self::assertSame(0.0002, $resolved['config']['trading']['fees']['maker_rate']);
        self::assertSame(0.0005, $resolved['config']['trading']['fees']['taker_rate']);
        self::assertSame(['base', 'mode', 'exchange'], array_column($resolved['layers'], 'type'));
    }

    public function testMergesAllLayersWithDeterministicOverridePriority(): void
    {
        $this->writeYaml('base.yaml', <<<YAML
trading:
  profile: base
  exchange: bitmart
  execution:
    dry_run: true
    live_enabled: false
  risk:
    max_leverage: 2
    risk_pct_percent: 1
YAML);
        $this->writeYaml('mode/scalper.yaml', <<<YAML
trading:
  profile: scalper
  risk:
    max_leverage: 4
YAML);
        $this->writeYaml('exchange/okx.yaml', <<<YAML
trading:
  exchange: okx
  risk:
    max_leverage: 5
YAML);
        $this->writeYaml('mode_exchange/scalper.okx.yaml', <<<YAML
trading:
  risk:
    max_leverage: 6
YAML);
        $this->writeYaml('env/dev.yaml', <<<YAML
trading:
  env: dev
  execution:
    dry_run: true
  risk:
    risk_pct_percent: 0.5
YAML);

        $resolved = $this->resolver()->resolve('scalper', 'okx', 'dev');

        self::assertSame('scalper', $resolved['config']['trading']['profile']);
        self::assertSame('okx', $resolved['config']['trading']['exchange']);
        self::assertSame('dev', $resolved['config']['trading']['env']);
        self::assertSame(6, $resolved['config']['trading']['risk']['max_leverage']);
        self::assertSame(0.5, $resolved['config']['trading']['risk']['risk_pct_percent']);
        self::assertTrue($resolved['config']['trading']['execution']['dry_run']);
        self::assertFalse($resolved['config']['trading']['execution']['live_enabled']);
        self::assertSame(['base', 'mode', 'exchange', 'mode_exchange', 'env'], array_column($resolved['layers'], 'type'));
    }

    public function testThrowsClearErrorWhenBaseLayerIsMissing(): void
    {
        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('Required trading config layer "base" is missing');

        $this->resolver()->resolve('scalper', 'okx', 'dev');
    }

    public function testIgnoresMissingOptionalLayers(): void
    {
        $this->writeYaml('base.yaml', <<<YAML
trading:
  execution:
    dry_run: true
YAML);

        $resolved = $this->resolver()->resolve('scalper_micro', 'fake', 'prod');

        self::assertSame(['base'], array_column($resolved['layers'], 'type'));
        self::assertSame(['mode', 'exchange', 'mode_exchange', 'env'], array_column($resolved['missing_optional_layers'], 'type'));
    }

    public function testRejectsNonMappingYamlFiles(): void
    {
        $this->writeYaml('base.yaml', '- not-a-map');

        $this->expectException(TradingConfigException::class);
        $this->expectExceptionMessage('must contain a YAML mapping');

        $this->resolver()->resolve('regular', 'fake', 'dev');
    }

    private function resolver(): EffectiveTradingConfigResolver
    {
        return new EffectiveTradingConfigResolver(new TradingConfigLayerLoader($this->configRoot));
    }

    private function writeYaml(string $relativePath, string $contents): void
    {
        $path = $this->configRoot . '/' . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents . "\n");
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path . '/' . $entry;
            if (is_dir($entryPath)) {
                $this->removeDirectory($entryPath);
                continue;
            }

            unlink($entryPath);
        }

        rmdir($path);
    }
}
