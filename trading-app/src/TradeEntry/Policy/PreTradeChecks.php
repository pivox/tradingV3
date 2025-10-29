<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\TradeEntry\Adapter\MainProviderAdapter;
use App\TradeEntry\Dto\{PreflightReport, TradeEntryRequest};

final class PreTradeChecks
{
    public function __construct(private readonly MainProviderAdapter $providers) {}

    public function run(TradeEntryRequest $req): PreflightReport
    {
        $symbol = $req->symbol;

        $specs = $this->providers->getContractSpecs($symbol);
        $orderBook = $this->providers->getOrderBookTop($symbol);
        $available = $this->providers->getAvailableUsdt();

        $bestBid = $orderBook['bid'];
        $bestAsk = $orderBook['ask'];
        if ($bestBid <= 0.0 || $bestAsk <= 0.0) {
            throw new \RuntimeException('Order book incomplet pour ' . $symbol);
        }

        $mid = 0.5 * ($bestBid + $bestAsk);
        $spreadPct = $mid > 0.0 ? ($bestAsk - $bestBid) / $mid : 0.0;
        if ($req->orderType === 'market' && $req->marketMaxSpreadPct !== null && $spreadPct > $req->marketMaxSpreadPct) {
            throw new \RuntimeException(sprintf(
                'Spread %.5f > seuil %.5f pour %s',
                $spreadPct,
                $req->marketMaxSpreadPct,
                $symbol
            ));
        }

        return new PreflightReport(
            symbol: $symbol,
            bestBid: $bestBid,
            bestAsk: $bestAsk,
            pricePrecision: $specs['price_precision'],
            contractSize: $specs['contract_size'],
            minVolume: $specs['min_volume'],
            maxLeverage: $specs['max_leverage'],
            minLeverage: $specs['min_leverage'],
            availableUsdt: $available,
            spreadPct: $spreadPct,
            modeNote: $req->orderType === 'market' ? 'market-entry' : 'limit-entry',
        );
    }
}
