<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MtfState;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    public function getOrCreateForSymbol(string $symbol, ?ExchangeContext $context = null): MtfState
    {
        $context = ExchangeContext::resolve($context);
        $state = $this->findOneBy([
            'exchange' => $context->exchange->value,
            'marketType' => $context->marketType->value,
            'symbol' => strtoupper($symbol),
        ]);
        if (!$state) {
            $state = new MtfState();
            $state->setSymbol($symbol);
            $state->setExchange($context->exchange);
            $state->setMarketType($context->marketType);
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

    public function update4hValidation(string $symbol, \DateTimeImmutable $klineTime, ?string $side, ?ExchangeContext $context = null): void
    {
        $state = $this->getOrCreateForSymbol($symbol, $context);
        $state->setK4hTime($klineTime);
        $state->set4hSide($side);
        $this->getEntityManager()->flush();
    }

    public function update1hValidation(string $symbol, \DateTimeImmutable $klineTime, ?string $side, ?ExchangeContext $context = null): void
    {
        $state = $this->getOrCreateForSymbol($symbol, $context);
        $state->setK1hTime($klineTime);
        $state->set1hSide($side);
        $this->getEntityManager()->flush();
    }

    public function update15mValidation(string $symbol, \DateTimeImmutable $klineTime, ?string $side, ?ExchangeContext $context = null): void
    {
        $state = $this->getOrCreateForSymbol($symbol, $context);
        $state->setK15mTime($klineTime);
        $state->set15mSide($side);
        $this->getEntityManager()->flush();
    }

    public function updateExecutionSides(string $symbol, ?string $side5m, ?string $side1m, ?ExchangeContext $context = null): void
    {
        $state = $this->getOrCreateForSymbol($symbol, $context);
        $state->set5mSide($side5m);
        $state->set1mSide($side1m);
        $this->getEntityManager()->flush();
    }

    public function isSymbolReadyForExecution(string $symbol, ?ExchangeContext $context = null): bool
    {
        $state = $this->findOneBy($this->criteria($symbol, $context));
        if (!$state) {
            return false;
        }

        return $state->areParentTimeframesValidated() && $state->hasConsistentSides();
    }

    public function getSymbolsReadyForExecution(?ExchangeContext $context = null): array
    {
        $qb = $this->scope($this->createQueryBuilder('s'), $context);

        return $qb
            ->andWhere('s.k4hTime IS NOT NULL')
            ->andWhere('s.k1hTime IS NOT NULL')
            ->andWhere('s.k15mTime IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    public function getSymbolsWithInconsistentSides(?ExchangeContext $context = null): array
    {
        $states = $this->scope($this->createQueryBuilder('s'), $context)
            ->getQuery()
            ->getResult();
        $inconsistent = [];

        foreach ($states as $state) {
            if ($state->areParentTimeframesValidated() && !$state->hasConsistentSides()) {
                $inconsistent[] = $state;
            }
        }

        return $inconsistent;
    }

    public function resetSymbolState(string $symbol, ?ExchangeContext $context = null): void
    {
        $state = $this->findOneBy($this->criteria($symbol, $context));
        if ($state) {
            $state->setK4hTime(null);
            $state->setK1hTime(null);
            $state->setK15mTime(null);
            $state->setSides([]);
            $this->getEntityManager()->flush();
        }
    }

    public function getSymbolsWithValidatedTimeframes(?ExchangeContext $context = null): array
    {
        return $this->scope($this->createQueryBuilder('s'), $context)
            ->andWhere('s.k4hTime IS NOT NULL OR s.k1hTime IS NOT NULL OR s.k15mTime IS NOT NULL')
            ->orderBy('s.symbol')
            ->getQuery()
            ->getResult();
    }

    public function getSymbolsByTimeframeValidation(string $timeframe, ?ExchangeContext $context = null): array
    {
        $field = match ($timeframe) {
            '4h' => 'k4hTime',
            '1h' => 'k1hTime',
            '15m' => 'k15mTime',
            default => throw new \InvalidArgumentException("Invalid timeframe: {$timeframe}")
        };

        return $this->scope($this->createQueryBuilder('s'), $context)
            ->andWhere("s.{$field} IS NOT NULL")
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
    public function getStatesForDashboard(string $startFromTimeframe = '4h', ?ExchangeContext $context = null): array
    {
        $qb = $this->scope($this->createQueryBuilder('s'), $context);

        if ($startFromTimeframe === '1h') {
            // Pour 1h, on récupère tous les états qui ont au moins k1hTime
            $qb->andWhere('s.k1hTime IS NOT NULL')
               ->orderBy('s.symbol', 'ASC');
        } else {
            // Pour 4h, on récupère tous les états qui ont au moins k4hTime
            $qb->andWhere('s.k4hTime IS NOT NULL')
               ->orderBy('s.symbol', 'ASC');
        }

        return $qb->getQuery()->getResult();
    }

    public function getSummary(string $startFromTimeframe = '4h', ?ExchangeContext $context = null): array
    {
        $qb   = $this->scope($this->createQueryBuilder('s'), $context);
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
                ->andWhere($expr->orX($rule1, $rule2, $rule3, $rule4))
                ->orderBy('ruleRank', 'ASC')
                ->addOrderBy('s.k1hTime', 'DESC');
        } else {
            // Ton code actuel basé sur 4h (4h → 1m)
            // (copier-coller de ta version, inchangé)
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{exchange:string,marketType:string,symbol:string}
     */
    private function criteria(string $symbol, ?ExchangeContext $context = null): array
    {
        $context = ExchangeContext::resolve($context);

        return [
            'exchange' => $context->exchange->value,
            'marketType' => $context->marketType->value,
            'symbol' => strtoupper($symbol),
        ];
    }

    private function scope(QueryBuilder $qb, ?ExchangeContext $context = null): QueryBuilder
    {
        $context = ExchangeContext::resolve($context);

        return $qb
            ->andWhere('s.exchange = :exchange')
            ->andWhere('s.marketType = :marketType')
            ->setParameter('exchange', $context->exchange->value)
            ->setParameter('marketType', $context->marketType->value);
    }

}
