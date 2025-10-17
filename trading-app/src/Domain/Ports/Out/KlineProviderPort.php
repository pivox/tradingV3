<?php

declare(strict_types=1);

namespace App\Domain\Ports\Out;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Enum\Timeframe;

interface KlineProviderPort
{
    /**
     * Récupère les klines depuis l'API REST
     */
    public function fetchKlines(string $symbol, Timeframe $timeframe, int $limit = 1000): array;

    /**
     * Récupère les klines dans une fenêtre de temps spécifique
     */
    public function fetchKlinesInWindow(string $symbol, Timeframe $timeframe, \DateTimeImmutable $start, \DateTimeImmutable $end, int $maxLimit = 500): array;

    /**
     * Récupère les klines depuis le WebSocket
     */
    public function getWebSocketKlines(string $symbol, Timeframe $timeframe): ?KlineDto;

    /**
     * Sauvegarde une kline
     */
    public function saveKline(KlineDto $kline): void;

    /**
     * Sauvegarde plusieurs klines
     */
    public function saveKlines(array $klines): void;

    /**
     * Récupère les klines depuis la base de données
     */
    public function getKlines(string $symbol, Timeframe $timeframe, int $limit = 1000): array;

    /**
     * Récupère la dernière kline pour un symbole et timeframe
     */
    public function getLastKline(string $symbol, Timeframe $timeframe): ?KlineDto;

    /**
     * Vérifie s'il y a des gaps dans les données
     */
    public function hasGaps(string $symbol, Timeframe $timeframe): bool;

    /**
     * Récupère les gaps dans les données
     */
    public function getGaps(string $symbol, Timeframe $timeframe): array;
}




