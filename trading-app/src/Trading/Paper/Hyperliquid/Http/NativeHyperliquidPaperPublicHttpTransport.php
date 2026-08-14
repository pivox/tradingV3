<?php

declare(strict_types=1);

namespace App\Trading\Paper\Hyperliquid\Http;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class NativeHyperliquidPaperPublicHttpTransport implements HyperliquidPaperPublicHttpTransportInterface
{
    private readonly HttpClientInterface $httpClient;

    public function __construct()
    {
        $this->httpClient = HttpClient::create();
    }

    public function postCandleSnapshot(string $uri, array $payload): ResponseInterface
    {
        return $this->post($uri, $payload);
    }

    public function postMetadata(string $uri, array $payload): ResponseInterface
    {
        return $this->post($uri, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $uri, array $payload): ResponseInterface
    {
        return $this->httpClient->request('POST', $uri, [
            'json' => $payload,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'timeout' => 10.0,
            'max_duration' => 10.0,
            'max_redirects' => 0,
            'buffer' => false,
        ]);
    }

    public function stream(ResponseInterface $response): ResponseStreamInterface
    {
        return $this->httpClient->stream($response);
    }
}
