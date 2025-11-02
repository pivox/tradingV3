<?php
declare(strict_types=1);

namespace App\TradeEntry\Policy;

use App\Common\Enum\Timeframe;
use App\Contract\Indicator\IndicatorProviderInterface;
use App\Contract\Provider\MainProviderInterface;
use App\TradeEntry\Dto\{PreflightReport, TradeEntryRequest};
use App\TradeEntry\Pricing\TickQuantizer;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class PreTradeChecks
{
    public function __construct(
        private MainProviderInterface $providers,
        private IndicatorProviderInterface $indicatorProvider,
        #[Autowire(service: 'monolog.logger.order_journey')] private LoggerInterface $journeyLogger,
    ) {}

    /**
     * @throws MathException
     */
    public function run(TradeEntryRequest $req): PreflightReport
    {
        $symbol = $req->symbol;

        $this->journeyLogger->debug('order_journey.pretrade.fetch_contract', [
            'symbol' => $symbol,
            'reason' => 'load_contract_specifications',
        ]);
        $specs = $this->providers->getContractProvider()->getContractDetails($symbol);

        $this->journeyLogger->debug('order_journey.pretrade.fetch_order_book', [
            'symbol' => $symbol,
            'reason' => 'load_order_book_snapshot',
        ]);
        $orderBook = $this->providers->getOrderProvider()->getOrderBookTop($symbol)->toArray();

        $this->journeyLogger->debug('order_journey.pretrade.fetch_balance', [
            'symbol' => $symbol,
            'reason' => 'load_available_balance',
        ]);
        $available = $this->providers->getAccountProvider()->getAccountBalance() ?? 0.0;

        $bestBid = $orderBook['bid'];
        $bestAsk = $orderBook['ask'];
        if ($bestBid <= 0.0 || $bestAsk <= 0.0) {
            $this->journeyLogger->error('order_journey.pretrade.invalid_order_book', [
                'symbol' => $symbol,
                'best_bid' => $bestBid,
                'best_ask' => $bestAsk,
                'reason' => 'non_positive_bid_or_ask',
            ]);
            throw new \RuntimeException('Order book incomplet pour ' . $symbol);
        }

        $mid = 0.5 * ($bestBid + $bestAsk);
        $spreadPct = $mid > 0.0 ? ($bestAsk - $bestBid) / $mid : 0.0;
        if ($req->orderType === 'market' && $req->marketMaxSpreadPct !== null && $spreadPct > $req->marketMaxSpreadPct) {
            $this->journeyLogger->info('order_journey.pretrade.spread_blocked', [
                'symbol' => $symbol,
                'spread_pct' => $spreadPct,
                'max_allowed' => $req->marketMaxSpreadPct,
                'reason' => 'market_order_spread_too_wide',
            ]);
            throw new \RuntimeException(sprintf(
                'Spread %.5f > seuil %.5f pour %s',
                $spreadPct,
                $req->marketMaxSpreadPct,
                $symbol
            ));
        }

        // Derive precision & integer fields safely to avoid rounding exceptions
        $pricePrecision   = $this->resolvePricePrecision($specs->pricePrecision);
        $volPrecision     = $this->resolveVolumePrecision($specs->volPrecision);
        $minVolume        = $this->toIntCeil($specs->minVolume);      // be safe: meet min constraints
        $maxLeverage      = $this->toIntFloor($specs->maxLeverage);   // be safe: do not exceed max
        $minLeverage      = $this->toIntCeil($specs->minLeverage);    // be safe: respect minimum
        $maxVolume        = $this->toFloatOrNull($specs->maxVolume);
        $marketMaxVolume  = $this->toFloatOrNull($specs->marketMaxVolume);

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
        $markPrice = $specs->indexPrice->toFloat();
        if (!is_finite($markPrice) || $markPrice <= 0.0) {
            $markPrice = $lastPrice;
        }

        $pivotLevels = $this->fetchPivotLevels($symbol);

        $this->journeyLogger->debug('order_journey.pretrade.metrics', [
            'symbol' => $symbol,
            'best_bid' => $bestBid,
            'best_ask' => $bestAsk,
            'spread_pct' => $spreadPct,
            'available_usdt' => $available,
            'tick_size' => $tickSize,
            'last_price' => $lastPrice,
            'mark_price' => $markPrice,
            'vol_precision' => $volPrecision,
            'max_volume' => $maxVolume,
            'market_max_volume' => $marketMaxVolume,
            'pivot_levels' => $pivotLevels,
            'reason' => 'pretrade_values_computed',
        ]);

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
            markPrice: $markPrice,
            volPrecision: $volPrecision,
            maxVolume: $maxVolume,
            marketMaxVolume: $marketMaxVolume,
            pivotLevels: $pivotLevels,
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

    private function resolveVolumePrecision(BigDecimal $volPrecision): int
    {
        try {
            return $volPrecision->toInt();
        } catch (MathException) {
            // continue
        }

        $s = $volPrecision->__toString();
        $dotPos = strpos($s, '.');
        if ($dotPos === false) {
            return (int) round((float)$s);
        }

        $frac = rtrim(substr($s, $dotPos + 1), '0');
        $decimals = strlen($frac);

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

    private function toFloatOrNull(BigDecimal $n): ?float
    {
        try {
            $float = (float)$n->__toString();
            if (!is_finite($float) || $float <= 0.0) {
                return null;
            }
            return $float;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,float>|null
     */
    private function fetchPivotLevels(string $symbol): ?array
    {
        try {
            $dto = $this->indicatorProvider->getListPivot('tp_daily', $symbol, Timeframe::TF_1D->value);
            if (!$dto instanceof \App\Contract\Indicator\Dto\ListIndicatorDto) {
                return null;
            }

            $data = $dto->toArray()['pivot_levels'] ?? null;
            if (!is_array($data)) {
                return null;
            }

            $filtered = [];
            foreach (['pp', 'r1', 'r2', 'r3', 's1', 's2', 's3'] as $key) {
                if (isset($data[$key]) && is_finite((float)$data[$key])) {
                    $filtered[$key] = (float)$data[$key];
                }
            }

            return !empty($filtered) ? $filtered : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
