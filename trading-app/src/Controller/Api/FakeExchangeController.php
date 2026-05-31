<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Adapter\FakeExchangeAdapter;
use App\Exchange\Dto\CancelOrderRequest;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Dto\PlaceOrderRequest;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\Exchange\Enum\ExchangeTimeInForce;
use App\Exchange\Fake\FakeExchangeScenarioService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
final readonly class FakeExchangeController
{
    public function __construct(
        private FakeExchangeAdapter $adapter,
        private FakeExchangeScenarioService $scenarioService,
    ) {
    }

    #[Route('/fake-exchange/orders', name: 'fake_exchange_place_order', methods: ['POST'])]
    public function placeOrder(Request $request): JsonResponse
    {
        try {
            $payload = $this->jsonPayload($request);
            $orderRequest = $this->placeOrderRequest($payload);
            $result = $this->adapter->placeOrder($orderRequest);
        } catch (\InvalidArgumentException|\ValueError $e) {
            return new JsonResponse(['status' => 'error', 'reason' => 'invalid_payload', 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'status' => $result->accepted ? 'accepted' : 'rejected',
            'exchange_order_id' => $result->exchangeOrderId,
            'client_order_id' => $result->clientOrderId,
            'order_status' => $result->status->value,
            'order' => $result->order instanceof ExchangeOrderDto ? $this->orderToArray($result->order) : null,
            'metadata' => $result->metadata,
        ]);
    }

    #[Route('/fake-exchange/orders/{id}', name: 'fake_exchange_get_order', methods: ['GET'])]
    public function getOrder(string $id, Request $request): JsonResponse
    {
        $symbol = strtoupper(trim((string) $request->query->get('symbol', 'BTCUSDT')));
        $order = $this->adapter->getOrder($symbol, $id);
        if (!$order instanceof ExchangeOrderDto) {
            return new JsonResponse(['status' => 'error', 'reason' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['status' => 'ok', 'order' => $this->orderToArray($order)]);
    }

    #[Route('/fake-exchange/open-orders', name: 'fake_exchange_open_orders', methods: ['GET'])]
    public function openOrders(Request $request): JsonResponse
    {
        $symbol = $this->optionalSymbol($request);

        return new JsonResponse([
            'status' => 'ok',
            'orders' => array_map($this->orderToArray(...), $this->adapter->getOpenOrders($symbol)),
        ]);
    }

    #[Route('/fake-exchange/positions', name: 'fake_exchange_positions', methods: ['GET'])]
    public function positions(Request $request): JsonResponse
    {
        $symbol = $this->optionalSymbol($request);

        return new JsonResponse([
            'status' => 'ok',
            'positions' => array_map($this->positionToArray(...), $this->adapter->getOpenPositions($symbol)),
        ]);
    }

    #[Route('/fake-exchange/orders/{id}/cancel', name: 'fake_exchange_cancel_order', methods: ['POST'])]
    public function cancelOrder(string $id, Request $request): JsonResponse
    {
        try {
            $payload = $this->jsonPayload($request, allowEmpty: true);
            $symbol = strtoupper(trim((string) ($payload['symbol'] ?? 'BTCUSDT')));
            $result = $this->adapter->cancelOrder(new CancelOrderRequest(
                exchange: Exchange::FAKE,
                marketType: MarketType::PERPETUAL,
                symbol: $symbol,
                exchangeOrderId: $id,
                clientOrderId: $this->optionalString($payload, 'client_order_id'),
            ));
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['status' => 'error', 'reason' => 'invalid_payload', 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'status' => $result->cancelled ? 'cancelled' : 'not_cancelled',
            'exchange_order_id' => $result->exchangeOrderId,
            'client_order_id' => $result->clientOrderId,
            'order_status' => $result->status->value,
            'metadata' => $result->metadata,
        ]);
    }

    #[Route('/fake-exchange/fill-order', name: 'fake_exchange_fill_order', methods: ['POST'])]
    public function fillOrder(Request $request): JsonResponse
    {
        try {
            $payload = $this->jsonPayload($request);
            $orderId = trim((string) ($payload['order_id'] ?? $payload['exchange_order_id'] ?? ''));
            if ($orderId === '') {
                throw new \InvalidArgumentException('order_id is required');
            }

            $order = $this->scenarioService->fillOrder(
                $orderId,
                $this->optionalFloat($payload, 'quantity'),
                $this->optionalFloat($payload, 'price'),
            );
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['status' => 'error', 'reason' => 'invalid_payload', 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$order instanceof ExchangeOrderDto) {
            return new JsonResponse(['status' => 'error', 'reason' => 'order_not_found'], JsonResponse::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['status' => 'ok', 'order' => $this->orderToArray($order)]);
    }

    #[Route('/fake-exchange/move-price', name: 'fake_exchange_move_price', methods: ['POST'])]
    public function movePrice(Request $request): JsonResponse
    {
        try {
            $payload = $this->jsonPayload($request);
            $symbol = strtoupper(trim((string) ($payload['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \InvalidArgumentException('symbol is required');
            }
            $price = $this->requiredFloat($payload, 'price');
            $spreadBps = $this->optionalFloat($payload, 'spread_bps') ?? 2.0;
            $result = $this->scenarioService->movePrice($symbol, $price, $spreadBps);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['status' => 'error', 'reason' => 'invalid_payload', 'message' => $e->getMessage()], JsonResponse::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'status' => 'ok',
            'book' => $result['book']->toArray(),
            'matched_orders' => array_map($this->orderToArray(...), $result['matched_orders']),
        ]);
    }

    #[Route('/fake-exchange/reset', name: 'fake_exchange_reset', methods: ['POST'])]
    public function reset(): JsonResponse
    {
        $this->scenarioService->reset();

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/fake-exchange/reject-next-protection', name: 'fake_exchange_reject_next_protection', methods: ['POST'])]
    public function rejectNextProtection(): JsonResponse
    {
        $this->scenarioService->rejectNextProtectionOrder();

        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/fake-exchange/events', name: 'fake_exchange_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        $type = $request->query->get('type');
        $type = \is_string($type) && trim($type) !== '' ? trim($type) : null;

        return new JsonResponse([
            'status' => 'ok',
            'events' => array_map(
                static fn ($event): array => $event->toArray(),
                $this->scenarioService->events($type),
            ),
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function placeOrderRequest(array $payload): PlaceOrderRequest
    {
        $orderType = ExchangeOrderType::from(strtolower((string) ($payload['order_type'] ?? $payload['type'] ?? 'limit')));
        $price = $this->optionalFloat($payload, 'price');

        return new PlaceOrderRequest(
            exchange: Exchange::FAKE,
            marketType: MarketType::PERPETUAL,
            symbol: strtoupper(trim((string) ($payload['symbol'] ?? ''))),
            side: ExchangeOrderSide::from(strtolower((string) ($payload['side'] ?? 'buy'))),
            positionSide: ExchangePositionSide::from(strtolower((string) ($payload['position_side'] ?? 'long'))),
            orderType: $orderType,
            timeInForce: ExchangeTimeInForce::from(strtolower((string) ($payload['time_in_force'] ?? 'gtc'))),
            quantity: $this->requiredFloat($payload, 'quantity', 'qty'),
            price: $orderType === ExchangeOrderType::MARKET ? null : $price,
            stopPrice: $this->optionalFloat($payload, 'stop_price'),
            reduceOnly: $this->boolPayload($payload, 'reduce_only'),
            postOnly: $this->boolPayload($payload, 'post_only'),
            leverage: $this->optionalInt($payload, 'leverage'),
            marginMode: (string) ($payload['margin_mode'] ?? 'isolated'),
            clientOrderId: trim((string) ($payload['client_order_id'] ?? uniqid('fake_', true))),
            attachedStopLossPrice: $this->optionalFloat($payload, 'attached_stop_loss_price'),
            attachedTakeProfitPrice: $this->optionalFloat($payload, 'attached_take_profit_price'),
            metadata: \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonPayload(Request $request, bool $allowEmpty = false): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return $allowEmpty ? [] : throw new \InvalidArgumentException('empty JSON body');
        }

        $payload = json_decode($content, true);
        if (!\is_array($payload)) {
            throw new \InvalidArgumentException('invalid JSON body');
        }

        return $payload;
    }

    private function optionalSymbol(Request $request): ?string
    {
        $symbol = $request->query->get('symbol');

        return \is_string($symbol) && trim($symbol) !== '' ? strtoupper(trim($symbol)) : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function requiredFloat(array $payload, string $key, ?string $alias = null): float
    {
        $value = $payload[$key] ?? ($alias !== null ? ($payload[$alias] ?? null) : null);
        if (!\is_scalar($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be numeric', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function optionalFloat(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_scalar($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be numeric when provided', $key));
        }

        return (float) $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function optionalInt(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_scalar($value) || !is_numeric($value)) {
            throw new \InvalidArgumentException(sprintf('%s must be numeric when provided', $key));
        }

        return (int) $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function boolPayload(array $payload, string $key): bool
    {
        return \filter_var($payload[$key] ?? false, \FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<string,mixed>
     */
    private function orderToArray(ExchangeOrderDto $order): array
    {
        return [
            'exchange' => $order->exchange->value,
            'market_type' => $order->marketType->value,
            'symbol' => $order->symbol,
            'exchange_order_id' => $order->exchangeOrderId,
            'client_order_id' => $order->clientOrderId,
            'side' => $order->side->value,
            'position_side' => $order->positionSide?->value,
            'order_type' => $order->orderType->value,
            'status' => $order->status->value,
            'quantity' => $order->quantity,
            'filled_quantity' => $order->filledQuantity,
            'remaining_quantity' => $order->remainingQuantity,
            'price' => $order->price,
            'average_price' => $order->averagePrice,
            'stop_price' => $order->stopPrice,
            'reduce_only' => $order->reduceOnly,
            'post_only' => $order->postOnly,
            'time_in_force' => $order->timeInForce?->value,
            'created_at' => $order->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $order->updatedAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $order->metadata,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function positionToArray(ExchangePositionDto $position): array
    {
        return [
            'exchange' => $position->exchange->value,
            'market_type' => $position->marketType->value,
            'symbol' => $position->symbol,
            'side' => $position->side->value,
            'size' => $position->size,
            'entry_price' => $position->entryPrice,
            'mark_price' => $position->markPrice,
            'unrealized_pnl' => $position->unrealizedPnl,
            'realized_pnl' => $position->realizedPnl,
            'margin' => $position->margin,
            'leverage' => $position->leverage,
            'opened_at' => $position->openedAt?->format(\DateTimeInterface::ATOM),
            'updated_at' => $position->updatedAt?->format(\DateTimeInterface::ATOM),
            'metadata' => $position->metadata,
        ];
    }
}
