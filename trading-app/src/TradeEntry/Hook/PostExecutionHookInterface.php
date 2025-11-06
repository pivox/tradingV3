<?php
declare(strict_types=1);

namespace App\TradeEntry\Hook;

use App\TradeEntry\Dto\{ExecutionResult, TradeEntryRequest};

/**
 * Interface pour les hooks post-exécution d'un ordre.
 * Permet d'ajouter des comportements après la soumission d'un ordre (switches, audit, etc.).
 */
interface PostExecutionHookInterface
{
    /**
     * Appelé après qu'un ordre ait été soumis avec succès.
     * 
     * @param TradeEntryRequest $request La requête originale
     * @param ExecutionResult $result Le résultat de l'exécution
     * @param string|null $decisionKey La clé de décision pour traçabilité
     */
    public function onSubmitted(
        TradeEntryRequest $request,
        ExecutionResult $result,
        ?string $decisionKey = null
    ): void;

    /**
     * Appelé après qu'un ordre ait été simulé (dry-run).
     * 
     * @param TradeEntryRequest $request La requête originale
     * @param ExecutionResult $result Le résultat de la simulation
     * @param string|null $decisionKey La clé de décision pour traçabilité
     */
    public function onSimulated(
        TradeEntryRequest $request,
        ExecutionResult $result,
        ?string $decisionKey = null
    ): void;
}

