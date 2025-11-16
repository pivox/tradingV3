<?php

declare(strict_types=1);

namespace App\Trading\Storage;

use App\Entity\Position;
use App\Repository\PositionRepository;
use App\Trading\Dto\PositionDto;
use App\Trading\Dto\PositionHistoryEntryDto;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;

final class PositionPositionStateRepository implements PositionStateRepositoryInterface
{
    public function __construct(
        private readonly PositionRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function findLocalOpenPosition(string $symbol, string $side): ?PositionDto
    {
        /** @var Position|null $entity */
        $entity = $this->repository->findOneBySymbolSide($symbol, $side);
        if ($entity === null || $entity->getStatus() !== 'OPEN') {
            return null;
        }

        return $this->mapEntityToDto($entity);
    }

    /**
     * @param string[]|null $symbols
     * @return PositionDto[]
     */
    public function findLocalOpenPositions(?array $symbols = null): array
    {
        $qb = $this->repository->createQueryBuilder('p')
            ->where('p.status = :status')
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
        /** @var Position|null $entity */
        $entity = $this->repository->findOneBySymbolSide($position->symbol, $position->side->value);

        if ($entity === null) {
            $entity = new Position($position->symbol, $position->side->value);
        }

        $this->mapDtoToOpenEntity($position, $entity);
        $this->em->persist($entity);
        $this->em->flush();
    }

    public function saveClosedPosition(PositionHistoryEntryDto $history): void
    {
        /** @var Position|null $entity */
        $entity = $this->repository->findOneBySymbolSide($history->symbol, $history->side->value);

        if ($entity === null) {
            $entity = new Position($history->symbol, $history->side->value);
        }

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

    /**
     * @param string[]|null $symbols
     * @return PositionHistoryEntryDto[]
     */
    public function findLocalClosedPositions(
        ?array $symbols = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null
    ): array {
        $qb = $this->repository->createQueryBuilder('p')
            ->where('p.status = :status')
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
            $history = $this->mapEntityToHistoryDto($entity);
            if ($history !== null) {
                $result[] = $history;
            }
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
            raw: $payload
        );
    }

    private function mapDtoToOpenEntity(PositionDto $dto, Position $entity): void
    {
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

    private function mapEntityToHistoryDto(Position $entity): ?PositionHistoryEntryDto
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
            raw: $payload['raw_history'] ?? $payload
        );
    }
}

