<?php

declare(strict_types=1);

namespace App\Domain\Ports\Out;

use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\Timeframe;

interface SignalProviderPort
{
    /**
     * Génère un signal basé sur les indicateurs
     */
    public function generateSignal(string $symbol, Timeframe $timeframe, array $indicators): SignalDto;

    /**
     * Sauvegarde un signal
     */
    public function saveSignal(SignalDto $signal): void;

    /**
     * Récupère le dernier signal pour un symbole et timeframe
     */
    public function getLastSignal(string $symbol, Timeframe $timeframe): ?SignalDto;

    /**
     * Récupère les signaux pour une période
     */
    public function getSignals(string $symbol, Timeframe $timeframe, int $limit = 100): array;

    /**
     * Valide un signal selon les règles métier
     */
    public function validateSignal(SignalDto $signal): bool;

    /**
     * Calcule le score d'un signal
     */
    public function calculateSignalScore(SignalDto $signal, array $indicators): float;
}




