<?php

declare(strict_types=1);

namespace App\Domain\Signal\Port\In;

use App\Domain\Common\Dto\SignalDto;
use App\Domain\Common\Enum\Timeframe;

interface SignalGenerationPort
{
    /**
     * Génère des signaux pour un symbole et timeframe donnés
     */
    public function generateSignals(string $symbol, Timeframe $timeframe): array;

    /**
     * Génère un signal unique basé sur les critères fournis
     */
    public function generateSingleSignal(
        string $symbol,
        Timeframe $timeframe,
        array $criteria
    ): ?SignalDto;

    /**
     * Valide un signal généré
     */
    public function validateSignal(SignalDto $signal): bool;

    /**
     * Filtre les signaux selon les critères de qualité
     */
    public function filterSignals(array $signals, array $filters): array;
}


