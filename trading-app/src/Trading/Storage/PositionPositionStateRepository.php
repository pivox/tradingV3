<?php

declare(strict_types=1);

namespace App\Trading\Storage;

use App\Entity\Position;
use App\Provider\Context\ExchangeContext;
use App\Repository\PositionRepository;
use App\Trading\Dto\PositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use App\Trading\Lineage\LineageContextException;
use App\Trading\Lineage\Persistence\CanonicalPositionRecoveryService;

final class PositionPositionStateRepository implements PositionStateRepositoryInterface
{
    public function __construct(
        private readonly PositionRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly ?CanonicalPositionRecoveryService $canonicalRecovery = null,
    ) {}

    public function findLocalOpenPosition(
        string $symbol,
        string $side,
        ?ExchangeContext $context = null,
    ): ?PositionDto
    {
        /** @var Position|null $entity */
        $entity = $this->repository->findOneBySymbolSide($symbol, $side, $context);
        if ($entity === null || $entity->getStatus() !== 'OPEN') {
            return null;
        }

        return $this->mapEntityToDto($entity);
    }

    /**
     * @param string[]|null $symbols
     * @return PositionDto[]
     */
    public function findLocalOpenPositions(
        ?array $symbols = null,
        ?ExchangeContext $context = null,
    ): array
    {
        $context = ExchangeContext::resolve($context);
        $qb = $this->repository->createQueryBuilder('p')
            ->where('p.exchange = :exchange')
            ->andWhere('p.marketType = :marketType')
            ->andWhere('p.status = :status')
            ->setParameter('exchange', $context->exchange->value)
            ->setParameter('marketType', $context->marketType->value)
            ->setParameter('status', 'OPEN');

        if ($symbols !== null && $symbols !== []) {
            $qb->andWhere('p.symbol IN (:symbols)')
               ->setParameter('symbols', array_map('strtoupper', $symbols));
        }

        /** @var Position[] $entities */
        $entities = $qb->getQuery()->getResult();

        $result = [];
        foreach ($entities as $entity) {
            $result[] = $this->mapEntityToDto($entity);
        }

        return $result;
    }

    public function saveOpenPosition(PositionDto $position): void
    {
        $context = $this->resolveContext($position->raw);
        $evidence = $position->canonicalEvidence();
        $entity = $evidence->exchangePositionId !== null
            ? $this->repository->findOneByCanonicalExchangePositionId($evidence->exchangePositionId, $context)
            : $this->repository->findOneBySymbolSide($position->symbol, $position->side->value, $context);

        if ($entity === null) {
            $entity = new Position($position->symbol, $position->side->value, $context->exchange, $context->marketType);
        }

        $this->applyCanonicalRecovery($entity, $evidence, $context, $position->symbol, $position->side->value);
        $this->mapDtoToOpenEntity($position, $entity);
        $this->em->persist($entity);
        $this->em->flush();
    }

    public function saveClosedPosition(PositionHistoryEntryDto $history): void
    {
        $context = $this->resolveContext($history->raw);
        $evidence = $history->canonicalEvidence();
        $entity = $evidence->exchangePositionId !== null
            ? $this->repository->findOneByCanonicalExchangePositionId($evidence->exchangePositionId, $context)
            : $this->repository->findOpenBySymbolSide($history->symbol, $history->side->value, $context);

        if ($entity === null) {
            $entity = new Position($history->symbol, $history->side->value, $context->exchange, $context->marketType);
        }

        $this->applyCanonicalRecovery(
            $entity,
            $evidence,
            $context,
            $history->symbol,
            $history->side->value,
        );

        $entity->setStatus('CLOSED');
        $entity->setSize($history->size->__toString());
        $entity->setAvgEntryPrice($history->entryPrice->__toString());
        $entity->setUnrealizedPnl($history->realizedPnl->__toString());
        $entity->mergePayload([
            'exit_price' => $history->exitPrice->__toString(),
            'fees' => $history->fees?->__toString(),
            'closed_at' => $history->closedAt->format('Y-m-d H:i:s'),
            'raw_history' => $history->raw,
        ]);

        $this->em->persist($entity);
        $this->em->flush();
    }

    private function applyCanonicalRecovery(
        Position $entity,
        \App\Trading\Lineage\Persistence\CanonicalPositionEvidence $evidence,
        ExchangeContext $context,
        string $symbol,
        string $side,
    ): void {
        $classification = $entity->lineageClassification();
        if ($entity->getSymbol() !== strtoupper($symbol)
            || $entity->getSide() !== strtoupper($side)
            || $entity->getExchange() !== $context->exchange->value
            || $entity->getMarketType() !== $context->marketType->value
        ) {
            throw new LineageContextException('canonical_identity_mismatch:position_boundary');
        }
        if ($classification === 'incomplete') {
            throw new LineageContextException('canonical_identity_incomplete:position');
        }
        $predecessor = $this->canonicalRecovery?->resolve($evidence, $context);
        if ($classification === 'canonical') {
            $stored = $entity->requireLineageContext();
            if ($evidence->exchangePositionId === null
                || $predecessor === null
                || $predecessor->context->toArray() !== $stored->toArray()
                || $predecessor->exchangePositionId !== $entity->getCanonicalExchangePositionId()
            ) {
                throw new LineageContextException('canonical_identity_mismatch:position_predecessor');
            }

            return;
        }
        if ($predecessor !== null) {
            if (!\in_array($predecessor->order->getSide(), [1, 4], true)) {
                throw new LineageContextException('canonical_identity_invalid:position_opening_order');
            }
            $entity->applyCanonicalPredecessor($predecessor);
        }
    }

