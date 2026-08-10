<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Position;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Position>
 */
class PositionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Position::class);
    }

    public function findOneBySymbolSide(string $symbol, string $side, ?ExchangeContext $context = null): ?Position
    {
        $matches = $this->createQueryBuilder('position')
            ->where('position.exchange = :exchange')
            ->andWhere('position.marketType = :marketType')
            ->andWhere('position.symbol = :symbol')
            ->andWhere('position.side = :side')
            ->andWhere('position.status = :status')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', strtoupper($symbol))
            ->setParameter('side', strtoupper($side))
            ->setParameter('status', 'OPEN')
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();
        if (count($matches) > 1) {
            throw new \App\Trading\Lineage\LineageContextException('canonical_identity_mismatch:ambiguous_open_position');
        }

        return $matches[0] ?? null;
    }

    public function findOpenBySymbolSide(string $symbol, string $side, ?ExchangeContext $context = null): ?Position
    {
        return $this->findOneBySymbolSide($symbol, $side, $context);
    }

    public function findOneByCanonicalExchangePositionId(string $positionId, ?ExchangeContext $context = null): ?Position
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'canonicalExchangePositionId' => $positionId,
        ]);
    }

    public function upsert(Position $position): void
    {
        $em = $this->getEntityManager();
        $em->persist($position);
        $em->flush();
    }

    /**
     * Récupère toutes les positions ouvertes
     * @return Position[]
     */
    public function findAllOpen(?ExchangeContext $context = null): array
    {
        return $this->findBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'status' => 'OPEN',
        ]);
    }

    /**
     * Récupère les symboles uniques des positions ouvertes
     * @return string[]
     */
    public function findOpenSymbols(?ExchangeContext $context = null): array
    {
        $qb = $this->createQueryBuilder('p');
        $results = $qb
            ->select('DISTINCT p.symbol')
            ->where('p.exchange = :exchange')
            ->andWhere('p.marketType = :marketType')
            ->andWhere('p.status = :status')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('status', 'OPEN')
            ->getQuery()
            ->getResult();

        // Doctrine peut retourner soit un tableau associatif ['symbol' => '...'], soit un tableau indexé
        return array_map(function($row) {
            if (is_array($row)) {
                return isset($row['symbol']) ? (string)$row['symbol'] : (string)array_values($row)[0];
            }
            return (string)$row;
        }, $results);
    }

    /**
     * Historique des positions pour un symbole (OPEN + CLOSED),
     * ordonné par date d’insertion (approx. "open time").
     *
     * @return Position[]
     */
    public function findHistoryBySymbol(string $symbol, ?int $limit = null, ?ExchangeContext $context = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.exchange = :exchange')
            ->andWhere('p.marketType = :marketType')
            ->andWhere('p.symbol = :symbol')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('p.insertedAt', 'DESC');

        if ($limit !== null) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }
}
