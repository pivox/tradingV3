<?php

namespace App\Tests\Controller\Bitmart;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class BitmartKlineFetchControllerTest extends WebTestCase
{
    public function testFetchKlinesSuccess(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        // Mock du HttpClient Bitmart
        $mockHttpClient = $this->createMock(HttpClientInterface::class);
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->method('toArray')->willReturn([
            'message' => 'OK',
            'data' => [
                'klines' => [
                    // timestamp, open, high, low, close, volume, turnover
                    [1700000000, '1.0', '1.2', '0.9', '1.1', '100', '100.0'],
                    [1700000600, '1.1', '1.3', '1.0', '1.2', '120', '120.0'],
                ]
            ]
        ]);
        $mockHttpClient->method('request')->willReturn($mockResponse);
        $container->set(HttpClientInterface::class, $mockHttpClient);

        $client->request('POST', '/klines/bitmart?symbol=BTC_USDT&interval=1h&limit=2');
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertEquals('Klines fetched and persisted', $data['status'] ?? null);
        $this->assertEquals(2, $data['count'] ?? 0);
    }

    public function testFetchKlinesMissingSymbol(): void
    {
        $client = static::createClient();
        $client->request('POST', '/klines/bitmart');
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertStringContainsString('Missing symbol', $data['error'] ?? '');
    }
}

