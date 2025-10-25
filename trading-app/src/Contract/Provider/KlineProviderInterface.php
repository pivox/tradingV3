<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;

/**
 * Interface pour les providers de klines
 */
interface KlineProviderInterface
{
    /**
     * Récupère les klines pour un symbole et timeframe
     */
    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 490): array;

    /**
     * Récupère les klines dans une fenêtre de temps
     */
    public function getKlinesInWindow(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit = 500
    ): array;

    /**
     * Récupère la dernière kline
     */
    public function getLastKline(string $symbol, Timeframe $timeframe): ?KlineDto;

    /**
     * Sauvegarde une kline
     */
    public function saveKline(KlineDto $kline): void;

    /**
     * Sauvegarde plusieurs klines
     */
    public function saveKlines(array $klines, string $symbol, Timeframe $timeframe): void;

    /**
     * Vérifie s'il y a des gaps dans les données
     */
    public function hasGaps(string $symbol, Timeframe $timeframe): bool;

    /**
     * Récupère les gaps dans les données
     */
    public function getGaps(string $symbol, Timeframe $timeframe): array;
}
