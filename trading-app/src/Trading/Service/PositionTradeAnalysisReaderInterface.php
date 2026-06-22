<?php

declare(strict_types=1);

namespace App\Trading\Service;

use App\Trading\Entity\PositionTradeAnalysisV2;

/**
 * OBS-003 — Source de lecture des trades d'un run (vue `position_trade_analysis_v2`).
 *
 * Découple {@see RunTradeOutcomeService} de l'implémentation Doctrine concrète
 * (repository `final`, non doublable) : les tests fournissent un stub, le runtime
 * câble la vraie {@see \App\Repository\PositionTradeAnalysisV2Repository}.
 */
interface PositionTradeAnalysisReaderInterface
{
    /**
     * Toutes les lignes de la vue v2 rattachées à un `run_id` de corrélation (lecture
     * seule, bornée). `$setId` filtre en plus sur `set_id` quand il est fourni.
     *
     * @return PositionTradeAnalysisV2[]
     */
    public function findByCorrelationRunId(string $correlationRunId, ?string $setId = null, int $limit = 2000): array;
}
