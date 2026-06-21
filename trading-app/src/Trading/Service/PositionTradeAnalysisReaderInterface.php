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
     * Toutes les lignes de la vue rattachées à un `run_id` (lecture seule, bornée).
     *
     * @return PositionTradeAnalysis[]
     */
    public function findByRunId(string $runId, int $limit = 1000): array;
}
