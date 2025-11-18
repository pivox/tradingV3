<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\Service;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Entity\FuturesTransaction;
use App\Entity\Position;
use App\Provider\Bitmart\View\BitmartSymbolSnapshot;
use App\Repository\FuturesOrderRepository;
use App\Repository\FuturesOrderTradeRepository;
use App\Repository\FuturesTransactionRepository;
use App\Repository\PositionRepository;

/**
 * Service qui reconstruit une "vue Bitmart" locale pour un symbole :
 * - Historique des ordres (futures_order)
 * - Historique des positions (position)
 * - Historique de trading (futures_order_trade)
 * - Historique des transactions (futures_transaction)
 *
 * Ce service reste côté Provider (sous-module Bitmart) et peut être utilisé par des contrôleurs,
 * ou par d'autres modules (ex: TradeEntry) via une interface si tu veux le découpler encore plus.
 */
final class BitmartSymbolSnapshotService
{
    public function __construct(
        private readonly FuturesOrderRepository $orderRepository,
        private readonly PositionRepository $positionRepository,
        private readonly FuturesOrderTradeRepository $tradeRepository,
        private readonly FuturesTransactionRepository $transactionRepository,
    ) {}

    public function getSnapshot(
        string $symbol,
        ?\DateTimeImmutable $since = null,
        int $limitOrders = 200,
        int $limitTrades = 200,
        int $limitTransactions = 200,
    ): BitmartSymbolSnapshot {
        $symbol = strtoupper($symbol);
        $since ??= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-7 days');

        /** @var FuturesOrder[] $orders */
        $orders = $this->orderRepository->createQueryBuilder('o')
            ->andWhere('o.symbol = :symbol')
            ->andWhere('o.createdAt >= :since')
            ->setParameter('symbol', $symbol)
            ->setParameter('since', $since)
            ->orderBy('o.createdAt', 'DESC')
            ->setMaxResults($limitOrders)
            ->getQuery()
            ->getResult();

        /** @var Position[] $positions */
        $positions = $this->positionRepository->createQueryBuilder('p')
            ->andWhere('p.symbol = :symbol')
            ->setParameter('symbol', $symbol)
            ->orderBy('p.openedAt', 'DESC')
            ->getQuery()
            ->getResult();

        /** @var FuturesOrderTrade[] $trades */
        $trades = $this->tradeRepository->createQueryBuilder('t')
            ->andWhere('t.symbol = :symbol')
            ->andWhere('t.tradeTime >= :since')
            ->setParameter('symbol', $symbol)
            ->setParameter('since', $since->getTimestamp() * 1000) // si tu stockes le tradeTime en ms
            ->orderBy('t.tradeTime', 'DESC')
            ->setMaxResults($limitTrades)
            ->getQuery()
            ->getResult();

        /** @var FuturesTransaction[] $transactions */
        $transactions = $this->transactionRepository->findBySymbolSince($symbol, $since);
        // findBySymbolSince est déjà ordonné ASC ; si tu veux DESC, tu peux créer une autre méthode

        return new BitmartSymbolSnapshot(
            ordersHistory: $orders,
            positionsHistory: $positions,
            tradesHistory: $trades,
            transactionsHistory: $transactions,
        );
    }
}
