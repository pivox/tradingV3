<?php

namespace App\Service;

class IntervalConverter
{
    private array $intervalMap = [
        '1m' => 1,
        '5m' => 5,
        '15m' => 15,
        '1h' => 60,
        '4h' => 240,
        '1d' => 1440,
    ];

    /**
     * Convertit un interval (ex: "4h") en step numérique (ex: 240)
     */
    public function intervalToStep(string $interval): ?int
    {
        return $this->intervalMap[$interval] ?? null;
    }

    /**
     * Convertit un step numérique (ex: 240) en interval string (ex: "4h")
     */
    public function stepToInterval(int $step): ?string
    {
        $flipped = array_flip($this->intervalMap);
        return $flipped[$step] ?? null;
    }

    /**
     * Retourne la liste des intervalles valides
     */
    public function getAvailableIntervals(): array
    {
        return array_keys($this->intervalMap);
    }
}
