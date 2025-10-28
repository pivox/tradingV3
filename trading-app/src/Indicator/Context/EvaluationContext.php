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
            'klines' => $this->klines,
            // Ajouter d'autres indicateurs calculés ici si nécessaire
        ];
    }
}
