<?php

declare(strict_types=1);

namespace App\Domain\PostValidation\Service;

use App\Domain\PostValidation\Dto\MarketDataDto;
use App\Infrastructure\Http\BitmartRestClient;
use App\Infrastructure\WebSocket\BitmartWsClient;
use App\Repository\KlineRepository;
use App\Repository\ContractRepository;
use App\Indicator\AtrCalculator;
use App\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

/**
 * Service de récupération des données de marché pour Post-Validation
 * 
 * Ordre de priorité pour le dernier prix :
 * 1. WS futures/ticker (last_price, bid_price, ask_price, mark_price)
 * 2. REST contract/public/market-trade (dernier trade)
 * 3. REST contract/public/kline (close_price dernière bougie)
 */
final class MarketDataProvider
{
    public function __construct(
        private readonly BitmartRestClient $restClient,
        private readonly BitmartWsClient $wsClient,
        private readonly KlineRepository $klineRepository,
        private readonly ContractRepository $contractRepository,
        private readonly AtrCalculator $atrCalculator,
        private readonly Vwap $vwapCalculator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Récupère les données de marché complètes pour un symbole
     */
    public function getMarketData(string $symbol): MarketDataDto
    {
        $this->logger->info('[MarketDataProvider] Fetching market data', ['symbol' => $symbol]);

        // 1. Récupération du dernier prix (priorité WS -> REST -> K-line)
        $priceData = $this->getLastPrice($symbol);
        
        // 2. Récupération des données de profondeur
        $depthData = $this->getDepthData($symbol);
        
        // 3. Récupération des détails du contrat
        $contractDetails = $this->getContractDetails($symbol);
        
        // 4. Récupération du bracket de levier
        $leverageBracket = $this->getLeverageBracket($symbol);
        
        // 5. Calcul des indicateurs (VWAP, ATR, RSI, volume_ratio)
        $tickSize = (float)($contractDetails['tick_size'] ?? 0.0);
        $indicators = $this->calculateIndicators($symbol, $tickSize);
        
        // 6. Récupération des données de funding et OI
        $fundingData = $this->getFundingData($symbol);

        $marketData = new MarketDataDto(
            symbol: $symbol,
            lastPrice: $priceData['last_price'],
            bidPrice: $priceData['bid_price'],
            askPrice: $priceData['ask_price'],
            markPrice: $priceData['mark_price'],
            indexPrice: $priceData['index_price'],
            spreadBps: $this->calculateSpreadBps($priceData['bid_price'], $priceData['ask_price']),
            depthTopUsd: $depthData['top_usd'],
            vwap: $indicators['vwap'],
            atr1m: $indicators['atr_1m'],
            atr5m: $indicators['atr_5m'],
            rsi1m: $indicators['rsi_1m'],
            volumeRatio1m: $indicators['volume_ratio_1m'],
            fundingRate: $fundingData['funding_rate'],
            openInterest: $fundingData['open_interest'],
            lastUpdateTimestamp: $priceData['timestamp'],
            isStale: $this->isDataStale($priceData['timestamp']),
            contractDetails: $contractDetails,
            leverageBracket: $leverageBracket
        );

        $this->logger->info('[MarketDataProvider] Market data retrieved', [
            'symbol' => $symbol,
            'last_price' => $marketData->lastPrice,
            'spread_bps' => $marketData->spreadBps,
            'is_stale' => $marketData->isStale,
            'data_age_seconds' => $marketData->getPriceAgeSeconds()
        ]);

        return $marketData;
    }

    /**
     * Récupère le dernier prix selon la priorité WS -> REST -> K-line
     */
    private function getLastPrice(string $symbol): array
    {
        // 1. Tentative via WebSocket (priorité)
        $wsData = $this->getWsPriceData($symbol);
        if ($wsData && !$this->isDataStale($wsData['timestamp'])) {
            $this->logger->debug('[MarketDataProvider] Using WS price data', ['symbol' => $symbol]);
            return $wsData;
        }

        // 2. Fallback via REST market-trade
        $restData = $this->getRestPriceData($symbol);
        if ($restData) {
            $this->logger->debug('[MarketDataProvider] Using REST price data', ['symbol' => $symbol]);
            return $restData;
        }

        // 3. Fallback via K-line
        $klineData = $this->getKlinePriceData($symbol);
        if ($klineData) {
            $this->logger->debug('[MarketDataProvider] Using K-line price data', ['symbol' => $symbol]);
            return $klineData;
        }

        throw new \RuntimeException("Unable to retrieve price data for symbol: $symbol");
    }

    /**
     * Récupère les données de prix via WebSocket
     */
    private function getWsPriceData(string $symbol): ?array
    {
        try {
            // TODO: Implémenter la récupération depuis WebSocket
            // Le WebSocket client n'a pas encore de méthode getFuturesTicker
            // On utilise des données simulées pour les tests
            
            $mockPrice = 43250.0 + (rand(-100, 100) / 100); // Prix simulé autour de 43250
            
            return [
                'last_price' => $mockPrice,
                'bid_price' => $mockPrice - 0.5,
                'ask_price' => $mockPrice + 0.5,
                'mark_price' => $mockPrice,
                'index_price' => $mockPrice,
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] WS price data failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupère les données de prix via REST ticker
     */
    private function getRestPriceData(string $symbol): ?array
    {
        try {
            // Utiliser la méthode getTicker qui existe dans BitmartRestClient
            $tickerData = $this->restClient->getTicker($symbol);
            
            if (empty($tickerData['data'])) {
                return null;
            }

            $ticker = $tickerData['data'];
            $price = (float) $ticker['last_price'];

            return [
                'last_price' => $price,
                'bid_price' => (float) $ticker['bid_price'],
                'ask_price' => (float) $ticker['ask_price'],
                'mark_price' => $price,
                'index_price' => $price,
                'timestamp' => time()
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] REST price data failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupère les données de prix via K-line
     */
    private function getKlinePriceData(string $symbol): ?array
    {
        try {
            $klines = $this->klineRepository->findLatestKlines($symbol, '1m', 1);
            
            if (empty($klines)) {
                return null;
            }

            $lastKline = $klines[0];
            $closePrice = (float) $lastKline->getClose();

            return [
                'last_price' => $closePrice,
                'bid_price' => $closePrice * 0.9999, // Estimation
                'ask_price' => $closePrice * 1.0001, // Estimation
                'mark_price' => $closePrice,
                'index_price' => $closePrice,
                'timestamp' => $lastKline->getCloseTime()
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] K-line price data failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupère les données de profondeur
     */
    private function getDepthData(string $symbol): array
    {
        try {
            // TODO: Implémenter getDepth dans BitmartRestClient
            // Pour l'instant, on simule avec des données mock
            $mockPrice = 43250.0 + (rand(-100, 100) / 100);
            
            $bids = [
                [$mockPrice - 0.5, 1.5],
                [$mockPrice - 1.0, 2.0],
                [$mockPrice - 1.5, 1.0]
            ];
            
            $asks = [
                [$mockPrice + 0.5, 1.2],
                [$mockPrice + 1.0, 1.8],
                [$mockPrice + 1.5, 0.8]
            ];
            
            // Calcul de la profondeur top en USD
            $topBid = (float) $bids[0][0] * (float) $bids[0][1];
            $topAsk = (float) $asks[0][0] * (float) $asks[0][1];
            $topUsd = max($topBid, $topAsk);

            return [
                'top_usd' => $topUsd,
                'bids' => $bids,
                'asks' => $asks
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] Depth data failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return ['top_usd' => 0, 'bids' => [], 'asks' => []];
        }
    }

    /**
     * Récupère les détails du contrat
     */
    private function getContractDetails(string $symbol): array
    {
        try {
            $contract = $this->contractRepository->findOneBy(['symbol' => $symbol]);
            
            if (!$contract) {
                return [];
            }

            return [
                'tick_size' => $contract->getTickSize(),
                'lot_size' => $contract->getMinSize(), // Utilise min_size comme lot_size
                'contract_type' => 'PERPETUAL', // Type par défaut
                'base_asset' => $contract->getBaseCurrency(),
                'quote_asset' => $contract->getQuoteCurrency()
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] Contract details failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère le bracket de levier
     */
    private function getLeverageBracket(string $symbol): array
    {
        try {
            // TODO: Implémenter getLeverageBracket dans BitmartRestClient
            // Pour l'instant, on simule avec des données mock
            return [
                [
                    'bracket' => 1,
                    'initial_leverage' => 125,
                    'notional_cap' => 10000,
                    'notional_floor' => 0,
                    'maint_margin_ratio' => 0.004,
                    'cum' => 0
                ],
                [
                    'bracket' => 2,
                    'initial_leverage' => 100,
                    'notional_cap' => 50000,
                    'notional_floor' => 10000,
                    'maint_margin_ratio' => 0.005,
                    'cum' => 40
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] Leverage bracket failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Calcule les indicateurs (VWAP, ATR)
     */
    private function calculateIndicators(string $symbol, float $tickSize): array
    {
        try {
            // VWAP intraday
            $klines1m = $this->klineRepository->findLatestKlines($symbol, '1m', 1440); // 24h
            $highs = array_map(fn($k) => $k->getHigh(), $klines1m);
            $lows = array_map(fn($k) => $k->getLow(), $klines1m);
            $closes = array_map(fn($k) => $k->getClose(), $klines1m);
            $volumes = array_map(fn($k) => $k->getVolume(), $klines1m);
            $vwap = $this->vwapCalculator->calculate($highs, $lows, $closes, $volumes);

            // ATR 1m et 5m (robustes)
            $klines5m = $this->klineRepository->findLatestKlines($symbol, '5m', 288); // 24h
            $ohlc1m = array_map(fn($k) => ['high' => $k->getHigh(), 'low' => $k->getLow(), 'close' => $k->getClose()], $klines1m);
            $ohlc5m = array_map(fn($k) => ['high' => $k->getHigh(), 'low' => $k->getLow(), 'close' => $k->getClose()], $klines5m);
            $atr1m = $this->atrCalculator->computeWithRules($ohlc1m, 14, 'wilder', '1m', $tickSize);
            $atr5m = $this->atrCalculator->computeWithRules($ohlc5m, 14, 'wilder', '5m', $tickSize);

            // RSI(14) 1m
            $rsi1m = $this->computeRsi14($closes);

            // Volume ratio (dernier volume vs moyenne 50)
            $volumeRatio = $this->computeVolumeRatio($volumes, 50);

            return [
                'vwap' => $vwap,
                'atr_1m' => $atr1m,
                'atr_5m' => $atr5m,
                'rsi_1m' => $rsi1m,
                'volume_ratio_1m' => $volumeRatio
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] Indicators calculation failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [
                'vwap' => 0.0,
                'atr_1m' => 0.0,
                'atr_5m' => 0.0,
                'rsi_1m' => 0.0,
                'volume_ratio_1m' => 0.0
            ];
        }
    }

    private function computeRsi14(array $closes): float
    {
        $n = count($closes);
        $period = 14;
        if ($n <= $period) return 0.0;
        $gains = $losses = [];
        for ($i = 1; $i < $n; $i++) {
            $d = (float)$closes[$i] - (float)$closes[$i - 1];
            $gains[] = max(0.0, $d);
            $losses[] = max(0.0, -$d);
        }
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }
        if ($avgLoss == 0.0) return 100.0;
        $rs = $avgGain / $avgLoss;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    private function computeVolumeRatio(array $volumes, int $window): float
    {
        $n = count($volumes);
        if ($n === 0) return 0.0;
        $last = (float) end($volumes);
        $slice = array_slice($volumes, max(0, $n - $window));
        $mean = array_sum($slice) / max(1, count($slice));
        if ($mean <= 0.0) return 0.0;
        return $last / $mean;
    }

    /**
     * Récupère les données de funding et open interest
     */
    private function getFundingData(string $symbol): array
    {
        try {
            // TODO: Implémenter getFundingRate() et getOpenInterest() dans BitmartRestClient
            // Pour l'instant, on utilise des données simulées
            $fundingData = [
                'funding_rate' => 0.0001, // 0.01% funding rate simulé
                'open_interest' => 1000000.0 // 1M USDT open interest simulé
            ];

            return [
                'funding_rate' => (float) ($fundingData['funding_rate'] ?? 0),
                'open_interest' => (float) ($fundingData['open_interest'] ?? 0)
            ];
        } catch (\Exception $e) {
            $this->logger->warning('[MarketDataProvider] Funding data failed', [
                'symbol' => $symbol,
                'error' => $e->getMessage()
            ]);
            return [
                'funding_rate' => 0.0,
                'open_interest' => 0.0
            ];
        }
    }

    /**
     * Calcule le spread en basis points
     */
    private function calculateSpreadBps(float $bidPrice, float $askPrice): float
    {
        if ($bidPrice <= 0 || $askPrice <= 0) {
            return 0.0;
        }
        
        $midPrice = ($bidPrice + $askPrice) / 2;
        return (($askPrice - $bidPrice) / $midPrice) * 10000;
    }

    /**
     * Vérifie si les données sont obsolètes (>2s)
     */
    private function isDataStale(int $timestamp): bool
    {
        return (time() - $timestamp) > 2;
    }
}
