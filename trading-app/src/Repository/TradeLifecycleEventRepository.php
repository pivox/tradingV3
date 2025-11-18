<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TradeLifecycleEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeLifecycleEvent>
 */
final class TradeLifecycleEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeLifecycleEvent::class);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return TradeLifecycleEvent[]
     */
    public function findRecentBy(array $criteria, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('event')
            ->orderBy('event.happenedAt', 'DESC')
            ->setMaxResults($limit);

        foreach ($criteria as $field => $value) {
            $param = ':' . $field;
            $qb->andWhere(sprintf('event.%s = %s', $field, $param))
                ->setParameter($field, $value);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Récupère les informations de trades actifs ou récents par symbole
     *
     * @param string[] $symbols
     * @return array<string, array{has_trade: bool, side: ?string, position_status: ?string, last_event: ?string, last_event_at: ?\DateTimeImmutable}>
     */
    public function getActiveOrRecentBySymbols(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }

        // Récupérer les derniers événements pour chaque symbole
        // On considère qu'un trade est actif s'il y a des événements récents (position_opened, order_placed, etc.)
        $qb = $this->createQueryBuilder('event')
            ->where('event.symbol IN (:symbols)')
            ->andWhere('event.eventType IN (:activeTypes)')
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
