<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Enum\Timeframe;
use App\Domain\Ports\Out\IndicatorProviderPort;
use App\Repository\IndicatorSnapshotRepository;
use Brick\Math\BigDecimal;

final class IndicatorProvider implements IndicatorProviderPort
{
    public function __construct(
        private readonly IndicatorSnapshotRepository $indicatorRepository
    ) {
    }

    /**
     * Calcule les indicateurs pour une kline donnée
     */
    public function calculateIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto
    {
        // Cette méthode sera implémentée avec la logique de calcul des indicateurs
        // Pour l'instant, on retourne un snapshot vide
        return new IndicatorSnapshotDto(
            symbol: $symbol,
            timeframe: $timeframe,
            klineTime: new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    /**
     * Sauvegarde un snapshot d'indicateurs
     */
    public function saveIndicatorSnapshot(IndicatorSnapshotDto $snapshot): void
    {
        $entity = new \App\Entity\IndicatorSnapshot();
        $entity
            ->setSymbol($snapshot->symbol)
            ->setTimeframe($snapshot->timeframe)
            ->setKlineTime($snapshot->klineTime)
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

    /**
     * Récupère le dernier snapshot d'indicateurs
     */
    public function getLastIndicatorSnapshot(string $symbol, Timeframe $timeframe): ?IndicatorSnapshotDto
    {
        $entity = $this->indicatorRepository->findLastBySymbolAndTimeframe($symbol, $timeframe);
        
        if (!$entity) {
            return null;
        }

        return new IndicatorSnapshotDto(
            symbol: $entity->getSymbol(),
            timeframe: $entity->getTimeframe(),
            klineTime: $entity->getKlineTime(),
            ema20: $entity->getEma20() ? BigDecimal::of($entity->getEma20()) : null,
            ema50: $entity->getEma50() ? BigDecimal::of($entity->getEma50()) : null,
            macd: $entity->getMacd() ? BigDecimal::of($entity->getMacd()) : null,
            macdSignal: $entity->getMacdSignal() ? BigDecimal::of($entity->getMacdSignal()) : null,
            macdHistogram: $entity->getMacdHistogram() ? BigDecimal::of($entity->getMacdHistogram()) : null,
            atr: $entity->getAtr() ? BigDecimal::of($entity->getAtr()) : null,
            rsi: $entity->getRsi(),
            vwap: $entity->getVwap() ? BigDecimal::of($entity->getVwap()) : null,
            bbUpper: $entity->getBbUpper() ? BigDecimal::of($entity->getBbUpper()) : null,
            bbMiddle: $entity->getBbMiddle() ? BigDecimal::of($entity->getBbMiddle()) : null,
            bbLower: $entity->getBbLower() ? BigDecimal::of($entity->getBbLower()) : null,
            ma9: $entity->getMa9() ? BigDecimal::of($entity->getMa9()) : null,
            ma21: $entity->getMa21() ? BigDecimal::of($entity->getMa21()) : null,
            meta: $entity->getValues()
        );
    }

    /**
     * Récupère les snapshots d'indicateurs pour une période
     */
    public function getIndicatorSnapshots(string $symbol, Timeframe $timeframe, int $limit = 100): array
    {
        $entities = $this->indicatorRepository->findRecentForIndicators($symbol, $timeframe, $limit);
        
        return array_map(function ($entity) {
            return new IndicatorSnapshotDto(
                symbol: $entity->getSymbol(),
                timeframe: $entity->getTimeframe(),
                klineTime: $entity->getKlineTime(),
                ema20: $entity->getEma20() ? BigDecimal::of($entity->getEma20()) : null,
                ema50: $entity->getEma50() ? BigDecimal::of($entity->getEma50()) : null,
                macd: $entity->getMacd() ? BigDecimal::of($entity->getMacd()) : null,
                macdSignal: $entity->getMacdSignal() ? BigDecimal::of($entity->getMacdSignal()) : null,
                macdHistogram: $entity->getMacdHistogram() ? BigDecimal::of($entity->getMacdHistogram()) : null,
                atr: $entity->getAtr() ? BigDecimal::of($entity->getAtr()) : null,
                rsi: $entity->getRsi(),
                vwap: $entity->getVwap() ? BigDecimal::of($entity->getVwap()) : null,
                bbUpper: $entity->getBbUpper() ? BigDecimal::of($entity->getBbUpper()) : null,
                bbMiddle: $entity->getBbMiddle() ? BigDecimal::of($entity->getBbMiddle()) : null,
                bbLower: $entity->getBbLower() ? BigDecimal::of($entity->getBbLower()) : null,
                ma9: $entity->getMa9() ? BigDecimal::of($entity->getMa9()) : null,
                ma21: $entity->getMa21() ? BigDecimal::of($entity->getMa21()) : null,
                meta: $entity->getValues()
            );
        }, $entities);
    }

    /**
     * Calcule l'EMA
     */
    public function calculateEMA(array $prices, int $period): array
    {
        // TODO: Implémenter le calcul EMA
        return [];
    }

    /**
     * Calcule le MACD
     */
    public function calculateMACD(array $prices, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array
    {
        // TODO: Implémenter le calcul MACD
        return [];
    }

    /**
     * Calcule l'ATR
     */
    public function calculateATR(array $klines, int $period = 14): array
    {
        // TODO: Implémenter le calcul ATR
        return [];
    }

    /**
     * Calcule le RSI
     */
    public function calculateRSI(array $prices, int $period = 14): array
    {
        // TODO: Implémenter le calcul RSI
        return [];
    }

    /**
     * Calcule le VWAP
     */
    public function calculateVWAP(array $klines): array
    {
        // TODO: Implémenter le calcul VWAP
        return [];
    }

    /**
     * Calcule les Bollinger Bands
     */
    public function calculateBollingerBands(array $prices, int $period = 20, float $stdDev = 2.0): array
    {
        // TODO: Implémenter le calcul Bollinger Bands
        return [];
    }
}




