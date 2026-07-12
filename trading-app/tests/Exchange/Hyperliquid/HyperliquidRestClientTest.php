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
    public function testPhpConfigurationHasNoAgentPrivateKeyCustody(): void
    {
        self::assertFalse(property_exists(HyperliquidConfig::class, 'testnetAgentPrivateKey'));
        self::assertFalse(interface_exists('App\\Exchange\\Hyperliquid\\HyperliquidSignatureBackendInterface'));
        self::assertStringNotContainsString(
            'HYPERLIQUID_TESTNET_AGENT_PRIVATE_KEY',
            file_get_contents(__DIR__ . '/../../../config/services.yaml') ?: '',
        );
    }

    public function testInfoPostsToConfiguredInfoEndpoint(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"universe":[{"name":"BTC"}]}');
        });
        $client = new HyperliquidRestClient(
            $http,
            $this->testnetConfig(),
        );

        $payload = $client->info(['type' => 'meta']);

        self::assertSame(['universe' => [['name' => 'BTC']]], $payload);
        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://api.hyperliquid-testnet.xyz/info', $captured['url']);
        self::assertSame('{"type":"meta"}', $captured['options']['body'] ?? null);
        self::assertSame(5.0, $captured['options']['timeout']);
        self::assertSame(5.0, $captured['options']['max_duration']);
        self::assertSame(0, $captured['options']['max_redirects']);
        self::assertNull($captured['options']['proxy']);
        self::assertSame('*', $captured['options']['no_proxy']);
    }

    public function testInfoAllowsGuardedOfficialMainnetEndpoint(): void
    {
        $capturedUrl = null;
        $client = new HyperliquidRestClient(
            new MockHttpClient(function (string $method, string $url) use (&$capturedUrl): MockResponse {
                $capturedUrl = $url;

                return new MockResponse('[]');
            }),
            new HyperliquidConfig(
                environment: 'mainnet',
                apiBaseUri: 'https://api.hyperliquid.xyz',
                network: 'mainnet',
                mainnetEnabled: true,
            ),
        );

        self::assertSame([], $client->info(['type' => 'meta']));
        self::assertSame('https://api.hyperliquid.xyz/info', $capturedUrl);
    }

    public function testInfoRejectsUnofficialOrEnvironmentMismatchedEndpointBeforeRequest(): void
    {
        foreach ([
            new HyperliquidConfig(environment: 'testnet', network: 'testnet', apiBaseUri: 'https://example.test'),
            new HyperliquidConfig(environment: 'testnet', network: 'testnet', apiBaseUri: 'https://api.hyperliquid.xyz'),
            new HyperliquidConfig(environment: 'mainnet', network: 'mainnet', apiBaseUri: 'https://api.hyperliquid.xyz', mainnetEnabled: false),
        ] as $config) {
            $requests = 0;
            $client = new HyperliquidRestClient(
                new MockHttpClient(function () use (&$requests): MockResponse {
                    ++$requests;

                    return new MockResponse('[]');
                }),
                $config,
            );

            try {
                $client->info(['type' => 'meta']);
                self::fail('Expected unofficial or unguarded endpoint to fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('hyperliquid_info_endpoint_not_allowed', $exception->getMessage());
                self::assertSame(0, $requests);
            }
        }
    }

    public function testReadinessInfoRequiresExactTestnetEndpoint(): void
    {
        $requests = 0;
        $client = new HyperliquidRestClient(
            new MockHttpClient(function () use (&$requests): MockResponse {
                ++$requests;

                return new MockResponse('[]');
            }),
            new HyperliquidConfig(environment: 'testnet', network: 'testnet', apiBaseUri: 'https://api.hyperliquid-testnet.xyz.attacker.invalid'),
        );

        $this->expectExceptionMessage('hyperliquid_readiness_testnet_endpoint_required');
        try {
            $client->readinessInfo(['type' => 'extraAgents']);
        } finally {
            self::assertSame(0, $requests);
        }
    }

    public function testInfoRejectsNonSuccessfulJsonWithoutLeakingBody(): void
    {
        $client = new HyperliquidRestClient(
            new MockHttpClient(new MockResponse('{"token":"must-not-leak"}', ['http_code' => 503])),
            $this->testnetConfig(),
        );

        try {
            $client->info(['type' => 'meta']);
            self::fail('Expected non-success response to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_info_http_status_503', $exception->getMessage());
            self::assertStringNotContainsString('must-not-leak', $exception->getMessage());
        }
    }

    public function testInfoRejectsResponseLargerThan64KiB(): void
    {
        $client = new HyperliquidRestClient(
            new MockHttpClient(new MockResponse(str_repeat('x', 65_537))),
            $this->testnetConfig(),
        );

        $this->expectExceptionMessage('hyperliquid_info_response_too_large');
        $client->info(['type' => 'meta']);
    }

    public function testInfoRejectsTimeoutOrTransportFailure(): void
    {
        $client = new HyperliquidRestClient(
            new MockHttpClient(new MockResponse('', ['error' => 'Operation timed out token=must-not-leak'])),
            $this->testnetConfig(),
        );

        $this->expectExceptionMessage('hyperliquid_info_transport_failed');
        $client->info(['type' => 'meta']);
    }

    public function testExchangeRejectsBecausePhpSigningIsDisabled(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{}');
        });
        $client = new HyperliquidRestClient(
            $http,
            new HyperliquidConfig(
                environment: 'testnet',
                network: 'testnet',
                testnetAgentAddress: '0x0000000000000000000000000000000000000002',
                testnetAccountAddress: '0x0000000000000000000000000000000000000001',
            ),
        );

        try {
            $client->exchange(['type' => 'order']);
            self::fail('Expected Hyperliquid exchange signing to remain disabled.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exchange signing is not enabled', $e->getMessage());
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
                environment: 'testnet',
                network: 'testnet',
                testnetAgentAddress: '0x0000000000000000000000000000000000000002',
                testnetAccountAddress: '0x0000000000000000000000000000000000000001',
            ),
        );

        try {
            $client->exchange(['type' => 'order']);
            self::fail('Expected default Hyperliquid exchange client to reject unsigned actions.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('exchange signing is not enabled', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    private function testnetConfig(): HyperliquidConfig
    {
        return new HyperliquidConfig(
            environment: 'testnet',
            apiBaseUri: 'https://api.hyperliquid-testnet.xyz',
            network: 'testnet',
        );
    }
}
