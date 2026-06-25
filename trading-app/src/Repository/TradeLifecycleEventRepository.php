<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradeLifecycleEvent;
use App\Provider\Context\ExchangeContext;
use App\Trading\Lineage\ReadModel\LineageReadCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeLifecycleEvent>
 */
final class TradeLifecycleEventRepository extends ServiceEntityRepository
{
    private const CRITERIA_FIELD_MAP = [
        'orchestration_run_id' => 'orchestrationRunId',
        'correlation_run_id' => 'correlationRunId',
        'orchestration_set_id' => 'orchestrationSetId',
        'orchestration_dashboard_id' => 'orchestrationDashboardId',
        'internal_trade_id' => 'internalTradeId',
        'internal_position_id' => 'internalPositionId',
        'client_order_id' => 'clientOrderId',
        'exchange_order_id' => 'orderId',
        'position_id' => 'positionId',
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeLifecycleEvent::class);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return TradeLifecycleEvent[]
     */
    public function findRecentBy(array $criteria, int $limit = 50, ?ExchangeContext $context = null): array
    {
        $qb = $this->createQueryBuilder('event')
            ->orderBy('event.happenedAt', 'DESC')
            ->setMaxResults($limit);

        $criteria += [
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
        ];

        foreach ($criteria as $field => $value) {
            $param = ':' . $field;
            $qb->andWhere(sprintf('event.%s = %s', $field, $param))
                ->setParameter($field, $value);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return TradeLifecycleEvent[]
     */
    public function findUnmatchedByReadCriteria(LineageReadCriteria $criteria): array
    {
        if (!in_array($criteria->kind, ['client_order_id', 'exchange_order_id', 'position_id'], true)) {
            return [];
        }

        return $this->createCriteriaQueryBuilder($criteria)
            ->andWhere('event.internalTradeId IS NULL')
            ->orderBy('event.happenedAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->setFirstResult($criteria->offset)
            ->setMaxResults($criteria->limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return TradeLifecycleEvent[]
     */
    public function findForLineageIdentifiers(
        string $internalTradeId,
        string $clientOrderId,
        ?string $exchangeOrderId,
        ?string $positionId,
        string $exchange,
        string $marketType,
        int $limit,
        int $offset = 0,
    ): array {
        $qb = $this->createQueryBuilder('event')
            ->andWhere('event.exchange = :exchange')
            ->andWhere('event.marketType = :marketType')
            ->setParameter('exchange', $exchange)
            ->setParameter('marketType', $marketType)
            ->setParameter('internalTradeId', $internalTradeId)
            ->setParameter('clientOrderId', $clientOrderId)
            ->orderBy('event.happenedAt', 'ASC')
            ->addOrderBy('event.id', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit));

        $this->applyLineageIdentifierPredicate($qb, $internalTradeId, $clientOrderId, $exchangeOrderId, $positionId);

        return $qb->getQuery()->getResult();
    }

    public function countForLineageIdentifiers(
        string $internalTradeId,
        string $clientOrderId,
        ?string $exchangeOrderId,
        ?string $positionId,
        string $exchange,
        string $marketType,
    ): int {
        $qb = $this->createQueryBuilder('event')
            ->select('COUNT(event.id)')
            ->andWhere('event.exchange = :exchange')
            ->andWhere('event.marketType = :marketType')
            ->setParameter('exchange', $exchange)
            ->setParameter('marketType', $marketType)
            ->setParameter('internalTradeId', $internalTradeId)
            ->setParameter('clientOrderId', $clientOrderId);

        $this->applyLineageIdentifierPredicate($qb, $internalTradeId, $clientOrderId, $exchangeOrderId, $positionId);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function hasCloseEventForLineageIdentifiers(
        string $internalTradeId,
        string $clientOrderId,
        ?string $exchangeOrderId,
        ?string $positionId,
        string $exchange,
        string $marketType,
    ): bool {
        $qb = $this->createQueryBuilder('event')
            ->select('1')
            ->andWhere('event.exchange = :exchange')
            ->andWhere('event.marketType = :marketType')
            ->andWhere('event.eventType = :eventType')
            ->setParameter('exchange', $exchange)
            ->setParameter('marketType', $marketType)
            ->setParameter('eventType', 'position_closed')
            ->setParameter('internalTradeId', $internalTradeId)
            ->setParameter('clientOrderId', $clientOrderId)
            ->setMaxResults(1);

        $this->applyLineageIdentifierPredicate($qb, $internalTradeId, $clientOrderId, $exchangeOrderId, $positionId);

        return $qb->getQuery()->getOneOrNullResult() !== null;
    }

    private function applyLineageIdentifierPredicate(
        QueryBuilder $qb,
        string $internalTradeId,
        string $clientOrderId,
        ?string $exchangeOrderId,
        ?string $positionId,
    ): void {
        $legacyBranches = [];
        $orderNoConflict = null;
        $positionNoConflict = null;

        if ($exchangeOrderId !== null && $exchangeOrderId !== '') {
            $orderNoConflict = $qb->expr()->orX('event.orderId IS NULL', 'event.orderId = :exchangeOrderId');
            $qb->setParameter('exchangeOrderId', $exchangeOrderId);
        }

        if ($positionId !== null && $positionId !== '') {
            $legacyBranches[] = 'event.positionId = :positionId';
            $positionNoConflict = $qb->expr()->orX('event.positionId IS NULL', 'event.positionId = :positionId');
            $qb->setParameter('positionId', $positionId);
        }

        if ($exchangeOrderId !== null && $exchangeOrderId !== '') {
            $orderBranch = [
                'event.orderId = :exchangeOrderId',
                $qb->expr()->orX('event.clientOrderId IS NULL', 'LOWER(event.clientOrderId) = LOWER(:clientOrderId)'),
            ];
            if ($positionNoConflict !== null) {
                $orderBranch[] = $positionNoConflict;
            }
            $legacyBranches[] = $qb->expr()->andX(...$orderBranch);
        }

        $clientOrderBranch = ['LOWER(event.clientOrderId) = LOWER(:clientOrderId)'];
        if ($orderNoConflict !== null) {
            $clientOrderBranch[] = $orderNoConflict;
        }
        if ($positionNoConflict !== null) {
            $clientOrderBranch[] = $positionNoConflict;
        }
        $legacyBranches[] = $qb->expr()->andX(...$clientOrderBranch);

        $qb->andWhere($qb->expr()->orX(
            'event.internalTradeId = :internalTradeId',
            $qb->expr()->andX(
                'event.internalTradeId IS NULL',
                $qb->expr()->orX(...$legacyBranches),
            ),
        ));
    }

    private function createCriteriaQueryBuilder(LineageReadCriteria $criteria): QueryBuilder
    {
        $field = self::CRITERIA_FIELD_MAP[$criteria->kind] ?? null;
        if ($field === null) {
            throw new \InvalidArgumentException(sprintf('Unsupported lifecycle read criteria "%s".', $criteria->kind));
        }

        $qb = $this->createQueryBuilder('event')
            ->andWhere(sprintf('event.%s = :value', $field))
            ->setParameter('value', $criteria->value);

        if ($criteria->requiresVenue()) {
            $qb->andWhere('event.exchange = :exchange')
                ->andWhere('event.marketType = :marketType')
                ->setParameter('exchange', $criteria->exchange)
                ->setParameter('marketType', $criteria->marketType);
        }

        return $qb;
    }

    /**
     * Récupère les informations de trades actifs ou récents par symbole
     *
     * @param string[] $symbols
     * @return array<string, array{has_trade: bool, side: ?string, position_status: ?string, last_event: ?string, last_event_at: ?\DateTimeImmutable}>
     */
    public function getActiveOrRecentBySymbols(array $symbols, ?ExchangeContext $context = null): array
    {
        if (empty($symbols)) {
            return [];
        }

        // Récupérer les derniers événements pour chaque symbole
        // On considère qu'un trade est actif s'il y a des événements récents (position_opened, order_placed, etc.)
        $qb = $this->createQueryBuilder('event')
            ->where('event.exchange = :exchange')
            ->andWhere('event.marketType = :marketType')
            ->andWhere('event.symbol IN (:symbols)')
            ->andWhere('event.eventType IN (:activeTypes)')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbols', $symbols)
            ->setParameter('activeTypes', ['position_opened', 'order_placed', 'order_filled', 'position_closed'])
            ->orderBy('event.happenedAt', 'DESC');

        $events = $qb->getQuery()->getResult();

        $result = [];
        foreach ($symbols as $symbol) {
            $result[$symbol] = [
                'has_trade' => false,
                'side' => null,
                'position_status' => null,
                'last_event' => null,
                'last_event_at' => null,
            ];
        }

        foreach ($events as $event) {
            $symbol = $event->getSymbol();
            if (!isset($result[$symbol])) {
                continue;
            }

            // Si on n'a pas encore trouvé de trade pour ce symbole, on prend le premier événement
            if (!$result[$symbol]['has_trade']) {
                $result[$symbol]['has_trade'] = true;
                $result[$symbol]['side'] = $event->getSide();
                $result[$symbol]['last_event'] = $event->getEventType();
                $result[$symbol]['last_event_at'] = $event->getHappenedAt();
                // Note: trace_id sera généré par TraceIdProvider dans le builder

                // Déterminer le statut de la position
                if ($event->getEventType() === 'position_opened') {
                    $result[$symbol]['position_status'] = 'OPEN';
                } elseif ($event->getEventType() === 'position_closed') {
                    $result[$symbol]['position_status'] = 'CLOSED';
                } elseif ($event->getEventType() === 'order_placed' || $event->getEventType() === 'order_filled') {
                    $result[$symbol]['position_status'] = 'PENDING';
                }
            }
        }

        return $result;
    }
}
