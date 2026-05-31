<?php

declare(strict_types=1);

namespace App\Exchange\Okx;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsAlias(id: OkxRestClientInterface::class)]
final readonly class OkxRestClient implements OkxRestClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private OkxConfig $config,
        private ClockInterface $clock,
    ) {
    }

    public function publicGet(string $path, array $query = []): array
    {
        /** @var array<string,mixed> $data */
        $data = $this->httpClient
            ->request('GET', $this->config->apiBaseUri() . $this->requestPath($path, $query))
            ->toArray(false);

        return $data;
    }

    public function privateGet(string $path, array $query = []): array
    {
        $this->config->assertPrivateConfigured();
        $requestPath = $this->requestPath($path, $query);

        /** @var array<string,mixed> $data */
        $data = $this->httpClient
            ->request('GET', $this->config->apiBaseUri() . $requestPath, [
                'headers' => $this->signedHeaders('GET', $requestPath),
            ])
            ->toArray(false);

        return $data;
    }

    public function privatePost(string $path, array $body = []): array
    {
        $this->config->assertTradingConfigured();
        $json = $this->json($body);

        /** @var array<string,mixed> $data */
        $data = $this->httpClient
            ->request('POST', $this->config->apiBaseUri() . $path, [
                'headers' => $this->signedHeaders('POST', $path, $json),
                'body' => $json,
            ])
            ->toArray(false);

        return $data;
    }

    /**
     * @param array<string,mixed> $query
     */
    private function requestPath(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query, '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * @return array<string,string>
     */
    private function signedHeaders(string $method, string $requestPath, string $body = ''): array
    {
        $timestamp = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        $prehash = $timestamp . strtoupper($method) . $requestPath . $body;

        $headers = [
            'Content-Type' => 'application/json',
            'OK-ACCESS-KEY' => $this->config->apiKey,
            'OK-ACCESS-SIGN' => base64_encode(hash_hmac('sha256', $prehash, $this->config->apiSecret, true)),
            'OK-ACCESS-TIMESTAMP' => $timestamp,
            'OK-ACCESS-PASSPHRASE' => $this->config->apiPassphrase,
        ];

        if ($this->config->isDemo()) {
            $headers['x-simulated-trading'] = '1';
        }

        return $headers;
    }

    /**
     * @param array<string,mixed> $body
     */
    private function json(array $body): string
    {
        $json = json_encode($body, \JSON_UNESCAPED_SLASHES);
        if (!\is_string($json)) {
            throw new \InvalidArgumentException('Unable to encode OKX request body as JSON.');
        }

        return $json;
    }
}
