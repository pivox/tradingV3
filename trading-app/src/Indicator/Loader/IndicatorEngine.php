<?php

declare(strict_types=1);

namespace App\Indicator\Loader;

use App\Common\Dto\IndicatorSnapshotDto;
use App\Provider\Bitmart\Dto\KlineDto;
use App\Common\Enum\Timeframe;
use App\Provider\Bitmart\Service\BitmartKlineProvider;
use Brick\Math\BigDecimal;
use Psr\Log\LoggerInterface;

class IndicatorEngine
{
    public function __construct(
        private readonly BitmartKlineProvider $indicatorProvider,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    /**
     * Calcule tous les indicateurs pour une kline donnée
     */
    public function calculateAllIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto
    {
        if (empty($klines)) {
            throw new \InvalidArgumentException('Klines array cannot be empty');
        }

        $lastKline = end($klines);
        $prices = array_map(fn(KlineDto $k) => $k->close, $klines);
        $highs = array_map(fn(KlineDto $k) => $k->high, $klines);
        $lows = array_map(fn(KlineDto $k) => $k->low, $klines);
        $volumes = array_map(fn(KlineDto $k) => $k->volume, $klines);

        // Calcul des EMAs
        $ema20 = $this->calculateEMA($prices, 20);
        $ema50 = $this->calculateEMA($prices, 50);

        // Calcul du MACD
        $macd = $this->calculateMACD($prices);

        // Calcul de l'ATR
        $atr = $this->calculateATR($klines);

        // Calcul du RSI
        $rsi = $this->calculateRSI($prices);

        // Calcul du VWAP
        $vwap = $this->calculateVWAP($klines);

        // Calcul des Bollinger Bands
        $bb = $this->calculateBollingerBands($prices);

        // Calcul des moyennes mobiles simples
        $ma9 = $this->calculateSMA($prices, 9);
        $ma21 = $this->calculateSMA($prices, 21);

        $snapshot = new IndicatorSnapshotDto(
            symbol: $symbol,
            timeframe: $timeframe,
            klineTime: $lastKline->openTime,
            ema20: !empty($ema20) ? BigDecimal::of(end($ema20)) : null,
            ema50: !empty($ema50) ? BigDecimal::of(end($ema50)) : null,
            macd: !empty($macd['macd']) ? BigDecimal::of(end($macd['macd'])) : null,
            macdSignal: !empty($macd['signal']) ? BigDecimal::of(end($macd['signal'])) : null,
            macdHistogram: !empty($macd['histogram']) ? BigDecimal::of(end($macd['histogram'])) : null,
            atr: !empty($atr) ? BigDecimal::of(end($atr)) : null,
            rsi: !empty($rsi) ? end($rsi) : null,
            vwap: !empty($vwap) ? BigDecimal::of(end($vwap)) : null,
            bbUpper: !empty($bb['upper']) ? BigDecimal::of(end($bb['upper'])) : null,
            bbMiddle: !empty($bb['middle']) ? BigDecimal::of(end($bb['middle'])) : null,
            bbLower: !empty($bb['lower']) ? BigDecimal::of(end($bb['lower'])) : null,
            ma9: !empty($ma9) ? BigDecimal::of(end($ma9)) : null,
            ma21: !empty($ma21) ? BigDecimal::of(end($ma21)) : null,
            meta: [
                'calculated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'klines_count' => count($klines),
                'timeframe' => $timeframe->value
            ]
        );

        // Sauvegarder automatiquement le snapshot
        try {
            $this->indicatorProvider->saveIndicatorSnapshot($snapshot);
            $this->logger?->info('Indicator snapshot saved', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'kline_time' => $snapshot->klineTime->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger?->error('Failed to save indicator snapshot', [
                'symbol' => $symbol,
                'timeframe' => $timeframe->value,
                'error' => $e->getMessage()
            ]);
        }

        return $snapshot;
    }

    /**
     * Calcule l'EMA (Exponential Moving Average)
     */
    private function calculateEMA(array $prices, int $period): array
    {
        return $this->indicatorProvider->calculateEMA($prices, $period);
    }

    /**
     * Calcule le MACD
     */
    private function calculateMACD(array $prices): array
    {
        return $this->indicatorProvider->calculateMACD($prices);
    }

    /**
     * Calcule l'ATR (Average True Range)
     */
    private function calculateATR(array $klines): array
    {
        return $this->indicatorProvider->calculateATR($klines);
    }

    /**
     * Calcule le RSI (Relative Strength Index)
     */
    private function calculateRSI(array $prices): array
    {
        return $this->indicatorProvider->calculateRSI($prices);
    }

    /**
     * Calcule le VWAP (Volume Weighted Average Price)
     */
    private function calculateVWAP(array $klines): array
    {
        return $this->indicatorProvider->calculateVWAP($klines);
    }

    /**
     * Calcule les Bollinger Bands
     */
    private function calculateBollingerBands(array $prices): array
    {
        return $this->indicatorProvider->calculateBollingerBands($prices);
    }

    /**
     * Calcule la SMA (Simple Moving Average)
     */
    private function calculateSMA(array $prices, int $period): array
    {
        $sma = [];
        $count = count($prices);

        for ($i = $period - 1; $i < $count; $i++) {
            $sum = 0;
            for ($j = 0; $j < $period; $j++) {
                $sum += $prices[$i - $j];
            }
            $sma[] = $sum / $period;
        }

        return $sma;
    }

    /**
     * Sauvegarde un snapshot d'indicateurs
     */
    public function saveIndicatorSnapshot(IndicatorSnapshotDto $snapshot): void
    {
        $this->indicatorProvider->saveIndicatorSnapshot($snapshot);
    }

    /**
     * Récupère le dernier snapshot d'indicateurs
     */
    public function getLastIndicatorSnapshot(string $symbol, Timeframe $timeframe): ?IndicatorSnapshotDto
    {
        return $this->indicatorProvider->getLastIndicatorSnapshot($symbol, $timeframe);
    }
}




