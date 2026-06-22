<?php

declare(strict_types=1);

namespace App\Trading\Service;

use App\Trading\Entity\PositionTradeAnalysis;
use Psr\Log\LoggerInterface;

/**
 * OBS-003 — Agrégation OUTCOME d'un run : relie un run d'orchestration à ses trades
 * résultants (vue `position_trade_analysis`) par identifiant de corrélation, en lecture
 * seule. Le PnL n'est JAMAIS recalculé : on agrège les valeurs déjà exposées par la vue
 * (somme/moyenne/médiane). Le winrate ne porte que sur les trades clôturés ET rapprochés.
 *
 * Distinction stricte recorded vs net PnL : `recorded_pnl_usdt` est la valeur enregistrée
 * (pas garantie nette de tous les coûts), `net_pnl_usdt` n'est agrégé que sur les lignes
 * dont la vue atteste `net_pnl_complete = true` ; `data_complete` est vrai uniquement si
 * tous les trades clôturés ont un net complet.
 *
 * Fail-safe : la source indisponible (exception du reader) est signalée par
 * `source_available = false` et NE doit jamais être confondue avec « 0 trade » — c'est à
 * l'appelant (contrôleur HTTP) de distinguer les deux. Ici, une exception est relancée
 * pour que le contrôleur réponde explicitement (jamais un agrégat vide silencieux).
 */
final class RunTradeOutcomeService
{
    public function __construct(
        private readonly PositionTradeAnalysisReaderInterface $reader,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Construit l'agrégat OUTCOME pour un `run_id` ORIGINAL (l'identifiant de corrélation
     * canonique est dérivé ici, identique à Python).
     *
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
            'by_set' => $this->group($rows, static fn (PositionTradeAnalysis $r) => $r->getSetId()),
            'by_profile' => $this->group($rows, static fn (PositionTradeAnalysis $r) => $r->getMtfProfile()),
            'by_exchange' => $this->group($rows, static fn (PositionTradeAnalysis $r) => $r->getExchange()),
            'by_symbol' => $this->group($rows, static fn (PositionTradeAnalysis $r) => $r->getSymbol()),
        ];
    }

    /**
     * @param PositionTradeAnalysis[] $rows
     * @param callable(PositionTradeAnalysis):?string $keyFn
     * @return list<array<string,mixed>>
     */
    private function group(array $rows, callable $keyFn): array
    {
        /** @var array<string,PositionTradeAnalysis[]> $buckets */
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
     * @param PositionTradeAnalysis[] $rows
     * @return array<string,mixed>
     */
    private function aggregate(array $rows): array
    {
        $tradeCount = count($rows);
        $closed = array_values(array_filter($rows, static fn (PositionTradeAnalysis $r): bool => $r->isClosed()));
        $matchedClosed = array_values(array_filter(
            $closed,
            static fn (PositionTradeAnalysis $r): bool => $r->isMatched()
        ));

        $closedCount = count($closed);
        $matchedCount = count(array_filter($rows, static fn (PositionTradeAnalysis $r): bool => $r->isMatched()));
        $unmatchedCount = $tradeCount - $matchedCount;

        // Winrate : trades clôturés ET rapprochés uniquement (PnL fiable).
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

        $recordedPnl = $this->sum($rows, static fn (PositionTradeAnalysis $r): ?float => $r->getRecordedPnlUsdt());
        $pnlR = $this->sum($rows, static fn (PositionTradeAnalysis $r): ?float => $r->getPnlR());

        // Net PnL : somme UNIQUEMENT sur les lignes au net complet ; sinon null + flag.
        $netComplete = $this->isDataComplete($rows);
        $netPnl = $netComplete
            ? $this->sum($rows, static fn (PositionTradeAnalysis $r): ?float => $r->getNetPnlUsdt())
            : null;

        $mfeValues = $this->values($rows, static fn (PositionTradeAnalysis $r): ?float => $r->getMfePct());
        $maeValues = $this->values($rows, static fn (PositionTradeAnalysis $r): ?float => $r->getMaePct());
        $holdValues = $this->values($rows, static fn (PositionTradeAnalysis $r): ?float => $r->getHoldingTimeSec());

        return [
            'trade_count' => $tradeCount,
            'closed_count' => $closedCount,
            'open_count' => $tradeCount - $closedCount,
            'matched_count' => $matchedCount,
            'unmatched_count' => $unmatchedCount,
            'win_count' => $winCount,
            'loss_count' => $lossCount,
            'win_rate_closed' => $winRate,
            'recorded_pnl_usdt' => $recordedPnl,
            'pnl_r' => $pnlR,
            'net_pnl_usdt' => $netPnl,
            'net_pnl_complete' => $netComplete,
            'mfe_pct_avg' => $this->avg($mfeValues),
            'mfe_pct_median' => $this->median($mfeValues),
            'mae_pct_avg' => $this->avg($maeValues),
            'mae_pct_median' => $this->median($maeValues),
            'holding_time_sec_avg' => $this->avg($holdValues),
        ];
    }

    /**
     * Net complet ssi tous les trades CLÔTURÉS le sont (un run sans trade clôturé est
     * « complet » de façon vide : il n'y a aucun PnL net manquant).
     *
     * @param PositionTradeAnalysis[] $rows
     */
    private function isDataComplete(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row->isClosed() && !$row->isNetPnlComplete()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param PositionTradeAnalysis[] $rows
     * @param callable(PositionTradeAnalysis):?float $fn
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
     * @param PositionTradeAnalysis[] $rows
     * @param callable(PositionTradeAnalysis):?float $fn
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
