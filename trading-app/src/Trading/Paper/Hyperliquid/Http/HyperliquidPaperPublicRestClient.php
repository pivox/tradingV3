<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class HyperliquidPaperPublicRestClient implements HyperliquidPaperPublicRestClientInterface
{
    private const REQUEST_TIMEOUT_SECONDS = 10.0;
    private const MAXIMUM_RESPONSE_BYTES = 1_048_576;
    /** @var list<float> */
    private const RETRY_DELAYS_SECONDS = [0.25, 0.5, 1.0, 2.0, 4.0];
    private const MAXIMUM_ROWS = 500;

    public function __construct(
        private HttpClientInterface $httpClient,
        private HyperliquidPaperPublicConfig $config,
        private HyperliquidPaperPublicRateLimiter $rateLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = self::MAXIMUM_RESPONSE_BYTES,
        int $maximumRetries = 5,
    ): array {
        $this->assertRequest($coin, $interval, $startTime, $endTime, $maximumResponseBytes, $maximumRetries);

        for ($attempt = 0; ; ++$attempt) {
            $this->rateLimiter->acquireRequest();
            $response = null;
            try {
                $response = $this->httpClient->request('POST', $this->config->infoUri, [
                    'json' => [
                        'type' => 'candleSnapshot',
                        'req' => [
                            'coin' => $coin,
                            'interval' => $interval,
                            'startTime' => $startTime,
                            'endTime' => $endTime,
                        ],
                    ],
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                    'max_duration' => self::REQUEST_TIMEOUT_SECONDS,
                    'max_redirects' => 0,
                    'buffer' => false,
                ]);
                $status = $response->getStatusCode();

                if ($status === 429 || ($status >= 500 && $status <= 599)) {
                    $response->cancel();
                    $this->retryOrFail($attempt, $maximumRetries);
                    continue;
                }
                if ($status < 200 || $status > 299) {
                    throw new \RuntimeException(sprintf('hyperliquid_paper_public_http_error_%d', $status));
                }

                $rows = $this->validateRows($this->decode($this->readBoundedBody($response, $maximumResponseBytes)), $coin, $interval);
                $this->rateLimiter->acquireResponseRows(count($rows));

                return $rows;
            } catch (TransportExceptionInterface) {
                $response?->cancel();
                $this->retryOrFail($attempt, $maximumRetries);
            }
        }
    }

    private function assertRequest(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes,
        int $maximumRetries,
    ): void {
        $map = new HyperliquidPaperInstrumentMap();
        try {
            $map->normalizedSymbol($coin);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_coin_invalid');
        }
        try {
            $map->intervalMilliseconds($interval);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_interval_invalid');
        }
        if ($startTime < 0 || $endTime < $startTime) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_time_range_invalid');
        }
        if ($maximumResponseBytes < 1 || $maximumResponseBytes > self::MAXIMUM_RESPONSE_BYTES) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_maximum_response_bytes_invalid');
        }
        if ($maximumRetries < 0 || $maximumRetries > count(self::RETRY_DELAYS_SECONDS)) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_maximum_retries_invalid');
        }
    }

    private function retryOrFail(int $attempt, int $maximumRetries): void
    {
        if ($attempt >= $maximumRetries) {
            throw new \RuntimeException('hyperliquid_paper_public_retry_exhausted');
        }
        $this->clock->sleep(self::RETRY_DELAYS_SECONDS[$attempt]);
    }

    private function readBoundedBody(ResponseInterface $response, int $maximumResponseBytes): string
    {
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if (strlen($body) + strlen($content) > $maximumResponseBytes) {
                $response->cancel();
                throw new \RuntimeException('hyperliquid_paper_public_response_too_large');
            }
            $body .= $content;
        }

        return $body;
    }

    /** @return list<mixed> */
    private function decode(string $body): array
    {
        try {
            $root = json_decode($body, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('hyperliquid_paper_public_response_invalid');
        }
        if (!is_array($root)) {
            throw new \RuntimeException('hyperliquid_paper_public_response_invalid');
        }

        $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new \RuntimeException('hyperliquid_paper_public_response_invalid');
        }

        return $payload;
    }

    /**
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function validateRows(array $rows, string $coin, string $interval): array
    {
        if (count($rows) > self::MAXIMUM_ROWS) {
            throw new \RuntimeException('hyperliquid_paper_public_response_invalid');
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row) || !isset($row['s'], $row['i']) || !is_string($row['s']) || !is_string($row['i']) || $row['s'] !== $coin || $row['i'] !== $interval) {
                throw new \RuntimeException('hyperliquid_paper_public_response_invalid');
            }
        }

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }
}