    /**
     * @param string[]|null $symbols
     * @return PositionHistoryEntryDto[]
     */
    public function findLocalClosedPositions(
        ?array $symbols = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
        ?ExchangeContext $context = null,
    ): array {
        $context = ExchangeContext::resolve($context);
        $qb = $this->repository->createQueryBuilder('p')
            ->where('p.exchange = :exchange')
            ->andWhere('p.marketType = :marketType')
            ->andWhere('p.status = :status')
            ->setParameter('exchange', $context->exchange->value)
            ->setParameter('marketType', $context->marketType->value)
            ->setParameter('status', 'CLOSED')
            ->orderBy('p.updatedAt', 'DESC');

        if ($symbols !== null && $symbols !== []) {
            $qb->andWhere('p.symbol IN (:symbols)')
               ->setParameter('symbols', array_map('strtoupper', $symbols));
        }

        if ($from !== null) {
            $qb->andWhere('p.updatedAt >= :from')
               ->setParameter('from', $from);
        }

        if ($to !== null) {
            $qb->andWhere('p.updatedAt <= :to')
               ->setParameter('to', $to);
        }

        /** @var Position[] $entities */
        $entities = $qb->getQuery()->getResult();

        $result = [];
        foreach ($entities as $entity) {
            $result[] = $this->mapEntityToHistoryDto($entity);
        }

        return $result;
    }

    private function mapEntityToDto(Position $entity): PositionDto
    {
        $payload = $entity->getPayload();
        $markPrice = BigDecimal::of($payload['mark_price'] ?? $entity->getAvgEntryPrice() ?? '0');
        $margin = BigDecimal::of($payload['margin'] ?? '0');
        $leverage = $entity->getLeverage() !== null ? BigDecimal::of((string)$entity->getLeverage()) : BigDecimal::of('1');

        // Mapper le side avec gestion d'erreur
        $sideStr = strtolower($entity->getSide());
        try {
            $side = \App\Common\Enum\PositionSide::from($sideStr);
        } catch (\ValueError $e) {
            // Valeur inconnue, utiliser LONG par défaut
            $side = \App\Common\Enum\PositionSide::LONG;
        }

        return new PositionDto(
            symbol: $entity->getSymbol(),
            side: $side,
            size: BigDecimal::of($entity->getSize() ?? '0'),
            entryPrice: BigDecimal::of($entity->getAvgEntryPrice() ?? '0'),
            markPrice: $markPrice,
            unrealizedPnl: BigDecimal::of($entity->getUnrealizedPnl() ?? '0'),
            leverage: $leverage,
            openedAt: $entity->getInsertedAt(),
            raw: array_replace($payload, [
                'exchange' => $entity->getExchange(),
                'market_type' => $entity->getMarketType(),
            ]),
            exchangePositionId: $entity->getCanonicalExchangePositionId(),
            exchangeOrderId: $entity->getOpeningOrder()?->getOrderId(),
            clientOrderId: $entity->getOpeningOrder()?->getClientOrderId(),
            exchangeFillId: $entity->getOpeningFill()?->getTradeId(),
        );
    }

    private function mapDtoToOpenEntity(PositionDto $dto, Position $entity): void
    {
        $context = $this->resolveContext($dto->raw);
        $entity->setExchange($context->exchange);
        $entity->setMarketType($context->marketType);
        $entity->setStatus('OPEN');
        $entity->setSize($dto->size->__toString());
        $entity->setAvgEntryPrice($dto->entryPrice->__toString());
        $entity->setUnrealizedPnl($dto->unrealizedPnl->__toString());
        $entity->setLeverage((int)$dto->leverage->__toString());
        $entity->mergePayload([
            'mark_price' => $dto->markPrice->__toString(),
            'raw_snapshot' => $dto->raw,
        ]);
    }

    /**
     * @param array<string,mixed> $raw
     */
    private function resolveContext(array $raw): ExchangeContext
    {
        return ExchangeContext::fromArray($raw);
    }

    private function mapEntityToHistoryDto(Position $entity): PositionHistoryEntryDto
    {
        $payload = $entity->getPayload();
        $exitPrice = $payload['exit_price'] ?? $entity->getAvgEntryPrice() ?? '0';
        $fees = isset($payload['fees']) ? BigDecimal::of($payload['fees']) : null;
        $closedAtStr = $payload['closed_at'] ?? null;

        if ($closedAtStr === null) {
            // Utiliser updatedAt comme date de fermeture si closed_at n'est pas dans payload
            $closedAt = $entity->getUpdatedAt();
        } else {
            try {
                $closedAt = new \DateTimeImmutable($closedAtStr);
            } catch (\Exception) {
                $closedAt = $entity->getUpdatedAt();
            }
        }

        // Mapper le side avec gestion d'erreur
        $sideStr = strtolower($entity->getSide());
        try {
            $side = \App\Common\Enum\PositionSide::from($sideStr);
        } catch (\ValueError $e) {
            // Valeur inconnue, utiliser LONG par défaut
            $side = \App\Common\Enum\PositionSide::LONG;
        }

        return new PositionHistoryEntryDto(
            symbol: $entity->getSymbol(),
            side: $side,
            size: BigDecimal::of($entity->getSize() ?? '0'),
            entryPrice: BigDecimal::of($entity->getAvgEntryPrice() ?? '0'),
            exitPrice: BigDecimal::of($exitPrice),
            realizedPnl: BigDecimal::of($entity->getUnrealizedPnl() ?? '0'), // Pour CLOSED, unrealizedPnl contient le realized
            fees: $fees,
            openedAt: $entity->getInsertedAt(),
            closedAt: $closedAt,
            raw: array_replace($payload['raw_history'] ?? $payload, [
                'exchange' => $entity->getExchange(),
                'market_type' => $entity->getMarketType(),
            ])
        );
    }
}
