<?php

declare(strict_types=1);

namespace App\Contract\Provider;

use App\Common\Enum\Timeframe;
use App\Contract\Provider\Dto\KlineDto;
use App\Provider\Context\ExchangeContext;

/**
 * Interface pour les providers de klines
 */
interface KlineProviderInterface
{
    /**
     * Récupère les klines pour un symbole et timeframe
     */
    public function getKlines(
        string $symbol,
        Timeframe $timeframe,
        int $limit = 490,
        ?ExchangeContext $context = null,
    ): array;

    /**
     * Récupère les klines dans une fenêtre de temps
     */
    public function getKlinesInWindow(
        string $symbol,
        Timeframe $timeframe,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        int $limit = 500,
        ?ExchangeContext $context = null,
    ): array;

    /**
     * Récupère la dernière kline
     */
    public function getLastKline(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): ?KlineDto;

    /**
     * Sauvegarde une kline
     */
    public function saveKline(KlineDto $kline, ?ExchangeContext $context = null): void;

    /**
     * Sauvegarde plusieurs klines
     */
    public function saveKlines(
        array $klines,
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): void;

    /**
     * Vérifie s'il y a des gaps dans les données
     */
    public function hasGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): bool;

    /**
     * Récupère les gaps dans les données
     */
    public function getGaps(
        string $symbol,
        Timeframe $timeframe,
        ?ExchangeContext $context = null,
    ): array;
}
