<?php

declare(strict_types=1);

namespace App\Provider\Bitmart\View;

use App\Entity\FuturesOrder;
use App\Entity\FuturesOrderTrade;
use App\Entity\Position;
use App\Entity\FuturesTransaction;

/**
 * Snapshot "vue Bitmart" pour un symbole :
 * - Historique des ordres
 * - Historique des positions
 * - Historique des trades (fills)
 * - Historique des transactions (PnL réalisé, fees, funding...)
 *
 * Tous les tableaux sont typés via phpdoc pour aider les IDE.
 */
final class BitmartSymbolSnapshot
{
    /**
     * @param FuturesOrder[]       $ordersHistory
     * @param Position[]           $positionsHistory
     * @param FuturesOrderTrade[]  $tradesHistory
     * @param FuturesTransaction[] $transactions
     */
    public function __construct(
        public readonly array $ordersHistory,
        public readonly array $positionsHistory,
        public readonly array $tradesHistory,
        public readonly array $transactions,
    ) {}
}
