<?php

declare(strict_types=1);

namespace App\Tests\Trading\Paper\Okx\Http;

use App\Trading\Paper\Okx\Http\OkxPaperPublicRateLimiter;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClient;
use App\Trading\Paper\Okx\Http\OkxPaperPublicRestClientInterface;
use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\Reservation;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

#[CoversClass(OkxPaperPublicRestClient::class)]
final class OkxPaperPublicRestClientTest extends TestCase
{
    public function testNamedMethodsUseOnlyTheFiveAllowlistedGetEndpointsWithoutPrivateHeaders(): void
    {
        $requests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            if (str_contains($url, '/api/v5/market/books?')) {
                return new MockResponse('{"code":"0","data":[{"bids":[],"asks":[]}]}');
            }

            return new MockResponse('{"code":"0","data":[{"ok":true}]}');
        });
        $historyLimiter = new CountingLimiter();
        $snapshotLimiter = new CountingLimiter();
        $client = $this->client($http, $historyLimiter, $snapshotLimiter);

        self::assertSame([['ok' => true]], $client->historyCandles('BTC-USDT-SWAP', '1m', '2000', 300));
        self::assertSame([['ok' => true]], $client->currentCandles('ETH-USDT-SWAP', '1H', '3000', '1000', 200));
        self::assertSame([['ok' => true]], $client->historyTrades('BTC-USDT-SWAP', 2, '2000', 100));
        self::assertSame([['ok' => true]], $client->recentTrades('ETH-USDT-SWAP', 500));
        self::assertSame([['bids' => [], 'asks' => []]], $client->orderBook('BTC-USDT-SWAP', 400));

        self::assertSame(
            [
                'https://www.okx.com/api/v5/market/history-candles?instId=BTC-USDT-SWAP&bar=1m&after=2000&limit=300',
                'https://www.okx.com/api/v5/market/candles?instId=ETH-USDT-SWAP&bar=1H&after=3000&before=1000&limit=200',
                'https://www.okx.com/api/v5/market/history-trades?instId=BTC-USDT-SWAP&type=2&after=2000&limit=100',
                'https://www.okx.com/api/v5/market/trades?instId=ETH-USDT-SWAP&limit=500',
                'https://www.okx.com/api/v5/market/books?instId=BTC-USDT-SWAP&sz=400',
            ],
            array_column($requests, 'url'),
        );
        self::assertSame(['GET', 'GET', 'GET', 'GET', 'GET'], array_column($requests, 'method'));
        self::assertSame(2, $historyLimiter->reservationCount);
        self::assertSame(3, $snapshotLimiter->reservationCount);
        self::assertInstanceOf(OkxPaperPublicRestClientInterface::class, $client);

        foreach ($requests as $request) {
            $encodedOptions = strtolower(json_encode($request['options'], JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('authorization', $encodedOptions);
            self::assertStringNotContainsString('ok-access-', $encodedOptions);
            self::assertStringNotContainsString('x-simulated-trading', $encodedOptions);
            self::assertStringNotContainsString('api-key', $encodedOptions);
            self::assertStringNotContainsString('api-secret', $encodedOptions);
            self::assertStringNotContainsString('passphrase', $encodedOptions);
            self::assertArrayNotHasKey('body', $request['options']);
            self::assertSame(10.0, $request['options']['timeout'] ?? null);
            self::assertSame(10.0, $request['options']['max_duration'] ?? null);
            self::assertSame(0, $request['options']['max_redirects'] ?? null);
            self::assertFalse($request['options']['buffer'] ?? null);
        }
    }

    public function testRejectsOversizedChunkedResponseWithCredentialFreeError(): void
    {
        $body = (static function (): \Generator {
            yield str_repeat('x', 524_288);
            yield str_repeat('y', 524_289);
            yield 'credential=must-not-be-read-or-leaked';
        })();
        $client = $this->client(new MockHttpClient(new MockResponse($body)));

        try {
            $client->recentTrades('BTC-USDT-SWAP', 1);
            self::fail('Expected oversized response to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('okx_paper_public_response_too_large', $exception->getMessage());
            self::assertStringNotContainsString('credential', $exception->getMessage());
        }
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function limitedRowRequests(): iterable
    {
        yield 'history candles' => ['historyCandles', ['BTC-USDT-SWAP', '1m', null, 1]];
        yield 'current candles' => ['currentCandles', ['BTC-USDT-SWAP', '1m', null, null, 1]];
        yield 'history trades' => ['historyTrades', ['BTC-USDT-SWAP', 2, null, 1]];
        yield 'recent trades' => ['recentTrades', ['BTC-USDT-SWAP', 1]];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('limitedRowRequests')]
    public function testRejectsCandleOrTradeRowsAboveRequestedLimit(string $method, array $arguments): void
    {
        $response = new MockResponse('{"code":"0","data":[[],[]]}');
        $client = $this->client(new MockHttpClient($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_paper_public_response_invalid');

        $client->{$method}(...$arguments);
    }

    /** @return iterable<string, array{list<array<array-key, mixed>>}> */
    public static function invalidOrderBookSnapshotCounts(): iterable
    {
        yield 'no snapshot' => [[]];
        yield 'more than one snapshot' => [[[], []]];
    }

    /** @param list<array<array-key, mixed>> $data */
    #[DataProvider('invalidOrderBookSnapshotCounts')]
    public function testRequiresExactlyOneOrderBookSnapshot(array $data): void
    {
        $response = new MockResponse(json_encode(['code' => '0', 'data' => $data], JSON_THROW_ON_ERROR));
        $client = $this->client(new MockHttpClient($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_paper_public_response_invalid');

        $client->orderBook('BTC-USDT-SWAP', 1);
    }

    /** @return iterable<string, array{array<array-key, mixed>}> */
    public static function invalidOrderBookSideShapes(): iterable
    {
        yield 'missing bids' => [['asks' => []]];
        yield 'scalar bids' => [['bids' => 'hidden-credential', 'asks' => []]];
        yield 'associative bids' => [['bids' => ['price' => '1'], 'asks' => []]];
        yield 'missing asks' => [['bids' => []]];
        yield 'scalar asks' => [['bids' => [], 'asks' => 'hidden-credential']];
        yield 'associative asks' => [['bids' => [], 'asks' => ['price' => '1']]];
    }

    /** @param array<array-key, mixed> $row */
    #[DataProvider('invalidOrderBookSideShapes')]
    public function testRequiresBothOrderBookSidesToBeListArrays(array $row): void
    {
        $body = json_encode(['code' => '0', 'data' => [$row]], JSON_THROW_ON_ERROR);
        $client = $this->client(new MockHttpClient(new MockResponse($body)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_paper_public_response_invalid');

        $client->orderBook('BTC-USDT-SWAP', 1);
    }

    /** @return iterable<string, array{string}> */
    public static function orderBookSides(): iterable
    {
        yield 'bids' => ['bids'];
        yield 'asks' => ['asks'];
    }

    #[DataProvider('orderBookSides')]
    public function testRejectsOrderBookLevelsAboveRequestedDepth(string $side): void
    {
        $body = json_encode([
            'code' => '0',
            'data' => [[
                'bids' => $side === 'bids' ? [[], []] : [],
                'asks' => $side === 'asks' ? [[], []] : [],
            ]],
        ], JSON_THROW_ON_ERROR);
        $client = $this->client(new MockHttpClient(new MockResponse($body)));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('okx_paper_public_response_invalid');

        $client->orderBook('BTC-USDT-SWAP', 1);
    }

    public function testHttp429RetriesWithExactBoundedScheduleAndALimiterTokenPerAttempt(): void
    {
        $responses = [
            new MockResponse('{"code":"50011","data":[]}', ['http_code' => 429]),
            new MockResponse('{"code":"50011","data":[]}', ['http_code' => 429]),
            new MockResponse('{"code":"50011","data":[]}', ['http_code' => 429]),
            new MockResponse('{"code":"50011","data":[]}', ['http_code' => 429]),
            new MockResponse('{"code":"50011","data":[]}', ['http_code' => 429]),
            new MockResponse('{"code":"0","data":[{"tradeId":"1"}]}'),
        ];
        $historyLimiter = new CountingLimiter();
        $clock = new RecordingClock();
        $client = $this->client(new MockHttpClient($responses), $historyLimiter, new CountingLimiter(), $clock);

        self::assertSame([['tradeId' => '1']], $client->historyTrades('BTC-USDT-SWAP'));
        self::assertSame(6, $historyLimiter->reservationCount);
        self::assertSame([0.25, 0.5, 1.0, 2.0, 4.0], $clock->sleeps);
    }

    public function testHttp429CancelsUnbufferedResponseBeforeSleepingAndRetrying(): void
    {
        $http = new RecordingHttpClient(new MockHttpClient([
            new MockResponse('{"code":"50011","data":[]}', ['http_code' => 429]),
            new MockResponse('{"code":"0","data":[{"tradeId":"1"}]}'),
        ]));
        $clock = new RecordingClock(static function () use ($http): void {
            self::assertTrue($http->responses[0]->getInfo('canceled'));
        });
        $client = $this->client($http, clock: $clock);

        self::assertSame([['tradeId' => '1']], $client->recentTrades('BTC-USDT-SWAP'));
        self::assertTrue($http->responses[0]->getInfo('canceled'));
        self::assertFalse($http->responses[1]->getInfo('canceled'));
    }

    public function testOkxCode50011RetriesEvenWhenHttpStatusIsSuccessful(): void
    {
        $responses = [
            new MockResponse('{"code":"50011","msg":"Rate limit reached"}'),
            new MockResponse('{"code":"0","data":[{"bids":[],"asks":[]}]}'),
        ];
        $snapshotLimiter = new CountingLimiter();
        $clock = new RecordingClock();
        $client = $this->client(new MockHttpClient($responses), new CountingLimiter(), $snapshotLimiter, $clock);

        self::assertSame([['bids' => [], 'asks' => []]], $client->orderBook('BTC-USDT-SWAP'));
        self::assertSame(2, $snapshotLimiter->reservationCount);
        self::assertSame([0.25], $clock->sleeps);
    }

    public function testRateLimitRetryExhaustionIsBoundedToSixAttempts(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"50011","data":[]}', ['http_code' => 429]);
        });
        $snapshotLimiter = new CountingLimiter();
        $clock = new RecordingClock();
        $client = $this->client($http, new CountingLimiter(), $snapshotLimiter, $clock);

        try {
            $client->recentTrades('BTC-USDT-SWAP');
            self::fail('Expected retry exhaustion.');
        } catch (\RuntimeException $exception) {
            self::assertSame('okx_paper_public_rate_limit_retry_exhausted', $exception->getMessage());
        }

        self::assertSame(6, $requests);
        self::assertSame(6, $snapshotLimiter->reservationCount);
        self::assertSame([0.25, 0.5, 1.0, 2.0, 4.0], $clock->sleeps);
    }

    public function testHttp429CancelsEveryUnbufferedResponseIncludingTheExhaustedAttempt(): void
    {
        $http = new RecordingHttpClient(new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"code":"50011","data":[]}',
            ['http_code' => 429],
        )));
        $client = $this->client($http);

        try {
            $client->recentTrades('BTC-USDT-SWAP');
            self::fail('Expected retry exhaustion.');
        } catch (\RuntimeException $exception) {
            self::assertSame('okx_paper_public_rate_limit_retry_exhausted', $exception->getMessage());
        }

        self::assertCount(6, $http->responses);
        foreach ($http->responses as $response) {
            self::assertTrue($response->getInfo('canceled'));
        }
    }

    public function testNormalizesTransportExceptionFromRequestWithoutRetryingOrLeakingItsMessage(): void
    {
        $http = new MockHttpClient(static function (): never {
            throw new TransportException('api-key=request-secret');
        });

        $this->assertTransportFailureIsNormalized($http);
    }

    public function testNormalizesTransportExceptionFromStatusCodeWithoutRetryingOrLeakingItsMessage(): void
    {
        $http = new MockHttpClient(new ThrowingStatusResponse(
            new TransportException('api-key=status-secret'),
        ));

        $this->assertTransportFailureIsNormalized($http);
    }

    public function testNormalizesTimeoutExceptionFromStreamWithoutRetryingOrLeakingItsMessage(): void
    {
        $http = new ThrowingStreamHttpClient(
            new MockHttpClient(new MockResponse('{"code":"0","data":[]}')),
            new TimeoutException('api-key=stream-secret'),
        );

        $this->assertTransportFailureIsNormalized($http);
    }

    public function testNormalizesTransportExceptionFromChunkContentWithoutRetryingOrLeakingItsMessage(): void
    {
        $body = (static function (): \Generator {
            yield new TransportException('api-key=chunk-secret');
        })();
        $http = new MockHttpClient(new MockResponse($body));

        $this->assertTransportFailureIsNormalized($http);
    }

    public function testPreservesNonTransportApplicationExceptionsFromHttpClient(): void
    {
        $expected = new \DomainException('application_exception');
        $http = new MockHttpClient(static function () use ($expected): never {
            throw $expected;
        });
        $client = $this->client($http);

        try {
            $client->recentTrades('BTC-USDT-SWAP');
            self::fail('Expected application exception.');
        } catch (\Throwable $actual) {
            self::assertSame($expected, $actual);
        }
    }

    /** @return iterable<string, array{MockResponse, string}> */
    public static function invalidResponses(): iterable
    {
        yield 'malformed JSON' => [new MockResponse('{'), 'okx_paper_public_response_invalid'];
        yield 'top-level list' => [new MockResponse('[]'), 'okx_paper_public_response_invalid'];
        yield 'missing code' => [new MockResponse('{"data":[]}'), 'okx_paper_public_response_invalid'];
        yield 'numeric code' => [new MockResponse('{"code":0,"data":[]}'), 'okx_paper_public_response_invalid'];
        yield 'unknown code shape' => [new MockResponse('{"code":"secret-code","data":[]}'), 'okx_paper_public_response_invalid'];
        yield 'missing data' => [new MockResponse('{"code":"0"}'), 'okx_paper_public_response_invalid'];
        yield 'object data' => [new MockResponse('{"code":"0","data":{"row":[]}}'), 'okx_paper_public_response_invalid'];
        yield 'scalar row' => [new MockResponse('{"code":"0","data":["row"]}'), 'okx_paper_public_response_invalid'];
        yield 'other OKX error' => [new MockResponse('{"code":"51000","msg":"invalid","data":[]}'), 'okx_paper_public_api_error_51000'];
        yield 'non-200 HTTP' => [new MockResponse('{"code":"0","data":[]}', ['http_code' => 503]), 'okx_paper_public_http_error_503'];
    }

    #[DataProvider('invalidResponses')]
    public function testRejectsInvalidHttpAndOkxResponseShapes(MockResponse $response, string $reason): void
    {
        $client = $this->client(new MockHttpClient($response));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);

        $client->recentTrades('BTC-USDT-SWAP');
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function invalidRequests(): iterable
    {
        yield 'blank instrument' => ['historyCandles', ['', '1m']];
        yield 'unsafe instrument' => ['recentTrades', ['BTC-USDT-SWAP?path=/api/v5/account']];
        yield 'blank bar' => ['currentCandles', ['BTC-USDT-SWAP', '']];
        yield 'unsafe bar' => ['historyCandles', ['BTC-USDT-SWAP', '1m&limit=999']];
        yield 'invalid cursor' => ['historyCandles', ['BTC-USDT-SWAP', '1m', 'yesterday']];
        yield 'invalid pagination type' => ['historyTrades', ['BTC-USDT-SWAP', 3]];
        yield 'zero limit' => ['recentTrades', ['BTC-USDT-SWAP', 0]];
        yield 'endpoint limit exceeded' => ['orderBook', ['BTC-USDT-SWAP', 401]];
    }

    /** @param list<mixed> $arguments */
    #[DataProvider('invalidRequests')]
    public function testRejectsUnsafeOrOutOfBoundsQueryParametersBeforeAnyRequest(
        string $method,
        array $arguments,
    ): void {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"code":"0","data":[]}');
        });
        $client = $this->client($http);

        try {
            $client->{$method}(...$arguments);
            self::fail('Expected query validation to reject the request.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringStartsWith('okx_paper_public_', $exception->getMessage());
        }

        self::assertSame(0, $requests);
    }

    private function client(
        HttpClientInterface $httpClient,
        ?CountingLimiter $historyLimiter = null,
        ?CountingLimiter $snapshotLimiter = null,
        ?RecordingClock $clock = null,
    ): OkxPaperPublicRestClient {
        return new OkxPaperPublicRestClient(
            $httpClient,
            new OkxPaperPublicConfig(
                acquisitionEnabled: false,
                restBaseUri: 'https://www.okx.com',
                webSocketUri: 'wss://ws.okx.com:8443/ws/v5/public',
                dataRoot: '/srv/app/var/paper-market-data',
            ),
            new OkxPaperPublicRateLimiter(
                $historyLimiter ?? new CountingLimiter(),
                $snapshotLimiter ?? new CountingLimiter(),
            ),
            $clock ?? new RecordingClock(),
        );
    }

    private function assertTransportFailureIsNormalized(HttpClientInterface $httpClient): void
    {
        $snapshotLimiter = new CountingLimiter();
        $client = $this->client($httpClient, snapshotLimiter: $snapshotLimiter);

        try {
            $client->recentTrades('BTC-USDT-SWAP');
            self::fail('Expected transport failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('okx_paper_public_transport_error', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }

        self::assertSame(1, $snapshotLimiter->reservationCount);
    }
}

final class RecordingHttpClient implements HttpClientInterface
{
    /** @var list<ResponseInterface> */
    public array $responses = [];

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

final class ThrowingStreamHttpClient implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $inner,
        private readonly TransportExceptionInterface $exception,
    ) {
    }

    /** @param array<string, mixed> $options */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->inner->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        throw $this->exception;
    }

    /** @param array<string, mixed> $options */
    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }
}

