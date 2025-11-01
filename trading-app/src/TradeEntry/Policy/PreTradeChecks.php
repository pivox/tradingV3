<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\Dto\{PreflightReport, TradeEntryRequest};
use App\TradeEntry\Pricing\TickQuantizer;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

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
        $orderBook = $this->providers->getOrderProvider()->getOrderBookTop($symbol)->toArray();
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

        // Derive precision & integer fields safely to avoid rounding exceptions
        $pricePrecision = $this->resolvePricePrecision($specs->pricePrecision);
        $minVolume      = $this->toIntCeil($specs->minVolume);      // be safe: meet min constraints
        $maxLeverage    = $this->toIntFloor($specs->maxLeverage);   // be safe: do not exceed max
        $minLeverage    = $this->toIntCeil($specs->minLeverage);    // be safe: respect minimum

        $tickSize = TickQuantizer::tick($pricePrecision);
        $lastPrice = $specs->lastPrice->toFloat();
        if (!is_finite($lastPrice) || $lastPrice <= 0.0) {
            $lastPrice = null;
        } else {
            $lastPrice = TickQuantizer::quantize($lastPrice, $pricePrecision);
        }
        if ($lastPrice === null) {
            $lastPrice = $mid;
        }

        return new PreflightReport(
            symbol: $symbol,
            bestBid: $bestBid,
            bestAsk: $bestAsk,
            pricePrecision: $pricePrecision,
            contractSize: $specs->contractSize->toFloat(),
            minVolume: $minVolume,
            maxLeverage: $maxLeverage,
            minLeverage: $minLeverage,
            availableUsdt: $available,
            spreadPct: $spreadPct,
            modeNote: $req->orderType === 'market' ? 'market-entry' : 'limit-entry',
            lastPrice: $lastPrice,
            tickSize: $tickSize,
        );
    }

    private function resolvePricePrecision(BigDecimal $pricePrecision): int
    {
        // Case 1: already an integer count of decimals
        try {
            return $pricePrecision->toInt();
        } catch (MathException) {
            // continue
        }

        // Case 2: provider returned a tick size like 0.1 / 0.01 / 0.0001...
        $s = $pricePrecision->__toString();
        $dotPos = strpos($s, '.');
        if ($dotPos === false) {
            // Fallback: round to nearest int
            return (int) round((float) $s);
        }

        // Count digits after decimal, trimming trailing zeros safely
        $frac = rtrim(substr($s, $dotPos + 1), '0');
        $decimals = strlen($frac);

        // If value is exactly a power-of-ten step (e.g., 0.01), this is correct.
        // If not, keep a reasonable cap.
        return max(0, min(10, $decimals));
    }

    private function toIntCeil(BigDecimal $n): int
    {
        try {
            return $n->toInt();
        } catch (MathException) {
            return $n->toScale(0, RoundingMode::CEILING)->toInt();
        }
    }

    private function toIntFloor(BigDecimal $n): int
    {
        try {
            return $n->toInt();
        } catch (MathException) {
            return $n->toScale(0, RoundingMode::FLOOR)->toInt();
        }
    }
}
