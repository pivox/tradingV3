<?php

declare(strict_types=1);

namespace App\Domain\Ports\Out;

use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Enum\Timeframe;

interface IndicatorProviderPort
{
    /**
     * Calcule les indicateurs pour une kline donnée
     */
    public function calculateIndicators(string $symbol, Timeframe $timeframe, array $klines): IndicatorSnapshotDto;

    /**
     * Sauvegarde un snapshot d'indicateurs
     */
    public function saveIndicatorSnapshot(IndicatorSnapshotDto $snapshot): void;

    /**
     * Récupère le dernier snapshot d'indicateurs
     */
    public function getLastIndicatorSnapshot(string $symbol, Timeframe $timeframe): ?IndicatorSnapshotDto;

    /**
     * Récupère les snapshots d'indicateurs pour une période
     */
    public function getIndicatorSnapshots(string $symbol, Timeframe $timeframe, int $limit = 100): array;

    /**
     * Calcule l'EMA
     */
    public function calculateEMA(array $prices, int $period): array;

    /**
     * Calcule le MACD
     */
    public function calculateMACD(array $prices, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array;

    /**
     * Calcule l'ATR
     */
    public function calculateATR(array $klines, int $period = 14): array;

    /**
     * Calcule le RSI
     */
    public function calculateRSI(array $prices, int $period = 14): array;

    /**
     * Calcule le VWAP
     */
    public function calculateVWAP(array $klines): array;

    /**
     * Calcule les Bollinger Bands
     */
    public function calculateBollingerBands(array $prices, int $period = 20, float $stdDev = 2.0): array;
}




