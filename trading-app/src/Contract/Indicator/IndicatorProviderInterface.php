<?php

declare(strict_types=1);

namespace App\Contract\Indicator;

use App\Common\Dto\IndicatorSnapshotDto;
use App\Contract\Indicator\Dto\ListIndicatorDto;
use App\Provider\Context\ExchangeContext;

interface IndicatorProviderInterface
{
    public function getSnapshot(
        string $symbol,
        string $timeframe,
        ?ExchangeContext $context = null,
    ): IndicatorSnapshotDto;

    /**
     * Persist a snapshot of indicator values.
     */
    public function saveIndicatorSnapshot(
        IndicatorSnapshotDto $snapshot,
        ?ExchangeContext $context = null,
    ): void;

    /**
     * Calcule une liste d'indicateurs techniques à partir de klines (objets ou arrays normalisés).
     */
    public function getListFromKlines(array $klines): ListIndicatorDto;

    /**
     * Évalue les conditions associées au timeframe
     * et retourne les résultats individuels (nom => ConditionResult)
     */
    public function evaluateConditions(
        string $symbol,
        string $timeframe,
        ?ExchangeContext $context = null,
    ): array;

    /**
     * Retourne un ATR mis en cache par clé "symbol_tf_period".
     * Si $key est null, il est construit à partir de $symbol/$tf et de la période par défaut.
     */
    public function getAtr(
        ?string $key = null,
        ?string $symbol = null,
        ?string $tf = null,
        ?ExchangeContext $context = null,
    ): ?float;

    public function getListPivot(
        ?string $key = null,
        ?string $symbol = null,
        ?string $tf = null,
        ?ExchangeContext $context = null,
    ): ?ListIndicatorDto;

    public function clearCaches(): void;

    /**
     * Retourne la liste des indicateurs disponibles avec leurs paramètres exposés.
     *
     * @return array<string,array<string,mixed>>
     */
    public function listAvailableIndicators(): array;

    public function getIndicatorsForSymbolAndTimeframes(
        string $symbol,
        array $timeframes,
        \DateTimeInterface $at,
        ?ExchangeContext $context = null,
    ): array;

}
