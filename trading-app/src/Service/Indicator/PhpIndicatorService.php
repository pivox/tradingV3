<?php

declare(strict_types=1);

namespace App\Service\Indicator;

use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Ports\Out\IndicatorProviderPort;
use App\Entity\IndicatorSnapshot;
use App\Indicator\Trend\Ema;
use App\Indicator\Momentum\Rsi;
use App\Indicator\Momentum\Macd;
use App\Indicator\Volume\Vwap;
use App\Repository\IndicatorSnapshotRepository;
use Psr\Log\LoggerInterface;

class PhpIndicatorService implements IndicatorProviderPort
{
    public function __construct(
        private readonly Ema $emaService,
        private readonly Macd $macdService,
        private readonly Rsi $rsiService,
        private readonly Vwap $vwapService,
        private readonly LoggerInterface $logger,
        private readonly IndicatorSnapshotRepository $indicatorRepository
    ) {
    }

    public function calculateIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto
    {
        $this->logger?->info('Calculating indicators in PHP mode', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'klines_count' => count($klines),
        ]);

        // Extract prices from klines
        $prices = array_column($klines, 'close_price');
        $highs = array_column($klines, 'high_price');
        $lows = array_column($klines, 'low_price');
        $volumes = array_column($klines, 'volume');

        // Calculate indicators
        $ema9 = $this->calculateEMA($prices, 9);
        $ema21 = $this->calculateEMA($prices, 21);
        $ema50 = $this->calculateEMA($prices, 50);
        $ema200 = $this->calculateEMA($prices, 200);
        $rsi = $this->calculateRSI($prices, 14);
        $macd = $this->calculateMACD($prices);
        $vwap = $this->calculateVWAP($klines);

        // Get the latest values
        $latestEma9 = !empty($ema9) ? end($ema9) : null;
        $latestEma21 = !empty($ema21) ? end($ema21) : null;
        $latestEma50 = !empty($ema50) ? end($ema50) : null;
        $latestEma200 = !empty($ema200) ? end($ema200) : null;
        $latestRsi = !empty($rsi) ? end($rsi) : null;
        $latestMacd = !empty($macd) ? end($macd) : null;
        $latestVwap = !empty($vwap) ? end($vwap) : null;

        return new IndicatorSnapshotDto(
            symbol: $symbol,
            timeframe: $timeframe,
            klineTime: new \DateTimeImmutable(),
            ema20: $latestEma21 ? \Brick\Math\BigDecimal::of($latestEma21) : null,
            ema50: $latestEma50 ? \Brick\Math\BigDecimal::of($latestEma50) : null,
            rsi: $latestRsi,
            macd: $latestMacd['macd'] ?? null ? \Brick\Math\BigDecimal::of($latestMacd['macd']) : null,
            macdSignal: $latestMacd['signal'] ?? null ? \Brick\Math\BigDecimal::of($latestMacd['signal']) : null,
            macdHistogram: $latestMacd['histogram'] ?? null ? \Brick\Math\BigDecimal::of($latestMacd['histogram']) : null,
            vwap: $latestVwap ? \Brick\Math\BigDecimal::of($latestVwap) : null,
            // Add other indicators as needed
        );
    }

    public function saveIndicatorSnapshot(IndicatorSnapshotDto $snapshot): void
    {
        $this->logger?->info('Saving indicator snapshot in PHP mode', [
            'symbol' => $snapshot->symbol,
            'timeframe' => $snapshot->timeframe->value,
        ]);
        $entity = new IndicatorSnapshot();
        $entity
            ->setSymbol($snapshot->symbol)
            ->setTimeframe($snapshot->timeframe)
            ->setKlineTime($snapshot->klineTime)
            ->setSource($snapshot->source)
            ->setValues([
                'ema20' => $snapshot->ema20?->toFixed(12),
                'ema50' => $snapshot->ema50?->toFixed(12),
                'macd' => $snapshot->macd?->toFixed(12),
                'macd_signal' => $snapshot->macdSignal?->toFixed(12),
                'macd_histogram' => $snapshot->macdHistogram?->toFixed(12),
                'atr' => $snapshot->atr?->toFixed(12),
                'rsi' => $snapshot->rsi,
                'vwap' => $snapshot->vwap?->toFixed(12),
                'bb_upper' => $snapshot->bbUpper?->toFixed(12),
                'bb_middle' => $snapshot->bbMiddle?->toFixed(12),
                'bb_lower' => $snapshot->bbLower?->toFixed(12),
                'ma9' => $snapshot->ma9?->toFixed(12),
                'ma21' => $snapshot->ma21?->toFixed(12),
                'meta' => $snapshot->meta,
                'created_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
                'snapshot_hash' => md5(serialize($snapshot->toArray()))
            ]);

        $this->indicatorRepository->upsert($entity);
    }

    public function getLastIndicatorSnapshot(string $symbol, Timeframe $timeframe): ?IndicatorSnapshotDto
    {
        $this->logger?->info('Getting last indicator snapshot in PHP mode', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
        ]);
        // In PHP mode, we would typically fetch from cache or database
        // For now, return null
        return null;
    }

    public function getIndicatorSnapshots(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        $this->logger?->info('Getting indicator snapshots in PHP mode', [
            'symbol' => $symbol,
            'timeframe' => $timeframe->value,
            'limit' => $limit,
        ]);
        // In PHP mode, we would typically fetch from cache or database
        // For now, return empty array
        return [];
    }

    public function calculateEMA(array $prices, int $period): array
    {
        $result = $this->emaService->calculate($prices, $period);
        return is_array($result) ? $result : [$result];
    }

    public function calculateMACD(array $prices, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array
    {
        return $this->macdService->calculateFull($prices);
    }

    public function calculateATR(array $klines, int $period = 14): array
    {
        // This would need to be implemented based on your ATR service
        $this->logger?->warning('calculateATR called on PhpIndicatorService but not implemented');
        return [];
    }

    public function calculateRSI(array $prices, int $period = 14): array
    {
        $result = $this->rsiService->calculate($prices, $period);
        return is_array($result) ? $result : [$result];
    }

    public function calculateVWAP(array $klines): array
    {
        $highs = array_column($klines, 'high_price');
        $lows = array_column($klines, 'low_price');
        $closes = array_column($klines, 'close_price');
        $volumes = array_column($klines, 'volume');

        $result = $this->vwapService->calculate($highs, $lows, $closes, $volumes);
        return is_array($result) ? $result : [$result];
    }

    public function calculateBollingerBands(array $prices, int $period = 20, float $stdDev = 2.0): array
    {
        // TODO: Implement Bollinger Bands calculation
        $this->logger?->warning('calculateBollingerBands called on PhpIndicatorService but not implemented');
        return [];
    }
}
