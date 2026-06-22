<?php

declare(strict_types=1);

namespace App\Trading\Service;

use App\Trading\Entity\PositionTradeAnalysisV2;
use Psr\Log\LoggerInterface;

/**
 * OBS-003 — Agrégation OUTCOME d'un run : relie un run d'orchestration à ses trades
 * résultants (vue `position_trade_analysis_v2`) par identifiant de corrélation, en lecture
 * seule. Le PnL n'est JAMAIS recalculé : on agrège les valeurs déjà exposées par la vue
 * (somme/moyenne/médiane).
 *
 * Qualité (issue #190) — distinctions strictes :
 *  - `matched_closed` : entrée rapprochée d'une clôture par identifiant exact (seule base
 *    d'un trade certifié) ;
 *  - `unmatched` : entrée sans clôture rapprochée — état réel INCONNU (peut être ouverte
 *    OU clôturée non-rapprochable) ; jamais comptée comme position ouverte confirmée ;
 *  - `confirmed_open` : 0 ici (la vue read-only ne porte pas de preuve d'ouverture ; le
 *    confirmer exige l'état ouvert live, hors périmètre OBS-003).
 *
 * PnL — on n'expose JAMAIS un net certifié : `recorded_pnl_usdt` (enregistré) et
 * `estimated_net_pnl_usdt` (estimation best-effort, cf. `cost_completeness`) sont distincts.
 * `data_complete` n'est vrai que si toutes les lignes pertinentes sont rapprochées ET au
 * contrat de coûts complet (jamais le cas tant que le contrat #190 n'est pas livré).
 *
 * Fail-safe : la source indisponible (exception du reader) est relancée pour que le
 * contrôleur HTTP réponde explicitement (jamais un agrégat vide silencieux).
 */
