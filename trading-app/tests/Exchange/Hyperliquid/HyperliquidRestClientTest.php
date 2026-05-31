<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Hyperliquid;

use App\Exchange\Hyperliquid\HyperliquidConfig;
use App\Exchange\Hyperliquid\HyperliquidRestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HyperliquidRestClient::class)]
#[CoversClass(HyperliquidConfig::class)]
final class HyperliquidRestClientTest extends TestCase
{
    public function testInfoPostsToConfiguredInfoEndpoint(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"universe":[{"name":"BTC"}]}');
        });
        $client = new HyperliquidRestClient(
            $http,
            new HyperliquidConfig(apiBaseUri: 'https://example.test/hyperliquid'),
        );

        $payload = $client->info(['type' => 'meta']);

        self::assertSame(['universe' => [['name' => 'BTC']]], $payload);
        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://example.test/hyperliquid/info', $captured['url']);
        self::assertSame('{"type":"meta"}', $captured['options']['body'] ?? null);
    }

    public function testExchangeRequiresTradingCredentialsBeforeSigning(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $client = new HyperliquidRestClient(
            $http,
            new HyperliquidConfig(accountAddress: '0x0000000000000000000000000000000000000001'),
        );

        try {
            $client->exchange(['type' => 'order']);
            self::fail('Expected Hyperliquid exchange guard to reject missing private key.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HYPERLIQUID_PRIVATE_KEY is required', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testDefaultExchangeClientDoesNotSignOrLeakPrivateKey(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $client = new HyperliquidRestClient(
            $http,
            new HyperliquidConfig(
                accountAddress: '0x0000000000000000000000000000000000000001',
                privateKey: 'super-secret-private-key',
            ),
        );

        try {
            $client->exchange(['type' => 'order']);
            self::fail('Expected default Hyperliquid exchange client to reject unsigned actions.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exchange signing is not enabled', $e->getMessage());
            self::assertStringNotContainsString('super-secret-private-key', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }
}
