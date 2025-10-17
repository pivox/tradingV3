<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\HotKline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class HotKlineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, HotKline::class);
    }

    /**
     * UPSERT de la bougie courante (ouverte) : remplace OHLC et met à jour last_update.
     * NOTE : plus fiable via SQL natif ON CONFLICT, mais on fournit ici une version ORM.
     */
    public function upsertOpen(
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $openTime,
        array $ohlc
    ): void {
        $em = $this->getEntityManager();

        /** @var HotKline|null $entity */
        $entity = $this->find([
            'symbol'   => $symbol,
            'timeframe'=> $timeframe,
            'openTime' => $openTime,
        ]);

        if (!$entity) {
            $entity = new HotKline($symbol, $timeframe, $openTime, $ohlc, false);
            $em->persist($entity);
        } else {
            // Ne jamais écraser une bougie déjà close
            if ($entity->isClosed()) {
                return;
            }
            $entity->setOhlc($ohlc);
            $entity->touchLastUpdate();
        }

        $em->flush();
    }

    /**
     * Marque la bougie précédente comme close lorsque la bougie "suivante" démarre.
     * $nextOpenTime = open_time de la nouvelle bougie.
     * $tfSeconds : durée du TF en secondes (ex: 60, 300, 900, 3600, 14400)
     */
    public function markPreviousClosed(string $symbol, string $timeframe, \DateTimeImmutable $nextOpenTime, int $tfSeconds): void
    {
        $prevOpen = $nextOpenTime->modify(sprintf('-%d seconds', $tfSeconds));

        $em = $this->getEntityManager();
        $qb = $this->createQueryBuilder('k')
            ->update()
            ->set('k.isClosed', ':true')
            ->set('k.lastUpdate', ':now')
            ->where('k.symbol = :symbol')
            ->andWhere('k.timeframe = :tf')
            ->andWhere('k.openTime = :prevOpen')
            ->andWhere('k.isClosed = :false')
            ->setParameter('true', true)
            ->setParameter('false', false)
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('symbol', $symbol)
            ->setParameter('tf', $timeframe)
            ->setParameter('prevOpen', $prevOpen);;

        $qb->getQuery()->execute();
    }

    /**
     * Dernière bougie CLOSE pour un symbole/TF (utile à la validation MTF).
     */
    public function findLatestClosed(string $symbol, string $timeframe): ?HotKline
    {
        return $this->createQueryBuilder('k')
            ->andWhere('k.symbol = :s')->setParameter('s', $symbol)
            ->andWhere('k.timeframe = :tf')->setParameter('tf', $timeframe)
            ->andWhere('k.isClosed = :closed')->setParameter('closed', true)
            ->orderBy('k.openTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}