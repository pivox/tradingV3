<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\MtfValidator\Decision\ExecutionDecision;

/**
 * Service de décision de timeframe d'exécution
 * Choisit le premier TF d'exécution aligné avec le context_side
 */
final class ExecutionTimeframeDecisionService
{
    /**
     * Décide du timeframe d'exécution basé sur les résultats et le context_side
     *
     * @param array<string,array<string,mixed>|null> $tfResults Résultats par TF (4h, 1h, 15m, 5m, 1m)
     * @param string[]                                $executionTimeframes Liste des TF d'exécution dans l'ordre (ex: ['5m','1m'] ou ['15m','5m','1m'])
     * @param string                                  $contextSide         'LONG' | 'SHORT'
     * @return ExecutionDecision
     */
    public function decide(
        array $tfResults,
        array $executionTimeframes,
        string $contextSide
    ): ExecutionDecision {
        $contextSide = strtoupper($contextSide);

        foreach ($executionTimeframes as $tf) {
            $tfKey = strtolower($tf);

            if (!isset($tfResults[$tfKey]) || !\is_array($tfResults[$tfKey])) {
                continue;
            }

            $res = $tfResults[$tfKey];

            if (($res['status'] ?? null) !== 'VALID') {
                continue;
            }

            $side = strtoupper((string)($res['signal_side'] ?? ''));

            if ($side === $contextSide) {
                // Premier TF d'exécution aligné avec le contexte
                return new ExecutionDecision($tfKey, 'FIRST_ALIGNED_EXEC_TF');
            }
        }

        return new ExecutionDecision(null, 'NO_EXEC_TF_ALIGNED');
    }
}

