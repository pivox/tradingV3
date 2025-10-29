<?php

declare(strict_types=1);

namespace App\Contract\Signal;

use App\Contract\Signal\Dto\SignalValidationResultDto;
use App\Entity\Contract;

/**
 * Contrat du service de validation multi-timeframes.
 */
interface SignalValidationServiceInterface
{
    /**
     * Construit un résumé de contexte MTF à partir des signaux connus.
     *
     * @param array  $knownSignals  Signaux déjà calculés, indexés par timeframe
     * @param string $currentTf     Timeframe courant évalué
     * @param string $currentSignal Signal courant (LONG/SHORT/NONE)
     */
    public function buildContextSummary(array $knownSignals, string $currentTf, string $currentSignal): array;

    /**
     * Valide un timeframe donné, en tenant compte du contexte MTF.
     *
     * @param string            $tf
     * @param array             $klines
     * @param array<string,array{signal?:string}> $knownSignals
     * @param Contract|null     $contract
     *
     * @return SignalValidationResultDto
     */
    public function validate(string $tf, array $klines, array $knownSignals = [], ?Contract $contract = null): SignalValidationResultDto;
}
