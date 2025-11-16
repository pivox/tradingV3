<?php

declare(strict_types=1);

namespace App\Trading\Storage;

use App\Entity\FuturesOrder;
use App\Repository\FuturesOrderRepository;
use App\Trading\Dto\OrderDto;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;

final class FuturesOrderOrderStateRepository implements OrderStateRepositoryInterface
{
    public function __construct(
        private readonly FuturesOrderRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function findLocalOrder(string $symbol, string $orderId): ?OrderDto
    {
        /** @var FuturesOrder|null $entity */
        $entity = $this->repository->findOneByOrderId($orderId);
        if ($entity === null || strtoupper($entity->getSymbol()) !== strtoupper($symbol)) {
            return null;
        }

        return $this->mapEntityToDto($entity);
    }

    /**
     * @param string[]|null $symbols
     * @return OrderDto[]
     */
    public function findLocalOpenOrders(?array $symbols = null): array
    {
        // Statuts considérés comme "ouverts" : pending, partially_filled
        $openStatuses = ['pending', 'partially_filled', 'new', 'sent'];

        $qb = $this->repository->createQueryBuilder('o')
            ->where('o.status IN (:statuses)')
            ->setParameter('statuses', $openStatuses);

        if ($symbols !== null && $symbols !== []) {
            $qb->andWhere('o.symbol IN (:symbols)')
               ->setParameter('symbols', array_map('strtoupper', $symbols));
        }

        /** @var FuturesOrder[] $entities */
        $entities = $qb->getQuery()->getResult();

        $result = [];
        foreach ($entities as $entity) {
            $result[] = $this->mapEntityToDto($entity);
        }

        return $result;
    }

    public function saveOrder(OrderDto $order): void
    {
        /** @var FuturesOrder|null $entity */
        $entity = $this->repository->findOneByOrderId($order->orderId);

        if ($entity === null) {
            $entity = new FuturesOrder();
            $entity->setOrderId($order->orderId);
        }

        $this->mapDtoToEntity($order, $entity);
        $this->em->persist($entity);
        $this->em->flush();
    }

    private function mapEntityToDto(FuturesOrder $entity): OrderDto
    {
        // Mapper le side numérique vers OrderSide enum
        $side = $this->mapNumericSideToOrderSide($entity->getSide());
        
        // Mapper le type avec gestion d'erreur
        $type = \App\Common\Enum\OrderType::MARKET;
        if ($entity->getType() !== null) {
            try {
                $type = \App\Common\Enum\OrderType::from($entity->getType());
            } catch (\ValueError $e) {
                // Valeur inconnue, utiliser MARKET par défaut
                $type = \App\Common\Enum\OrderType::MARKET;
            }
        }
        
        // Mapper le status avec gestion d'erreur
        $status = \App\Common\Enum\OrderStatus::PENDING;
        if ($entity->getStatus() !== null) {
            try {
                $status = \App\Common\Enum\OrderStatus::from($entity->getStatus());
            } catch (\ValueError $e) {
                // Valeur inconnue, utiliser PENDING par défaut
                $status = \App\Common\Enum\OrderStatus::PENDING;
            }
        }

        $price = $entity->getPrice() !== null ? BigDecimal::of($entity->getPrice()) : BigDecimal::zero();
        $quantity = $entity->getSize() !== null ? BigDecimal::of((string)$entity->getSize()) : BigDecimal::zero();
        $filledQuantity = $entity->getFilledSize() !== null ? BigDecimal::of((string)$entity->getFilledSize()) : BigDecimal::zero();

        // Calculer avgFilledPrice depuis filledNotional et filledSize
        $avgFilledPrice = null;
        if ($entity->getFilledNotional() !== null && $entity->getFilledSize() !== null && $entity->getFilledSize() > 0) {
            $avgFilledPrice = BigDecimal::of($entity->getFilledNotional())->dividedBy(BigDecimal::of((string)$entity->getFilledSize()));
        }

        $createdAt = null;
        if ($entity->getCreatedTime() !== null) {
            $createdAt = (new \DateTimeImmutable('@' . (int)($entity->getCreatedTime() / 1000)))->setTimezone(new \DateTimeZone('UTC'));
        }

        $updatedAt = null;
        if ($entity->getUpdatedTime() !== null) {
            $updatedAt = (new \DateTimeImmutable('@' . (int)($entity->getUpdatedTime() / 1000)))->setTimezone(new \DateTimeZone('UTC'));
        }

        return new OrderDto(
            orderId: $entity->getOrderId() ?? '',
            clientOrderId: $entity->getClientOrderId(),
            symbol: $entity->getSymbol(),
            side: $side,
            type: $type,
            status: $status,
            price: $price,
            quantity: $quantity,
            filledQuantity: $filledQuantity,
            avgFilledPrice: $avgFilledPrice,
            createdAt: $createdAt ?? new \DateTimeImmutable('now'),
            updatedAt: $updatedAt,
            raw: $entity->getRawData()
        );
    }

    private function mapDtoToEntity(OrderDto $dto, FuturesOrder $entity): void
    {
        $entity->setSymbol($dto->symbol);
        $entity->setClientOrderId($dto->clientOrderId);
        $entity->setSide($this->mapOrderSideToNumericSide($dto->side));
        $entity->setType($dto->type->value);
        $entity->setStatus($dto->status->value);
        $entity->setPrice($dto->price->__toString());
        $entity->setSize((int)$dto->quantity->__toString());
        $entity->setFilledSize((int)$dto->filledQuantity->__toString());

        if ($dto->avgFilledPrice !== null) {
            $filledNotional = $dto->avgFilledPrice->multipliedBy($dto->filledQuantity);
            $entity->setFilledNotional($filledNotional->__toString());
        }

        if ($dto->createdAt !== null) {
            $entity->setCreatedTime($dto->createdAt->getTimestamp() * 1000);
        }

        if ($dto->updatedAt !== null) {
            $entity->setUpdatedTime($dto->updatedAt->getTimestamp() * 1000);
        }

        $entity->setRawData($dto->raw);
    }

    /**
     * Mappe le side numérique BitMart vers OrderSide enum
     * 1=open_long, 2=close_long, 3=close_short, 4=open_short
     */
    private function mapNumericSideToOrderSide(?int $numericSide): \App\Common\Enum\OrderSide
    {
        // Par défaut, on considère que 1 et 4 sont des BUY, 2 et 3 sont des SELL
        // Mais pour les ordres ouverts, on peut aussi utiliser le contexte
        if ($numericSide === null) {
            return \App\Common\Enum\OrderSide::BUY;
        }

        // Pour simplifier, on mappe 1 et 4 vers BUY, 2 et 3 vers SELL
        // Dans un contexte réel, il faudrait peut-être plus de logique
        return in_array($numericSide, [1, 4], true) ? \App\Common\Enum\OrderSide::BUY : \App\Common\Enum\OrderSide::SELL;
    }

    /**
     * Mappe OrderSide enum vers side numérique BitMart
     */
    private function mapOrderSideToNumericSide(\App\Common\Enum\OrderSide $orderSide): int
    {
        // Par défaut, BUY = open_long (1), SELL = open_short (4)
        // Dans un contexte réel, il faudrait plus de contexte pour déterminer si c'est open ou close
        return $orderSide === \App\Common\Enum\OrderSide::BUY ? 1 : 4;
    }
}

