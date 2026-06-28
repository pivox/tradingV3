<?php

declare(strict_types=1);

namespace App\Tests\Trading\Controller\Api;

use App\Trading\Controller\Api\EffectiveTradingConfigApiController;
use App\TradingCore\Config\EffectiveTradingConfigReadService;
use App\TradingCore\Config\EffectiveTradingConfigResolver;
use App\TradingCore\Config\Exception\TradingConfigException;
use App\TradingCore\Config\TradingConfigLayer;
use App\TradingCore\Config\TradingConfigLayerLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(EffectiveTradingConfigApiController::class)]
#[CoversClass(EffectiveTradingConfigReadService::class)]
final class EffectiveTradingConfigApiControllerTest extends TestCase
{
    private string $configRoot;

    protected function setUp(): void
    {
        $this->configRoot = sys_get_temp_dir() . '/effective-config-api-' . bin2hex(random_bytes(6));
        mkdir($this->configRoot . '/mode', 0777, true);
        mkdir($this->configRoot . '/exchange', 0777, true);
        mkdir($this->configRoot . '/mode_exchange', 0777, true);
        mkdir($this->configRoot . '/env', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->configRoot);
    }

    public function testMissingQueryParametersReturnStructured400(): void
    {
        $response = $this->controller()->effective(new Request(['mode' => 'scalper']));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('missing_query_parameter', $body['error']['code']);
        self::assertSame(['exchange', 'env'], $body['error']['missing']);
    }

    public function testInvalidLayerNameReturnsStructured400(): void
    {
        $this->writeYaml('base.yaml', "trading:\n  execution:\n    dry_run: true\n");

        $response = $this->controller()->effective(new Request([
            'mode' => '../../services',
            'exchange' => 'okx',
            'env' => 'demo',
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('invalid_config_request', $body['error']['code']);
        self::assertStringContainsString('Invalid trading config layer name', $body['error']['message']);
    }

    public function testRejectsUnsupportedExchangeEnvironmentPair(): void
    {
        $this->writeYaml('base.yaml', "trading:\n  execution:\n    dry_run: true\n");
        $this->writeYaml('exchange/okx.yaml', "trading:\n  exchange: okx\n  environment: demo\n");
        $this->writeYaml('env/testnet.yaml', "trading:\n  environment: testnet\n");

        $response = $this->controller()->effective(new Request([
            'mode' => 'scalper',
            'exchange' => 'okx',
            'env' => 'testnet',
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('invalid_config_request', $body['error']['code']);
        self::assertStringContainsString('Unsupported exchange/env pair', $body['error']['message']);
    }

    public function testRejectsUnknownExchangeEvenWhenEnvironmentLayerExists(): void
    {
        $this->writeYaml('base.yaml', "trading:\n  exchange: fake\n  execution:\n    dry_run: true\n");
        $this->writeYaml('env/testnet.yaml', "trading:\n  environment: testnet\n");

        $response = $this->controller()->effective(new Request([
            'mode' => 'scalper',
            'exchange' => 'okxx',
            'env' => 'testnet',
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('invalid_config_request', $body['error']['code']);
        self::assertStringContainsString('Unsupported exchange/env pair', $body['error']['message']);
    }

    public function testRejectsBitmartDemoBecauseCommon002ApiIsOnlyForDemoTestnetTargets(): void
    {
        $this->writeYaml('base.yaml', "trading:\n  exchange: fake\n  execution:\n    dry_run: true\n");
        $this->writeYaml('exchange/bitmart.yaml', "trading:\n  exchange: bitmart\n  exchange_status: legacy_runtime_only\n");
        $this->writeYaml('env/demo.yaml', "trading:\n  environment: demo\n");

        $response = $this->controller()->effective(new Request([
            'mode' => 'scalper',
            'exchange' => 'bitmart',
            'env' => 'demo',
        ]));

        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('invalid_config_request', $body['error']['code']);
        self::assertStringContainsString('Unsupported exchange/env pair', $body['error']['message']);
    }

    public function testReturnsEffectiveConfigWithHashAndProvenance(): void
    {
        $this->writeYaml('base.yaml', <<<YAML
trading:
  exchange: fake
  market_type: perpetual
  execution:
    dry_run: true
    mainnet_write_enabled: false
YAML);
        $this->writeYaml('mode/scalper.yaml', <<<YAML
trading:
  profile: scalper
YAML);
        $this->writeYaml('exchange/okx.yaml', <<<YAML
trading:
  exchange: okx
  environment: demo
  execution:
    demo_testnet_write_enabled: false
YAML);
        $this->writeYaml('env/demo.yaml', <<<YAML
trading:
  execution:
    kill_switch_enabled: true
YAML);

        $response = $this->controller()->effective(new Request([
            'mode' => 'scalper',
            'exchange' => 'okx',
            'env' => 'demo',
        ]));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('scalper', $body['request']['mode']);
        self::assertSame('okx', $body['request']['exchange']);
        self::assertSame('demo', $body['request']['env']);
        self::assertSame('okx', $body['config']['trading']['exchange']);
        self::assertFalse($body['config']['trading']['execution']['mainnet_write_enabled']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $body['config_hash']);
        self::assertSame('exchange', $body['provenance']['trading.exchange']['type']);
        self::assertSame('env', $body['provenance']['trading.execution.kill_switch_enabled']['type']);
        self::assertStringNotContainsString('SECRET', (string) $response->getContent());
        self::assertStringNotContainsString('PRIVATE_KEY', (string) $response->getContent());
    }

    private function controller(): EffectiveTradingConfigApiController
    {
        $controller = new EffectiveTradingConfigApiController(
            new EffectiveTradingConfigReadService(
                new EffectiveTradingConfigResolver(new TradingConfigLayerLoader($this->configRoot)),
            ),
        );

        $controller->setContainer(new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                throw new \RuntimeException('not available: ' . $id);
            }

            public function has(string $id): bool
            {
                return false;
            }
        });

        return $controller;
    }

    /**
     * @return array<string,mixed>
     */
    private function json(Response $response): array
    {
        return json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
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
