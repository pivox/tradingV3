<?php
declare(strict_types=1);

namespace App\TradeEntry\Service;

use App\TradeEntry\TradeEntryBox;
use App\TradeEntry\Types\Side;
use App\Contract\Provider\MainProviderInterface;
use App\Contract\Provider\KlineProviderInterface;
use App\Contract\Provider\ContractProviderInterface;
use Psr\Log\LoggerInterface;
 
final class RealMarketTestService
{
    public function __construct(
        private TradeEntryBox $tradeEntryBox,
        private MainProviderInterface $mainProvider,
        private LoggerInterface $logger
    ) {}

    /**
     * Test avec de vraies données de marché pour BTCUSDT
     */
    public function testBtcUsdtRealData(): array
    {
        try {
            // Récupération des vraies données de marché
            $klineProvider = $this->mainProvider->getKlineProvider();
            $contractProvider = $this->mainProvider->getContractProvider();
            
            // Récupération du dernier prix
            $lastPrice = $contractProvider->getLastPrice('BTCUSDT');
            if (!$lastPrice) {
                throw new \Exception('Impossible de récupérer le prix BTCUSDT');
            }

            // Récupération des klines pour calculer ATR et RSI
            $klines = $klineProvider->getKlines('BTCUSDT', '1m', 100);
            if (empty($klines)) {
                throw new \Exception('Impossible de récupérer les klines BTCUSDT');
            }

            // Calcul de l'ATR simple (14 périodes)
            $atr = $this->calculateSimpleATR($klines, 14);
            
            // Calcul du RSI simple (14 périodes)
            $rsi = $this->calculateSimpleRSI($klines, 14);
            
            // Calcul du VWAP comme pivot
            $vwap = $this->calculateVWAP($klines);

            $this->logger->info('RealMarketTest: Market data retrieved', [
                'symbol' => 'BTCUSDT',
                'last_price' => $lastPrice,
                'atr' => $atr,
                'rsi' => $rsi,
                'vwap' => $vwap
            ]);

            // Test LONG
            $longResult = $this->testLongPosition($lastPrice, $atr, $vwap, $rsi);
            
            // Test SHORT
            $shortResult = $this->testShortPosition($lastPrice, $atr, $vwap, $rsi);

            return [
                'market_data' => [
                    'symbol' => 'BTCUSDT',
                    'last_price' => $lastPrice,
                    'atr' => $atr,
                    'rsi' => $rsi,
                    'vwap' => $vwap,
                    'klines_count' => count($klines)
                ],
                'long_test' => $longResult,
                'short_test' => $shortResult
            ];

        } catch (\Exception $e) {
            $this->logger->error('RealMarketTest: Failed to test with real data', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'error' => $e->getMessage(),
                'market_data' => null,
                'long_test' => null,
                'short_test' => null
            ];
        }
    }

    private function testLongPosition(float $lastPrice, float $atr, float $vwap, float $rsi): array
    {
        $input = [
            'symbol' => 'BTCUSDT',
            'side' => Side::LONG,
            'entry_price_base' => $lastPrice,
            'atr_value' => $atr,
            'pivot_price' => $vwap,
            'risk_pct' => 1.5, // 1.5% de risque
            'budget_usdt' => 200.0, // 200 USDT
            'equity_usdt' => 5000.0, // 5000 USDT capital
            'rsi' => $rsi,
            'volume_ratio' => 1.2, // Volume modéré
            'pullback_confirmed' => $rsi < 70, // Pullback si RSI < 70
        ];

        $result = $this->tradeEntryBox->handle($input);
        
        return [
            'input' => $input,
            'result' => [
                'status' => $result->status,
                'data' => $result->data
            ]
        ];
    }

    private function testShortPosition(float $lastPrice, float $atr, float $vwap, float $rsi): array
    {
        $input = [
            'symbol' => 'BTCUSDT',
            'side' => Side::SHORT,
            'entry_price_base' => $lastPrice,
            'atr_value' => $atr,
            'pivot_price' => $vwap,
            'risk_pct' => 1.0, // 1% de risque pour short
            'budget_usdt' => 150.0, // 150 USDT
            'equity_usdt' => 5000.0, // 5000 USDT capital
            'rsi' => $rsi,
            'volume_ratio' => 1.1, // Volume modéré
            'pullback_confirmed' => $rsi > 30, // Pullback si RSI > 30
        ];

        $result = $this->tradeEntryBox->handle($input);
        
        return [
            'input' => $input,
            'result' => [
                'status' => $result->status,
                'data' => $result->data
            ]
        ];
    }

    private function calculateSimpleATR(array $klines, int $periods): float
    {
        if (count($klines) < $periods + 1) {
            return 0.0;
        }

        $trs = [];
        for ($i = 1; $i < count($klines); $i++) {
            $high = (float)$klines[$i]['high'];
            $low = (float)$klines[$i]['low'];
            $prevClose = (float)$klines[$i-1]['close'];
            
            $tr = max(
                $high - $low,
                abs($high - $prevClose),
                abs($low - $prevClose)
            );
            $trs[] = $tr;
        }

        if (count($trs) < $periods) {
            return array_sum($trs) / count($trs);
        }

        return array_sum(array_slice($trs, -$periods)) / $periods;
    }

    private function calculateSimpleRSI(array $klines, int $periods): float
    {
        if (count($klines) < $periods + 1) {
            return 50.0; // RSI neutre par défaut
        }

        $gains = [];
        $losses = [];

        for ($i = 1; $i < count($klines); $i++) {
            $change = (float)$klines[$i]['close'] - (float)$klines[$i-1]['close'];
            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }

        if (count($gains) < $periods) {
            return 50.0;
        }

        $avgGain = array_sum(array_slice($gains, -$periods)) / $periods;
        $avgLoss = array_sum(array_slice($losses, -$periods)) / $periods;

        if ($avgLoss == 0) {
            return 100.0;
        }

        $rs = $avgGain / $avgLoss;
        return 100 - (100 / (1 + $rs));
    }

    private function calculateVWAP(array $klines): float
    {
        if (empty($klines)) {
            return 0.0;
        }

        $totalVolume = 0;
        $totalValue = 0;

        foreach ($klines as $kline) {
            $volume = (float)$kline['volume'];
            $typicalPrice = ((float)$kline['high'] + (float)$kline['low'] + (float)$kline['close']) / 3;
            
            $totalVolume += $volume;
            $totalValue += $volume * $typicalPrice;
        }

        return $totalVolume > 0 ? $totalValue / $totalVolume : 0.0;
    }
}


