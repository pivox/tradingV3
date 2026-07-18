<?php

declare(strict_types=1);

namespace App\Exchange\Event;

use App\Entity\Position;
use App\Common\Enum\Exchange;
use App\Common\Enum\MarketType;
use App\Exchange\Dto\ExchangeFillDto;
use App\Exchange\Dto\ExchangeOrderDto;
use App\Exchange\Dto\ExchangePositionDto;
use App\Exchange\Enum\ExchangeOrderSide;
use App\Exchange\Enum\ExchangeOrderStatus;
use App\Exchange\Enum\ExchangeOrderType;
use App\Exchange\Enum\ExchangePositionSide;
use App\MtfRunner\Service\FuturesOrderSyncService;
use App\Provider\Context\ExchangeContext;
use App\Repository\FuturesOrderRepository;
use App\Repository\PositionRepository;
use App\Trading\Pnl\FillCostLedgerIngestionService;
use App\Exchange\Value\ExactOrderQuantities;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(id: ExchangeLocalProjectionStoreInterface::class)]
final readonly class DoctrineExchangeLocalProjectionStore implements ExchangeLocalProjectionStoreInterface
{
    public function __construct(
        private FuturesOrderSyncService $orderSync,
        private FuturesOrderRepository $orders,
        private PositionRepository $positions,
        private EntityManagerInterface $entityManager,
        private FillCostLedgerIngestionService $fillCostLedger,
    ) {
    }

    public function hasOrder(ExchangeOrderDto $order): bool
    {
        $context = new ExchangeContext($order->exchange, $order->marketType);

        if ($this->orders->findOneByOrderId($order->exchangeOrderId, $context) !== null) {
            return true;
        }

        return $order->clientOrderId !== null
            && $this->orders->findOneByClientOrderId($order->clientOrderId, $context) !== null;
    }

    public function openOrders(Exchange $exchange, MarketType $marketType): array
    {
        $context = new ExchangeContext($exchange, $marketType);
        $result = [];
        foreach ($this->orders->findOpenOrders($context) as $order) {
            $status = $this->mapOpenOrderStatus($order->getStatus());
            if (!in_array($status, [ExchangeOrderStatus::PENDING, ExchangeOrderStatus::OPEN, ExchangeOrderStatus::PARTIALLY_FILLED], true)) {
                continue;
            }
            $sides = $this->storedOrderSides($order->getSide());
            $type = ExchangeOrderType::tryFrom((string) $order->getType());
            $orderId = $order->getOrderId();
            if ($sides === null || $type === null || $orderId === null) {
                continue;
            }
            [$side, $positionSide] = $sides;
            $quantityDecimal = $order->getQuantityDecimal();
            $filledQuantityDecimal = $order->getFilledQuantityDecimal();
            $quantity = (float) ($quantityDecimal ?? $order->getSize() ?? 0);
            $filled = (float) ($filledQuantityDecimal ?? $order->getFilledSize() ?? 0);
            $metadata = ['source' => 'local_projection'];
            if ($quantityDecimal !== null && $filledQuantityDecimal !== null) {
                $metadata += ExactOrderQuantities::fromQuantityAndFilled(
                    $quantityDecimal,
                    $filledQuantityDecimal,
                )->toArray();
            }
            $result[] = new ExchangeOrderDto(
                $exchange, $marketType, $order->getSymbol(), $orderId, $order->getClientOrderId(), $side, $positionSide,
                $type, $status, $quantity, $filled, max(0.0, $quantity - $filled),
                $order->getPrice() !== null ? (float) $order->getPrice() : null, null, null, false, false, null,
                $order->getCreatedAt(), $order->getUpdatedAt(), $metadata,
            );
        }
        return $result;
    }

    /** @return array{ExchangeOrderSide, ExchangePositionSide}|null */
    private function storedOrderSides(?int $side): ?array
    {
        return match ($side) {
            1 => [ExchangeOrderSide::BUY, ExchangePositionSide::LONG],
            2 => [ExchangeOrderSide::SELL, ExchangePositionSide::LONG],
            3 => [ExchangeOrderSide::BUY, ExchangePositionSide::SHORT],
            4 => [ExchangeOrderSide::SELL, ExchangePositionSide::SHORT],
            default => null,
        };
    }

    private function mapOpenOrderStatus(?string $status): ?ExchangeOrderStatus
    {
        return match (strtolower((string) $status)) {
            'new', 'sent', 'submitted', '1' => ExchangeOrderStatus::PENDING,
            '2' => ExchangeOrderStatus::PARTIALLY_FILLED,
            default => ExchangeOrderStatus::tryFrom((string) $status),
        };
    }

    public function openPositions(Exchange $exchange, MarketType $marketType, ?string $symbol = null): array
    {
        $context = new ExchangeContext($exchange, $marketType);
        $normalizedSymbol = $symbol !== null ? strtoupper($symbol) : null;
        $positions = [];

        foreach ($this->positions->findAllOpen($context) as $position) {
            if ($normalizedSymbol !== null && $position->getSymbol() !== $normalizedSymbol) {
                continue;
            }

            try {
                $side = ExchangePositionSide::from(strtolower($position->getSide()));
            } catch (\ValueError) {
                continue;
            }

            $positions[] = [
                'symbol' => $position->getSymbol(),
                'side' => $side,
                'size' => is_numeric($position->getSize()) ? (float)$position->getSize() : 0.0,
            ];
        }

        return $positions;
    }

    public function project(ExchangeEventInterface $event): void
    {
        if ($event instanceof AbstractExchangeOrderEvent) {
            if ($this->orderSync->syncOrderFromApi($this->orderPayload($event->order(), $event)) === null) {
                throw new \RuntimeException('exchange_order_projection_failed');
            }

            return;
        }

        if ($event instanceof ExchangeFillReceived) {
            $this->fillCostLedger->ingestExchangeFill($event);
            if ($this->orderSync->syncTradeFromApi($this->fillPayload($event->fill(), $event)) === null) {
                throw new \RuntimeException('exchange_fill_projection_failed');
            }

            return;
        }

        if ($event instanceof ExchangeFundingReceived) {
            $this->fillCostLedger->ingestFunding($event);

            return;
        }

        if ($event instanceof AbstractExchangePositionEvent) {
            $this->projectPosition($event);
        }
    }

    public function projectAtomically(array $events): void
    {
        try {
            $this->entityManager->getConnection()->transactional(function () use ($events): void {
                foreach ($events as $event) {
                    $this->project($event);
                }
            });
        } catch (\Throwable $exception) {
            if ($this->entityManager->isOpen()) {
                $this->entityManager->clear();
            }

            throw $exception;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function orderPayload(ExchangeOrderDto $order, ExchangeEventInterface $event): array
    {
        $exactQuantities = ExactOrderQuantities::fromArray($order->metadata)?->toArray() ?? [];
        $filledNotional = $order->averagePrice !== null && $order->filledQuantity > 0.0
            ? (string)($order->averagePrice * $order->filledQuantity)
            : null;

        return [
            'exchange' => $order->exchange->value,
            'market_type' => $order->marketType->value,
            'order_id' => $order->exchangeOrderId,
            'client_order_id' => $order->clientOrderId,
            'symbol' => $order->symbol,
            'side' => $this->numericSide($order->side, $order->positionSide),
            'type' => $order->orderType->value,
            'status' => $order->status->value,
            'price' => $order->price !== null ? (string)$order->price : null,
            'average_price' => $order->averagePrice !== null ? (string)$order->averagePrice : null,
            'stop_price' => $order->stopPrice !== null ? (string)$order->stopPrice : null,
            'size' => (int)round($order->quantity),
            'filled_size' => (int)round($order->filledQuantity),
            ...$exactQuantities,
            'filled_notional' => $filledNotional,
            'open_type' => $order->metadata['open_type'] ?? $order->metadata['margin_mode'] ?? null,
            'position_mode' => 1,
            'leverage' => isset($order->metadata['leverage']) && is_numeric($order->metadata['leverage'])
                ? (int)$order->metadata['leverage']
                : null,
            'filled_time' => $order->status->value === 'filled' ? $this->millis($order->updatedAt ?? $event->occurredAt()) : null,
            'created_time' => $this->millis($order->createdAt),
            'updated_time' => $this->millis($order->updatedAt ?? $event->occurredAt()),
            'raw' => [
                'event_type' => $event->eventType(),
                'order_type' => $order->orderType->value,
                'stop_price' => $order->stopPrice !== null ? (string)$order->stopPrice : null,
                'position_side' => $order->positionSide?->value,
                'remaining_quantity' => $order->remainingQuantity,
                'reduce_only' => $order->reduceOnly,
                'post_only' => $order->postOnly,
                'time_in_force' => $order->timeInForce?->value,
                'metadata' => $order->metadata,
                'payload' => $event->payload(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fillPayload(ExchangeFillDto $fill, ExchangeEventInterface $event): array
    {
        $payload = [
            'exchange' => $fill->exchange->value,
            'market_type' => $fill->marketType->value,
            'trade_id' => $this->projectionFillId($fill),
            'order_id' => $fill->exchangeOrderId,
            'symbol' => $fill->symbol,
            'side' => $this->numericSide($fill->side, $fill->positionSide),
            'price' => (string)$fill->price,
            'size' => (int)round($fill->quantity),
            'fee' => $fill->fee !== null ? (string)$fill->fee : null,
            'fee_currency' => $fill->feeCurrency,
            'trade_time' => $this->millis($fill->filledAt),
            'raw' => [
                'event_type' => $event->eventType(),
                'client_order_id' => $fill->clientOrderId,
                'position_side' => $fill->positionSide?->value,
                'metadata' => $fill->metadata,
                'payload' => $event->payload(),
            ],
        ];
        $quantityDecimal = $this->fillQuantityDecimal($fill, $event);
        if ($quantityDecimal !== null) {
            $payload['quantity_decimal'] = $quantityDecimal;
        }

        return $payload;
    }

    private function fillQuantityDecimal(ExchangeFillDto $fill, ExchangeEventInterface $event): ?string
    {
        $metadata = $fill->metadata['quantity_decimal'] ?? null;
        $eventPayload = $event->payload()['quantity_decimal'] ?? null;
        if ($metadata !== null && (!\is_string($metadata) || ($eventPayload !== null && $eventPayload !== $metadata))) {
            throw new \InvalidArgumentException('exchange_fill_quantity_decimal_invalid');
        }
        if ($eventPayload !== null && !\is_string($eventPayload)) {
            throw new \InvalidArgumentException('exchange_fill_quantity_decimal_invalid');
        }
        $value = $metadata ?? $eventPayload;
        if ($value === null) {
            return null;
        }

        try {
            $canonical = ExactOrderQuantities::canonicalNonNegative($value);
            ExactOrderQuantities::fromQuantityAndFilled($canonical, '0');

            return $canonical;
        } catch (\InvalidArgumentException) {
            throw new \InvalidArgumentException('exchange_fill_quantity_decimal_invalid');
        }
    }

    private function projectPosition(AbstractExchangePositionEvent $event): void
    {
        $context = new ExchangeContext($event->exchange(), $event->marketType());
        $position = $this->positions->findOneBySymbolSide($event->symbol(), $event->side()->value, $context);

        if (!$position instanceof Position) {
            $position = new Position($event->symbol(), $event->side()->value, $event->exchange(), $event->marketType());
        }

        if ($event instanceof ExchangePositionClosed) {
            $position
                ->setStatus('CLOSED')
                ->setSize('0')
                ->mergePayload($this->positionPayload($event, null));
        } else {
            $dto = $event->position();
            $position
                ->setStatus('OPEN')
                ->setSize((string)$event->size())
                ->setAvgEntryPrice($dto instanceof ExchangePositionDto ? (string)$dto->entryPrice : null)
                ->setLeverage($dto instanceof ExchangePositionDto && $dto->leverage !== null ? (int)round($dto->leverage) : null)
                ->setUnrealizedPnl($dto instanceof ExchangePositionDto && $dto->unrealizedPnl !== null ? (string)$dto->unrealizedPnl : null)
                ->mergePayload($this->positionPayload($event, $dto));
        }

        $this->entityManager->persist($position);
        $this->entityManager->flush();
    }

    /**
     * @return array<string,mixed>
     */
    private function positionPayload(AbstractExchangePositionEvent $event, ?ExchangePositionDto $position): array
    {
        return [
            'exchange_event_type' => $event->eventType(),
            'occurred_at' => $event->occurredAt()->format(\DateTimeInterface::ATOM),
            'size' => $event->size(),
            'mark_price' => $position?->markPrice,
            'realized_pnl' => $position?->realizedPnl,
            'margin' => $position?->margin,
            'metadata' => $position?->metadata ?? [],
            'payload' => $event->payload(),
        ];
    }

    private function numericSide(ExchangeOrderSide $side, ?ExchangePositionSide $positionSide): int
    {
        return match ([$side, $positionSide]) {
            [ExchangeOrderSide::BUY, ExchangePositionSide::LONG] => 1,
            [ExchangeOrderSide::SELL, ExchangePositionSide::LONG] => 2,
            [ExchangeOrderSide::BUY, ExchangePositionSide::SHORT] => 3,
            [ExchangeOrderSide::SELL, ExchangePositionSide::SHORT] => 4,
            default => $side === ExchangeOrderSide::BUY ? 1 : 4,
        };
    }

    private function deterministicFillId(ExchangeFillDto $fill): string
    {
        return 'fill-' . substr(hash('sha256', implode(':', [
            $fill->exchange->value,
            $fill->marketType->value,
            $fill->symbol,
            $fill->exchangeOrderId,
            $fill->filledAt->format('U.u'),
            (string)$fill->quantity,
            (string)$fill->price,
            $fill->side->value,
            $fill->positionSide?->value ?? '',
        ])), 0, 48);
    }

    private function projectionFillId(ExchangeFillDto $fill): string
    {
        $fillId = $fill->fillId !== null ? trim($fill->fillId) : '';

        return $fillId !== '' ? $fillId : $this->deterministicFillId($fill);
    }

    private function millis(?\DateTimeImmutable $time): ?int
    {
        if (!$time instanceof \DateTimeImmutable) {
            return null;
        }

        return ((int)$time->format('U') * 1000) + (int)floor(((int)$time->format('u')) / 1000);
    }
}
