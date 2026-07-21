<?php

declare(strict_types=1);

namespace App\Trading\Backfill;

final readonly class PositionTradeAnalysisBackfillDivergenceReportService implements PositionTradeAnalysisBackfillDivergenceReportServiceInterface
{
    private const PNL_DIVERGENCE_EPSILON = 0.000001;

    public function __construct(private PositionTradeAnalysisBackfillDivergenceReaderInterface $reader)
    {
    }

    public function buildReport(BackfillDivergenceCriteria $criteria): array
    {
        $fetchedRows = $this->fetchRows($criteria);
        $truncated = count($fetchedRows) > $criteria->limit;
        $sourceRows = $truncated ? array_slice($fetchedRows, 0, $criteria->limit) : $fetchedRows;

        $rows = [];
        $counts = [];
        $v1Rows = 0;
        $v2Rows = 0;
        $commonRows = 0;
        $divergentRows = 0;
        $certifiedRows = 0;
        $lineageEvidenceRows = 0;
        $costEvidenceRows = 0;
        $quantityEvidenceRows = 0;

        foreach ($sourceRows as $sourceRow) {
            $normalized = $this->normalizeRow($sourceRow);
            if ($normalized['v1_present']) {
                $v1Rows++;
            }
            if ($normalized['v2_present']) {
                $v2Rows++;
            }
            if ($normalized['v1_present'] && $normalized['v2_present']) {
                $commonRows++;
            }
            if ($normalized['has_lineage_evidence']) {
                $lineageEvidenceRows++;
            }
            if ($normalized['has_cost_evidence']) {
                $costEvidenceRows++;
            }
            if ($normalized['has_quantity_evidence']) {
                $quantityEvidenceRows++;
            }
            if ($normalized['classification'] === 'pnl_divergence') {
                $divergentRows++;
            }
            if ($normalized['classification'] === 'certified') {
                $certifiedRows++;
            }
            $counts[$normalized['classification']] = ($counts[$normalized['classification']] ?? 0) + 1;
            $rows[] = $normalized;
        }

        $nextCursor = $rows === [] ? null : (int) $rows[array_key_last($rows)]['entry_event_id'];
        $excludedRows = count($rows) - $certifiedRows;
        $ready = $excludedRows === 0 && !$truncated;

        return [
            'metadata' => [
                'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'dry_run' => $criteria->dryRun,
                'read_only' => true,
                'comparison_key' => 'entry_event_id',
                'matching_policy' => 'v1/v2 comparison only; no symbol-only or time-window backfill matching',
                'filters' => [
                    'from' => $criteria->from?->format(\DateTimeInterface::ATOM),
                    'to' => $criteria->to?->format(\DateTimeInterface::ATOM),
                    'profile' => $criteria->profile,
                    'exchange' => $criteria->exchange,
                    'market_type' => $criteria->marketType,
                    'symbol' => $criteria->symbol,
                ],
            ],
            'pagination' => [
                'limit' => $criteria->limit,
                'batch_size' => $criteria->batchSize,
                'resume_cursor' => $criteria->resumeCursor,
                'next_cursor' => $nextCursor,
                'truncated' => $truncated,
            ],
            'summary' => [
                'v1_rows' => $v1Rows,
                'v2_rows' => $v2Rows,
                'common_rows' => $commonRows,
                'v1_only_rows' => $counts['v1_only'] ?? 0,
                'v2_only_rows' => $counts['v2_only'] ?? 0,
                'divergent_rows' => $divergentRows,
                'certified_rows' => $certifiedRows,
                'excluded_rows' => $excludedRows,
                'classification_counts' => $counts,
                'lineage_evidence_rate' => $this->rate($lineageEvidenceRows, count($rows)),
                'cost_evidence_rate' => $this->rate($costEvidenceRows, count($rows)),
                'quantity_evidence_rate' => $this->rate($quantityEvidenceRows, count($rows)),
                'pnl_delta_usdt_sum' => $this->sumColumn($rows, 'pnl_delta_usdt'),
                'holding_time_delta_sec_sum' => $this->sumColumn($rows, 'holding_time_delta_sec'),
                'mfe_delta_pct_sum' => $this->sumColumn($rows, 'mfe_delta_pct'),
                'mae_delta_pct_sum' => $this->sumColumn($rows, 'mae_delta_pct'),
            ],
            'proposal' => [
                'ready_for_backfill' => $ready,
                'blocking_reason' => $ready ? null : ($truncated ? 'pagination_truncated' : 'divergence_or_incomplete_data'),
                'apply_mode_available' => false,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchRows(BackfillDivergenceCriteria $criteria): array
    {
        $rows = [];
        $cursor = $criteria->resumeCursor;
        $target = $criteria->limit + 1;

        while (count($rows) < $target) {
            $batchLimit = min($criteria->batchSize, $target - count($rows));
            $batchCriteria = new BackfillDivergenceCriteria(
                from: $criteria->from,
                to: $criteria->to,
                profile: $criteria->profile,
                exchange: $criteria->exchange,
                marketType: $criteria->marketType,
                symbol: $criteria->symbol,
                limit: $batchLimit,
                batchSize: $criteria->batchSize,
                resumeCursor: $cursor,
                dryRun: $criteria->dryRun,
            );
            $batch = $this->reader->fetchRows($batchCriteria);
            if ($batch === []) {
                break;
            }

            $advanced = false;
            foreach ($batch as $row) {
                $entryEventId = $this->intOrNull($row['entry_event_id'] ?? null);
                if ($entryEventId === null || ($cursor !== null && $entryEventId <= $cursor)) {
                    continue;
                }

                $rows[] = $row;
                $cursor = $entryEventId;
                $advanced = true;
                if (count($rows) >= $target) {
                    break 2;
                }
            }

            if (!$advanced || count($batch) < $batchLimit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $v1Present = $this->bool($row['v1_present'] ?? false);
        $v2Present = $this->bool($row['v2_present'] ?? false);
        $flags = $this->flags($row['v2_pnl_quality_flags'] ?? []);
        $v2Pnl = $this->v2ComparablePnl($row);
        $v1Pnl = $this->float($row['v1_pnl_usdt'] ?? null);
        $pnlDelta = $this->delta($v1Pnl, $v2Pnl);
        $classification = $this->classify($row, $v1Present, $v2Present, $flags, $pnlDelta);

        return [
            'entry_event_id' => (int) $row['entry_event_id'],
            'classification' => $classification,
            'symbol' => $row['symbol'] ?? null,
            'exchange' => $row['exchange'] ?? null,
            'market_data_venue' => ($venue = trim((string) ($row['market_data_venue'] ?? ''))) !== '' ? $venue : null,
            'market_type' => $row['market_type'] ?? null,
            'profile' => $row['profile'] ?? null,
            'entry_time' => $this->stringOrNull($row['entry_time'] ?? null),
            'v1_present' => $v1Present,
            'v2_present' => $v2Present,
            'v1_close_event_id' => $this->intOrNull($row['v1_close_event_id'] ?? null),
            'v2_close_event_id' => $this->intOrNull($row['v2_close_event_id'] ?? null),
            'internal_trade_id' => $row['v2_internal_trade_id'] ?? null,
            'trade_id' => $row['v2_trade_id'] ?? null,
            'position_id' => $row['v2_position_id'] ?? null,
            'close_match_status' => $row['v2_close_match_status'] ?? null,
            'close_matched_by' => $row['v2_close_matched_by'] ?? null,
            'analysis_status' => $row['v2_analysis_status'] ?? null,
            'cost_completeness' => $row['v2_cost_completeness'] ?? null,
            'position_fully_closed' => $this->nullableBool($row['v2_position_fully_closed'] ?? null),
            'quality_flags' => $flags,
            'v1_pnl_usdt' => $v1Pnl,
            'v2_pnl_usdt' => $v2Pnl,
            'pnl_delta_usdt' => $pnlDelta,
            'holding_time_delta_sec' => $this->delta($this->float($row['v1_holding_time_sec'] ?? null), $this->float($row['v2_holding_time_sec'] ?? null)),
            'mfe_delta_pct' => $this->delta($this->float($row['v1_mfe_pct'] ?? null), $this->float($row['v2_mfe_pct'] ?? null)),
            'mae_delta_pct' => $this->delta($this->float($row['v1_mae_pct'] ?? null), $this->float($row['v2_mae_pct'] ?? null)),
            'has_lineage_evidence' => $v2Present && (($row['v2_internal_trade_id'] ?? null) !== null || ($row['v2_trade_id'] ?? null) !== null || ($row['v2_position_id'] ?? null) !== null),
            'has_cost_evidence' => $v2Present && in_array($row['v2_cost_completeness'] ?? null, ['partial', 'complete'], true),
            'has_quantity_evidence' => $v2Present && $this->nullableBool($row['v2_position_fully_closed'] ?? null) === true && !in_array('quantity_mismatch', $flags, true),
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $flags
     */
    private function classify(array $row, bool $v1Present, bool $v2Present, array $flags, ?float $pnlDelta): string
    {
        if ($v1Present && !$v2Present) {
            return 'v1_only';
        }
        if (!$v1Present && $v2Present) {
            return 'v2_only';
        }
        if (!$v1Present && !$v2Present) {
            return 'unknown';
        }
        if (in_array('identifier_conflict', $flags, true)) {
            return 'identifier_conflict';
        }
        if (($row['v2_close_match_status'] ?? null) === 'unmatched' || ($row['v2_analysis_status'] ?? null) === 'unmatched') {
            return 'unmatched';
        }
        if (in_array('quantity_mismatch', $flags, true)) {
            return 'quantity_mismatch';
        }
        if ($pnlDelta !== null && abs($pnlDelta) > self::PNL_DIVERGENCE_EPSILON) {
            return 'pnl_divergence';
        }
        if (($row['v2_cost_completeness'] ?? null) === 'complete' && ($row['v2_net_pnl_usdt'] ?? null) !== null) {
            return 'certified';
        }
        if (($row['v2_cost_completeness'] ?? null) === 'unknown') {
            return 'unknown';
        }
        if (($row['v2_cost_completeness'] ?? null) === 'partial') {
            return 'costs_incomplete';
        }

        return 'partial';
    }

    /**
     * @param list<array<string,mixed>> $rows
     */
    private function sumColumn(array $rows, string $column): ?float
    {
        $sum = null;
        foreach ($rows as $row) {
            if (!is_float($row[$column] ?? null) && !is_int($row[$column] ?? null)) {
                continue;
            }
            $sum = ($sum ?? 0.0) + (float) $row[$column];
        }

        return $sum === null ? null : round($sum, 8);
    }

    private function rate(int $num, int $den): ?float
    {
        return $den > 0 ? round($num / $den, 6) : null;
    }

    private function delta(?float $left, ?float $right): ?float
    {
        return $left !== null && $right !== null ? round($right - $left, 8) : null;
    }

    private function firstFloat(mixed ...$values): ?float
    {
        foreach ($values as $value) {
            $float = $this->float($value);
            if ($float !== null) {
                return $float;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function v2ComparablePnl(array $row): ?float
    {
        if (($row['v2_cost_completeness'] ?? null) === 'complete' && ($row['v2_net_pnl_usdt'] ?? null) !== null) {
            return $this->float($row['v2_net_pnl_usdt']);
        }

        return $this->firstFloat(
            $row['v2_recorded_pnl_usdt'] ?? null,
            $row['v2_gross_realized_pnl_usdt'] ?? null,
        );
    }

    private function float(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value) || is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    private function bool(mixed $value): bool
    {
        if ($value === 't' || $value === 'true' || $value === '1') {
            return true;
        }
        if ($value === 'f' || $value === 'false' || $value === '0') {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->bool($value);
    }

    /**
     * @return list<string>
     */
    private function flags(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $flag): bool => is_string($flag) && $flag !== ''));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(\DateTimeInterface::ATOM);
        }
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
