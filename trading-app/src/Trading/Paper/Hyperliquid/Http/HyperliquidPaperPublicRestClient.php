<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

use App\Trading\Paper\Hyperliquid\HyperliquidPaperInstrumentMap;
use App\Trading\Paper\Hyperliquid\HyperliquidPaperPublicConfig;
use App\Trading\Paper\MarketData\PaperMarketDataNetwork;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class HyperliquidPaperPublicRestClient implements HyperliquidPaperPublicRestClientInterface
{
    private const MAXIMUM_RESPONSE_BYTES = 1_048_576;
    /** @var list<float> */
    private const RETRY_DELAYS_SECONDS = [0.25, 0.5, 1.0, 2.0, 4.0];
    private const MAXIMUM_ROWS = 500;

    public function __construct(
        private HyperliquidPaperPublicHttpTransportInterface $transport,
        private HyperliquidPaperPublicConfig $config,
        private HyperliquidPaperPublicRateLimiter $rateLimiter,
        private ClockInterface $clock,
    ) {
    }

    public function network(): PaperMarketDataNetwork
    {
        return $this->config->network;
    }

    public function candleSnapshot(
        string $coin,
        string $interval,
        int $startTime,
        int $endTime,
        int $maximumResponseBytes = self::MAXIMUM_RESPONSE_BYTES,
        int $maximumRetries = 5,
    ): array {
        if (!$this->config->acquisitionEnabled) {
            throw new \RuntimeException('hyperliquid_paper_public_acquisition_disabled');
        }
        $this->assertRequest($coin, $interval, $startTime, $endTime, $maximumResponseBytes, $maximumRetries);

        for ($attempt = 0; ; ++$attempt) {
            $this->rateLimiter->acquireRequest();
            $response = null;
            try {
                $response = $this->transport->postCandleSnapshot($this->config->infoUri, [
                    'type' => 'candleSnapshot',
                    'req' => [
                        'coin' => $coin,
                        'interval' => $interval,
                        'startTime' => $startTime,
                        'endTime' => $endTime,
                    ],
                ]);
                $status = $response->getStatusCode();

                if ($status === 429 || ($status >= 500 && $status <= 599)) {
                    $response->cancel();
                    $this->retryOrFail($attempt, $maximumRetries);
                    continue;
                }
                if ($status < 200 || $status > 299) {
                    $response->cancel();
                    throw new \RuntimeException(sprintf('hyperliquid_paper_public_http_error_%d', $status));
                }

                $rows = $this->validateRows($this->decode($this->readBoundedBody($response, $maximumResponseBytes)), $coin, $interval);
                $this->rateLimiter->acquireResponseRows(count($rows));

                return $rows;
            } catch (TransportExceptionInterface) {
                if ($response instanceof ResponseInterface) {
                    $response->cancel();
                }
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
            $intervalMilliseconds = $map->intervalMilliseconds($interval);
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_interval_invalid');
        }
        if ($startTime < 0 || $endTime < $startTime) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_time_range_invalid');
        }
        $nowMilliseconds = $this->nowMilliseconds();
        if ($nowMilliseconds < $intervalMilliseconds
            || $endTime > $nowMilliseconds - $intervalMilliseconds
        ) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_public_candle_range_not_closed',
            );
        }
        if ($maximumResponseBytes < 1 || $maximumResponseBytes > self::MAXIMUM_RESPONSE_BYTES) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_maximum_response_bytes_invalid');
        }
        if ($maximumRetries < 0 || $maximumRetries > count(self::RETRY_DELAYS_SECONDS)) {
            throw new \InvalidArgumentException('hyperliquid_paper_public_maximum_retries_invalid');
        }
    }

    private function nowMilliseconds(): int
    {
        $now = $this->clock->now();
        $seconds = (int) $now->format('U');
        $milliseconds = (int) $now->format('v');
        if ($seconds < 0 || $seconds > intdiv(\PHP_INT_MAX - $milliseconds, 1_000)) {
            throw new \InvalidArgumentException(
                'hyperliquid_paper_public_candle_range_not_closed',
            );
        }

        return $seconds * 1_000 + $milliseconds;
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
        foreach ($this->transport->stream($response) as $chunk) {
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
        if (!str_starts_with(ltrim($body), '[')) {
            throw new \RuntimeException('hyperliquid_paper_public_response_invalid');
        }

        try {
            $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('hyperliquid_paper_public_response_invalid');
        }
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
