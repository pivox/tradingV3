<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Hyperliquid\Http;

use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRateLimiter;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperInstrumentMetadataClientInterface;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicHttpTransportInterface;
use App\Trading\Paper\Hyperliquid\Http\NativeHyperliquidPaperPublicHttpTransport;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClient;
use App\Trading\Paper\Hyperliquid\Http\HyperliquidPaperPublicRestClientInterface;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

#[CoversClass(HyperliquidPaperPublicRestClient::class)]
final class HyperliquidPaperPublicRestClientTest extends TestCase
{
    public function testReadsExactSupportedUniverseFromPublicMetaRequest(): void
    {
        $requests = [];
        $client = $this->client(new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = [$method, $url, $options];

            return new MockResponse('{"universe":[{"name":"BTC","szDecimals":5,"maxLeverage":50},{"name":"SOL","szDecimals":2,"maxLeverage":20},{"name":"ETH","szDecimals":4,"maxLeverage":25}]}');
        }));

        self::assertSame([
            ['coin' => 'BTC', 'asset_id' => 0, 'sz_decimals' => 5, 'max_leverage' => 50],
            ['coin' => 'ETH', 'asset_id' => 2, 'sz_decimals' => 4, 'max_leverage' => 25],
        ], $client->instrumentMetadata());
        self::assertInstanceOf(HyperliquidPaperInstrumentMetadataClientInterface::class, $client);
        self::assertSame('{"type":"meta"}', $requests[0][2]['body']);
        self::assertStringNotContainsString('authorization', strtolower(json_encode($requests[0][2], JSON_THROW_ON_ERROR)));
    }

    public function testMetadataRequiresBothSupportedAssetsExactlyOnce(): void
    {
        foreach ([
            '{"universe":[{"name":"BTC","szDecimals":5,"maxLeverage":50}]}',
            '{"universe":[{"name":"BTC","szDecimals":5,"maxLeverage":50},{"name":"BTC","szDecimals":5,"maxLeverage":50},{"name":"ETH","szDecimals":4,"maxLeverage":25}]}',
            '{"universe":[{"name":"BTC","szDecimals":7,"maxLeverage":50},{"name":"ETH","szDecimals":4,"maxLeverage":25}]}',
        ] as $body) {
            $client = $this->client(new MockHttpClient(new MockResponse($body)));
            try {
                $client->instrumentMetadata();
                self::fail('Invalid Hyperliquid metadata must fail closed.');
            } catch (\RuntimeException $exception) {
                self::assertSame('hyperliquid_paper_public_response_invalid', $exception->getMessage());
            }
        }
    }

    public function testMetadataRetriesRetryableStatusesWithinTheBoundedBudget(): void
    {
        $responses = [
            new MockResponse('wallet=one', ['http_code' => 429]),
            new MockResponse('wallet=two', ['http_code' => 503]),
            new MockResponse('{"universe":[{"name":"BTC","szDecimals":5,"maxLeverage":50},{"name":"ETH","szDecimals":4,"maxLeverage":25}]}'),
        ];
        $http = new HyperliquidRecordingHttpClient(new MockHttpClient($responses));
        $clock = new HyperliquidRecordingClock();
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client($http, $limiter, $clock);

        self::assertCount(2, $client->instrumentMetadata());
        self::assertSame([0.25, 0.5], $clock->sleeps);
        self::assertSame([[20, 65.0], [20, 65.0], [20, 65.0], [1, 65.0]], $limiter->reservations);
        self::assertTrue($http->responses[0]->getInfo('canceled'));
        self::assertTrue($http->responses[1]->getInfo('canceled'));
    }

    public function testPostsTheExactCredentialFreeSnapshotRequestForBothApprovedNetworks(): void
    {
        foreach ([
            [PaperMarketDataNetwork::MAINNET, HyperliquidPaperPublicConfig::MAINNET_INFO_URI],
            [PaperMarketDataNetwork::TESTNET, HyperliquidPaperPublicConfig::TESTNET_INFO_URI],
        ] as [$network, $uri]) {
            $requests = [];
            $client = $this->client(new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $url, $options];
                return new MockResponse('[{"s":"BTC","i":"1m"}]');
            }), network: $network);

            self::assertSame([['s' => 'BTC', 'i' => '1m']], $client->candleSnapshot('BTC', '1m', 0, 60_000));
            self::assertSame('POST', $requests[0][0]);
            self::assertSame($uri, $requests[0][1]);
            self::assertSame('{"type":"candleSnapshot","req":{"coin":"BTC","interval":"1m","startTime":0,"endTime":60000}}', $requests[0][2]['body']);
            self::assertSame(['Accept: application/json', 'Content-Type: application/json', 'Content-Length: 92'], $requests[0][2]['headers']);
            self::assertSame(10.0, $requests[0][2]['timeout']);
            self::assertSame(10.0, $requests[0][2]['max_duration']);
            self::assertSame(0, $requests[0][2]['max_redirects']);
            self::assertFalse($requests[0][2]['buffer']);
            self::assertStringNotContainsString('authorization', strtolower(json_encode($requests[0][2], JSON_THROW_ON_ERROR)));
            self::assertInstanceOf(HyperliquidPaperPublicRestClientInterface::class, $client);
        }
    }

    public function testNativeTransportAppliesTheExactFixedPostOptionsAndForwardsStreaming(): void
    {
        $requests = [];
        $http = new HyperliquidRecordingHttpClient(new MockHttpClient(
            function (string $method, string $uri, array $options) use (&$requests): MockResponse {
                $requests[] = [$method, $uri, $options];
                return new MockResponse('[]');
            },
        ));
        $reflection = new \ReflectionClass(NativeHyperliquidPaperPublicHttpTransport::class);
        $transport = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('httpClient');
        $property->setValue($transport, $http);

        $response = $transport->postCandleSnapshot('https://api.hyperliquid.xyz/info', [
            'type' => 'candleSnapshot',
            'req' => ['coin' => 'BTC', 'interval' => '1m', 'startTime' => 0, 'endTime' => 60_000],
        ]);
        foreach ($transport->stream($response) as $_) {
            break;
        }

        self::assertSame('POST', $requests[0][0]);
        self::assertSame('https://api.hyperliquid.xyz/info', $requests[0][1]);
        self::assertSame('{"type":"candleSnapshot","req":{"coin":"BTC","interval":"1m","startTime":0,"endTime":60000}}', $requests[0][2]['body']);
        self::assertSame(['Accept: application/json', 'Content-Type: application/json', 'Content-Length: 92'], $requests[0][2]['headers']);
        self::assertSame(10.0, $requests[0][2]['timeout']);
        self::assertSame(10.0, $requests[0][2]['max_duration']);
        self::assertSame(0, $requests[0][2]['max_redirects']);
        self::assertFalse($requests[0][2]['buffer']);
        self::assertSame(1, $http->streamCalls);
    }

    public function testInterfaceAndConstructorHaveOnlyTheCredentialFreeSnapshotSurface(): void
    {
        $methods = (new \ReflectionClass(HyperliquidPaperPublicRestClientInterface::class))->getMethods();
        self::assertSame(['network', 'candleSnapshot'], array_map(static fn (\ReflectionMethod $method): string => $method->getName(), $methods));

        $constructor = (new \ReflectionClass(HyperliquidPaperPublicRestClient::class))->getConstructor();
        self::assertNotNull($constructor);
        self::assertSame(['transport', 'config', 'rateLimiter', 'clock'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor->getParameters(),
        ));
        $transportType = $constructor->getParameters()[0]->getType();
        self::assertInstanceOf(\ReflectionNamedType::class, $transportType);
        self::assertSame(HyperliquidPaperPublicHttpTransportInterface::class, $transportType->getName());

        $native = new \ReflectionClass(NativeHyperliquidPaperPublicHttpTransport::class);
        self::assertSame([], $native->getConstructor()?->getParameters() ?? []);
        self::assertSame(['postCandleSnapshot', 'postMetadata', 'stream'], array_values(array_map(
            static fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter($native->getMethods(), static fn (\ReflectionMethod $method): bool => $method->isPublic() && !$method->isConstructor()),
        )));

        $this->expectException(\TypeError::class);
        (new \ReflectionClass(HyperliquidPaperPublicRestClient::class))->newInstanceArgs([
            new MockHttpClient([], 'https://credential=secret.example'),
            new HyperliquidPaperPublicConfig(
                PaperMarketDataNetwork::MAINNET,
                false,
                HyperliquidPaperPublicConfig::MAINNET_INFO_URI,
                HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI,
                '/tmp',
            ),
            new HyperliquidPaperPublicRateLimiter(new HyperliquidRestClientRecordingLimiter()),
            new HyperliquidRecordingClock(),
        ]);
    }

    /** @return iterable<string, array{string, string, int, int, int, int, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'coin' => ['SOL', '1m', 0, 1, 1, 0, 'hyperliquid_paper_public_coin_invalid'];
        yield 'interval' => ['BTC', '3m', 0, 1, 1, 0, 'hyperliquid_paper_public_interval_invalid'];
        yield 'negative start' => ['BTC', '1m', -1, 1, 1, 0, 'hyperliquid_paper_public_time_range_invalid'];
        yield 'end before start' => ['BTC', '1m', 2, 1, 1, 0, 'hyperliquid_paper_public_time_range_invalid'];
        yield 'zero bytes' => ['BTC', '1m', 0, 1, 0, 0, 'hyperliquid_paper_public_maximum_response_bytes_invalid'];
        yield 'too many bytes' => ['BTC', '1m', 0, 1, 1_048_577, 0, 'hyperliquid_paper_public_maximum_response_bytes_invalid'];
        yield 'negative retries' => ['BTC', '1m', 0, 1, 1, -1, 'hyperliquid_paper_public_maximum_retries_invalid'];
        yield 'too many retries' => ['BTC', '1m', 0, 1, 1, 6, 'hyperliquid_paper_public_maximum_retries_invalid'];
    }

    #[DataProvider('invalidInputs')]
    public function testRejectsInputBoundsBeforeMakingARequest(
        string $coin, string $interval, int $start, int $end, int $bytes, int $retries, string $reason,
    ): void {
        $requests = 0;
        $client = $this->client(new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse('[]');
        }));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($reason);
        try {
            $client->candleSnapshot($coin, $interval, $start, $end, $bytes, $retries);
        } finally {
            self::assertSame(0, $requests);
        }
    }

    public function testDisabledAcquisitionRejectsBeforeRateLimitingOrHttp(): void
    {
        $requests = 0;
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client(
            new MockHttpClient(function () use (&$requests): MockResponse {
                ++$requests;
                return new MockResponse('[]');
            }),
            limiter: $limiter,
            acquisitionEnabled: false,
        );

        try {
            $client->candleSnapshot('BTC', '1m', 0, 1);
            self::fail('Expected disabled acquisition rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_public_acquisition_disabled', $exception->getMessage());
        }
        self::assertSame([], $limiter->reservations);
        self::assertSame(0, $requests);
    }

    public function testRejectsAStillFormingCandleBeforeRateLimitingOrHttp(): void
    {
        $requests = 0;
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client(
            new MockHttpClient(function () use (&$requests): MockResponse {
                ++$requests;
                return new MockResponse('[]');
            }),
            limiter: $limiter,
            clock: new HyperliquidRecordingClock(
                now: '1970-01-01T00:01:30.000000+00:00',
            ),
        );

        try {
            $client->candleSnapshot('BTC', '1m', 60_000, 60_000);
            self::fail('Expected mutable candle rejection.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('hyperliquid_paper_public_candle_range_not_closed', $exception->getMessage());
        }
        self::assertSame([], $limiter->reservations);
        self::assertSame(0, $requests);
    }

    public function testRejectsMoreThanFiveHundredRowsAndChargesNoRowTokens(): void
    {
        $rows = array_fill(0, 501, ['s' => 'BTC', 'i' => '1m']);
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client(new MockHttpClient(new MockResponse(json_encode($rows, JSON_THROW_ON_ERROR))), $limiter);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_paper_public_response_invalid');
        try {
            $client->candleSnapshot('BTC', '1m', 0, 1);
        } finally {
            self::assertSame([[20, 65.0]], $limiter->reservations);
        }
    }

    public function testStopsReadingAndRedactsAnOversizedChunkedResponse(): void
    {
        $body = (static function (): \Generator {
            yield str_repeat('x', 10);
            yield str_repeat('y', 11);
            throw new \LogicException('unread_sentinel_was_read');
        })();
        $http = new HyperliquidRecordingHttpClient(new MockHttpClient(new MockResponse($body)));
        $client = $this->client($http);

        try {
            $client->candleSnapshot('BTC', '1m', 0, 1, 20);
            self::fail('Expected size bound rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_public_response_too_large', $exception->getMessage());
            self::assertStringNotContainsString('wallet', $exception->getMessage());
        }
        self::assertTrue($http->responses[0]->getInfo('canceled'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPayloads(): iterable
    {
        yield 'json' => ['{'];
        yield 'object root' => ['{}'];
        yield 'scalar row' => ['["wallet=secret"]'];
        yield 'list row' => ['[["BTC", "1m"]]'];
        yield 'missing coin' => ['[{"i":"1m"}]'];
        yield 'coin mismatch' => ['[{"s":"ETH","i":"1m"}]'];
        yield 'interval mismatch' => ['[{"s":"BTC","i":"5m"}]'];
    }

    #[DataProvider('invalidPayloads')]
    public function testRejectsInvalidOrMismatchedResponseRowsWithoutLeakingThem(string $body): void
    {
        $client = $this->client(new MockHttpClient(new MockResponse($body)));
        try {
            $client->candleSnapshot('BTC', '1m', 0, 1);
            self::fail('Expected response validation rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_public_response_invalid', $exception->getMessage());
            self::assertStringNotContainsString('wallet', $exception->getMessage());
        }
    }

    public function testChargesResponseRowsAfterSuccessfulValidation(): void
    {
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client(new MockHttpClient(new MockResponse('[{"s":"BTC","i":"1m"},{"s":"BTC","i":"1m"}]')), $limiter);

        self::assertCount(2, $client->candleSnapshot('BTC', '1m', 0, 1));
        self::assertSame([[20, 65.0], [1, 65.0]], $limiter->reservations);
    }

    public function testRetriesHttp429AndFiveHundredResponsesAndCancelsEachBeforeSleeping(): void
    {
        $responses = [
            new MockResponse('wallet=one', ['http_code' => 429]),
            new MockResponse('wallet=two', ['http_code' => 503]),
            new MockResponse('[{"s":"BTC","i":"1m"}]'),
        ];
        $http = new HyperliquidRecordingHttpClient(new MockHttpClient($responses));
        $retry = 0;
        $clock = new HyperliquidRecordingClock(static function () use ($http, &$retry): void {
            self::assertTrue($http->responses[$retry]->getInfo('canceled'));
            ++$retry;
        });
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client($http, $limiter, $clock);

        self::assertCount(1, $client->candleSnapshot('BTC', '1m', 0, 1));
        self::assertSame([0.25, 0.5], $clock->sleeps);
        self::assertSame([[20, 65.0], [20, 65.0], [20, 65.0], [1, 65.0]], $limiter->reservations);
        self::assertTrue($http->responses[0]->getInfo('canceled'));
        self::assertTrue($http->responses[1]->getInfo('canceled'));
    }

    public function testDefaultRetryBudgetIsBoundedToSixAttempts(): void
    {
        $requests = 0;
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $clock = new HyperliquidRecordingClock();
        $client = $this->client(new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;
            return new MockResponse('wallet=unread', ['http_code' => 429]);
        }), $limiter, $clock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('hyperliquid_paper_public_retry_exhausted');
        try {
            $client->candleSnapshot('BTC', '1m', 0, 1);
        } finally {
            self::assertSame(6, $requests);
            self::assertSame([0.25, 0.5, 1.0, 2.0, 4.0], $clock->sleeps);
            self::assertSame([[20, 65.0], [20, 65.0], [20, 65.0], [20, 65.0], [20, 65.0], [20, 65.0]], $limiter->reservations);
        }
    }

    public function testRetriesTransportExceptionsAndStopsAfterTheRequestedRetryBudget(): void
    {
        $requests = 0;
        $clock = new HyperliquidRecordingClock();
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client(new MockHttpClient(function () use (&$requests): never {
            ++$requests;
            throw new TransportException('https://secret.example/wallet=secret');
        }), $limiter, $clock);

        try {
            $client->candleSnapshot('BTC', '1m', 0, 1, maximumRetries: 2);
            self::fail('Expected retry exhaustion.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_public_retry_exhausted', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
        self::assertSame(3, $requests);
        self::assertSame([0.25, 0.5], $clock->sleeps);
        self::assertSame([[20, 65.0], [20, 65.0], [20, 65.0]], $limiter->reservations);
    }

    public function testRetriesTransportExceptionsFromStatusStreamAndChunkWithoutLeakingThem(): void
    {
        $status = new HyperliquidStatusThrowingTransport();
        $this->assertTransportRetryCancelsBeforeSleep($status, static function () use ($status): void {
            self::assertTrue($status->responses[0]->canceled);
        });

        $stream = new HyperliquidStreamThrowingTransport();
        $this->assertTransportRetryCancelsBeforeSleep($stream, static function () use ($stream): void {
            self::assertTrue($stream->responses[0]->getInfo('canceled'));
        });

        $chunkHttp = new HyperliquidRecordingHttpClient(new MockHttpClient(static function (): MockResponse {
            $chunk = (static function (): \Generator { yield new TransportException('wallet=chunk-secret'); })();
            return new MockResponse($chunk);
        }));
        $this->assertTransportRetryCancelsBeforeSleep(new HyperliquidRecordingTransport($chunkHttp), static function () use ($chunkHttp): void {
            self::assertTrue($chunkHttp->responses[0]->getInfo('canceled'));
        });
    }

    /** @return iterable<string, array{int}> */
    public static function nonRetryableStatuses(): iterable
    {
        yield 'redirect' => [302];
        yield 'bad request' => [400];
        yield 'unauthorized' => [401];
        yield 'not found' => [404];
    }

    #[DataProvider('nonRetryableStatuses')]
    public function testRejectsNonRetryableHttpStatusesWithoutReadingTheirBody(int $status): void
    {
        $body = (static function (): \Generator {
            yield from [];
            throw new \LogicException('non_2xx_body_was_read');
        })();
        $http = new HyperliquidRecordingHttpClient(new MockHttpClient(new MockResponse($body, ['http_code' => $status])));
        $client = $this->client($http);
        try {
            $client->candleSnapshot('BTC', '1m', 0, 1);
            self::fail('Expected non-retryable status rejection.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_public_http_error_' . $status, $exception->getMessage());
        }
        self::assertTrue($http->responses[0]->getInfo('canceled'));
    }

    private function client(
        HttpClientInterface|HyperliquidPaperPublicHttpTransportInterface $http,
        ?HyperliquidRestClientRecordingLimiter $limiter = null,
        ?HyperliquidRecordingClock $clock = null,
        PaperMarketDataNetwork $network = PaperMarketDataNetwork::MAINNET,
        bool $acquisitionEnabled = true,
    ): HyperliquidPaperPublicRestClient {
        return new HyperliquidPaperPublicRestClient(
            $http instanceof HyperliquidPaperPublicHttpTransportInterface ? $http : new HyperliquidRecordingTransport($http),
            new HyperliquidPaperPublicConfig(
                $network,
                $acquisitionEnabled,
                $network === PaperMarketDataNetwork::MAINNET
                    ? HyperliquidPaperPublicConfig::MAINNET_INFO_URI
                    : HyperliquidPaperPublicConfig::TESTNET_INFO_URI,
                $network === PaperMarketDataNetwork::MAINNET
                    ? HyperliquidPaperPublicConfig::MAINNET_WEBSOCKET_URI
                    : HyperliquidPaperPublicConfig::TESTNET_WEBSOCKET_URI,
                '/srv/app/var/paper-market-data',
            ),
            new HyperliquidPaperPublicRateLimiter($limiter ?? new HyperliquidRestClientRecordingLimiter()),
            $clock ?? new HyperliquidRecordingClock(),
        );
    }

    private function assertTransportRetryCancelsBeforeSleep(
        HyperliquidPaperPublicHttpTransportInterface $transport,
        \Closure $assertCanceled,
    ): void {
        $clock = new HyperliquidRecordingClock($assertCanceled);
        $limiter = new HyperliquidRestClientRecordingLimiter();
        $client = $this->client($transport, $limiter, $clock);
        try {
            $client->candleSnapshot('BTC', '1m', 0, 1, maximumRetries: 1);
            self::fail('Expected retry exhaustion.');
        } catch (\RuntimeException $exception) {
            self::assertSame('hyperliquid_paper_public_retry_exhausted', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
        self::assertSame([0.25], $clock->sleeps);
        self::assertSame([[20, 65.0], [20, 65.0]], $limiter->reservations);
    }
}

final class HyperliquidRecordingTransport implements HyperliquidPaperPublicHttpTransportInterface
{
    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    public function postCandleSnapshot(string $uri, array $payload): ResponseInterface
    {
        return $this->http->request('POST', $uri, [
            'json' => $payload,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'timeout' => 10.0,
            'max_duration' => 10.0,
            'max_redirects' => 0,
            'buffer' => false,
        ]);
    }

    public function postMetadata(string $uri, array $payload): ResponseInterface
    {
        return $this->http->request('POST', $uri, [
            'json' => $payload,
            'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'timeout' => 10.0,
            'max_duration' => 10.0,
            'max_redirects' => 0,
            'buffer' => false,
        ]);
    }

    public function stream(ResponseInterface $response): ResponseStreamInterface
    {
        return $this->http->stream($response);
    }
}

final class HyperliquidStatusThrowingTransport implements HyperliquidPaperPublicHttpTransportInterface
{
    /** @var list<HyperliquidThrowingStatusResponse> */
    public array $responses = [];

    public function postCandleSnapshot(string $uri, array $payload): ResponseInterface
    {
        return $this->responses[] = new HyperliquidThrowingStatusResponse(new TransportException('wallet=status-secret'));
    }
    public function postMetadata(string $uri, array $payload): ResponseInterface
    {
        return $this->responses[] = new HyperliquidThrowingStatusResponse(
            new TransportException('wallet=status-secret'),
        );
    }
    public function stream(ResponseInterface $response): ResponseStreamInterface { throw new \LogicException('stream_not_expected'); }
}

final class HyperliquidStreamThrowingTransport implements HyperliquidPaperPublicHttpTransportInterface
{
    /** @var list<MockResponse> */
    public array $responses = [];
    public function postCandleSnapshot(string $uri, array $payload): ResponseInterface { return $this->responses[] = new MockResponse('[]'); }
    public function postMetadata(string $uri, array $payload): ResponseInterface
    {
        return $this->responses[] = new MockResponse('[]');
    }
    public function stream(ResponseInterface $response): ResponseStreamInterface { throw new TransportException('wallet=stream-secret'); }
}

final class HyperliquidThrowingStatusResponse implements ResponseInterface
{
    public bool $canceled = false;
    public function __construct(private readonly TransportException $exception) {}
    public function getStatusCode(): int { throw $this->exception; }
    public function getHeaders(bool $throw = true): array { throw new \LogicException('headers_not_expected'); }
    public function getContent(bool $throw = true): string { throw new \LogicException('content_not_expected'); }
    /** @return array<string, mixed> */
    public function toArray(bool $throw = true): array { throw new \LogicException('array_not_expected'); }
    public function cancel(): void { $this->canceled = true; }
    public function getInfo(?string $type = null): mixed { return null; }
}

final class HyperliquidRestClientRecordingLimiter implements LimiterInterface
{
    /** @var list<array{int, float|null}> */
    public array $reservations = [];

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        $this->reservations[] = [$tokens, $maxTime];

        return new Reservation(
            microtime(true),
            new RateLimit(100, new \DateTimeImmutable(), true, 100),
        );
    }

    public function consume(int $tokens = 1): RateLimit
    {
        throw new \LogicException('consume_not_expected');
    }

    public function reset(): void
    {
        $this->reservations = [];
    }
}

final class HyperliquidRecordingClock implements ClockInterface
{
    /** @var list<float> */
    public array $sleeps = [];
    public function __construct(
        private readonly ?\Closure $onSleep = null,
        private readonly string $now = '2026-07-28T00:00:00+00:00',
    )
    {
    }
    public function now(): \DateTimeImmutable { return new \DateTimeImmutable($this->now); }
    public function sleep(float|int $seconds): void
    {
        ($this->onSleep ?? static function (): void {})();
        $this->sleeps[] = (float) $seconds;
    }
    public function withTimeZone(\DateTimeZone|string $timezone): static { return clone $this; }
}

final class HyperliquidRecordingHttpClient implements HttpClientInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];
    public int $streamCalls = 0;

    public function __construct(private HttpClientInterface $inner)
    {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->responses[] = $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        ++$this->streamCalls;
        return $this->inner->stream($responses, $timeout);
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }
}
