<?php

declare(strict_types=1);

namespace App\Domain\Signal\Strategy;

use App\Domain\Common\Dto\KlineDto;
use App\Domain\Common\Dto\IndicatorSnapshotDto;
use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\Timeframe;

interface StrategyInterface
{
    /**
     * Nom de la stratégie
     */
    public function getName(): string;

    /**
     * Description de la stratégie
     */
    public function getDescription(): string;

    /**
     * Vérifie si la stratégie supporte le timeframe donné
     */
    public function supports(Timeframe $timeframe): bool;

    /**
     * Génère un signal basé sur les données fournies
     */
    public function generateSignal(
        string $symbol,
        Timeframe $timeframe,
        KlineDto $kline,
        IndicatorSnapshotDto $indicators
    ): ?SignalDto;

    /**
     * Retourne les paramètres de configuration de la stratégie
     */
    public function getParameters(): array;

    /**
     * Met à jour les paramètres de la stratégie
     */
    public function setParameters(array $parameters): void;

    /**
     * Vérifie si la stratégie est activée
     */
    public function isEnabled(): bool;

    /**
     * Active/désactive la stratégie
     */
    public function setEnabled(bool $enabled): void;
}


