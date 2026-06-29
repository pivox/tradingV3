<?php

declare(strict_types=1);

namespace App\Tests\Exchange\Okx;

use App\Exchange\Okx\OkxConfig;
use App\Exchange\Okx\OkxRestClient;
use PHPUnit\Framework\Attributes\CoversClass;
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
            ),
            $this->fixedClock(),
        );

        $client->privateGet('/api/v5/account/balance', ['ccy' => 'USDT']);

        self::assertIsArray($captured);
        self::assertSame('GET', $captured['method']);
        self::assertSame('https://eea.okx.com/api/v5/account/balance?ccy=USDT', $captured['url']);
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
                demoTradingEnabled: true,
            ),
            $this->fixedClock(),
        );
        $body = ['instId' => 'BTC-USDT-SWAP', 'side' => 'buy'];

        $client->privatePost('/api/v5/trade/order', $body);

        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://eea.okx.com/api/v5/trade/order', $captured['url']);
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
