<?php

declare(strict_types=1);

namespace App\Trading\Paper\Execution\Strategy;

use App\TradeEntry\Dto\PreparedTradeEntry;
use App\Trading\Paper\Execution\Identity\PaperExecutionCell;
use App\Trading\Paper\MarketData\PaperMarketEvent;

interface PaperStrategyPreparationInterface
{
    public function prepareFor(PaperExecutionCell $cell, PaperMarketEvent $event): ?PreparedTradeEntry;
}
