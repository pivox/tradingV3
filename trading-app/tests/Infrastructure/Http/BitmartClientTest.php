<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Config\BitmartConfig;
use App\Infrastructure\Http\BitmartClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\TestClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class BitmartClientTest extends TestCase
{
    public function testAuthenticatedRequestSignsPayload(): void
    {
        $captured = [];
        $mockResponse = new MockResponse(json_encode(['code' => 1000, 'data' => ['order_id' => '123']], JSON_THROW_ON_ERROR));
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $mockResponse) {
            $captured = [$method, $url, $options];
            return $mockResponse;
        });

        $clock = new TestClock(new \DateTimeImmutable('2025-01-01T00:00:00.000Z'));
        $config = new BitmartConfig(
            baseUrl: 'https://api-cloud-v2.bitmart.com',
            publicApiUrl: 'https://api-cloud-v2.bitmart.com',
            privateApiUrl: 'https://api-cloud-v2.bitmart.com',
            timeout: 30,
            maxRetries: 1,
            apiKey: 'test-key',
            apiSecret: 'test-secret',
            apiMemo: 'test-memo',
        );

        $client = new BitmartClient($httpClient, new NullLogger(), $config, $clock);

        $payload = [
            'symbol' => 'BTCUSDT',
            'client_order_id' => 'TEST',
            'side' => 1,
            'type' => 'market',
            'size' => '1',
        ];

        $client->submitOrder($payload);

        [$method, $url, $options] = $captured;
        self::assertSame('POST', $method);
        self::assertSame($config->getOrderUrl(), $url);
        self::assertSame($payload, $options['json']);

        $headers = $options['headers'];
        self::assertSame('test-key', $headers['X-BM-KEY']);
        self::assertSame('MTF-Trading-System/1.0', $headers['User-Agent']);

        $timestamp = $headers['X-BM-TIMESTAMP'];
        $expectedBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $expectedSignature = hash_hmac('sha256', $timestamp . '#test-memo#' . $expectedBody, 'test-secret');
        self::assertSame($expectedSignature, $headers['X-BM-SIGN']);
    }
}
