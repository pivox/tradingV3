<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderIntent;
use App\Entity\FuturesOrder;
use App\Provider\Context\ExchangeContext;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuturesOrder>
 */
final class FuturesOrderRepository extends ServiceEntityRepository
{
    private const OPEN_STATUSES = ['pending', 'partially_filled', 'new', 'sent', 'open', 'submitted'];
    private const CLOSED_STATUSES = ['filled', 'cancelled', 'canceled', 'rejected', 'expired', 'closed'];
    private const OPEN_NUMERIC_STATES = ['1', '2'];
    private const CLOSED_NUMERIC_STATES = ['3', '4', '5'];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuturesOrder::class);
    }

    public function findOneByOrderId(string $orderId, ?ExchangeContext $context = null): ?FuturesOrder
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'orderId' => $orderId,
        ]);
    }

    public function findOneByClientOrderId(string $clientOrderId, ?ExchangeContext $context = null): ?FuturesOrder
    {
        return $this->findOneBy([
            'exchange' => ExchangeContext::exchangeValue($context),
            'marketType' => ExchangeContext::marketTypeValue($context),
            'clientOrderId' => $clientOrderId,
        ]);
    }

    /**
     * @return FuturesOrder[]
     */
    public function findRecentBySymbol(
        string $symbol,
        int $limit = 200,
        ?\DateTimeImmutable $since = null,
        ?ExchangeContext $context = null,
    ): array {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('o.exchange = :exchange')
            ->andWhere('o.marketType = :marketType')
            ->andWhere('o.symbol = :symbol')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', strtoupper($symbol))
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('o.createdAt >= :since')
                ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    public function hasOpenOrderForSymbol(string $symbol, ?ExchangeContext $context = null): bool
    {
        $orders = $this->createQueryBuilder('o')
            ->select('o.status AS status, o.rawData AS rawData')
            ->andWhere('o.exchange = :exchange')
            ->andWhere('o.marketType = :marketType')
            ->andWhere('o.symbol = :symbol')
            ->setParameter('exchange', ExchangeContext::exchangeValue($context))
            ->setParameter('marketType', ExchangeContext::marketTypeValue($context))
            ->setParameter('symbol', strtoupper($symbol))
            ->getQuery()
            ->getResult();

        foreach ($orders as $order) {
            if (
                \is_array($order)
                && $this->isOpenOrderState(
                    $order['status'] ?? null,
                    \is_array($order['rawData'] ?? null) ? $order['rawData'] : [],
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function markOpenOrdersCancelledForIntent(OrderIntent $intent): int
    {
        $ids = array_values(array_filter([
            $intent->getExchangeOrderId(),
            $intent->getOrderId(),
        ], static fn (?string $id): bool => $id !== null && trim($id) !== ''));
        $clientOrderId = $intent->getClientOrderId();

        if ($ids === [] && trim($clientOrderId) === '') {
            return 0;
        }

        $qb = $this->createQueryBuilder('o')
            ->update()
            ->set('o.status', ':cancelled')
            ->set('o.updatedAt', ':now')
            ->where('o.exchange = :exchange')
            ->andWhere('o.marketType = :marketType')
            ->andWhere('o.symbol = :symbol')
            ->andWhere('(o.status IN (:statuses) OR o.status IS NULL OR o.status = :blankStatus)')
            ->setParameter('cancelled', 'cancelled')
            ->setParameter('now', new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->setParameter('exchange', $intent->getExchange())
            ->setParameter('marketType', $intent->getMarketType())
            ->setParameter('symbol', strtoupper($intent->getSymbol()))
            ->setParameter('statuses', self::OPEN_STATUSES)
            ->setParameter('blankStatus', '');

        if ($ids !== []) {
            $qb->andWhere('(o.clientOrderId = :clientOrderId OR o.orderId IN (:orderIds))')
                ->setParameter('clientOrderId', $clientOrderId)
                ->setParameter('orderIds', $ids);
        } else {
            $qb->andWhere('o.clientOrderId = :clientOrderId')
                ->setParameter('clientOrderId', $clientOrderId);
        }

        return (int) $qb->getQuery()->execute();
    }

    /**
     * @param array<string,mixed> $rawData
     */
    private function isOpenOrderState(mixed $statusValue, array $rawData): bool
    {
        $status = $this->normalizeOrderState($statusValue);
        if ($status !== null) {
            if (\in_array($status, self::OPEN_STATUSES, true)) {
                return true;
            }

            if (\in_array($status, self::CLOSED_STATUSES, true)) {
                return false;
            }
        }

        foreach (['state', 'status', 'order_state'] as $key) {
            $rawStatus = $this->normalizeOrderState($rawData[$key] ?? null);
            if ($rawStatus === null) {
                continue;
            }

            if (\in_array($rawStatus, self::OPEN_STATUSES, true)) {
                return true;
            }

            if (\in_array($rawStatus, self::CLOSED_STATUSES, true)) {
                return false;
            }
        }

        return false;
    }

    private function normalizeOrderState(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $state = strtolower(trim((string) $value));
        if ($state === '') {
            return null;
        }

        if (\in_array($state, self::OPEN_NUMERIC_STATES, true)) {
            return $state === '2' ? 'partially_filled' : 'pending';
        }

        if (\in_array($state, self::CLOSED_NUMERIC_STATES, true)) {
            return match ($state) {
                '3' => 'filled',
                '4' => 'cancelled',
                default => 'rejected',
            };
        }

        return str_replace('-', '_', $state);
    }
}
