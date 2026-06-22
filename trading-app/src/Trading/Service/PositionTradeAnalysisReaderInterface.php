<?php

declare(strict_types=1);

namespace App\Trading\Service;

use App\Trading\Entity\PositionTradeAnalysis;

/**
 * OBS-003 — Source de lecture des trades d'un run (`position_trade_analysis`).
 *
 * Découple {@see RunTradeOutcomeService} de l'implémentation Doctrine concrète
 * (repository `final`, non doublable) : les tests fournissent un stub, le runtime
 * câble la vraie {@see \App\Repository\PositionTradeAnalysisRepository}.
 */
interface PositionTradeAnalysisReaderInterface
{
    /**
     * Toutes les lignes de la vue rattachées à un `run_id` de corrélation (lecture
     * seule, bornée). `$setId` filtre en plus sur `set_id` quand il est fourni.
     *
     * @return PositionTradeAnalysis[]
     */
    public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array;
}
