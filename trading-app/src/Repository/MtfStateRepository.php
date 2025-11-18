<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MtfState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MtfState>
 */
class MtfStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MtfState::class);
    }

    public function getOrCreateForSymbol(string $symbol): MtfState
    {
        $state = $this->findOneBy(['symbol' => $symbol]);
        if (!$state) {
            $state = new MtfState();
            $state->setSymbol($symbol);
            $em = $this->getEntityManager();
            // Eviter les erreurs en cascade si l'EntityManager est fermé
            $isOpen = true;
            try {
                if (method_exists($em, 'isOpen')) {
                    $isOpen = (bool) $em->isOpen();
                }
            } catch (\Throwable) {
                $isOpen = true;
            }

            if ($isOpen) {
                try {
                    $em->persist($state);
                    $em->flush();
                } catch (\Throwable) {
                    // best-effort: retourner un état non flushé pour éviter de masquer l'erreur racine
                }
            }
        }
        return $state;
    }

    public function update4hValidation(string $symbol, \DateTimeImmutable $klineTime, ?string $side): void
    {
        $state = $this->getOrCreateForSymbol($symbol);
        $state->setK4hTime($klineTime);
        $state->set4hSide($side);
        $this->getEntityManager()->flush();
    }

    public function update1hValidation(string $symbol, \DateTimeImmutable $klineTime, ?string $side): void
    {
        $state = $this->getOrCreateForSymbol($symbol);
        $state->setK1hTime($klineTime);
        $state->set1hSide($side);
        $this->getEntityManager()->flush();
    }

    public function update15mValidation(string $symbol, \DateTimeImmutable $klineTime, ?string $side): void
    {
        $state = $this->getOrCreateForSymbol($symbol);
        $state->setK15mTime($klineTime);
        $state->set15mSide($side);
        $this->getEntityManager()->flush();
    }

    public function updateExecutionSides(string $symbol, ?string $side5m, ?string $side1m): void
    {
        $state = $this->getOrCreateForSymbol($symbol);
        $state->set5mSide($side5m);
        $state->set1mSide($side1m);
        $this->getEntityManager()->flush();
    }

    public function isSymbolReadyForExecution(string $symbol): bool
    {
        $state = $this->findOneBy(['symbol' => $symbol]);
        if (!$state) {
            return false;
        }

        return $state->areParentTimeframesValidated() && $state->hasConsistentSides();
    }

    public function getSymbolsReadyForExecution(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.k4hTime IS NOT NULL')
            ->andWhere('s.k1hTime IS NOT NULL')
            ->andWhere('s.k15mTime IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    public function getSymbolsWithInconsistentSides(): array
    {
        $states = $this->findAll();
        $inconsistent = [];

        foreach ($states as $state) {
            if ($state->areParentTimeframesValidated() && !$state->hasConsistentSides()) {
                $inconsistent[] = $state;
            }
        }

        return $inconsistent;
    }

    public function resetSymbolState(string $symbol): void
    {
        $state = $this->findOneBy(['symbol' => $symbol]);
        if ($state) {
            $state->setK4hTime(null);
            $state->setK1hTime(null);
            $state->setK15mTime(null);
            $state->setSides([]);
            $this->getEntityManager()->flush();
        }
    }

    public function getSymbolsWithValidatedTimeframes(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.k4hTime IS NOT NULL OR s.k1hTime IS NOT NULL OR s.k15mTime IS NOT NULL')
            ->orderBy('s.symbol')
            ->getQuery()
            ->getResult();
    }

    public function getSymbolsByTimeframeValidation(string $timeframe): array
    {
        $field = match ($timeframe) {
            '4h' => 'k4hTime',
            '1h' => 'k1hTime',
            '15m' => 'k15mTime',
            default => throw new \InvalidArgumentException("Invalid timeframe: {$timeframe}")
        };

        return $this->createQueryBuilder('s')
            ->where("s.{$field} IS NOT NULL")
            ->orderBy('s.symbol')
            ->getQuery()
            ->getResult();
    }

    /**
     * Récupère tous les états pertinents pour le dashboard selon le timeframe de départ
     *
     * @param string $startFromTimeframe '4h' ou '1h'
     * @return MtfState[]
     */
    public function getStatesForDashboard(string $startFromTimeframe = '4h'): array
    {
        $qb = $this->createQueryBuilder('s');

        if ($startFromTimeframe === '1h') {
            // Pour 1h, on récupère tous les états qui ont au moins k1hTime
            $qb->where('s.k1hTime IS NOT NULL')
               ->orderBy('s.symbol', 'ASC');
        } else {
            // Pour 4h, on récupère tous les états qui ont au moins k4hTime
            $qb->where('s.k4hTime IS NOT NULL')
               ->orderBy('s.symbol', 'ASC');
        }

        return $qb->getQuery()->getResult();
    }

    public function getSummary(string $startFromTimeframe = '4h'): array
    {
        $qb   = $this->createQueryBuilder('s');
        $expr = $qb->expr();

        if ($startFromTimeframe === '1h') {
            // Règles basées sur 1h (4h optionnel, juste pour info)
            $rule1 = $expr->andX(
                's.k1hTime IS NOT NULL',
                's.k15mTime IS NOT NULL',
                's.k5mTime IS NOT NULL',
                's.k1mTime IS NOT NULL',
                's.k1hTime < s.k15mTime',
                's.k15mTime < s.k5mTime',
                's.k5mTime < s.k1mTime'
            );

            $rule2 = $expr->andX(
                's.k1hTime IS NOT NULL',
                's.k15mTime IS NOT NULL',
                's.k5mTime IS NOT NULL',
                's.k1hTime < s.k15mTime',
                's.k15mTime < s.k5mTime'
            );

            $rule3 = $expr->andX(
                's.k1hTime IS NOT NULL',
                's.k15mTime IS NOT NULL',
                's.k1hTime < s.k15mTime'
            );

            $rule4 = $expr->andX(
                's.k1hTime IS NOT NULL'
            );

            $caseRuleRank = <<<DQL
CASE
    WHEN
        s.k1hTime IS NOT NULL AND
        s.k15mTime IS NOT NULL AND
        s.k5mTime IS NOT NULL AND
        s.k1mTime IS NOT NULL AND
        s.k1hTime < s.k15mTime AND
        s.k15mTime < s.k5mTime AND
        s.k5mTime < s.k1mTime
    THEN 1
    WHEN
        s.k1hTime IS NOT NULL AND
        s.k15mTime IS NOT NULL AND
        s.k5mTime IS NOT NULL AND
        s.k1hTime < s.k15mTime AND
        s.k15mTime < s.k5mTime
    THEN 2
    WHEN
        s.k1hTime IS NOT NULL AND
        s.k15mTime IS NOT NULL AND
        s.k1hTime < s.k15mTime
    THEN 3
    WHEN
        s.k1hTime IS NOT NULL
    THEN 4
    ELSE 5
END
DQL;

            $qb
                ->addSelect($caseRuleRank . ' AS HIDDEN ruleRank')
                ->where($expr->orX($rule1, $rule2, $rule3, $rule4))
                ->orderBy('ruleRank', 'ASC')
                ->addOrderBy('s.k1hTime', 'DESC');
        } else {
            // Ton code actuel basé sur 4h (4h → 1m)
            // (copier-coller de ta version, inchangé)
        }

        return $qb->getQuery()->getResult();
    }

}


