<?php

declare(strict_types=1);

namespace App\MtfValidator\Service;

use App\MtfValidator\Decision\ContextDecision;
use Psr\Log\LoggerInterface;

/**
 * Service de décision de contexte (context_side)
 * Détermine le side (LONG/SHORT) basé sur les timeframes de contexte
 */
final class ContextDecisionService
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Décide du context_side basé sur les résultats des timeframes de contexte
     *
     * @param array<string,array<string,mixed>|null> $tfResults Résultats par TF (4h, 1h, 15m, 5m, 1m)
     * @param string[]                                $contextTimeframes Liste des TF de contexte (ex: ['4h','1h'] ou ['1h','15m'])
     * @return ContextDecision
     */
    public function decide(array $tfResults, array $contextTimeframes): ContextDecision
    {
        /** @var array<string,string> $validSides */
        $validSides = [];

        foreach ($contextTimeframes as $tf) {
            $tfKey = strtolower($tf);

            if (!isset($tfResults[$tfKey]) || !\is_array($tfResults[$tfKey])) {
                // TF pas processé (par ex. au-dessus de start_from_timeframe)
                $this->logger?->debug('[ContextDecision] TF not processed', [
                    'tf' => $tfKey,
                    'reason' => 'not in tfResults or not array',
                ]);
                continue;
            }

            $res = $tfResults[$tfKey];
            $status = $res['status'] ?? null;

            if ($status !== 'VALID') {
                $this->logger?->info('[ContextDecision] TF not VALID', [
                    'tf' => $tfKey,
                    'status' => $status ?? 'NULL',
                    'reason' => $res['reason'] ?? 'NO_REASON',
                ]);
                continue;
            }

            $side = strtoupper((string)($res['signal_side'] ?? ''));

            if ($side === 'LONG' || $side === 'SHORT') {
                $validSides[$tfKey] = $side;
                $this->logger?->info('[ContextDecision] TF VALID with side', [
                    'tf' => $tfKey,
                    'side' => $side,
                ]);
            } else {
                $this->logger?->warning('[ContextDecision] TF VALID but invalid side', [
                    'tf' => $tfKey,
                    'side' => $side,
                ]);
            }
        }

        if ($validSides === []) {
            return new ContextDecision(
                false,
                null,
                'NO_CONTEXT_SIDE',
                $validSides
            );
        }

        $uniqueSides = array_values(array_unique($validSides)); // ['LONG'] ou ['SHORT'] ou ['LONG','SHORT']

        if (\count($uniqueSides) > 1) {
            // Conflit LONG vs SHORT entre TF de contexte
            return new ContextDecision(
                false,
                null,
                'CONTEXT_SIDE_MISMATCH',
                $validSides
            );
        }

        return new ContextDecision(
            true,
            $uniqueSides[0], // 'LONG' ou 'SHORT'
            null,
            $validSides
        );
    }
}


