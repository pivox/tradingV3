<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxRestClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(OkxRestClient::class)]
#[CoversClass(OkxConfig::class)]
final class OkxRestClientTest extends TestCase
{
    public function testDemoPublicGetUsesEeaDemoBaseUriByDefault(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(environment: 'demo'),
            $this->fixedClock(),
        );

        $client->publicGet('/api/v5/public/instruments', ['instType' => 'SWAP']);

        self::assertIsArray($captured);
        self::assertSame('GET', $captured['method']);
        self::assertSame('https://eea.okx.com/api/v5/public/instruments?instType=SWAP', $captured['url']);
        self::assertSame(60.0, $captured['options']['timeout'] ?? null);
        self::assertSame(0, $captured['options']['max_duration'] ?? null);
        self::assertNull($this->header($captured['options'], 'OK-ACCESS-KEY'));
        self::assertNull($this->header($captured['options'], 'x-simulated-trading'));
    }

    public function testPublicGetRaisesExplicitRateLimitException(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"code":"50011","msg":"Too Many Requests","data":[]}',
            ['http_code' => 429],
        ));
        $client = new OkxRestClient(
            $http,
            new OkxConfig(environment: 'demo'),
            $this->fixedClock(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_public_rate_limited');

        $client->publicGet('/api/v5/market/ticker', ['instId' => 'BTC-USDT-SWAP']);
    }

    public function testDemoPrivateGetSignsRequestAndAddsSimulatedTradingHeader(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                simulatedTrading: true,
            ),
            $this->fixedClock(),
        );

        $client->privateGet('/api/v5/account/balance', ['ccy' => 'USDT']);

        self::assertIsArray($captured);
        self::assertSame('GET', $captured['method']);
        self::assertSame('https://eea.okx.com/api/v5/account/balance?ccy=USDT', $captured['url']);
        self::assertSame(2.0, $captured['options']['timeout'] ?? null);
        self::assertSame(2.0, $captured['options']['max_duration'] ?? null);
        self::assertSame('test-key', $this->header($captured['options'], 'OK-ACCESS-KEY'));
        self::assertSame('test-passphrase', $this->header($captured['options'], 'OK-ACCESS-PASSPHRASE'));
        self::assertSame('2026-01-01T00:00:00.000Z', $this->header($captured['options'], 'OK-ACCESS-TIMESTAMP'));
        self::assertSame('1', $this->header($captured['options'], 'x-simulated-trading'));
        self::assertSame(
            base64_encode(hash_hmac(
                'sha256',
                '2026-01-01T00:00:00.000ZGET/api/v5/account/balance?ccy=USDT',
                'test-secret',
                true,
            )),
            $this->header($captured['options'], 'OK-ACCESS-SIGN'),
        );
    }

    public function testExplicitCanonicalLiveBaseUriAllowsSignedPrivateRequest(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'live',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                apiBaseUri: 'https://www.okx.com',
                liveEnabled: true,
            ),
            $this->fixedClock(),
        );

        $client->privateGet('/api/v5/account/balance');

        self::assertIsArray($captured);
        self::assertSame('GET', $captured['method']);
        self::assertSame('https://www.okx.com/api/v5/account/balance', $captured['url']);
        self::assertNotNull($this->header($captured['options'], 'OK-ACCESS-SIGN'));
        self::assertNull($this->header($captured['options'], 'x-simulated-trading'));
    }

    public function testDemoPrivateGetRequiresSimulatedTradingFlagBeforeHttpCall(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                simulatedTrading: false,
            ),
            $this->fixedClock(),
        );

        try {
            $client->privateGet('/api/v5/account/balance', ['ccy' => 'USDT']);
            self::fail('Expected demo simulated trading guard to reject the private GET.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('OKX_SIMULATED_TRADING=1 is required for OKX demo private requests.', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testDemoPrivateGetRejectsProductionBaseUriBeforeHttpCall(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                apiBaseUri: 'https://www.okx.com',
                simulatedTrading: true,
            ),
            $this->fixedClock(),
        );

        try {
            $client->privateGet('/api/v5/account/balance', ['ccy' => 'USDT']);
            self::fail('Expected demo production URL guard to reject the private GET.');
        } catch (\RuntimeException $e) {
            self::assertSame('okx_private_rest_endpoint_not_allowed', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    /**
     * @return iterable<string,array{string,string,bool}>
     */
    public static function invalidPrivateRestBaseUris(): iterable
    {
        yield 'demo http' => ['demo', 'http://eea.okx.com', false];
        yield 'demo userinfo' => ['demo', 'https://user:secret@eea.okx.com', false];
        yield 'demo port' => ['demo', 'https://eea.okx.com:443', false];
        yield 'demo evil suffix' => ['demo', 'https://eea.okx.com.evil.test', false];
        yield 'demo subdomain' => ['demo', 'https://api.eea.okx.com', false];
        yield 'demo path' => ['demo', 'https://eea.okx.com/api', false];
        yield 'demo trailing slash' => ['demo', 'https://eea.okx.com/', false];
        yield 'demo query' => ['demo', 'https://eea.okx.com?target=evil', false];
        yield 'demo fragment' => ['demo', 'https://eea.okx.com#target', false];
        yield 'demo invalid parse' => ['demo', 'https://[eea.okx.com', false];
        yield 'live http' => ['live', 'http://www.okx.com', true];
        yield 'live userinfo' => ['live', 'https://user:secret@www.okx.com', true];
        yield 'live port' => ['live', 'https://www.okx.com:443', true];
        yield 'live evil suffix' => ['live', 'https://www.okx.com.evil.test', true];
        yield 'live subdomain' => ['live', 'https://api.www.okx.com', true];
        yield 'live path' => ['live', 'https://www.okx.com/api', true];
        yield 'live trailing slash' => ['live', 'https://www.okx.com/', true];
        yield 'live query' => ['live', 'https://www.okx.com?target=evil', true];
        yield 'live fragment' => ['live', 'https://www.okx.com#target', true];
        yield 'live invalid parse' => ['live', 'https://[www.okx.com', true];
    }

    #[DataProvider('invalidPrivateRestBaseUris')]
    public function testPrivateConfigurationRejectsEveryNonAllowlistedRestBaseUri(
        string $environment,
        string $apiBaseUri,
        bool $liveEnabled,
    ): void {
        $config = new OkxConfig(
            environment: $environment,
            apiKey: 'test-key',
            apiSecret: 'test-secret',
            apiPassphrase: 'test-passphrase',
            apiBaseUri: $apiBaseUri,
            simulatedTrading: true,
            liveEnabled: $liveEnabled,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_private_rest_endpoint_not_allowed');

        $config->assertPrivateConfigured();
    }

    public function testInvalidPrivateRestBaseUriIsRejectedBeforeHttpCallWithoutLeakingCredentials(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                apiBaseUri: 'https://test-key:test-secret@eea.okx.com',
                simulatedTrading: true,
            ),
            $this->fixedClock(),
        );

        try {
            $client->privateGet('/api/v5/account/balance');
            self::fail('Expected the private REST endpoint guard to reject the request.');
        } catch (\RuntimeException $e) {
            self::assertSame('okx_private_rest_endpoint_not_allowed', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testDemoPrivatePostRequiresExplicitTradingFlagBeforeHttpCall(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                simulatedTrading: true,
            ),
            $this->fixedClock(),
        );

        try {
            $client->privatePost('/api/v5/trade/order', ['instId' => 'BTC-USDT-SWAP']);
            self::fail('Expected demo trading guard to reject the private POST.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('demo trading is disabled', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testDemoPrivatePostSignsBodyAndAddsSimulatedTradingHeaderWhenEnabled(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'options' => $options];

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'demo',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
                simulatedTrading: true,
                demoTradingEnabled: true,
            ),
            $this->fixedClock(),
        );
        $body = ['instId' => 'BTC-USDT-SWAP', 'side' => 'buy'];

        $client->privatePost('/api/v5/trade/order', $body);

        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://eea.okx.com/api/v5/trade/order', $captured['url']);
        self::assertSame(60.0, $captured['options']['timeout'] ?? null);
        self::assertSame(0, $captured['options']['max_duration'] ?? null);
        self::assertSame('{"instId":"BTC-USDT-SWAP","side":"buy"}', $captured['options']['body'] ?? null);
        self::assertSame('1', $this->header($captured['options'], 'x-simulated-trading'));
        self::assertSame(
            base64_encode(hash_hmac(
                'sha256',
                '2026-01-01T00:00:00.000ZPOST/api/v5/trade/order{"instId":"BTC-USDT-SWAP","side":"buy"}',
                'test-secret',
                true,
            )),
            $this->header($captured['options'], 'OK-ACCESS-SIGN'),
        );
    }

    public function testLivePrivateGetRequiresExplicitLiveFlagBeforeHttpCall(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'live',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
            ),
            $this->fixedClock(),
        );

        try {
            $client->privateGet('/api/v5/account/balance');
            self::fail('Expected live guard to reject the private GET.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('live trading is disabled', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    public function testLivePrivatePostRequiresExplicitLiveFlagBeforeHttpCall(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient(
            $http,
            new OkxConfig(
                environment: 'live',
                apiKey: 'test-key',
                apiSecret: 'test-secret',
                apiPassphrase: 'test-passphrase',
            ),
            $this->fixedClock(),
        );

        try {
            $client->privatePost('/api/v5/trade/order', ['instId' => 'BTC-USDT-SWAP']);
            self::fail('Expected live guard to reject the private POST.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('live trading is disabled', $e->getMessage());
        }

        self::assertSame(0, $requests);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function header(array $options, string $name): ?string
    {
        $needle = strtolower($name);
        foreach (($options['headers'] ?? []) as $key => $value) {
            if (\is_string($key) && strtolower($key) === $needle) {
                return \is_array($value) ? (string)($value[0] ?? '') : (string)$value;
            }
            if (\is_string($value) && str_starts_with(strtolower($value), $needle . ':')) {
                return trim(substr($value, strlen($name) + 1));
            }
        }

        return null;
    }

    private function fixedClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}
