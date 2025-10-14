<?php
declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;

final class HttpClient
{
    private Client $client;
    private string $baseUrl;
    private string $token;
    private int $timeout;

    public function __construct(string $baseUrl, ?string $token = null, int $timeout = 5)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token ?? '';
        $this->timeout = $timeout;
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout'  => $this->timeout,
        ]);
    }

    public function postJson(string $path, array $payload): void
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->token !== '') {
            $headers['X-Webhook-Token'] = $this->token;
        }

        $this->client->post($path, [
            'headers' => $headers,
            'json'    => $payload,
        ]);
    }
}
