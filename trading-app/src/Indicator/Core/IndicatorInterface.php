<?php
declare(strict_types=1);

namespace App\Indicator\Core;

interface IndicatorInterface
{
    /**
     * Retourne une description de l'indicateur.
     * - detailed=false: résumé court
     * - detailed=true: formules et détails de calcul
     */
    public function getDescription(bool $detailed = false): string;

    /**
     * Appel générique pour obtenir la dernière valeur/point de l'indicateur.
     * Signature libre selon l'indicateur (utiliser ...$args).
     * Exemple: closes, period; ou highs,lows,closes,period; etc.
     * Retourne typiquement un float ou un tableau associatif (ex: MACD).
     */
    public function calculateValue(mixed ...$args): mixed;

    /**
     * Appel générique pour obtenir la/les série(s) complète(s) de l'indicateur.
     * Signature libre selon l'indicateur (utiliser ...$args).
     * Retourne un tableau (liste simple ou tableau associatif de séries).
     */
    public function calculateSeries(mixed ...$args): array;
}