final class ThrowingStatusResponse implements ResponseInterface
{
    public function __construct(private readonly TransportExceptionInterface $exception)
    {
    }

    public function getStatusCode(): int
    {
        throw $this->exception;
    }

    public function getHeaders(bool $throw = true): array
    {
        throw new \LogicException('getHeaders_not_expected');
    }

    public function getContent(bool $throw = true): string
    {
        throw new \LogicException('getContent_not_expected');
    }

    /** @return array<array-key, mixed> */
    public function toArray(bool $throw = true): array
    {
        throw new \LogicException('toArray_not_expected');
    }

    public function cancel(): void
    {
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = [
            'canceled' => false,
            'error' => null,
            'http_code' => 0,
            'http_method' => 'GET',
            'redirect_count' => 0,
            'redirect_url' => null,
            'response_headers' => [],
            'start_time' => 0.0,
            'url' => 'https://www.okx.com',
            'user_data' => null,
        ];

        return $type === null ? $info : ($info[$type] ?? null);
    }
}

final class CountingLimiter implements LimiterInterface
{
    public int $reservationCount = 0;

    public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
    {
        ++$this->reservationCount;

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
        $this->reservationCount = 0;
    }
}

final class RecordingClock implements ClockInterface
{
    /** @var list<float> */
    public array $sleeps = [];

    public function __construct(private readonly ?\Closure $onSleep = null)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-07-21T00:00:00+00:00');
    }

    public function sleep(float|int $seconds): void
    {
        ($this->onSleep ?? static function (): void {})();
        $this->sleeps[] = (float)$seconds;
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return clone $this;
    }
}
