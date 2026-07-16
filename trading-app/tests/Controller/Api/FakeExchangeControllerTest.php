<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\FakeExchangeController;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Fake\FakeExchangeMatchingEngine;
use App\Exchange\Fake\FakeExchangeOrderBook;
use App\Exchange\Fake\FakeExchangeScenarioService;
use App\Exchange\Fake\FakeExchangeStateStore;
use App\Exchange\Fake\FakeInstrumentCatalog;
use App\Exchange\Fake\FakeOrderValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(FakeExchangeController::class)]
final class FakeExchangeControllerTest extends TestCase
{
    private FakeExchangeController $controller;

    protected function setUp(): void
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
            }
        };
        $state = new FakeExchangeStateStore();
        $state->setOrderBookTop('BTCUSDT', 24999.0, 25001.0);
        $book = new FakeExchangeOrderBook($state);
        $catalog = new FakeInstrumentCatalog();
        $engine = new FakeExchangeMatchingEngine(
            $state,
            $book,
            $clock,
            new FakeOrderValidator($catalog),
            $catalog,
        );
        $adapter = new FakeExchangeAdapter($state, $book, $engine, $clock, $catalog);

        $this->controller = new FakeExchangeController(
            $adapter,
            new FakeExchangeScenarioService($state, $book, $engine),
        );
    }

    public function testPlaceOrderPreservesExactDecimalStringsForValidation(): void
    {
        $response = $this->controller->placeOrder($this->request([
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'position_side' => 'long',
            'order_type' => 'limit',
            'quantity' => '0.00100000000000000001',
            'price' => '24950.0',
            'client_order_id' => 'controller-exact-reject',
            'post_only' => true,
        ]));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('rejected', $payload['status'] ?? null);
        self::assertSame('quantity_not_quantized', $payload['metadata']['reason'] ?? null);
    }

    public function testPlaceOrderRejectsJsonNumbersForPrecisionSensitiveFields(): void
    {
        $response = $this->controller->placeOrder($this->request([
            'symbol' => 'BTCUSDT',
            'side' => 'buy',
            'position_side' => 'long',
            'order_type' => 'limit',
            'quantity' => 0.001,
            'price' => 24950.0,
            'client_order_id' => 'controller-rounded-json-number',
        ]));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_payload', $payload['reason'] ?? null);
        self::assertStringContainsString('decimal string', $payload['message'] ?? '');
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function request(array $payload): Request
    {
        return Request::create(
            '/fake-exchange/orders',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }
}
