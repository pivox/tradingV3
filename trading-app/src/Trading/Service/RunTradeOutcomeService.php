<?php

declare(strict_types=1);

namespace App\Trading\Service;

use App\Trading\Entity\PositionTradeAnalysis;
use Psr\Log\LoggerInterface;

/**
 * OBS-003 — Rapproche un run d'orchestration de ses trades (`position_trade_analysis`).
 *
 * Service de LECTURE SEULE, fail-safe : il agrège, pour un `run_id` donné, les lignes
 * de la vue `position_trade_analysis` (jointure ORDER_SUBMITTED / POSITION_CLOSED /
 * indicator_snapshots, en lecture seule) en :
 *  - un agrégat global du run (nombre de trades, PnL net en USDT et en R, win-rate,
 *    MFE/MAE moyens et médians, durée de détention moyenne) ;
 *  - une ventilation par symbole.
 *
 * Il ne recalcule JAMAIS le PnL : il ne fait que sommer/moyenner les valeurs déjà
 * exposées par la vue. Toute indisponibilité (vue absente, erreur SQL, run inconnu)
 * retombe sur un agrégat vide EXPLICITE (`available=false` / compteurs à 0), jamais une
 * exception : l'endpoint ne doit jamais renvoyer un 500.
 *
 * Réconciliation du `run_id` : `trade_lifecycle_event.run_id` (et donc la vue) est un
 * VARCHAR(64), alors que l'orchestration `runs.run_id` peut aller jusqu'à 255 caractères
 * (forme hachée = 68). Le run_id propagé par X-Run-Id est déjà tronqué à 64 côté runner ;
 * on applique ici la MÊME règle à la valeur requêtée pour que les deux côtés du
 * rapprochement utilisent strictement le même identifiant.
 */
final class RunTradeOutcomeService
{
    /** Largeur de `trade_lifecycle_event.run_id` (== `position_trade_analysis.run_id`). */
    public const RUN_ID_MAX_LENGTH = 64;

    public function __construct(
        private readonly PositionTradeAnalysisReaderInterface $repository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Agrège les trades d'un run par `run_id` (+ ventilation par symbole).
     *
     * @return array<string,mixed> Agrégat JSON-sérialisable (toujours renseigné).
     */
    public function aggregateByRunId(string $runId): array
    {
        $normalized = mb_substr(trim($runId), 0, self::RUN_ID_MAX_LENGTH);
        if ($normalized === '') {
            return $this->emptyOutcome('', true);
        }

        try {
            $rows = $this->repository->findByRunId($normalized);
        } catch (\Throwable $e) {
            // Vue indisponible / erreur SQL : fail-safe, agrégat vide non disponible.
            $this->logger->warning('[RunTradeOutcome] Failed to read position_trade_analysis', [
                'run_id' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return $this->emptyOutcome($normalized, false);
        }

        if ($rows === []) {
            // Run connu côté orchestrateur mais sans trade (ou run inconnu) : agrégat
            // vide explicite, disponible (la source a répondu), pas une erreur.
            return $this->emptyOutcome($normalized, true);
        }

        $outcome = $this->aggregateRows($rows);
        $outcome['run_id'] = $normalized;
        $outcome['available'] = true;

        return $outcome;
    }

    /**
     * @param PositionTradeAnalysis[] $rows
     * @return array<string,mixed>
     */
    private function aggregateRows(array $rows): array
    {
        $global = $this->aggregateGroup($rows);

        $bySymbol = [];
        $groups = [];
        foreach ($rows as $row) {
            $groups[$row->getSymbol()][] = $row;
        }
        // Tri par symbole pour une sortie déterministe.
        ksort($groups);
        foreach ($groups as $symbol => $symbolRows) {
            $symbolStats = $this->aggregateGroup($symbolRows);
            $symbolStats['symbol'] = $symbol;
            $bySymbol[] = $symbolStats;
        }

        $global['by_symbol'] = $bySymbol;

        return $global;
    }

    /**
     * Statistiques d'un groupe de trades (global ou par symbole).
     *
     * @param PositionTradeAnalysis[] $rows
     * @return array<string,mixed>
     */
    private function aggregateGroup(array $rows): array
    {
        $tradeCount = count($rows);
        $closedCount = 0;
        $winCount = 0;
        $lossCount = 0;
        $pnlUsdt = 0.0;
        $pnlR = 0.0;
        $hasPnlUsdt = false;
        $hasPnlR = false;
        $mfeValues = [];
        $maeValues = [];
        $holdingValues = [];

        foreach ($rows as $row) {
            $pnl = $row->getPnlUsdt();
            if ($pnl !== null) {
                $closedCount++;
                $pnlUsdt += $pnl;
                $hasPnlUsdt = true;
                if ($pnl > 0) {
                    $winCount++;
                } elseif ($pnl < 0) {
                    $lossCount++;
                }
            }
            if ($row->getPnlR() !== null) {
                $pnlR += $row->getPnlR();
                $hasPnlR = true;
            }
            if ($row->getMfePct() !== null) {
                $mfeValues[] = $row->getMfePct();
            }
            if ($row->getMaePct() !== null) {
                $maeValues[] = $row->getMaePct();
            }
            if ($row->getHoldingTimeSec() !== null) {
                $holdingValues[] = $row->getHoldingTimeSec();
            }
        }

        return [
            'trade_count' => $tradeCount,
            'closed_count' => $closedCount,
            'open_count' => $tradeCount - $closedCount,
            'win_count' => $winCount,
            'loss_count' => $lossCount,
            // Win-rate sur les trades CLOTURÉS uniquement (un trade encore ouvert n'a pas
            // d'issue). Null si aucun trade clôturé (pas de division par zéro trompeuse).
            'win_rate' => $closedCount > 0 ? round($winCount / $closedCount, 4) : null,
            // PnL net : somme des valeurs de la vue (jamais recalculé). Null si aucune
            // valeur disponible (distinct d'un vrai 0).
            'pnl_usdt' => $hasPnlUsdt ? round($pnlUsdt, 8) : null,
            'pnl_r' => $hasPnlR ? round($pnlR, 8) : null,
            'mfe_pct_avg' => $this->avg($mfeValues),
            'mfe_pct_median' => $this->median($mfeValues),
            'mae_pct_avg' => $this->avg($maeValues),
            'mae_pct_median' => $this->median($maeValues),
            'holding_time_sec_avg' => $this->avg($holdingValues),
        ];
    }

    /**
     * Agrégat vide explicite (run sans trade, run inconnu ou source indisponible).
     *
     * @return array<string,mixed>
     */
    private function emptyOutcome(string $runId, bool $available): array
    {
        return [
            'run_id' => $runId,
            'available' => $available,
            'trade_count' => 0,
            'closed_count' => 0,
            'open_count' => 0,
            'win_count' => 0,
            'loss_count' => 0,
            'win_rate' => null,
            'pnl_usdt' => null,
            'pnl_r' => null,
            'mfe_pct_avg' => null,
            'mfe_pct_median' => null,
            'mae_pct_avg' => null,
            'mae_pct_median' => null,
            'holding_time_sec_avg' => null,
            'by_symbol' => [],
        ];
    }

    /**
     * @param float[] $values
     */
    private function avg(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 8);
    }

    /**
     * @param float[] $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);
        if ($count % 2 === 1) {
            return round($values[$mid], 8);
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2, 8);
    }
}
