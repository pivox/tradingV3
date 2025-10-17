<?php

declare(strict_types=1);

namespace App\Service\Indicator;

use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Ports\Out\IndicatorProviderPort;
use App\Indicator\Trend\Ema;
use App\Indicator\Momentum\Rsi;
use App\Indicator\Momentum\Macd;
use App\Indicator\Volume\Vwap;
use Psr\Log\LoggerInterface;

/**
 * Service hybride qui combine les calculs PHP et SQL selon la configuration
 */
class HybridIndicatorService implements IndicatorProviderPort
{
    public function __construct(
        private readonly IndicatorCalculationModeService $modeService,
        private readonly SqlIndicatorService $sqlService,
        private readonly PhpIndicatorService $phpService,
        private readonly ?LoggerInterface $logger = null
    ) {
    }

    public function getModeService(): IndicatorCalculationModeService
    {
        return $this->modeService;
    }

    public function calculateIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto
    {
        if ($this->modeService->isSqlMode()) {
            try {
                $start = microtime(true);
                $snapshot = $this->sqlService->calculateIndicators($symbol, $timeframe, $klines);
                $end = microtime(true);
                $duration = ($end - $start) * 1000; // ms

                if ($duration > $this->modeService->getPerformanceThreshold() && $this->modeService->isFallbackEnabled()) {
                    $this->logger?->warning('SQL indicator calculation exceeded performance threshold, falling back to PHP.', [
                        'symbol' => $symbol,
                        'timeframe' => $timeframe->value,
                        'duration_ms' => $duration,
                        'threshold_ms' => $this->modeService->getPerformanceThreshold(),
                    ]);
                    $phpSnapshot = $this->phpService->calculateIndicators($symbol, $timeframe, $klines);
                    // Override source to indicate fallback
                    return new IndicatorSnapshotDto(
                        symbol: $phpSnapshot->symbol,
                        timeframe: $phpSnapshot->timeframe,
                        klineTime: $phpSnapshot->klineTime,
                        ema20: $phpSnapshot->ema20,
                        ema50: $phpSnapshot->ema50,
                        macd: $phpSnapshot->macd,
                        macdSignal: $phpSnapshot->macdSignal,
                        macdHistogram: $phpSnapshot->macdHistogram,
                        atr: $phpSnapshot->atr,
                        rsi: $phpSnapshot->rsi,
                        vwap: $phpSnapshot->vwap,
                        bbUpper: $phpSnapshot->bbUpper,
                        bbMiddle: $phpSnapshot->bbMiddle,
                        bbLower: $phpSnapshot->bbLower,
                        ma9: $phpSnapshot->ma9,
                        ma21: $phpSnapshot->ma21,
                        meta: $phpSnapshot->meta,
                        source: 'PHP_FALLBACK'
                    );
                }
                return $snapshot;
            } catch (\Exception $e) {
                if ($this->modeService->isFallbackEnabled()) {
                    $this->logger?->error('SQL indicator calculation failed, falling back to PHP.', [
                        'symbol' => $symbol,
                        'timeframe' => $timeframe->value,
                        'error' => $e->getMessage(),
                    ]);
                    $phpSnapshot = $this->phpService->calculateIndicators($symbol, $timeframe, $klines);
                    // Override source to indicate fallback
                    return new IndicatorSnapshotDto(
                        symbol: $phpSnapshot->symbol,
                        timeframe: $phpSnapshot->timeframe,
                        klineTime: $phpSnapshot->klineTime,
                        ema20: $phpSnapshot->ema20,
                        ema50: $phpSnapshot->ema50,
                        macd: $phpSnapshot->macd,
                        macdSignal: $phpSnapshot->macdSignal,
                        macdHistogram: $phpSnapshot->macdHistogram,
                        atr: $phpSnapshot->atr,
                        rsi: $phpSnapshot->rsi,
                        vwap: $phpSnapshot->vwap,
                        bbUpper: $phpSnapshot->bbUpper,
                        bbMiddle: $phpSnapshot->bbMiddle,
                        bbLower: $phpSnapshot->bbLower,
                        ma9: $phpSnapshot->ma9,
                        ma21: $phpSnapshot->ma21,
                        meta: $phpSnapshot->meta,
                        source: 'PHP_FALLBACK'
                    );
                }
                throw $e;
            }
        }

        $phpSnapshot = $this->phpService->calculateIndicators($symbol, $timeframe, $klines);
        // Ensure source is set to PHP
        return new IndicatorSnapshotDto(
            symbol: $phpSnapshot->symbol,
            timeframe: $phpSnapshot->timeframe,
            klineTime: $phpSnapshot->klineTime,
            ema20: $phpSnapshot->ema20,
            ema50: $phpSnapshot->ema50,
            macd: $phpSnapshot->macd,
            macdSignal: $phpSnapshot->macdSignal,
            macdHistogram: $phpSnapshot->macdHistogram,
            atr: $phpSnapshot->atr,
            rsi: $phpSnapshot->rsi,
            vwap: $phpSnapshot->vwap,
            bbUpper: $phpSnapshot->bbUpper,
            bbMiddle: $phpSnapshot->bbMiddle,
            bbLower: $phpSnapshot->bbLower,
            ma9: $phpSnapshot->ma9,
            ma21: $phpSnapshot->ma21,
            meta: $phpSnapshot->meta,
            source: 'PHP'
        );
    }

    public function saveIndicatorSnapshot(IndicatorSnapshotDto $snapshot): void
    {
        // Saving is typically handled by the underlying service (e.g., PHP service might save to DB, SQL mode relies on MVs)
        // For now, delegate to PHP service if it has a saving mechanism, or log.
        $this->phpService->saveIndicatorSnapshot($snapshot);
    }

    public function getLastIndicatorSnapshot(string $symbol, Timeframe $timeframe): ?IndicatorSnapshotDto
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->getLastIndicatorSnapshot($symbol, $timeframe);
        }
        return $this->phpService->getLastIndicatorSnapshot($symbol, $timeframe);
    }

    public function getIndicatorSnapshots(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->getIndicatorSnapshots($symbol, $timeframe, $limit);
        }
        return $this->phpService->getIndicatorSnapshots($symbol, $timeframe, $limit);
    }

    public function calculateEMA(array $prices, int $period): array
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->calculateEMA($prices, $period);
        }
        return $this->phpService->calculateEMA($prices, $period);
    }

    public function calculateMACD(array $prices, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->calculateMACD($prices, $fastPeriod, $slowPeriod, $signalPeriod);
        }
        return $this->phpService->calculateMACD($prices, $fastPeriod, $slowPeriod, $signalPeriod);
    }

    public function calculateATR(array $klines, int $period = 14): array
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->calculateATR($klines, $period);
        }
        return $this->phpService->calculateATR($klines, $period);
    }

    public function calculateRSI(array $prices, int $period = 14): array
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->calculateRSI($prices, $period);
        }
        return $this->phpService->calculateRSI($prices, $period);
    }

    public function calculateVWAP(array $klines): array
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->calculateVWAP($klines);
        }
        return $this->phpService->calculateVWAP($klines);
    }

    public function calculateBollingerBands(array $prices, int $period = 20, float $stdDev = 2.0): array
    {
        if ($this->modeService->isSqlMode()) {
            return $this->sqlService->calculateBollingerBands($prices, $period, $stdDev);
        }
        return $this->phpService->calculateBollingerBands($prices, $period, $stdDev);
    }


}
