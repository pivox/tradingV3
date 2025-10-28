<?php

declare(strict_types=1);

namespace App\Contract\MtfValidator;

use App\Contract\MtfValidator\Dto\TimeframeResultDto;
use App\Contract\MtfValidator\Dto\ValidationContextDto;

/**
 * Interface pour le traitement des timeframes
 * Inspiré de Symfony Contracts pour l'abstraction des services
 */
interface TimeframeProcessorInterface
{
    /**
     * Traite la validation pour un timeframe spécifique
     */
    public function processTimeframe(
        string $symbol,
        ValidationContextDto $context
    ): TimeframeResultDto;

    /**
     * Retourne le timeframe géré par ce processeur
     */
    public function getTimeframeValue(): string;

    /**
     * Vérifie si le processeur peut traiter le timeframe
     */
    public function canProcess(string $timeframe): bool;
}
