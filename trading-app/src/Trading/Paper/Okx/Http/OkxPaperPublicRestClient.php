<?php

declare(strict_types=1);

namespace App\Trading\Paper\Okx\Http;

use App\Trading\Paper\Okx\OkxPaperPublicConfig;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class OkxPaperPublicRestClient implements OkxPaperPublicRestClientInterface
{
    private const REQUEST_TIMEOUT_SECONDS = 10.0;
    private const MAX_RESPONSE_BYTES = 1_048_576;
    private const RETRY_DELAYS_SECONDS = [0.25, 0.5, 1.0, 2.0, 4.0];

    public function __construct(
        private HttpClientInterface $httpClient,
        private OkxPaperPublicConfig $config,
        private OkxPaperPublicRateLimiter $rateLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function historyCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        int $limit = 300,
    ): array {
        $this->assertInstrumentId($instrumentId);
        $this->assertBar($bar);
        $this->assertCursor($after);
        $this->assertLimit(OkxPublicEndpoint::HistoryCandles, $limit);

        return $this->get(OkxPublicEndpoint::HistoryCandles, $this->withoutNulls([
            'instId' => $instrumentId,
            'bar' => $bar,
            'after' => $after,
            'limit' => $limit,
        ]), $limit);
    }

    public function currentCandles(
        string $instrumentId,
        string $bar,
        ?string $after = null,
        ?string $before = null,
        int $limit = 300,
    ): array {
        $this->assertInstrumentId($instrumentId);
        $this->assertBar($bar);
        $this->assertCursor($after);
        $this->assertCursor($before);
        $this->assertLimit(OkxPublicEndpoint::CurrentCandles, $limit);

        return $this->get(OkxPublicEndpoint::CurrentCandles, $this->withoutNulls([
            'instId' => $instrumentId,
            'bar' => $bar,
            'after' => $after,
            'before' => $before,
            'limit' => $limit,
        ]), $limit);
    }

    public function historyTrades(
        string $instrumentId,
        int $paginationType = 2,
        ?string $after = null,
        int $limit = 100,
    ): array {
        $this->assertInstrumentId($instrumentId);
        if ($paginationType !== 1 && $paginationType !== 2) {
            throw new \InvalidArgumentException('okx_paper_public_pagination_type_invalid');
        }
        $this->assertCursor($after);
        $this->assertLimit(OkxPublicEndpoint::HistoryTrades, $limit);

        return $this->get(OkxPublicEndpoint::HistoryTrades, $this->withoutNulls([
            'instId' => $instrumentId,
            'type' => $paginationType,
            'after' => $after,
            'limit' => $limit,
        ]), $limit);
    }

    public function recentTrades(string $instrumentId, int $limit = 500): array
    {
        $this->assertInstrumentId($instrumentId);
        $this->assertLimit(OkxPublicEndpoint::RecentTrades, $limit);

        return $this->get(OkxPublicEndpoint::RecentTrades, [
            'instId' => $instrumentId,
            'limit' => $limit,
        ], $limit);
    }

    public function orderBook(string $instrumentId, int $depth = 400): array
    {
        $this->assertInstrumentId($instrumentId);
        $this->assertLimit(OkxPublicEndpoint::OrderBook, $depth);

        return $this->get(OkxPublicEndpoint::OrderBook, [
            'instId' => $instrumentId,
            'sz' => $depth,
        ], $depth);
    }

    /**
     * @param array<string, int|string> $query
     * @return list<array<array-key, mixed>>
     */
    private function get(OkxPublicEndpoint $endpoint, array $query, int $requestedLimit): array
    {
        $url = $this->config->restBaseUri . $endpoint->value . '?' . http_build_query(
            $query,
            '',
            '&',
            \PHP_QUERY_RFC3986,
        );

        for ($attempt = 0; ; ++$attempt) {
            $this->rateLimiter->acquire($endpoint);
            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => ['Accept' => 'application/json'],
                    'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                    'max_duration' => self::REQUEST_TIMEOUT_SECONDS,
                    'max_redirects' => 0,
                    'buffer' => false,
                ]);
                $status = $response->getStatusCode();

                if ($status === 429) {
                    $response->cancel();
                    $this->retryOrFail($attempt);

                    continue;
                }

                if ($status !== 200) {
                    throw new \RuntimeException(sprintf('okx_paper_public_http_error_%d', $status));
                }

                $payload = $this->decode($this->readBoundedBody($response));
                if ($payload['code'] === '50011') {
                    $this->retryOrFail($attempt);

                    continue;
                }

                if ($payload['code'] !== '0') {
                    throw new \RuntimeException('okx_paper_public_api_error_' . $payload['code']);
                }

                return $this->validateData($endpoint, $requestedLimit, $payload['data']);
            } catch (TransportExceptionInterface) {
                throw new \RuntimeException('okx_paper_public_transport_error');
            }
        }
    }

    private function readBoundedBody(ResponseInterface $response): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if (\strlen($body) + \strlen($content) > self::MAX_RESPONSE_BYTES) {
                $response->cancel();

                throw new \RuntimeException('okx_paper_public_response_too_large');
            }
            $body .= $content;
        }

        return $body;
    }

    private function retryOrFail(int $attempt): void
    {
        if (!isset(self::RETRY_DELAYS_SECONDS[$attempt])) {
            throw new \RuntimeException('okx_paper_public_rate_limit_retry_exhausted');
        }

        $this->clock->sleep(self::RETRY_DELAYS_SECONDS[$attempt]);
    }

    /** @return array{code: numeric-string, data: mixed} */
    private function decode(string $body): array
    {
        try {
            $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('okx_paper_public_response_invalid');
        }

        if (
            !\is_array($payload)
            || array_is_list($payload)
            || !isset($payload['code'])
            || !\is_string($payload['code'])
            || preg_match('/\A[0-9]+\z/D', $payload['code']) !== 1
        ) {
            throw new \RuntimeException('okx_paper_public_response_invalid');
        }

        /** @var numeric-string $code */
        $code = $payload['code'];

        return ['code' => $code, 'data' => $payload['data'] ?? null];
    }

    /** @return list<array<array-key, mixed>> */
    private function validateData(OkxPublicEndpoint $endpoint, int $requestedLimit, mixed $data): array
    {
        if (!\is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('okx_paper_public_response_invalid');
        }

        foreach ($data as $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException('okx_paper_public_response_invalid');
            }
        }

        if ($endpoint === OkxPublicEndpoint::OrderBook) {
            if (\count($data) !== 1) {
                throw new \RuntimeException('okx_paper_public_response_invalid');
            }

            foreach (['bids', 'asks'] as $side) {
                $levels = $data[0][$side] ?? null;
                if (
                    !\is_array($levels)
                    || !array_is_list($levels)
                    || \count($levels) > $requestedLimit
                ) {
                    throw new \RuntimeException('okx_paper_public_response_invalid');
                }
            }
        } elseif (\count($data) > $requestedLimit) {
            throw new \RuntimeException('okx_paper_public_response_invalid');
        }

        /** @var list<array<array-key, mixed>> $data */
        return $data;
    }

    private function assertInstrumentId(string $instrumentId): void
    {
        if (preg_match('/\A[A-Z0-9]+(?:-[A-Z0-9]+)+\z/D', $instrumentId) !== 1) {
            throw new \InvalidArgumentException('okx_paper_public_instrument_invalid');
        }
    }

    private function assertBar(string $bar): void
    {
        if (preg_match('/\A[1-9][0-9]*[mH]\z/D', $bar) !== 1) {
            throw new \InvalidArgumentException('okx_paper_public_bar_invalid');
        }
    }

    private function assertCursor(?string $cursor): void
    {
        if ($cursor !== null && preg_match('/\A[0-9]+\z/D', $cursor) !== 1) {
            throw new \InvalidArgumentException('okx_paper_public_cursor_invalid');
        }
    }

    private function assertLimit(OkxPublicEndpoint $endpoint, int $limit): void
    {
        if ($limit < 1 || $limit > $endpoint->maximumLimit()) {
            throw new \InvalidArgumentException('okx_paper_public_limit_invalid');
        }
    }

    /**
     * @param array<string, int|string|null> $query
     * @return array<string, int|string>
     */
    private function withoutNulls(array $query): array
    {
        return array_filter($query, static fn (int|string|null $value): bool => $value !== null);
    }
}
