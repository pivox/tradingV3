<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Contract\Provider\MainProviderInterface;
use Brick\Math\Exception\MathException;
use App\TradeEntry\Dto\{PreflightReport, TradeEntryRequest};

final readonly class PreTradeChecks
{
    public function __construct(private MainProviderInterface $providers) {}

    /**
     * @throws MathException
     */
    public function run(TradeEntryRequest $req): PreflightReport
    {
        $symbol = $req->symbol;

        $specs = $this->providers->getContractProvider()->getContractDetails($symbol);
        $orderBook = $this->providers->getOrderProvider()->getOrderBookTop($symbol);
        $available = $this->providers->getAccountProvider()->getAccountBalance() ?? 0.0;

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
            pricePrecision: $specs->pricePrecision->toInt(),
            contractSize: $specs->contractSize->toFloat(),
            minVolume: $specs->minVolume->toInt(),
            maxLeverage: $specs->maxLeverage->toInt(),
            minLeverage: $specs->minLeverage->toInt(),
            availableUsdt: $available,
            spreadPct: $spreadPct,
            modeNote: $req->orderType === 'market' ? 'market-entry' : 'limit-entry',
        );
    }
}
