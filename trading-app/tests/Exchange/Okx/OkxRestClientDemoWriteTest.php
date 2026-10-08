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
final class OkxRestClientDemoWriteTest extends TestCase
{
    public function testDemoPrivatePostSendsSimulatedTradingHeaderToDemoBaseUri(): void
    {
        $captured = null;
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'] ?? []];

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = new OkxRestClient($http, $this->config(), $this->clock());

        $client->privatePost('/api/v5/trade/order', ['instId' => 'BTC-USDT-SWAP']);

        self::assertIsArray($captured);
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://eea.okx.com/api/v5/trade/order', $captured['url']);
        self::assertSame(['x-simulated-trading: 1'], $captured['headers']['x-simulated-trading'] ?? null);
    }

    public function testPrivatePostRefusedWhenDemoTradingFlagIsOffOrEndpointIsLive(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });

        foreach ([
            $this->config(demoTradingEnabled: false),
            $this->config(simulatedTrading: false),
            $this->config(apiBaseUri: 'https://www.okx.com'),
            $this->config(liveEnabled: true),
        ] as $config) {
            try {
                (new OkxRestClient($http, $config, $this->clock()))->privatePost('/api/v5/trade/order', []);
                self::fail('private POST must be refused');
            } catch (\RuntimeException) {
            }
        }

        self::assertSame(0, $requests);
    }

    private function config(
        bool $demoTradingEnabled = true,
        bool $simulatedTrading = true,
        string $apiBaseUri = '',
        bool $liveEnabled = false,
    ): OkxConfig {
        return new OkxConfig(
            environment: 'demo',
            apiKey: 'k',
            apiSecret: 's',
            apiPassphrase: 'p',
            apiBaseUri: $apiBaseUri,
            simulatedTrading: $simulatedTrading,
            demoTradingEnabled: $demoTradingEnabled,
            liveEnabled: $liveEnabled,
        );
    }

    private function clock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
    }
}