final class RunTradeOutcomeService
{
    public function __construct(
        private readonly PositionTradeAnalysisReaderInterface $reader,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string,mixed>
     *
     * @throws PositionTradeOutcomeSourceException si la source est indisponible.
     */
    public function buildOutcome(string $originalRunId, ?string $setId = null): array
    {
        $correlationRunId = RunCorrelationId::canonical($originalRunId);

        try {
            $rows = $this->reader->findByCorrelationRunId($correlationRunId, $setId);
        } catch (\Throwable $e) {
            $this->logger?->warning('obs003.outcome.source_unavailable', [
                'run_id' => $originalRunId,
                'correlation_run_id' => $correlationRunId,
                'error' => $e->getMessage(),
            ]);

            throw new PositionTradeOutcomeSourceException(
                'position_trade_analysis source unavailable',
                previous: $e,
            );
        }

        return [
            'run_id' => $originalRunId,
            'correlation_run_id' => $correlationRunId,
            'set_id' => $setId,
            'source_available' => true,
            'data_complete' => $this->isDataComplete($rows),
            'summary' => $this->aggregate($rows),
            'by_set' => $this->group($rows, static fn (PositionTradeAnalysisV2 $r) => $r->getSetId()),
            'by_profile' => $this->group($rows, static fn (PositionTradeAnalysisV2 $r) => $r->getMtfProfile()),
            'by_exchange' => $this->group($rows, static fn (PositionTradeAnalysisV2 $r) => $r->getExchange()),
            'by_symbol' => $this->group($rows, static fn (PositionTradeAnalysisV2 $r) => $r->getSymbol()),
        ];
    }

    /**
     * @param PositionTradeAnalysisV2[] $rows
     * @param callable(PositionTradeAnalysisV2):?string $keyFn
     * @return list<array<string,mixed>>
     */
    private function group(array $rows, callable $keyFn): array
    {
        /** @var array<string,PositionTradeAnalysisV2[]> $buckets */
        $buckets = [];
        foreach ($rows as $row) {
            $key = $keyFn($row);
            if ($key === null || $key === '') {
                $key = 'unknown';
            }
            $buckets[$key][] = $row;
        }

        ksort($buckets);

        $out = [];
        foreach ($buckets as $key => $bucketRows) {
            $out[] = ['key' => $key] + $this->aggregate($bucketRows);
        }

        return $out;
    }

    /**
     * @param PositionTradeAnalysisV2[] $rows
     * @return array<string,mixed>
     */
    private function aggregate(array $rows): array
    {
        $tradeCount = count($rows);
        $matchedClosed = array_values(array_filter(
            $rows,
            static fn (PositionTradeAnalysisV2 $r): bool => $r->isMatchedClosed()
        ));
        $matchedClosedCount = count($matchedClosed);
        $unmatchedCount = $tradeCount - $matchedClosedCount;

        // Winrate : trades clôturés ET rapprochés disposant d'un PnL enregistré uniquement.
        $winCount = 0;
        $lossCount = 0;
        foreach ($matchedClosed as $r) {
            $pnl = $r->getRecordedPnlUsdt();
            if ($pnl === null) {
                continue;
            }
            if ($pnl > 0.0) {
                $winCount++;
            } elseif ($pnl < 0.0) {
                $lossCount++;
            }
        }
        $decided = $winCount + $lossCount;
        $winRate = $decided > 0 ? round($winCount / $decided, 6) : null;

        $recordedPnl = $this->sum($rows, static fn (PositionTradeAnalysisV2 $r): ?float => $r->getRecordedPnlUsdt());
        $pnlR = $this->sum($rows, static fn (PositionTradeAnalysisV2 $r): ?float => $r->getPnlR());
        // Somme d'ESTIMATIONS (best-effort), jamais présentée comme nette certifiée.
        $estimatedNet = $this->sum($rows, static fn (PositionTradeAnalysisV2 $r): ?float => $r->getEstimatedNetPnlUsdt());

        $mfeValues = $this->values($rows, static fn (PositionTradeAnalysisV2 $r): ?float => $r->getMfePct());
        $maeValues = $this->values($rows, static fn (PositionTradeAnalysisV2 $r): ?float => $r->getMaePct());
        $holdValues = $this->values($rows, static fn (PositionTradeAnalysisV2 $r): ?float => $r->getHoldingTimeSec());

        return [
            'trade_count' => $tradeCount,
            // unmatched n'est JAMAIS compté comme position ouverte confirmée.
            'matched_closed_count' => $matchedClosedCount,
            'unmatched_count' => $unmatchedCount,
            'confirmed_open_count' => 0,
            'unknown_state_count' => $unmatchedCount,
            'win_count' => $winCount,
            'loss_count' => $lossCount,
            'win_rate_closed' => $winRate,
            'recorded_pnl_usdt' => $recordedPnl,
            'pnl_r' => $pnlR,
            'estimated_net_pnl_usdt' => $estimatedNet,
            'cost_completeness' => $this->aggregateCostCompleteness($rows),
            'data_complete' => $this->isDataComplete($rows),
            'mfe_pct_avg' => $this->avg($mfeValues),
            'mfe_pct_median' => $this->median($mfeValues),
            'mae_pct_avg' => $this->avg($maeValues),
            'mae_pct_median' => $this->median($maeValues),
            'holding_time_sec_avg' => $this->avg($holdValues),
        ];
    }

    /**
     * Complétude agrégée des coûts sur les lignes clôturées-rapprochées :
     * `not_applicable` (aucune), `complete`/`partial`/`unknown` sinon, `mixed` si plusieurs
     * niveaux coexistent. Jamais `complete` tant que le contrat #190 n'est pas livré.
     *
     * @param PositionTradeAnalysisV2[] $rows
     */
    private function aggregateCostCompleteness(array $rows): string
    {
        $levels = [];
        foreach ($rows as $row) {
            if ($row->isMatchedClosed()) {
                $levels[$row->getCostCompleteness()] = true;
            }
        }
        if ($levels === []) {
            return 'not_applicable';
        }
        if (count($levels) === 1) {
            return array_key_first($levels);
        }

        return 'mixed';
    }

    /**
     * Données complètes ssi tout est exploitable comme KPI certifié : aucune ligne
     * unmatched ET tous les trades clôturés au contrat de coûts complet (jamais le cas
     * tant que les producteurs #190 ne fournissent pas brut + frais détaillés + spread).
     *
     * @param PositionTradeAnalysisV2[] $rows
     */
    private function isDataComplete(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!$row->isMatchedClosed()) {
                return false;
            }
            if (!$row->isCostComplete()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param PositionTradeAnalysisV2[] $rows
     * @param callable(PositionTradeAnalysisV2):?float $fn
     */
    private function sum(array $rows, callable $fn): ?float
    {
        $values = $this->values($rows, $fn);
        if ($values === []) {
            return null;
        }

        return round(array_sum($values), 8);
    }

    /**
     * @param PositionTradeAnalysisV2[] $rows
     * @param callable(PositionTradeAnalysisV2):?float $fn
     * @return list<float>
     */
    private function values(array $rows, callable $fn): array
    {
        $values = [];
        foreach ($rows as $row) {
            $v = $fn($row);
            if ($v !== null) {
                $values[] = $v;
            }
        }

        return $values;
    }

    /**
     * @param list<float> $values
     */
    private function avg(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 8);
    }

    /**
     * @param list<float> $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);

        if ($n % 2 === 1) {
            return round($values[$mid], 8);
        }

        return round(($values[$mid - 1] + $values[$mid]) / 2.0, 8);
    }
}
