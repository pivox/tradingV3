<?php

namespace App\Provider\Service;

use App\Provider\Bitmart\View\BitmartSymbolSnapshot;
use App\Repository\FuturesOrderRepository;
use App\Repository\FuturesOrderTradeRepository;
use App\Repository\FuturesTransactionRepository;
use App\Repository\PositionRepository;

final class BitmartLikeViewService
{
    public function __construct(
        private FuturesOrderRepository $orderRepo,
        private FuturesOrderTradeRepository $tradeRepo,
        private PositionRepository $positionRepo,
        private FuturesTransactionRepository $txnRepo,
    ) {}

    public function getSymbolSnapshot(
        string $symbol,
        ?\DateTimeImmutable $since = null,
        int $ordersLimit = 200,
        int $tradesLimit = 200,
        int $transactionsLimit = 200,
    ): BitmartSymbolSnapshot {
        $symbol = strtoupper($symbol);
        $since ??= (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-7 days');

        return new BitmartSymbolSnapshot(
            ordersHistory:    $this->orderRepo->findRecentBySymbol($symbol, $ordersLimit, $since),
            positionsHistory: $this->positionRepo->findHistoryBySymbol($symbol),
            tradesHistory:    $this->tradeRepo->findRecentBySymbol($symbol, $tradesLimit, $since),
            transactions:     $this->txnRepo->findRecentBySymbol($symbol, $transactionsLimit, $since),
        );
    }
}

