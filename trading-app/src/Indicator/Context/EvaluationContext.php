<?php
// src/Indicator/Context/EvaluationContext.php
declare(strict_types=1);

namespace App\Indicator\Context;

final class EvaluationContext
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $timeframe,
        private array $klines, // array of ['open','high','low','close','volume','open_time']
    ) {}

    /** Retourne une série (array<float>) */
    public function series(string $field): array
    {
        return array_column($this->klines, $field);
    }

    /** Convertit le contexte en array pour les conditions */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'close' => end($this->klines)['close'] ?? null,
            'high' => end($this->klines)['high'] ?? null,
            'low' => end($this->klines)['low'] ?? null,
            'open' => end($this->klines)['open'] ?? null,
            'volume' => end($this->klines)['volume'] ?? null,
            'volume_ratio' => $this->computeVolumeRatio(),
            'klines' => $this->klines,
            // Ajouter d'autres indicateurs calculés ici si nécessaire
        ];
    }

    /**
     * Calcule le ratio volume actuel / moyenne mobile adaptative des volumes.
     * Logique adaptative :
     * - Si < 3 klines → null (pas assez de données)
     * - Si 3 ≤ klines < 20 → SMA partiel (moyenne des volumes disponibles, sans la dernière)
     * - Si ≥ 20 → SMA20 normal (moyenne des 20 dernières, sans la dernière)
     */
    private function computeVolumeRatio(): ?float
    {
        $count = count($this->klines);

        if ($count < 3) {
            return null; // pas de data utilisable
        }

        // Volume actuel (dernière kline)
        $lastKline = end($this->klines);
        $currentVol = $lastKline['volume'] ?? null;
        if ($currentVol === null || $currentVol <= 0.0) {
            return null;
        }

        // Nombre de périodes disponible pour la moyenne (adaptatif : min entre 20 et count-1)
        // On exclut la dernière kline du calcul de la moyenne
        $window = min(20, $count - 1);
        if ($window < 1) {
            return null;
        }

        // Extraire les volumes passés (sans la dernière kline)
        $pastKlines = array_slice($this->klines, -$window - 1, $window);
        if (empty($pastKlines)) {
            // Fallback : toutes les klines sauf la dernière
            $pastKlines = array_slice($this->klines, 0, -1);
        }

        if (empty($pastKlines)) {
            return null;
        }

        // Extraire les volumes
        $pastVolumes = array_map(fn($k) => (float)($k['volume'] ?? 0.0), $pastKlines);
        $pastVolumes = array_filter($pastVolumes, fn($v) => $v > 0.0);

        if (empty($pastVolumes)) {
            return null;
        }

        // Calculer la moyenne simple (SMA adaptatif)
        $avgVol = array_sum($pastVolumes) / count($pastVolumes);

        if ($avgVol <= 0.0) {
            return null;
        }

        return $currentVol / $avgVol;
    }
}
