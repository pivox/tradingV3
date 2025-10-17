<?php

namespace App\Signal;

use App\Entity\Contract;

interface SignalServiceInterface
{
    /**
     * Évalue toutes les conditions et retourne un signal structuré.
     *
     * @param Contract $contract
     * @param array $klines  // Données OHLCV du timeframe
     * @param array $config  // Bloc YAML correspondant (long/short)
     * @return array{
     *     signal: string,                // 'LONG', 'SHORT' ou 'NONE'
     *     timeframe: string,
     *     conditions: array<array>,      // résultat de chaque condition
     *     context: array,                // résumé du contexte (indicateurs)
     *     timestamp: int
     * }
     */
    public function evaluate(Contract $contract, array $klines, array $config): array;

    public function supportsTimeframe(string $tf): bool;
}
