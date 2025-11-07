<?php

declare(strict_types=1);

namespace App\Contract\Signal;

use App\Provider\Entity\Contract;

/**
 * Contrat commun aux services de génération de signaux par timeframe.
 */
interface SignalServiceInterface
{
    /**
     * Évalue toutes les conditions et retourne un signal structuré.
     *
     * @param Contract $contract
     * @param array    $klines   Données OHLCV du timeframe (entités Kline ou équivalent)
     * @param array    $config   Bloc de configuration spécifique (long/short)
     *
     * @return array{
     *     signal: string,
     *     timeframe: string,
     *     conditions_long?: array<array>,
     *     conditions_short?: array<array>,
     *     requirements_long?: array<array>,
     *     requirements_short?: array<array>,
     *     failed_conditions_long?: array<array>,
     *     failed_conditions_short?: array<array>,
     *     status?: string,
     *     reason?: string,
     *     context?: array,
     *     indicator_context?: array,
     *     timestamp?: int
     * }
     */
    public function evaluate(Contract $contract, array $klines, array $config): array;

    public function supportsTimeframe(string $tf): bool;
}

