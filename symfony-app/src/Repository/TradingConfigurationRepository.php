<?php

namespace App\Repository;

use App\Entity\TradingConfiguration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradingConfiguration>
 */
class TradingConfigurationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradingConfiguration::class);
    }

    public function findByContext(string $context, ?string $scope = null): ?TradingConfiguration
    {
        $qb = $this->createQueryBuilder('cfg')
            ->andWhere('cfg.context = :context')
            ->setParameter('context', strtolower($context))
            ->setMaxResults(1);

        if ($scope === null) {
            $qb->andWhere('cfg.scope IS NULL');
        } else {
            $qb->andWhere('cfg.scope = :scope')
                ->setParameter('scope', strtolower($scope));
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * @return array<int, TradingConfiguration>
     */
    public function findAllByScope(?string $scope = null): array
    {
        $qb = $this->createQueryBuilder('cfg')
            ->orderBy('cfg.context', 'ASC');

        if ($scope === null) {
            $qb->andWhere('cfg.scope IS NULL');
        } else {
            $qb->andWhere('cfg.scope = :scope')
                ->setParameter('scope', strtolower($scope));
        }

        return $qb->getQuery()->getResult();
    }

    public function findGlobal(): ?TradingConfiguration
    {
        return $this->findByContext(TradingConfiguration::CONTEXT_GLOBAL, null);
    }
}
