<?php

declare(strict_types=1);

namespace App\Contract\Indicator;

interface IndicatorEngineInterface
{
    /**
     * Construit un contexte d'indicateurs unifié à partir d'une série de klines.
     * $klines: tableau d'objets/arrays ayant les clés/champs: open, high, low, close, volume, open_time|openTime
     * $options: paramètres optionnels (entry_price, stop_loss, etc.).
     */
    public function buildContext(string $symbol, string $timeframe, array $klines, array $options = []): array;

    /**
     * Évaluation via le moteur YAML (TimeframeEvaluator) retournant la structure standard.
     */
    public function evaluateYaml(string $timeframe, array $context): array;

    /**
     * Évaluation via le registre compilé (CompilerPass) pour un timeframe et optionnellement un side.
     * Retourne map name => résultat (toArray()).
     */
    public function evaluateCompiled(string $timeframe, array $context, ?string $side = null): array;

    /**
     * Calcule un ATR (ou renvoie null si non calculable).
     */
    public function computeAtr(array $highs, array $lows, array $closes, ?array $ohlc = null, int $period = 14): ?float;

    /** Évalue toutes les conditions simples (registry) pour un contexte donné. */
    public function evaluateAllConditions(array $context): array;

    /** Évalue un sous-ensemble de conditions par noms. */
    public function evaluateConditions(array $context, array $names): array;

    /** Liste des noms de conditions disponibles. */
    public function listConditionNames(): array;
}
